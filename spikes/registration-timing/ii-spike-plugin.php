<?php
/**
 * Plugin Name: Infinite Icons registration spike
 * Description: Throwaway plugin for issue #20. Registers icons lazily per rendered core/icon block and reports what happened in an HTML comment.
 * Version: 0.0.0
 *
 * Copy this file and an icons.json ({ name: svg }) into
 * wp-content/plugins/ii-spike-registration/, activate it, view a page with
 * <!-- wp:icon {"icon":"ii-spike/house"} /--> in it, then deactivate and delete it.
 *
 * @package InfiniteIcons
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Throwaway spike, never shipped.

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics collected during the request.
 *
 * @return array
 */
function ii_spike_log( $line = null ) {
	static $log = array();
	if ( null !== $line ) {
		$log[] = $line;
	}
	return $log;
}

/**
 * Icons of the spike pack, loaded once per request and only when needed.
 *
 * @return array
 */
function ii_spike_icons() {
	static $icons = null;
	if ( null === $icons ) {
		$icons = json_decode( file_get_contents( __DIR__ . '/icons.json' ), true );
		ii_spike_log( sprintf( 'pack loaded at hook %s, %d icons', current_filter(), count( $icons ) ) );
	}
	return $icons;
}

add_action(
	'init',
	static function () {
		// Lazy strategy: only the collection on init, icons on first use.
		wp_register_icon_collection( 'ii-spike', array( 'label' => 'Infinite Icons spike' ) );
	}
);

add_filter(
	'render_block_data',
	static function ( $block ) {
		$name = $block['attrs']['icon'] ?? '';
		if ( 'core/icon' !== ( $block['blockName'] ?? '' ) || ! str_starts_with( $name, 'ii-spike/' ) ) {
			return $block;
		}
		if ( WP_Icons_Registry::get_instance()->is_registered( $name ) ) {
			return $block;
		}
		$short = substr( $name, strlen( 'ii-spike/' ) );
		$icons = ii_spike_icons();
		if ( isset( $icons[ $short ] ) ) {
			$ok = wp_register_icon(
				$name,
				array(
					'label'   => $short,
					'content' => $icons[ $short ],
				)
			);
			ii_spike_log( sprintf( '%s registered at %s (init done: %s, wp_head done: %s): %s', $name, current_filter(), did_action( 'init' ) ? 'yes' : 'no', did_action( 'wp_head' ) ? 'yes' : 'no', $ok ? 'ok' : 'failed' ) );
		}
		return $block;
	}
);

foreach ( array( 'doing_it_wrong_run', 'wp_trigger_error_run' ) as $ii_hook ) {
	add_action(
		$ii_hook,
		static function ( $function_name, $message ) use ( $ii_hook ) {
			ii_spike_log( "$ii_hook: $function_name: $message" );
		},
		10,
		2
	);
}

add_action(
	'wp_footer',
	static function () {
		printf( "\n<!-- ii-spike\n%s\n-->\n", esc_html( implode( "\n", ii_spike_log() ) ) );
	},
	PHP_INT_MAX
);
