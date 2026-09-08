<?php
/**
 * Icon registration.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Packs;

use InfiniteIcons\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers enabled packs with the WordPress Icons API on `init`.
 *
 * Icons are registered with `file_path`, so core reads and sanitizes the SVG
 * lazily: registering 1800 icons costs no file I/O until one is rendered.
 *
 * @since 1.0.0
 */
final class Registrar {

	/**
	 * Pack locator.
	 *
	 * @since 1.0.0
	 * @var PackLocator
	 */
	private $locator;

	/**
	 * Settings.
	 *
	 * @since 1.0.0
	 * @var Options
	 */
	private $options;

	/**
	 * Registered icons, keyed by qualified name ("collection/icon-name").
	 *
	 * Used by the REST search and the picker so we never ask core for "all
	 * icons with their content", which would read every SVG from disk.
	 *
	 * @since 1.0.0
	 * @var array<string, array{pack: string, variant: string, label: string, keywords: array<int, string>, file: string}>
	 */
	private $registered = array();

	/**
	 * Slugs of packs that were registered.
	 *
	 * @since 1.0.0
	 * @var array<int, string>
	 */
	private $registered_packs = array();

	/**
	 * Whether registration already ran.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $done = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param PackLocator $locator Pack locator.
	 * @param Options     $options Settings.
	 */
	public function __construct( PackLocator $locator, Options $options ) {
		$this->locator = $locator;
		$this->options = $options;
	}

	/**
	 * Hooks registration onto `init`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_packs' ), 10 );
	}

	/**
	 * Registers every enabled pack and its icons.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_packs(): void {
		if ( $this->done ) {
			return;
		}
		$this->done = true;

		foreach ( $this->locator->all() as $slug => $pack ) {
			if ( ! $this->options->is_pack_enabled( $slug ) ) {
				continue;
			}

			$collection = wp_register_icon_collection(
				$slug,
				array(
					'label'       => $pack->label,
					'description' => $pack->description,
				)
			);

			// Another plugin already owns this collection slug. Renaming ours at
			// runtime would break every icon name already saved in post content,
			// so the pack is skipped instead.
			if ( ! $collection ) {
				$this->debug_log( sprintf( 'Icon collection "%s" is already registered by other code; the pack was skipped.', $slug ) );
				continue;
			}

			$this->registered_packs[] = $slug;
			$variants                 = $this->options->enabled_variants( $pack );

			foreach ( $pack->icons as $icon ) {
				if ( ! in_array( $icon['variant'], $variants, true ) ) {
					continue;
				}

				$path = $pack->icon_path( $icon );
				if ( ! is_readable( $path ) ) {
					$this->debug_log( sprintf( 'Icon file "%s" is missing or unreadable.', $path ) );
					continue;
				}

				$name = $slug . '/' . $icon['name'];

				/**
				 * Filters the arguments passed to wp_register_icon().
				 *
				 * @since 1.0.0
				 *
				 * @param array<string, string> $args Registration arguments: label, file_path.
				 * @param string                $name Qualified icon name, "collection/icon-name".
				 * @param Pack                  $pack The pack the icon belongs to.
				 */
				$args = apply_filters(
					'infinite_icons_register_icon_args',
					array(
						'label'     => $icon['label'],
						'file_path' => $path,
					),
					$name,
					$pack
				);

				if ( ! wp_register_icon( $name, $args ) ) {
					$this->debug_log( sprintf( 'Icon "%s" could not be registered.', $name ) );
					continue;
				}

				$this->registered[ $name ] = array(
					'pack'     => $slug,
					'variant'  => $icon['variant'],
					'label'    => $icon['label'],
					'keywords' => $icon['keywords'],
					'file'     => $path,
				);
			}
		}

		/**
		 * Fires after every enabled pack has been registered.
		 *
		 * @since 1.0.0
		 *
		 * @param Registrar $registrar The registrar instance.
		 */
		do_action( 'infinite_icons_registered', $this );
	}

	/**
	 * Retrieves every icon this plugin registered.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{pack: string, variant: string, label: string, keywords: array<int, string>, file: string}>
	 */
	public function registered_icons(): array {
		return $this->registered;
	}

	/**
	 * Retrieves the slugs of the packs that were registered.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	public function registered_packs(): array {
		return $this->registered_packs;
	}

	/**
	 * Checks whether this plugin registered the given icon.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Qualified icon name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->registered[ $name ] );
	}

	/**
	 * Logs a message when debugging is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Infinite Icons] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only under WP_DEBUG, as documented.
		}
	}
}
