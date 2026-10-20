<?php
/**
 * Real Testimonials on the wire: the generic Builder and prompt checks (see Builder_Adapter_Test_Case), plus what
 * only this product has: the bulk `create-testimonials` tool and its opt-in toolset (E124).
 *
 * Edition, adapter copy and toolset state come from the environment (see adapter-support.php);
 * `tests/run-adapter-matrix.sh` runs every combination. One body for Free and Pro (PRD §20 rule 6).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Adapter;

require_once __DIR__ . '/Builder_Adapter_Test_Case.php';

/**
 * Class RealTestimonialsPromptsAdapterTest
 */
class RealTestimonialsPromptsAdapterTest extends Builder_Adapter_Test_Case {

	/**
	 * Bulk create is served and runs through the real adapter, so the nested item schema (arrays of objects, enums)
	 * is accepted by the adapter and by core, and a bad item is refused before anything is written.
	 *
	 * @group builder-on
	 */
	public function test_bulk_create_is_served_and_runs_through_the_adapter() {
		$this->needs_builder( true );

		$tool    = 'real-testimonials-create-testimonials';
		$headers = $this->open_session();

		$list   = $this->mcp( 'tools/list', null, $headers );
		$served = array();
		foreach ( $list['body']['result']['tools'] ?? array() as $listed ) {
			$served[ $listed['name'] ] = $listed;
		}
		$this->assertArrayHasKey( $tool, $served, 'The Bulk Import toolset serves bulk create when it is on.' );

		// The item schema crosses the adapter intact: a list of objects that require a title.
		$schema = $served[ $tool ]['inputSchema'];
		$this->assertSame( 'array', $schema['properties']['items']['type'] );
		$this->assertSame( array( 'title' ), $schema['properties']['items']['items']['required'] );
		$this->assertArrayHasKey( 'social_profiles', $schema['properties']['items']['items']['properties'] );

		$count = static function () {
			return count(
				get_posts(
					array(
						'post_type'      => 'spt_testimonial',
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				)
			);
		};

		$dry = $this->call_tool(
			$tool,
			array(
				'dry_run' => true,
				'items'   => array(
					array(
						'title'  => 'Wire check',
						'rating' => 5,
					),
					array( 'title' => '   ' ),
				),
			),
			$headers
		);
		$this->assertFalse( $this->failed( $dry ) );
		$data = $this->tool_data( $dry );
		$this->assertSame( 1, $data['would_create'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertSame( 0, $count(), 'A dry run writes nothing.' );

		$real = $this->call_tool(
			$tool,
			array(
				'items' => array(
					array(
						'title'         => 'Created over MCP',
						'rating'        => 4,
						'reviewer_name' => 'Wire Tester',
					),
				),
			),
			$headers
		);
		$this->assertFalse( $this->failed( $real ) );
		$data = $this->tool_data( $real );
		$this->assertSame( 1, $data['created'] );
		$this->assertSame( 'Created over MCP', get_post( $data['results'][0]['id'] )->post_title );
		$this->assertSame( 1, $count() );

		// The single create tool stays small (it is on by default): the major properties only. The extras are refused
		// by its schema, and the answer of a normal call lists what was saved.
		$single_tool = 'real-testimonials-create-testimonial';
		$this->assertArrayHasKey( $single_tool, $served );
		$this->assertArrayNotHasKey( 'social_profiles', $served[ $single_tool ]['inputSchema']['properties'], 'Extras are offered by the Bulk Import tool only.' );
		$this->assertArrayHasKey( 'reviewer_image_id', $served[ $single_tool ]['inputSchema']['properties'] );
		$this->assertSame( array( 'title' ), $served[ $single_tool ]['inputSchema']['required'] );

		$one = $this->call_tool(
			$single_tool,
			array(
				'title'         => 'Single over MCP',
				'reviewer_name' => 'Wire Single',
				'rating'        => 5,
			),
			$headers
		);
		$this->assertFalse( $this->failed( $one ) );
		$data = $this->tool_data( $one );
		$this->assertSame( 'Single over MCP', $data['title'] );
		$this->assertContains( 'reviewer_name', $data['saved'] );
		$this->assertSame( 2, $count(), 'The single tool created one more testimonial.' );

		$extra = $this->call_tool(
			$single_tool,
			array(
				'title'           => 'Needs Bulk Import',
				'social_profiles' => array(
					array(
						'network' => 'github',
						'url'     => 'https://github.com/example',
					),
				),
			),
			$headers
		);
		$this->assertTrue( $this->failed( $extra ), 'The default tool refuses a property only Bulk Import offers.' );
		$this->assertSame( 2, $count() );

		// An unknown key inside an item is refused by the schema, so nothing is written.
		$bad = $this->call_tool(
			$tool,
			array(
				'items' => array(
					array(
						'title'   => 'Unknown key',
						'unknown' => 'x',
					),
				),
			),
			$headers
		);
		$this->assertTrue( $this->failed( $bad ) );
		$this->assertSame( 2, $count() );
	}

	/**
	 * Off is the default of every site: the single create tool stays in Management and bulk create is not served.
	 *
	 * @group builder-off
	 */
	public function test_with_the_builder_toolset_off_the_management_tool_is_served_and_bulk_is_not() {
		$this->needs_builder( false );

		$tools = $this->listed_tools( $this->open_session() );

		$this->assertContains( 'real-testimonials-create-testimonial', $tools, 'The single create tool stays in Management.' );
		$this->assertNotContains( 'real-testimonials-create-testimonials', $tools, 'Bulk create is off by default: its schema is a token cost on every message (D18).' );
	}
}
