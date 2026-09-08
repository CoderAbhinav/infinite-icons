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
	public function set_up(): void {
		parent::set_up();
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
		parent::tear_down();
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
		if ( WP_Icon_Collections_Registry::get_instance()->is_registered( $slug ) ) {
			wp_unregister_icon_collection( $slug );
		}
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
		foreach ( WP_Icons_Registry::get_instance()->get_registered_icons() as $icon ) {
			if ( ( $icon['collection'] ?? '' ) === $slug ) {
				++$count;
			}
		}
		return $count;
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
