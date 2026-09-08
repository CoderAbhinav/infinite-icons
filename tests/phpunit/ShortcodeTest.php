<?php
/**
 * Shortcode tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Registrar;
use InfiniteIcons\Render\Icon;
use InfiniteIcons\Render\Shortcode;
use InfiniteIcons\Settings\Options;

/**
 * Shortcode attributes and output.
 *
 * @covers \InfiniteIcons\Render\Shortcode
 */
final class ShortcodeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$dir = $this->make_temp_dir();
		$this->make_pack(
			$dir,
			'demo',
			array(
				array(
					'name'  => 'home',
					'label' => 'Home',
				),
			)
		);
		( new Registrar( $this->locator_for( $dir ), new Options() ) )->register_packs();
		( new Shortcode( new Icon() ) )->register();
	}

	public function tear_down(): void {
		remove_shortcode( Shortcode::TAG );
		remove_shortcode( Shortcode::ALIAS );
		$this->unregister_collection( 'demo' );
		parent::tear_down();
	}

	public function test_both_tags_are_registered_and_render(): void {
		$this->assertTrue( shortcode_exists( 'infinite_icon' ) );
		$this->assertTrue( shortcode_exists( 'ii_icon' ) );

		$this->assertStringContainsString( 'ii-icon', do_shortcode( '[infinite_icon name="demo/home"]' ) );
		$this->assertStringContainsString( 'ii-icon', do_shortcode( '[ii_icon name="demo/home"]' ) );
	}

	public function test_size_attribute(): void {
		$this->assertStringContainsString( 'width="32"', do_shortcode( '[infinite_icon name="demo/home" size="32"]' ) );
		$this->assertStringContainsString( 'ii-icon--inherit', do_shortcode( '[infinite_icon name="demo/home" size="inherit"]' ) );
		$this->assertStringContainsString( 'ii-icon--inherit', do_shortcode( '[infinite_icon name="demo/home" size="INHERIT"]' ) );
	}

	public function test_color_class_label_rotate_and_flip(): void {
		$html = do_shortcode( '[infinite_icon name="demo/home" color="#333" class="extra" label="Home" rotate="180" flip="both"]' );

		$this->assertStringContainsString( 'style="color:#333"', $html );
		$this->assertStringContainsString( 'extra', $html );
		$this->assertStringContainsString( 'aria-label="Home"', $html );
		$this->assertStringContainsString( 'ii-rotate-180', $html );
		$this->assertStringContainsString( 'ii-flip-both', $html );
	}

	public function test_link_wraps_the_icon(): void {
		$html = do_shortcode( '[infinite_icon name="demo/home" link="https://example.org/a?b=1&amp;c=2"]' );

		$this->assertStringStartsWith( '<a href="https://example.org/a?b=1', $html );
		$this->assertStringEndsWith( '</a>', $html );
		$this->assertStringNotContainsString( 'target=', $html );
	}

	public function test_target_blank_adds_rel_noopener(): void {
		$html = do_shortcode( '[infinite_icon name="demo/home" link="https://example.org" target="_blank" label="Site"]' );

		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( 'aria-label="Site"', $html );
	}

	public function test_target_without_blank_is_ignored(): void {
		$this->assertStringNotContainsString( 'target=', do_shortcode( '[infinite_icon name="demo/home" link="https://example.org" target="_top"]' ) );
	}

	/**
	 * Runs the assertion for one data set.
	 *
	 * @dataProvider data_dangerous_links
	 *
	 * @param string $url URL that must not become an anchor.
	 */
	public function test_dangerous_links_are_dropped( string $url ): void {
		$html = do_shortcode( '[infinite_icon name="demo/home" link="' . $url . '"]' );

		$this->assertStringStartsWith( '<svg', $html, 'The icon still renders, but without a link.' );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * Supplies the data sets.
	 *
	 * @return array<int, array{0: string}>
	 */
	public function data_dangerous_links(): array {
		return array(
			array( 'javascript:alert(1)' ),
			array( 'data:text/html,<script>alert(1)</script>' ),
			array( 'vbscript:msgbox(1)' ),
			array( 'ftp://example.org' ),
		);
	}

	public function test_align_wraps_the_icon(): void {
		$this->assertStringStartsWith( '<span class="ii-align-center">', do_shortcode( '[infinite_icon name="demo/home" align="center"]' ) );
		$this->assertStringStartsWith( '<svg', do_shortcode( '[infinite_icon name="demo/home" align="top"]' ) );
	}

	public function test_align_and_link_nest_correctly(): void {
		$html = do_shortcode( '[infinite_icon name="demo/home" align="right" link="https://example.org"]' );

		$this->assertStringStartsWith( '<span class="ii-align-right"><a href="https://example.org"><svg', $html );
		$this->assertStringEndsWith( '</svg></a></span>', $html );
	}

	/**
	 * Runs the assertion for one data set.
	 *
	 * @dataProvider data_bad_names
	 *
	 * @param string $shortcode Shortcode that should render nothing.
	 */
	public function test_bad_names_render_nothing( string $shortcode ): void {
		$this->assertSame( '', do_shortcode( $shortcode ) );
	}

	/**
	 * Supplies the data sets.
	 *
	 * @return array<int, array{0: string}>
	 */
	public function data_bad_names(): array {
		return array(
			array( '[infinite_icon]' ),
			array( '[infinite_icon name=""]' ),
			array( '[infinite_icon name="demo/missing"]' ),
			array( '[infinite_icon name="missing/home"]' ),
			array( '[infinite_icon name="home"]' ),
			array( '[infinite_icon name="demo/../../etc/passwd"]' ),
			array( '[infinite_icon name="<script>alert(1)</script>"]' ),
		);
	}

	public function test_an_unknown_icon_is_silent_for_visitors_even_with_debugging_on(): void {
		wp_set_current_user( 0 );

		$this->assertSame( '', do_shortcode( '[infinite_icon name="demo/missing"]' ) );
	}

	public function test_extra_attributes_are_ignored(): void {
		$html = do_shortcode( '[infinite_icon name="demo/home" onclick="alert(1)" style="x" unknown="y"]' );

		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'unknown', $html );
	}

	public function test_the_shortcode_works_inside_post_content(): void {
		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Before [infinite_icon name="demo/home" size="16"] after.' )
		);
		$post    = get_post( $post_id );

		$rendered = apply_filters( 'the_content', $post->post_content );

		$this->assertStringContainsString( 'Before <svg', $rendered );
		$this->assertStringContainsString( 'width="16"', $rendered );
		$this->assertStringContainsString( 'after.', $rendered );
	}
}
