<?php
/**
 * Spike for #20, part 4: can REST requests for a single icon register lazily too?
 *
 * Usage: wp eval-file spikes/registration-timing/eval-rest-lazy.php <icons.json>
 *
 * Hooks rest_pre_dispatch, which runs before the icons controller, and registers only the
 * icon named in the route. List and search routes still need the full pack.
 *
 * @package InfiniteIcons
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI only spike, never loaded by the plugin.

$ii_icons = json_decode( file_get_contents( $args[0] ), true );

add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, $request ) use ( $ii_icons ) {
		if ( preg_match( '#^/wp/v2/icons/ii-spike/([a-z0-9_-]+)$#', $request->get_route(), $m )
			&& isset( $ii_icons[ $m[1] ] )
			&& ! WP_Icons_Registry::get_instance()->is_registered( "ii-spike/{$m[1]}" )
		) {
			wp_register_icon(
				"ii-spike/{$m[1]}",
				array(
					'label'   => $m[1],
					'content' => $ii_icons[ $m[1] ],
				)
			);
		}
		return $result;
	},
	10,
	3
);

wp_set_current_user( 1 );
foreach ( array( '/wp/v2/icons/ii-spike/house', '/wp/v2/icons/ii-spike/missing', '/wp/v2/icons/ii-spike' ) as $ii_route ) {
	$ii_res  = rest_do_request( new WP_REST_Request( 'GET', $ii_route ) );
	$ii_data = $ii_res->get_data();
	WP_CLI::log(
		sprintf(
			'GET %-30s %d  %s',
			$ii_route,
			$ii_res->get_status(),
			isset( $ii_data['name'] ) ? $ii_data['name'] . ', content ' . strlen( $ii_data['content'] ?? '' ) . ' bytes' : ( $ii_data['code'] ?? count( $ii_data ) . ' items' )
		)
	);
}
