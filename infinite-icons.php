<?php
/**
 * Plugin Name:       Infinite Icons
 * Plugin URI:        https://github.com/CoderAbhinav/infinite-icons
 * Description:       Brings popular open-source icon packs to WordPress as native icons, usable in the Icon block, a shortcode, ACF/SCF, Gravity Forms and Elementor.
 * Version:           1.0.0
 * Requires at least: 7.1
 * Requires PHP:      7.4
 * Author:            Abhinav Belhekar
 * Author URI:        https://github.com/CoderAbhinav
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       infinite-icons
 * Domain Path:       /languages
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

define( 'INFINITE_ICONS_VERSION', VERSION );
define( 'INFINITE_ICONS_FILE', __FILE__ );
define( 'INFINITE_ICONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'INFINITE_ICONS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Prints an admin notice when the environment is too old, and deactivates the plugin.
 *
 * @since 1.0.0
 *
 * @param string $requirement Human-readable unmet requirement.
 * @return void
 */
function infinite_icons_requirements_notice( string $requirement ): void {
	add_action(
		'admin_notices',
		static function () use ( $requirement ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: Unmet requirement, e.g. "WordPress 7.1 or newer". */
						__( 'Infinite Icons requires %s and has been deactivated.', 'infinite-icons' ),
						$requirement
					)
				)
			);
		}
	);
	add_action(
		'admin_init',
		static function () {
			deactivate_plugins( plugin_basename( PLUGIN_FILE ) );
		}
	);
}

// The Icons API landed in WordPress 7.1; there is nothing sensible to do without it.
if ( ! function_exists( 'wp_register_icon_collection' ) ) {
	infinite_icons_requirements_notice( __( 'WordPress 7.1 or newer', 'infinite-icons' ) );
	return;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	infinite_icons_requirements_notice( __( 'PHP 7.4 or newer', 'infinite-icons' ) );
	return;
}

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	/**
	 * Minimal PSR-4 autoloader, so the plugin also runs from a git checkout
	 * without `composer install`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	spl_autoload_register(
		static function ( $class_name ) {
			if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
			$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				// The path is built from a class name in this plugin's own
				// namespace and resolves inside src/, so it is not user input.
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Namespaced autoload path.
				require_once $file;
			}
		}
	);
}

require_once __DIR__ . '/src/functions.php';

Plugin::instance()->boot();
