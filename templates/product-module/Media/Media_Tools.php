<?php
/**
 * The Media_Tools template file: `find-media` and `import-image`.
 *
 * Copy the whole `Media/` folder into a product's MCP module. See README.md in this folder.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Media;

use ShapedPlugin\Template\Mcp\Module;
use ShapedPlugin\Template\Mcp\Permission_Gate;
use ShapedPlugin\Template\Mcp\Support\WriteGuard;
use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lets an AI client get a media library id for an image: find one already uploaded, or import one from an https
 * address. Product write tools then take that id (for example a reviewer photo), and never fetch anything themselves.
 */
class Media_Tools {

	/**
	 * Attachment meta holding the SHA-256 of the address an image was imported from. Shared by every ShapedPlugin
	 * product (a data contract, not a call between products), so one address becomes one attachment on a site.
	 */
	const SOURCE_META = '_shaped_mcp_source_sha256';

	/**
	 * Name prefix of the per-address import lock (core stores it as the option `{name}.lock`), shared for the same
	 * reason.
	 */
	const LOCK_PREFIX = 'shaped_mcp_image_lock_';

	/**
	 * Seconds a lock is honoured. A call that died leaves its lock behind; after this it no longer blocks.
	 */
	const LOCK_TTL = 60;

	/**
	 * Seconds one `import-image` call may take. PHP's own limit cannot be relied on (30 to 60 seconds behind most
	 * proxies, 1200 on some local setups), and other plugins react to every new attachment (`add_attachment`).
	 */
	const DEADLINE = 20;

	/**
	 * Most images per `import-image` call.
	 */
	const MAX_IMPORT = 5;

	/**
	 * Most results per `find-media` page, and most ids per lookup.
	 */
	const MAX_RESULTS = 20;

	/**
	 * Highest `find-media` page. A larger page made WP_Query's offset overflow and serve page 1 under the asked
	 * number (E176).
	 */
	const MAX_PAGE = 1000;

	/**
	 * Most words of a `find-media` search that are matched.
	 */
	const MAX_TERMS = 6;

	/**
	 * Longest title or alt text returned by `find-media`. Whoever uploads a file names it, so its text reaches the
	 * AI client as data, kept short.
	 */
	const TEXT_LIMIT = 120;

	/**
	 * Permission of both tools: the product's base capability, and the right to upload files. The base capability
	 * can be lowered by a filter, and a Contributor must still not reach the media library through it.
	 *
	 * @return bool
	 */
	public static function can_use() {
		return Permission_Gate::can_access() && current_user_can( 'upload_files' );
	}

	/**
	 * Tool `find-media`: media library items by search text or by id.
	 *
	 * @param mixed $input Tool input.
	 * @return array|WP_Error
	 */
	public static function find_media( $input = null ) {
		if ( ! self::can_use() ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to browse the media library.', 'template-domain' ) );
		}

		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['type'] ) && 'video' === $input['type'] ? 'video' : 'image';
		$args  = array(
			'post_type'              => 'attachment',
			'post_status'            => array( 'inherit', 'private' ),
			'post_mime_type'         => $type,
			'suppress_filters'       => true,
			'lang'                   => '',
			'update_post_term_cache' => false,
		);

