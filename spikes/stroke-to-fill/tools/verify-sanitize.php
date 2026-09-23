<?php
/**
 * Spike for #12: prove builder output survives core's icon sanitizer unchanged, and
 * capture what wp_get_icon() actually returns for it.
 *
 * Usage: wp eval-file tools/verify-sanitize.php <icons.json> [<core-icons.json>]
 *
 * <icons.json> maps icon name to SVG markup. Every SVG is run through
 * WP_Icons_Registry::sanitize_icon_content(), which is protected, so it is called through
 * reflection to test core's own code rather than a copy of its allowlist. Each icon is then
 * registered in a throwaway "ii-spike" collection and rendered with wp_get_icon(); the
 * markup is written to <core-icons.json> for compare.mjs --from-core.
 *
 * One change is expected and allowed: wp_kses() lowercases attribute names, so viewBox is
 * stored as viewbox. Core's own icons are stored the same way. HTML parsers restore the
 * case for inline SVG, but the markup is not valid as a standalone .svg file or data URI.
 *
 * @package InfiniteIcons
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI only spike, never loaded by the plugin.

$ii_in  = $args[0] ?? '';
$ii_out = $args[1] ?? '';

if ( ! is_readable( $ii_in ) ) {
	WP_CLI::error( 'Usage: wp eval-file tools/verify-sanitize.php <icons.json> [<core-icons.json>]' );
}

$ii_icons    = json_decode( file_get_contents( $ii_in ), true );
$ii_registry = WP_Icons_Registry::get_instance();
$ii_sanitize = new ReflectionMethod( $ii_registry, 'sanitize_icon_content' );
$ii_sanitize->setAccessible( true );

$ii_changed = array();
foreach ( $ii_icons as $ii_name => $ii_svg ) {
	$ii_clean = $ii_sanitize->invoke( $ii_registry, $ii_svg );
	if ( str_replace( ' viewbox=', ' viewBox=', $ii_clean ) !== $ii_svg ) {
		$ii_changed[ $ii_name ] = $ii_clean;
	}
}

WP_CLI::log( sprintf( 'Sanitizer: %d of %d icons unchanged apart from viewBox case.', count( $ii_icons ) - count( $ii_changed ), count( $ii_icons ) ) );
foreach ( array_slice( $ii_changed, 0, 3, true ) as $ii_name => $ii_clean ) {
	WP_CLI::log( "  changed: $ii_name" );
	WP_CLI::log( '    in:  ' . substr( $ii_icons[ $ii_name ], 0, 160 ) );
	WP_CLI::log( '    out: ' . substr( $ii_clean, 0, 160 ) );
}

if ( $ii_out ) {
	wp_register_icon_collection( 'ii-spike', array( 'label' => 'Infinite Icons spike' ) );

	$ii_rendered = array();
	$ii_start    = microtime( true );
	foreach ( $ii_icons as $ii_name => $ii_svg ) {
		if ( ! wp_register_icon(
			"ii-spike/$ii_name",
			array(
				'label'   => $ii_name,
				'content' => $ii_svg,
			)
		) ) {
			WP_CLI::warning( "Could not register $ii_name" );
			continue;
		}
		$ii_rendered[ $ii_name ] = wp_get_icon( "ii-spike/$ii_name", array( 'size' => 24 ) );
	}
	WP_CLI::log( sprintf( 'Registered and rendered %d icons in %.0f ms.', count( $ii_rendered ), ( microtime( true ) - $ii_start ) * 1000 ) );

	file_put_contents( $ii_out, wp_json_encode( $ii_rendered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	WP_CLI::log( "Wrote $ii_out" );
}

if ( $ii_changed ) {
	WP_CLI::error( count( $ii_changed ) . ' icons were changed by the sanitizer.' );
}
WP_CLI::success( 'All icons survive core\'s sanitizer unchanged.' );
