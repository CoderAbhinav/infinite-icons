<?php
/**
 * Public template functions.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'infinite_icons_get' ) ) {
	/**
	 * Retrieves the markup for a registered icon.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $name Qualified icon name, e.g. "lucide/heart".
	 * @param array<string, mixed> $args {
	 *     Optional. Rendering arguments.
	 *
	 *     @type int|null              $size   Pixel size, or null to size with the font. Default 24.
	 *     @type string                $class  Extra class names. Default ''.
	 *     @type string                $label  Accessible label; empty means decorative. Default ''.
	 *     @type string                $color  CSS colour applied inline. Default ''.
	 *     @type int                   $rotate 0, 90, 180 or 270. Default 0.
	 *     @type string                $flip   'horizontal', 'vertical' or 'both'. Default ''.
	 *     @type array<string, string> $attrs  Extra data-*, aria-* or id attributes. Default array().
	 * }
	 * @return string SVG markup, or '' when the icon is not registered.
	 */
	function infinite_icons_get( string $name, array $args = array() ): string {
		$icon = \InfiniteIcons\Plugin::instance()->get( 'icon' );
		return $icon instanceof \InfiniteIcons\Render\Icon ? $icon->render( $name, $args ) : '';
	}
}

if ( ! function_exists( 'infinite_icons_the_icon' ) ) {
	/**
	 * Displays a registered icon.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $name Qualified icon name.
	 * @param array<string, mixed> $args Arguments; see {@see infinite_icons_get()}.
	 * @return void
	 */
	function infinite_icons_the_icon( string $name, array $args = array() ): void {
		// The markup comes from wp_get_icon(), which core has already sanitized.
		echo infinite_icons_get( $name, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_icon() returns markup core has already sanitized.
	}
}

if ( ! function_exists( 'infinite_icons_exists' ) ) {
	/**
	 * Checks whether an icon is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Qualified icon name.
	 * @return bool
	 */
	function infinite_icons_exists( string $name ): bool {
		$icon = \InfiniteIcons\Plugin::instance()->get( 'icon' );
		return $icon instanceof \InfiniteIcons\Render\Icon && $icon->exists( $name );
	}
}
