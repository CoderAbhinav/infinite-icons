<?php
/**
 * Icon rendering tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Registrar;
use InfiniteIcons\Render\Icon;
use InfiniteIcons\Settings\Options;

/**
 * Icon markup, sanitizing and enqueueing.
 *
 * @covers \InfiniteIcons\Render\Icon
 */
final class IconTest extends TestCase {

	/**
	 * Icon renderer under test.
	 *
	 * @var Icon
	 */
	private $icon;

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
		$this->icon = new Icon();
	}

	public function tear_down(): void {
		$this->unregister_collection( 'demo' );
		parent::tear_down();
	}

	public function test_renders_with_the_base_class_and_default_size(): void {
		$html = $this->icon->render( 'demo/home' );

		$this->assertStringContainsString( 'class="ii-icon"', $html );
		$this->assertStringContainsString( 'width="24"', $html );
		$this->assertStringContainsString( 'height="24"', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'fill="currentColor"', $html );
	}

	public function test_unknown_icons_render_nothing(): void {
		$this->assertSame( '', $this->icon->render( 'demo/nope' ) );
		$this->assertSame( '', $this->icon->render( 'nope/home' ) );
		$this->assertSame( '', $this->icon->render( '' ) );
	}

	public function test_exists(): void {
		$this->assertTrue( $this->icon->exists( 'demo/home' ) );
		$this->assertFalse( $this->icon->exists( 'demo/nope' ) );
	}

	public function test_a_label_makes_the_icon_non_decorative(): void {
		$html = $this->icon->render( 'demo/home', array( 'label' => 'Go home' ) );

		$this->assertStringContainsString( 'role="img"', $html );
		$this->assertStringContainsString( 'aria-label="Go home"', $html );
		$this->assertStringNotContainsString( 'aria-hidden', $html );
	}

	public function test_null_size_drops_the_dimensions_and_adds_the_inherit_class(): void {
		$html = $this->icon->render( 'demo/home', array( 'size' => null ) );

		$this->assertStringNotContainsString( 'width=', $html );
		$this->assertStringNotContainsString( 'height=', $html );
		$this->assertStringContainsString( 'ii-icon--inherit', $html );
	}

	public function test_zero_size_is_treated_as_inherit(): void {
		$this->assertStringContainsString( 'ii-icon--inherit', $this->icon->render( 'demo/home', array( 'size' => 0 ) ) );
	}

	public function test_rotation_and_flip_add_classes(): void {
		$html = $this->icon->render(
			'demo/home',
			array(
				'rotate' => 90,
				'flip'   => 'horizontal',
			)
		);
		$this->assertStringContainsString( 'ii-rotate-90', $html );
		$this->assertStringContainsString( 'ii-flip-h', $html );

		$this->assertStringContainsString( 'ii-flip-both', $this->icon->render( 'demo/home', array( 'flip' => 'both' ) ) );
		$this->assertStringContainsString( 'ii-flip-v', $this->icon->render( 'demo/home', array( 'flip' => 'VERTICAL' ) ) );
	}

	public function test_invalid_rotation_and_flip_are_ignored(): void {
		$html = $this->icon->render(
			'demo/home',
			array(
				'rotate' => 45,
				'flip'   => 'diagonal',
			)
		);

		$this->assertStringNotContainsString( 'ii-rotate', $html );
		$this->assertStringNotContainsString( 'ii-flip', $html );
	}

	public function test_extra_classes_are_sanitized(): void {
		$html = $this->icon->render( 'demo/home', array( 'class' => 'one two  three' ) );
		$this->assertStringContainsString( 'ii-icon one two three', $html );

		$html = $this->icon->render( 'demo/home', array( 'class' => 'ok "><script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script', $html );
		preg_match( '/class="([^"]*)"/', $html, $matches );
		$this->assertSame( 'ii-icon ok scriptalert1script', $matches[1] );
	}

	/**
	 * Runs the assertion for one data set.
	 *
	 * @dataProvider data_valid_colors
	 *
	 * @param string $input    Colour to pass in.
	 * @param string $expected Expected sanitized value.
	 */
	public function test_valid_colors_are_kept( string $input, string $expected ): void {
		$this->assertSame( $expected, Icon::sanitize_color( $input ) );
	}

	/**
	 * Supplies the data sets.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function data_valid_colors(): array {
		return array(
			array( '#fff', '#fff' ),
			array( '#FFFFFF', '#FFFFFF' ),
			array( '#ffffffaa', '#ffffffaa' ),
			array( 'red', 'red' ),
			array( 'RED', 'red' ),
			array( 'currentColor', 'currentcolor' ),
			array( 'rgb(1,2,3)', 'rgb(1,2,3)' ),
			array( 'rgba(1, 2, 3, 0.5)', 'rgba(1, 2, 3, 0.5)' ),
			array( 'hsl(200 50% 50%)', 'hsl(200 50% 50%)' ),
			array( 'var(--wp--preset--color--primary)', 'var(--wp--preset--color--primary)' ),
			array( '  #abc  ', '#abc' ),
		);
	}

	/**
	 * Runs the assertion for one data set.
	 *
	 * @dataProvider data_invalid_colors
	 *
	 * @param string $input Colour that must be rejected.
	 */
	public function test_dangerous_colors_are_rejected( string $input ): void {
		$this->assertSame( '', Icon::sanitize_color( $input ) );
	}

	/**
	 * Supplies the data sets.
	 *
	 * @return array<int, array{0: string}>
	 */
	public function data_invalid_colors(): array {
		return array(
			array( 'red;background:url(javascript:alert(1))' ),
			array( 'url(https://example.org/x.png)' ),
			array( 'expression(alert(1))' ),
			array( 'red" onload="alert(1)' ),
			array( "red' onload='alert(1)" ),
			array( '</style><script>alert(1)</script>' ),
			array( 'red}body{display:none' ),
			array( '@import "evil.css"' ),
			array( 'red/*x*/' ),
			array( 'notacolor' ),
			array( '' ),
		);
	}

	public function test_a_rejected_color_adds_no_style_attribute(): void {
		$html = $this->icon->render( 'demo/home', array( 'color' => 'red" onload="alert(1)' ) );

		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringNotContainsString( 'onload', $html );
	}

	public function test_an_accepted_color_becomes_an_inline_style(): void {
		$this->assertStringContainsString( 'style="color:#ff0000"', $this->icon->render( 'demo/home', array( 'color' => '#ff0000' ) ) );
	}

	public function test_only_safe_extra_attributes_are_set(): void {
		$html = $this->icon->render(
			'demo/home',
			array(
				'attrs' => array(
					'data-id'    => '7',
					'aria-live'  => 'polite',
					'id'         => 'my-icon',
					'onclick'    => 'alert(1)',
					'onload'     => 'alert(1)',
					'href'       => 'https://example.org',
					'xlink:href' => 'x',
				),
			)
		);

		$this->assertStringContainsString( 'data-id="7"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( 'id="my-icon"', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'onload', $html );
		$this->assertStringNotContainsString( 'href', $html );
	}

	public function test_attribute_values_are_escaped(): void {
		$html = $this->icon->render( 'demo/home', array( 'attrs' => array( 'data-x' => '"><script>alert(1)</script>' ) ) );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '&quot;&gt;', $html );
	}

	public function test_rendering_enqueues_the_stylesheet(): void {
		$this->assertFalse( wp_style_is( Icon::STYLE_HANDLE, 'enqueued' ) );

		$this->icon->render( 'demo/home' );

		$this->assertTrue( wp_style_is( Icon::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_an_unknown_icon_does_not_enqueue_the_stylesheet(): void {
		( new Icon() )->render( 'demo/nope' );

		$this->assertFalse( wp_style_is( Icon::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_the_render_filter_can_change_the_markup(): void {
		add_filter(
			'infinite_icons_render',
			static function ( string $html ): string {
				return '<span>' . $html . '</span>';
			}
		);

		$this->assertStringStartsWith( '<span><svg', $this->icon->render( 'demo/home' ) );
	}

	public function test_template_functions_delegate_to_the_renderer(): void {
		$this->assertStringContainsString( 'ii-icon', infinite_icons_get( 'demo/home' ) );
		$this->assertTrue( infinite_icons_exists( 'demo/home' ) );
		$this->assertFalse( infinite_icons_exists( 'demo/nope' ) );

		ob_start();
		infinite_icons_the_icon( 'demo/home' );
		$this->assertStringContainsString( '<svg', (string) ob_get_clean() );
	}
}
