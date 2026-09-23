<?php
/**
 * Spike for #20, part 3: what does each registration strategy cost per request?
 *
 * Usage: wp eval-file spikes/registration-timing/bench.php <icons.json>
 *
 * Measures, on this machine:
 * - decoding a whole pack JSON (what a naive loader does on every request),
 * - registering N icons through `content` (core runs wp_kses on each one in register()),
 * - listing them the way GET /wp/v2/icons does,
 * - the REST routes when icons are only registered lazily.
 *
 * @package InfiniteIcons
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI only spike, never loaded by the plugin.

$ii_raw = file_get_contents( $args[0] );

/**
 * Median wall time in ms and peak memory delta in MB of running $fn $runs times.
 */
function ii_bench( callable $fn, int $runs = 5 ): array {
	$times = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$start   = hrtime( true );
		$fn( $i );
		$times[] = ( hrtime( true ) - $start ) / 1e6;
	}
	sort( $times );
	return array( 'ms' => round( $times[ intdiv( $runs, 2 ) ], 1 ) );
}

$ii_mem = memory_get_usage();
$ii_r   = ii_bench( static fn() => json_decode( $ii_raw, true ) );
$ii_all = json_decode( $ii_raw, true );
WP_CLI::log( sprintf( 'json_decode whole pack (%.1f MB, %d icons): %.1f ms, +%.1f MB memory', strlen( $ii_raw ) / 1e6, count( $ii_all ), $ii_r['ms'], ( memory_get_usage() - $ii_mem ) / 1e6 ) );

// Build packs of N icons by repeating Lucide under suffixed names.
$ii_names = array_keys( $ii_all );
foreach ( array( 1, 50, 1000, 1848, 5000 ) as $ii_n ) {
	$ii_r = ii_bench(
		static function ( $run ) use ( $ii_n, $ii_names, $ii_all ) {
			$slug = "ii-bench-$ii_n-$run";
			wp_register_icon_collection( $slug, array( 'label' => $slug ) );
			for ( $i = 0; $i < $ii_n; $i++ ) {
				$base = $ii_names[ $i % count( $ii_names ) ];
				wp_register_icon(
					"$slug/$base-" . intdiv( $i, count( $ii_names ) ),
					array(
						'label'   => $base,
						'content' => $ii_all[ $base ],
					)
				);
			}
		},
		3
	);
	WP_CLI::log( sprintf( 'register %5d icons via content: %8.1f ms (%.3f ms per icon)', $ii_n, $ii_r['ms'], $ii_r['ms'] / $ii_n ) );
}

$ii_r = ii_bench( static fn() => WP_Icons_Registry::get_instance()->get_registered_icons( 'arrow' ) );
WP_CLI::log( sprintf( 'get_registered_icons( "arrow" ) over %d registered icons: %.1f ms', count( WP_Icons_Registry::get_instance()->get_registered_icons() ), $ii_r['ms'] ) );

// REST, as an administrator, with only the lazy spike plugin registering ii-spike icons.
wp_set_current_user( 1 );
foreach ( array( '/wp/v2/icon-collections', '/wp/v2/icons/ii-spike', '/wp/v2/icons/ii-spike/house' ) as $ii_route ) {
	$ii_res  = rest_do_request( new WP_REST_Request( 'GET', $ii_route ) );
	$ii_data = $ii_res->get_data();
	$ii_desc = is_array( $ii_data ) && isset( $ii_data[0] ) ? count( $ii_data ) . ' items' : ( $ii_data['code'] ?? ( $ii_data['name'] ?? 'object' ) );
	if ( '/wp/v2/icon-collections' === $ii_route ) {
		$ii_desc = implode( ', ', array_column( $ii_data, 'slug' ) );
	}
	WP_CLI::log( sprintf( 'GET %-30s %d  %s', $ii_route, $ii_res->get_status(), $ii_desc ) );
}
