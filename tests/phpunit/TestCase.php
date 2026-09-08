<?php
/**
 * Shared test case.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\ManifestCache;
use InfiniteIcons\Packs\PackLocator;
use InfiniteIcons\Settings\Options;
use WP_Icon_Collections_Registry;
use WP_Icons_Registry;
use WP_UnitTestCase;

/**
 * Base class with helpers for building throwaway packs on disk.
 *
 * @since 1.0.0
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Temporary directories created by a test, removed on teardown.
	 *
	 * @since 1.0.0
	 * @var array<int, string>
	 */
	private $temp_dirs = array();

	/**
	 * Resets the plugin's option and the manifest cache before each test.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	/**
	 * Icon collections registered before any test ran.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $pristine_collections = null;

	/**
	 * Icons registered before any test ran.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $pristine_icons = null;

	public function set_up(): void {
		parent::set_up();
		self::reset_icon_registries();
		delete_option( Options::OPTION );
		( new ManifestCache() )->flush();
		wp_dequeue_style( \InfiniteIcons\Render\Icon::STYLE_HANDLE );
		wp_deregister_style( \InfiniteIcons\Render\Icon::STYLE_HANDLE );
	}

	/**
	 * Removes temporary directories and any icons the test registered.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->temp_dirs as $dir ) {
			self::rmdir_recursive( $dir );
		}
		$this->temp_dirs = array();
		( new ManifestCache() )->flush();
		self::reset_icon_registries();
		parent::tear_down();
	}

	/**
	 * Restores the icon registries to the state WordPress booted with.
	 *
	 * Both registries are singletons that outlive a test, so without this a test that fails
	 * part way through leaves its collection behind and every later test using the same slug
	 * fails with "Icon collection is already registered" -- one broken test becoming twenty.
	 *
	 * The registries' own unregister() methods are deliberately not used: unregistering a
	 * collection makes core walk every icon and read its file, which errors on the missing
	 * files some tests create on purpose. Restoring a snapshot avoids all file access.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected static function reset_icon_registries(): void {
		$collections = \WP_Icon_Collections_Registry::get_instance();
		$icons       = \WP_Icons_Registry::get_instance();

		$collections_property = new \ReflectionProperty( $collections, 'registered_collections' );
		$icons_property       = new \ReflectionProperty( $icons, 'registered_icons' );

		// No-ops since PHP 8.1 and deprecated in 8.5, but still required on PHP 7.4.
		if ( PHP_VERSION_ID < 80100 ) {
			$collections_property->setAccessible( true );
			$icons_property->setAccessible( true );
		}

		if ( null === self::$pristine_collections ) {
			self::$pristine_collections = $collections_property->getValue( $collections );
			self::$pristine_icons       = $icons_property->getValue( $icons );
			return;
		}

		$collections_property->setValue( $collections, self::$pristine_collections );
		$icons_property->setValue( $icons, self::$pristine_icons );
	}

	/**
	 * Creates a temporary directory that is removed after the test.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Directory name prefix.
	 * @return string Absolute path.
	 */
	protected function make_temp_dir( string $prefix = 'ii-test-' ): string {
		$dir = rtrim( sys_get_temp_dir(), '/' ) . '/' . $prefix . wp_generate_password( 8, false );
		mkdir( $dir, 0777, true );
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Writes a pack to disk.
	 *
	 * @since 1.0.0
	 *
	 * @param string                           $parent   Directory to create the pack in.
	 * @param string                           $slug     Pack slug.
	 * @param array<int, array<string, mixed>> $icons Icon entries; each needs at least a name.
	 * @param array<string, mixed>             $overrides Manifest keys to override.
	 * @return string Absolute path to the pack directory.
	 */
	protected function make_pack( string $parent, string $slug, array $icons, array $overrides = array() ): string {
		$dir = $parent . '/' . $slug;
		if ( ! is_dir( $dir . '/icons' ) ) {
			mkdir( $dir . '/icons', 0777, true );
		}

		$entries = array();
		foreach ( $icons as $icon ) {
			$name      = $icon['name'];
			$entries[] = array(
				'name'     => $name,
				'label'    => $icon['label'] ?? ucfirst( $name ),
				'variant'  => $icon['variant'] ?? '',
				'keywords' => $icon['keywords'] ?? array(),
				'file'     => $icon['file'] ?? "icons/{$name}.svg",
			);
			if ( ! array_key_exists( 'write_file', $icon ) || $icon['write_file'] ) {
				file_put_contents( $dir . "/icons/{$name}.svg", $icon['svg'] ?? self::svg() );
			}
		}

		$manifest = array_merge(
			array(
				'schema'          => 1,
				'slug'            => $slug,
				'label'           => ucfirst( $slug ),
				'description'     => "The {$slug} pack.",
				'version'         => '1.0.0+ii.1',
				'upstream'        => array(
					'name'    => $slug,
					'version' => '1.0.0',
					'url'     => 'https://example.org',
				),
				'license'         => array(
					'spdx'        => 'MIT',
					'attribution' => '© Example',
				),
				'requires_plugin' => '>=1.0.0',
				'variants'        => array(
					array(
						'key'     => '',
						'label'   => 'Regular',
						'default' => true,
					),
				),
				'icons'           => $entries,
			),
			$overrides
		);

		if ( null !== $manifest ) {
			file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) );
		}
		return $dir;
	}

	/**
	 * Builds a canonical icon SVG.
	 *
	 * @since 1.0.0
	 *
	 * @param string $d Path data.
	 * @return string
	 */
	protected static function svg( string $d = 'M2 2h20v20H2z' ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="' . $d . '"/></svg>';
	}

	/**
	 * Builds a locator that looks in the given directories.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $bundled Directory to treat as the bundled pack directory.
	 * @param string|null $uploads Directory to treat as the uploads pack directory.
	 * @return PackLocator
	 */
	protected function locator_for( string $bundled, ?string $uploads = null ): PackLocator {
		return new PackLocator( new ManifestCache(), $bundled, $uploads ?? $this->make_temp_dir( 'ii-uploads-' ) );
	}

	/**
	 * Creates the directory downloaded packs live in, below an uploads base.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uploads_base Directory standing in for the uploads directory.
	 * @return string Absolute path to the packs directory.
	 */
	protected function downloaded_packs_dir( string $uploads_base ): string {
		$dir = $uploads_base . '/packs';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		return $dir;
	}

	/**
	 * Unregisters an icon collection if it is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Collection slug.
	 * @return void
	 */
	protected function unregister_collection( string $slug ): void {
		$collections = WP_Icon_Collections_Registry::get_instance();

		if ( ! $collections->is_registered( $slug ) ) {
			return;
		}

		// Core's unregister() walks the collection's icons and reads every file, which errors
		// on packs whose files are deliberately absent. Drop the entries directly instead.
		$icons             = WP_Icons_Registry::get_instance();
		$icons_property    = new \ReflectionProperty( $icons, 'registered_icons' );
		$collection_holder = new \ReflectionProperty( $collections, 'registered_collections' );

		// No-ops since PHP 8.1 and deprecated in 8.5, but still required on PHP 7.4.
		if ( PHP_VERSION_ID < 80100 ) {
			$icons_property->setAccessible( true );
			$collection_holder->setAccessible( true );
		}

		$remaining = array_filter(
			$icons_property->getValue( $icons ),
			static function ( $icon ) use ( $slug ) {
				return ( $icon['collection'] ?? '' ) !== $slug;
			}
		);
		$icons_property->setValue( $icons, $remaining );

		$collections_value = $collection_holder->getValue( $collections );
		unset( $collections_value[ $slug ] );
		$collection_holder->setValue( $collections, $collections_value );
	}

	/**
	 * Counts registered icons in a collection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Collection slug.
	 * @return int
	 */
	protected function count_icons( string $slug ): int {
		$count = 0;

		// Read the registry's own array rather than calling get_registered_icons(), which
		// loads every icon's file and so cannot be used on packs with a deliberately
		// missing file.
		foreach ( self::registered_icons() as $icon ) {
			if ( ( $icon['collection'] ?? '' ) === $slug ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Reads the icons registry without triggering any file reads.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function registered_icons(): array {
		$registry = WP_Icons_Registry::get_instance();
		$property = new \ReflectionProperty( $registry, 'registered_icons' );

		// No-op since PHP 8.1 and deprecated in 8.5, but still required on PHP 7.4.
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return $property->getValue( $registry );
	}

	/**
	 * Removes a directory and everything in it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	protected static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
