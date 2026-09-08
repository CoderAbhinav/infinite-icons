<?php
/**
 * Plugin bootstrap.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons;

use InfiniteIcons\Packs\PackLocator;
use InfiniteIcons\Packs\Registrar;
use InfiniteIcons\Render\Icon;
use InfiniteIcons\Render\Shortcode;
use InfiniteIcons\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's services together.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Services, keyed by short name.
	 *
	 * @since 1.0.0
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor; use {@see Plugin::instance()}.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Registers hooks. Safe to call more than once.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$options = new Options();
		$locator = new PackLocator();
		$icon    = new Icon();

		$this->services = array(
			'options'   => $options,
			'locator'   => $locator,
			'icon'      => $icon,
			'registrar' => new Registrar( $locator, $options ),
			'shortcode' => new Shortcode( $icon ),
		);

		$this->services['registrar']->register();
		$this->services['shortcode']->register();

		/**
		 * Fires once every Infinite Icons service is wired up.
		 *
		 * Use this to access services or to register additional packs via the
		 * `infinite_icons_packs` filter.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'infinite_icons_loaded', $this );
	}

	/**
	 * Retrieves a service.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Service name: options, locator, icon, registrar, shortcode.
	 * @return object|null The service, or null when unknown.
	 */
	public function get( string $name ) {
		return $this->services[ $name ] ?? null;
	}
}
