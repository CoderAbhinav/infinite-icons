<?php
/**
 * Icon rendering.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Render;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Renders registered icons.
 *
 * The SVG always comes from `wp_get_icon()`, which returns markup core has
 * already sanitized. Every attribute this class adds goes through
 * WP_HTML_Tag_Processor rather than string concatenation.
 *
 * @since 1.0.0
 */
final class Icon {

	/**
	 * Handle of the front-end stylesheet.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const STYLE_HANDLE = 'infinite-icons';

	/**
	 * Rotations the class accepts.
	 *
	 * @since 1.0.0
	 * @var array<int, int>
	 */
	const ROTATIONS = array( 0, 90, 180, 270 );

	/**
	 * Flip values the class accepts.
	 *
	 * @since 1.0.0
	 * @var array<int, string>
	 */
	const FLIPS = array( 'horizontal', 'vertical', 'both' );

	/**
	 * CSS colour keywords accepted in addition to hex, rgb(), hsl() and var().
	 *
	 * @since 1.0.0
	 * @var array<int, string>
	 */
	const COLOR_KEYWORDS = array( 'currentcolor', 'transparent', 'inherit', 'initial', 'unset', 'black', 'silver', 'gray', 'grey', 'white', 'maroon', 'red', 'purple', 'fuchsia', 'green', 'lime', 'olive', 'yellow', 'navy', 'blue', 'teal', 'aqua', 'orange', 'pink' );

