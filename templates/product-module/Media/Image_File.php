<?php
/**
 * The Image_File template file: what a downloaded file must be before it reaches the media library.
 *
 * Copy the whole `Media/` folder into a product's MCP module. See README.md in this folder.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Checks a downloaded file by its content, never by the address or the header it came with.
 */
class Image_File {

	/**
	 * Types accepted, by the type read from the file's bytes, with the extension it is saved under. No SVG (it can
	 * carry script), no HEIC or AVIF (not every server can make thumbnails of them).
	 */
	const TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/**
	 * Most pixels accepted. Making thumbnails holds the whole image in memory at roughly five bytes a pixel, against
	 * WP_MAX_MEMORY_LIMIT (256 MB by default, class-wp-image-editor-gd.php:100): 25 MP keeps well under it and still
	 * takes a 24 MP camera photo.
	 */
	const MAX_PIXELS = 25000000;

	/**
	 * Largest GIF accepted. Its size header gives one frame only, and Imagick works through every frame.
	 */
	const GIF_MAX_BYTES = 2097152;

	/**
	 * Inspect a file.
	 *
	 * @param string $file Path.
	 * @return array{mime: string, ext: string, width: int, height: int}|WP_Error
	 */
	public static function inspect( $file ) {
		$mime = is_string( $file ) && is_readable( $file ) ? wp_get_image_mime( $file ) : false;
		if ( ! is_string( $mime ) || ! isset( self::TYPES[ $mime ] ) ) {
			return new WP_Error( 'not_an_image', __( 'The file is not a JPEG, PNG, GIF or WebP image.', 'template-domain' ) );
		}

		$size = wp_getimagesize( $file );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return new WP_Error( 'not_an_image', __( 'The file is not a JPEG, PNG, GIF or WebP image.', 'template-domain' ) );
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];
		if ( $width * $height > self::MAX_PIXELS ) {
			return new WP_Error( 'too_many_pixels', __( 'The image has more than 25 megapixels.', 'template-domain' ) );
		}

		if ( 'image/gif' === $mime && filesize( $file ) > self::GIF_MAX_BYTES ) {
			return new WP_Error( 'gif_too_large', __( 'A GIF can be at most 2 MB.', 'template-domain' ) );
		}

		return array(
			'mime'   => $mime,
			'ext'    => self::TYPES[ $mime ],
			'width'  => $width,
			'height' => $height,
		);
	}
}
