<?php
/**
 * Spike for #20, part 1: does wp_register_icon() work after init, from inside a
 * render_block_data filter on core/icon?
 *
 * Usage: wp eval-file spikes/registration-timing/eval-late-register.php <icons.json>
 *
 * wp eval-file runs after init and wp_loaded, so everything here is "late". The icon is
 * unknown until the block is rendered; the filter registers the collection and the icon on
 * first sight, then core's render callback resolves it through wp_get_icon().
 *
 * @package InfiniteIcons
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI only spike, never loaded by the plugin.

$ii_icons = json_decode( file_get_contents( $args[0] ), true );
$ii_log   = array();

// Record anything core complains about.
add_action(
	'doing_it_wrong_run',
	static function ( $function_name, $message ) use ( &$ii_log ) {
		$ii_log[] = "doing_it_wrong: $function_name: $message";
	},
	10,
	2
);
add_action(
	'wp_trigger_error_run',
	static function ( $function_name, $message ) use ( &$ii_log ) {
		$ii_log[] = "trigger_error: $function_name: $message";
	},
	10,
	2
);

add_filter(
	'render_block_data',
	static function ( $block ) use ( $ii_icons, &$ii_log ) {
		if ( 'core/icon' !== ( $block['blockName'] ?? '' ) ) {
			return $block;
		}
		$name = $block['attrs']['icon'] ?? '';
		if ( ! str_starts_with( $name, 'ii-spike/' ) ) {
			return $block;
		}
		if ( ! WP_Icon_Collections_Registry::get_instance()->is_registered( 'ii-spike' ) ) {
			$ii_log[] = 'collection registered: ' . var_export( wp_register_icon_collection( 'ii-spike', array( 'label' => 'Spike' ) ), true );
		}
		$short = substr( $name, strlen( 'ii-spike/' ) );
		if ( isset( $ii_icons[ $short ] ) && ! WP_Icons_Registry::get_instance()->is_registered( $name ) ) {
			$ok       = wp_register_icon(
				$name,
				array(
					'label'   => $short,
					'content' => $ii_icons[ $short ],
				)
			);
			$ii_log[] = "icon $name registered: " . var_export( $ok, true );
		}
		return $block;
	}
);

$ii_checks = array(
	'init has fired'                    => did_action( 'init' ) > 0,
	'wp_loaded has fired'               => did_action( 'wp_loaded' ) > 0,
	'ii-spike/house not registered yet' => ! WP_Icons_Registry::get_instance()->is_registered( 'ii-spike/house' ),
);

$ii_html = do_blocks(
	'<!-- wp:icon {"icon":"ii-spike/house"} /-->' .
	'<!-- wp:icon {"icon":"ii-spike/house","ariaLabel":"Home"} /-->' .
	'<!-- wp:icon {"icon":"ii-spike/not-in-pack"} /-->' .
	'<!-- wp:icon {"icon":"core/arrow-left"} /-->'
);

$ii_checks['ii-spike/house registered after'] = WP_Icons_Registry::get_instance()->is_registered( 'ii-spike/house' );
$ii_checks['rendered two ii-spike icons']     = 2 === substr_count( $ii_html, 'fill-rule="evenodd"' );
$ii_checks['labelled icon has role=img']      = str_contains( $ii_html, 'aria-label="Home" role="img"' );
$ii_checks['unknown icon renders nothing']    = 3 === substr_count( $ii_html, '<div' );
$ii_checks['core icon still renders']         = str_contains( $ii_html, 'M20 11.2H6.8' );
$ii_checks['no notices from core']            = ! array_filter( $ii_log, static fn( $l ) => str_contains( $l, ':' ) && ! str_contains( $l, 'registered' ) );

foreach ( $ii_log as $ii_line ) {
	WP_CLI::log( "  log: $ii_line" );
}
foreach ( $ii_checks as $ii_label => $ii_ok ) {
	WP_CLI::log( sprintf( '  %s %s', $ii_ok ? 'ok  ' : 'FAIL', $ii_label ) );
}
WP_CLI::log( '  html: ' . substr( preg_replace( '/ d="[^"]{40}[^"]*"/', ' d="..."', $ii_html ), 0, 700 ) );

if ( in_array( false, $ii_checks, true ) ) {
	WP_CLI::error( 'Late registration check failed.' );
}
WP_CLI::success( 'wp_register_icon() works after init, inside render_block_data.' );
