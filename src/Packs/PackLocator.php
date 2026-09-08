<?php
/**
 * Pack discovery.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Packs;

defined( 'ABSPATH' ) || exit;

/**
 * Finds installed packs, in the plugin's own packs/ directory and in
 * uploads/infinite-icons/packs/.
 *
 * @since 1.0.0
 */
final class PackLocator {

	/**
	 * Directory name created inside wp-content/uploads.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const UPLOADS_DIR = 'infinite-icons';

	/**
	 * Manifest cache.
	 *
	 * @since 1.0.0
	 * @var ManifestCache
	 */
	private $cache;

	/**
	 * Discovered packs, keyed by slug. Null until the first scan.
	 *
	 * @since 1.0.0
	 * @var array<string, Pack>|null
	 */
	private $packs = null;

	/**
	 * Directory holding bundled packs, when overridden.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private $bundled_dir;

	/**
	 * Directory holding downloaded packs, when overridden.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private $uploads_dir;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ManifestCache|null $cache       Manifest cache. A new one is created when omitted.
	 * @param string|null        $bundled_dir Directory holding bundled packs. Defaults to the plugin's packs/ directory.
	 * @param string|null        $uploads_dir Directory holding downloaded packs. Defaults to uploads/infinite-icons.
	 */
	public function __construct( ?ManifestCache $cache = null, ?string $bundled_dir = null, ?string $uploads_dir = null ) {
		$this->cache       = $cache ?? new ManifestCache();
		$this->bundled_dir = null === $bundled_dir ? null : untrailingslashit( $bundled_dir );
		$this->uploads_dir = null === $uploads_dir ? null : untrailingslashit( $uploads_dir );
	}

	/**
	 * Retrieves the manifest cache.
	 *
	 * @since 1.0.0
	 *
	 * @return ManifestCache
	 */
	public function cache(): ManifestCache {
		return $this->cache;
	}

	/**
	 * Retrieves the directory holding downloaded packs.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	public function uploads_dir(): string {
		if ( null !== $this->uploads_dir ) {
			return $this->uploads_dir;
		}
		$uploads = wp_get_upload_dir();
		return untrailingslashit( $uploads['basedir'] ) . '/' . self::UPLOADS_DIR;
	}

	/**
	 * Retrieves the directory holding bundled packs.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	public function bundled_dir(): string {
		return $this->bundled_dir ?? untrailingslashit( INFINITE_ICONS_DIR ) . '/packs';
	}

	/**
	 * Retrieves every installed pack, keyed by slug and sorted by label.
	 *
	 * A downloaded pack wins over a bundled pack with the same slug, so an
	 * updated pack can supersede the one shipped in the plugin zip.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $refresh Whether to rescan instead of using the per-request result.
	 * @return array<string, Pack>
	 */
	public function all( bool $refresh = false ): array {
		if ( null !== $this->packs && ! $refresh ) {
			return $this->packs;
		}

		$packs = array();
		foreach ( $this->scan( $this->bundled_dir(), true ) as $pack ) {
			$packs[ $pack->slug ] = $pack;
		}
		foreach ( $this->scan( $this->uploads_dir() . '/packs', false ) as $pack ) {
			$packs[ $pack->slug ] = $pack;
		}

		uasort(
			$packs,
			static function ( Pack $a, Pack $b ): int {
				return strcasecmp( $a->label, $b->label );
			}
		);

		/**
		 * Filters the installed packs before they are registered.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, Pack> $packs   Packs keyed by slug.
		 * @param PackLocator         $locator The locator instance.
		 */
		$filtered = apply_filters( 'infinite_icons_packs', $packs, $this );

		$this->packs = array();
		foreach ( (array) $filtered as $slug => $pack ) {
			if ( $pack instanceof Pack && is_string( $slug ) ) {
				$this->packs[ $slug ] = $pack;
			}
		}
		return $this->packs;
	}

	/**
	 * Retrieves one installed pack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Pack slug.
	 * @return Pack|null
	 */
	public function get( string $slug ): ?Pack {
		$packs = $this->all();
		return $packs[ $slug ] ?? null;
	}

	/**
	 * Forgets the scan result so the next call rescans.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function invalidate(): void {
		$this->packs = null;
	}

	/**
	 * Scans a directory for pack subdirectories.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir     Directory to scan.
	 * @param bool   $bundled Whether packs found here ship with the plugin.
	 * @return array<int, Pack>
	 */
	private function scan( string $dir, bool $bundled ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return array();
		}

		$packs = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry[0] || ! Pack::is_valid_name( $entry ) || ! is_dir( $dir . '/' . $entry ) ) {
				continue;
			}
			$pack = $this->cache->get( $dir . '/' . $entry, $bundled );
			// A directory name that disagrees with the manifest would let a pack
			// masquerade as another collection; skip it.
			if ( $pack instanceof Pack && $pack->slug === $entry ) {
				$packs[] = $pack;
			}
		}
		return $packs;
	}
}