	/**
	 * Whether the stylesheet was requested during this request.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $style_enqueued = false;

	/**
	 * Renders a registered icon.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $name Qualified icon name, "collection/icon-name".
	 * @param array<string, mixed> $args {
	 *     Optional. Rendering arguments.
	 *
	 *     @type int|null           $size   Width and height in pixels, or null to leave the
	 *                                      SVG's own dimensions alone. Default 24.
	 *     @type string             $class  Extra class names, space separated. Default ''.
	 *     @type string             $label  Accessible label. Empty renders the icon as
	 *                                      decorative (aria-hidden). Default ''.
	 *     @type string             $color  Any safe CSS colour, applied as an inline
	 *                                      `color` style. Default ''.
	 *     @type int                $rotate 0, 90, 180 or 270. Default 0.
	 *     @type string             $flip   'horizontal', 'vertical' or 'both'. Default ''.
	 *     @type array<string, string> $attrs Extra attributes; only data-*, aria-* and id.
	 * }
	 * @return string SVG markup, or an empty string when the icon is not registered.
	 */
	public function render( string $name, array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				'size'   => 24,
				'class'  => '',
				'label'  => '',
				'color'  => '',
				'rotate' => 0,
				'flip'   => '',
				'attrs'  => array(),
			)
		);

		$size = $args['size'];
		if ( null !== $size ) {
			$size = absint( $size );
			if ( ! $size ) {
				$size = null;
			}
		}

		$svg = wp_get_icon(
			$name,
			array(
				'size'  => $size,
				'label' => (string) $args['label'],
			)
		);

		if ( '' === $svg ) {
			return '';
		}

		$classes = array( 'ii-icon' );

		$rotate = (int) $args['rotate'];
		if ( in_array( $rotate, self::ROTATIONS, true ) && 0 !== $rotate ) {
			$classes[] = 'ii-rotate-' . $rotate;
		}

		$flip = strtolower( (string) $args['flip'] );
		if ( in_array( $flip, self::FLIPS, true ) ) {
			$classes[] = 'ii-flip-' . ( 'both' === $flip ? 'both' : $flip[0] );
		}

		foreach ( preg_split( '/\s+/', (string) $args['class'], -1, PREG_SPLIT_NO_EMPTY ) as $class_name ) {
			$clean = sanitize_html_class( $class_name );
			if ( '' !== $clean ) {
				$classes[] = $clean;
			}
		}

		$processor = new WP_HTML_Tag_Processor( $svg );
		if ( ! $processor->next_tag( array( 'tag_name' => 'svg' ) ) ) {
			return '';
		}

		foreach ( array_unique( $classes ) as $class_name ) {
			$processor->add_class( $class_name );
		}

		$color = self::sanitize_color( (string) $args['color'] );
		if ( '' !== $color ) {
			$processor->set_attribute( 'style', 'color:' . $color );
		}

		// When the caller asked for intrinsic sizing, scale with the surrounding
		// font size instead of falling back to the SVG's own 24px.
		if ( null === $size ) {
			$processor->remove_attribute( 'width' );
			$processor->remove_attribute( 'height' );
			$processor->add_class( 'ii-icon--inherit' );
		}

		foreach ( (array) $args['attrs'] as $attr => $value ) {
			$attr = strtolower( (string) $attr );
			if ( ! self::is_safe_attribute( $attr ) ) {
				continue;
			}
			$processor->set_attribute( $attr, (string) $value );
		}

		$this->enqueue_style();

		$html = $processor->get_updated_html();

		/**
		 * Filters the rendered icon markup.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $html The SVG markup.
		 * @param string               $name Qualified icon name.
		 * @param array<string, mixed> $args Rendering arguments.
		 */
		return (string) apply_filters( 'infinite_icons_render', $html, $name, $args );
	}

	/**
	 * Checks whether an icon is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Qualified icon name.
	 * @return bool
	 */
	public function exists( string $name ): bool {
		return '' !== wp_get_icon( $name, array( 'size' => null ) );
	}

	/**
	 * Enqueues the front-end stylesheet, registering it on first use.
	 *
	 * Icons are usually rendered while content is being filtered, which is
	 * after wp_enqueue_scripts. wp_enqueue_style still works there because the
	 * footer queue has not been printed yet.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_style(): void {
		if ( $this->style_enqueued ) {
			return;
		}
		$this->style_enqueued = true;

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				INFINITE_ICONS_URL . 'assets/frontend.css',
				array(),
				INFINITE_ICONS_VERSION
			);
		}
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Sanitizes a CSS colour.
	 *
	 * Accepts hex colours, the common keywords, `rgb()`/`rgba()`/`hsl()`/`hsla()`
	 * and `var(--custom-property)`. Anything else yields an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $color Colour to sanitize.
	 * @return string The colour, or '' when it is not safe to use.
	 */
	public static function sanitize_color( string $color ): string {
		$color = trim( $color );
		if ( '' === $color ) {
			return '';
		}
		// Anything that could close the attribute or start a new declaration.
		if ( preg_match( '/[<>"\'\\\;{}]|url\s*\(|expression|@import|\/\*/i', $color ) ) {
			return '';
		}
		if ( in_array( strtolower( $color ), self::COLOR_KEYWORDS, true ) ) {
			return strtolower( $color );
		}
		$hex = sanitize_hex_color( $color );
		if ( is_string( $hex ) && '' !== $hex ) {
			return $hex;
		}
		if ( preg_match( '/^#(?:[0-9a-f]{4}|[0-9a-f]{8})$/i', $color ) ) {
			return strtolower( $color );
		}
		if ( preg_match( '/^(?:rgba?|hsla?)\(\s*[0-9a-z%.,\/\s+-]+\s*\)$/i', $color ) ) {
			return $color;
		}
		if ( preg_match( '/^var\(\s*--[a-z0-9_-]+\s*(?:,\s*[a-z0-9#%.,()\s-]+)?\)$/i', $color ) ) {
			return $color;
		}
		return '';
	}

	/**
	 * Checks whether an extra attribute may be set on the SVG.
	 *
	 * @since 1.0.0
	 *
	 * @param string $attr Lowercase attribute name.
	 * @return bool
	 */
	private static function is_safe_attribute( string $attr ): bool {
		if ( 'id' === $attr ) {
			return true;
		}
		if ( 0 === strpos( $attr, 'on' ) ) {
			return false;
		}
		return 1 === preg_match( '/^(?:data|aria)-[a-z0-9_-]+$/', $attr );
	}
}