		$ids = array();
		if ( ! empty( $input['ids'] ) && is_array( $input['ids'] ) ) {
			$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $input['ids'] ) ) ) ), 0, self::MAX_RESULTS );
		}

		$page     = 1;
		$has_more = false;
		if ( array() !== $ids ) {
			$args['post__in']       = $ids;
			$args['orderby']        = 'post__in';
			$args['posts_per_page'] = count( $ids );
			$args['no_found_rows']  = true;
		} else {
			$per_page = isset( $input['per_page'] ) ? max( 1, min( self::MAX_RESULTS, (int) $input['per_page'] ) ) : 10;
			$page     = isset( $input['page'] ) ? max( 1, min( self::MAX_PAGE, (int) $input['page'] ) ) : 1;
			$search   = isset( $input['search'] ) && is_string( $input['search'] ) ? sanitize_text_field( mb_substr( $input['search'], 0, 100 ) ) : '';

			if ( '' !== $search ) {
				$matched  = self::search_ids( $search, $type, $per_page, $page );
				$has_more = count( $matched ) > $per_page;
				$matched  = array_slice( $matched, 0, $per_page );

				$args['post__in']       = array() !== $matched ? $matched : array( 0 );
				$args['orderby']        = 'post__in';
				$args['posts_per_page'] = $per_page;
				$args['no_found_rows']  = true;
			} else {
				$args['posts_per_page'] = $per_page;
				$args['paged']          = $page;
				$args['orderby']        = 'date';
				$args['order']          = 'DESC';
			}
		}

		$query = new WP_Query( $args );
		$items = array();
		$found = array();

		foreach ( $query->posts as $post ) {
			// Status follows the parent post (post.php:1303-1335): an image on someone else's draft stays hidden.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$items[] = self::describe( $post->ID );
			$found[] = (int) $post->ID;
		}

		$result = array(
			'type'  => $type,
			'items' => $items,
		);

		if ( array() !== $ids ) {
			// Missing and not readable look the same, so an id's existence does not leak.
			$result['not_found'] = array_values( array_diff( $ids, $found ) );
		} else {
			$result['page']     = $page;
			$result['has_more'] = isset( $args['paged'] ) ? $page < (int) $query->max_num_pages : $has_more;
		}

		return $result;
	}

	/**
	 * Ids of one page of attachments whose title, file name or alt text holds every word of the search, newest first,
	 * plus one more when a next page exists.
	 *
	 * Exactly the text `describe()` returns, so the client can see why each item matched. Core's search cannot do
	 * this: it reads title, caption and description (class-wp-query.php:1465-1488) and never alt text, and the
	 * `posts_search` filter is off under `suppress_filters` (E75). Captions and descriptions are never returned,
	 * so they are not searched either (E175).
	 *
	 * @param string $search   Sanitised search text.
	 * @param string $type     `image` or `video`.
	 * @param int    $per_page Page size.
	 * @param int    $page     Page, at most MAX_PAGE.
	 * @return int[]
	 */
	private static function search_ids( $search, $type, $per_page, $page ) {
		global $wpdb;

		$terms = array_slice( array_values( array_unique( preg_split( '/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY ) ) ), 0, self::MAX_TERMS );
		if ( array() === $terms ) {
			return array();
		}

		$where  = '';
		$values = array( $type . '/%' );
		foreach ( $terms as $term ) {
			$like     = '%' . $wpdb->esc_like( $term ) . '%';
			$where   .= " AND ( p.post_title LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ( '_wp_attached_file', '_wp_attachment_image_alt' ) AND m.meta_value LIKE %s ) )";
			$values[] = $like;
			$values[] = $like;
		}
		$values[] = ( $page - 1 ) * $per_page;
		$values[] = $per_page + 1;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where holds only %s placeholders built above, each with its value in $values; one search per tool call.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_status IN ( 'inherit', 'private' ) AND p.post_mime_type LIKE %s{$where} ORDER BY p.post_date DESC, p.ID DESC LIMIT %d, %d",
				$values
			)
		);
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Tool `import-image`: download up to 5 https images into the media library and return their ids.
	 *
	 * @param mixed $input Tool input.
	 * @return array|WP_Error
	 */
	public static function import_image( $input = null ) {
		if ( ! self::can_use() ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to upload files.', 'template-domain' ) );
		}

		$input  = is_array( $input ) ? $input : array();
		$images = isset( $input['images'] ) && is_array( $input['images'] ) ? array_values( $input['images'] ) : array();
		if ( array() === $images ) {
			return new WP_Error( 'invalid_input', __( 'Send at least one image address.', 'template-domain' ) );
		}
		if ( count( $images ) > self::MAX_IMPORT ) {
			return new WP_Error(
				'too_many_images',
				sprintf(
					/* translators: %d: most images per call. */
					__( 'At most %d images per call.', 'template-domain' ),
					self::MAX_IMPORT
				)
			);
		}

		$dry_run = ! empty( $input['dry_run'] );
		if ( $dry_run ) {
			return self::run_import( $images, true );
		}

		$key = isset( $input['idempotency_key'] ) && is_string( $input['idempotency_key'] ) ? mb_substr( $input['idempotency_key'], 0, 64 ) : '';

		return WriteGuard::idempotent(
			Module::SLUG . '/import-image',
			'import-image',
			$key,
			static function () use ( $images ) {
				return self::run_import( $images, false );
			}
		);
	}

	/**
	 * Import each image in turn, reporting one row per image. One failure does not stop the others.
	 *
	 * @param array $images  Items as sent.
	 * @param bool  $dry_run Check the addresses and look for earlier imports only: no request, nothing written.
	 * @return array
	 */
	private static function run_import( array $images, $dry_run ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$deadline  = microtime( true ) + self::DEADLINE;
		$own_hosts = Remote_Guard::own_hosts();
		$rows      = array();
		$counts    = array();

		foreach ( $images as $index => $image ) {
			if ( microtime( true ) > $deadline - 2 ) {
				$row = array(
					'status' => 'not_attempted',
					'error'  => array(
						'code'    => 'deadline_reached',
						'message' => __( 'Not tried: the call ran out of time. Send it again on its own.', 'template-domain' ),
					),
				);
			} else {
				$row = self::import_one( is_array( $image ) ? $image : array(), $own_hosts, $deadline, $dry_run );
			}

			$row    = array( 'index' => (int) $index ) + $row;
			$rows[] = $row;

			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
		}

		return array(
			'dry_run' => (bool) $dry_run,
			'counts'  => $counts,
			'images'  => $rows,
		);
	}

	/**
	 * Import one image.
	 *
	 * @param array    $image     Item: url, alt, title.
	 * @param string[] $own_hosts This site's hosts.
	 * @param float    $deadline  End of the call.
	 * @param bool     $dry_run   Check only.
	 * @return array Row without its index.
	 */
	private static function import_one( array $image, array $own_hosts, $deadline, $dry_run ) {
		$checked = Remote_Guard::check_url( $image['url'] ?? null, $own_hosts );
		if ( is_wp_error( $checked ) ) {
			return self::failed( $checked );
		}

		$hash     = Remote_Guard::source_hash( $checked['url'] );
		$existing = self::find_import( $hash );
		if ( $existing ) {
			return array( 'status' => $dry_run ? 'would_reuse' : 'reused' ) + self::describe( $existing );
		}

		if ( $dry_run ) {
			return array( 'status' => 'would_import' );
		}

		$quota = self::quota_error( 0 );
		if ( $quota ) {
			return self::failed( $quota );
		}

		if ( ! self::lock( $hash ) ) {
			return self::failed( new WP_Error( 'busy', __( 'The same image is being imported by another call. Try again in a minute.', 'template-domain' ) ) );
		}

		$file = '';
		try {
			// Another call may have finished the same address between the first look and the lock.
			$existing = self::find_import( $hash );
			if ( $existing ) {
				return array( 'status' => 'reused' ) + self::describe( $existing );
			}

			$fetched = Remote_Fetch::fetch( $checked['url'], $deadline );
			if ( is_wp_error( $fetched ) ) {
				return self::failed( $fetched );
			}
			$file = $fetched['file'];

			$info = Image_File::inspect( $file );
			if ( is_wp_error( $info ) ) {
				return self::failed( $info );
			}

			$quota = self::quota_error( $fetched['size'] );
			if ( $quota ) {
				return self::failed( $quota );
			}

			$id = self::store( $file, $info, $image, $hash );
			if ( is_wp_error( $id ) ) {
				return self::failed( $id );
			}

			return array( 'status' => 'imported' ) + self::describe( $id );
		} finally {
			Remote_Fetch::forget( $file );
			self::unlock( $hash );
		}
	}

	/**
	 * Put a checked file into the media library under our own name and text.
	 *
	 * Public so the storage rules can be tested on a real file: a download can only succeed against a real public
	 * host, which a test suite must not call.
	 *
	 * @param string $file  Temp file, checked by `Image_File::inspect()`. Moved or copied by core; the caller deletes
	 *                      what is left.
	 * @param array  $info  Result of `Image_File::inspect()`.
	 * @param array  $image Item as sent: alt, title.
	 * @param string $hash  Address hash.
	 * @return int|WP_Error Attachment id.
	 */
	public static function store( $file, array $info, array $image, $hash ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title = isset( $image['title'] ) && is_string( $image['title'] ) ? sanitize_text_field( mb_substr( $image['title'], 0, 200 ) ) : '';
		$base  = sanitize_title( '' !== $title ? $title : 'image' );
		$name  = sanitize_file_name( ( '' !== $base ? $base : 'image' ) . '-' . substr( $hash, 0, 8 ) . '.' . $info['ext'] );

		// Our own title and empty text: the title, caption and alt written inside the file (media.php:493-504) are the
		// remote author's words, and would reach the next AI client that reads this attachment.
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $file,
			),
			0,
			null,
			array(
				'post_title'   => '' !== $title ? $title : __( 'Imported image', 'template-domain' ),
				'post_content' => '',
				'post_excerpt' => '',
			)
		);

		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'upload_failed', __( 'The media library did not accept the file.', 'template-domain' ) );
		}

		// The file's own alt text is saved after our data (media.php:530-531): replace or remove it.
		$alt = isset( $image['alt'] ) && is_string( $image['alt'] ) ? sanitize_text_field( mb_substr( $image['alt'], 0, 250 ) ) : '';
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		} else {
			delete_post_meta( $id, '_wp_attachment_image_alt' );
		}

		add_post_meta( $id, self::SOURCE_META, $hash, true );

		return (int) $id;
	}

	/**
	 * An earlier import of the same address that the user can read and that is still an image.
	 *
	 * @param string $hash Address hash.
	 * @return int Attachment id, or 0.
	 */
	private static function find_import( $hash ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'meta_key'               => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact match on one indexed key, on an import only.
				'meta_value'             => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				'fields'                 => 'ids',
				'posts_per_page'         => 5,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'lang'                   => '',
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $id ) {
			if ( wp_attachment_is( 'image', $id ) && current_user_can( 'read_post', $id ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * The multisite storage quota, checked the way core checks an upload (ms.php:18-55). Core's own check is hooked
	 * only to browser uploads (`wp_handle_upload_prefilter`), never to sideloads, and it can end the request with
	 * wp_die(), so it is not called.
	 *
	 * @param int $size File size, or 0 before the download.
	 * @return WP_Error|null
	 */
	private static function quota_error( $size ) {
		if ( ! is_multisite() || get_site_option( 'upload_space_check_disabled' ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/ms.php';

		if ( upload_is_user_over_quota( false ) || get_upload_space_available() < max( 1, (int) $size ) ) {
			return new WP_Error( 'over_quota', __( 'This site has no storage space left for the image.', 'template-domain' ) );
		}

		return null;
	}

	/**
	 * Take the import lock of one address, with core's lock (`WP_Upgrader::create_lock()`, class-wp-upgrader.php:1057):
	 * an `INSERT IGNORE`, so two calls cannot both get it, and a lock older than LOCK_TTL is taken over. `add_option()`
	 * is not a lock: it writes with `ON DUPLICATE KEY UPDATE` (option.php:1142), so two calls would both succeed.
	 *
	 * @param string $hash Address hash.
	 * @return bool
	 */
	private static function lock( $hash ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		return \WP_Upgrader::create_lock( self::LOCK_PREFIX . substr( $hash, 0, 40 ), self::LOCK_TTL );
	}

	/**
	 * Release the import lock of one address.
	 *
	 * @param string $hash Address hash.
	 * @return void
	 */
	private static function unlock( $hash ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		\WP_Upgrader::release_lock( self::LOCK_PREFIX . substr( $hash, 0, 40 ) );
	}

	/**
	 * A failed row.
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	private static function failed( WP_Error $error ) {
		return array(
			'status' => 'failed',
			'error'  => array(
				'code'    => (string) $error->get_error_code(),
				'message' => (string) $error->get_error_message(),
			),
		);
	}

	/**
	 * What an AI client needs of an attachment: its id, short text, size and addresses. Never a server path,
	 * caption, description or the file's own metadata.
	 *
	 * @param int $id Attachment id.
	 * @return array
	 */
	private static function describe( $id ) {
		$id    = (int) $id;
		$meta  = wp_get_attachment_metadata( $id );
		$thumb = wp_attachment_is( 'image', $id ) ? wp_get_attachment_image_src( $id, 'thumbnail' ) : false;

		return array(
			'id'        => $id,
			'title'     => self::clip( get_the_title( $id ) ),
			'alt'       => self::clip( get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			'filename'  => self::clip( wp_basename( (string) get_attached_file( $id ) ) ),
			'mime'      => (string) get_post_mime_type( $id ),
			'width'     => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'    => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'url'       => (string) wp_get_attachment_url( $id ),
			'thumbnail' => is_array( $thumb ) ? (string) $thumb[0] : '',
		);
	}

	/**
	 * Plain text, one line, at most TEXT_LIMIT characters.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	private static function clip( $text ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );

		return mb_substr( $text, 0, self::TEXT_LIMIT );
	}
}
