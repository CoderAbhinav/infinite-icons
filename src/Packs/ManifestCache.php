<?php
/**
 * Manifest decoding and caching.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Packs;

defined( 'ABSPATH' ) || exit;

/**
 * Reads manifest.json files and caches the decoded Pack objects.
 *
 * Manifests are large (Lucide's is ~470 KB), so decoding one on every request
 * is wasteful. The cache key includes the file's size and mtime, so a replaced
 * pack is picked up without an explicit flush.
 *
 * @since 1.0.0
 */
final class ManifestCache {

	/**
	 * Cache group for the object cache.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const GROUP = 'infinite_icons';

	/**
	 * Transient prefix used when no persistent object cache is available.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'infinite_icons_manifest_';

	/**
	 * Cache lifetime in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const TTL = WEEK_IN_SECONDS;

	/**
	 * Per-request memoization, keyed by cache key.
	 *
	 * @since 1.0.0
	 * @var array<string, Pack|null>
	 */
	private $memo = array();

	/**
	 * Reads a local file through WP_Filesystem when it is available.
	 *
	 * Hosts that run the WordPress VIP standards forbid raw file reads because
	 * they are usually remote requests in disguise. These are genuinely local
	 * files, and WP_Filesystem is the API those hosts expect.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute path.
	 * @return string|null Contents, or null when the file could not be read.
	 */
	public static function read_file( string $file ): ?string {
		global $wp_filesystem;

		if ( ! $wp_filesystem && function_exists( 'WP_Filesystem' ) ) {
			WP_Filesystem();
		}

		if ( $wp_filesystem ) {
			$contents = $wp_filesystem->get_contents( $file );
			return false === $contents ? null : (string) $contents;
		}

		// WP_Filesystem is not loaded on every request; fall back to a plain
		// read of a path we have already checked is a readable local file.
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local pack file, not a remote resource.
		return false === $contents ? null : (string) $contents;
	}

	/**
	 * Loads the pack in a directory, from cache when possible.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir     Absolute path to the pack directory.
	 * @param bool   $bundled Whether the pack ships with the plugin.
	 * @return Pack|null The pack, or null when there is no usable manifest.
	 */
	public function get( string $dir, bool $bundled ): ?Pack {
		$dir  = untrailingslashit( $dir );
		$file = $dir . '/manifest.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		// filesize()/filemtime() rather than stat(): is_readable() above has
		// already ruled out the case that would make them warn, so neither needs
		// silencing, which hosts such as WordPress VIP forbid.
		$size  = filesize( $file );
		$mtime = filemtime( $file );
		if ( false === $size || false === $mtime ) {
			return null;
		}
		$key = $this->key( $dir, (int) $size, (int) $mtime );

		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$cached = $this->read_cache( $key );
		if ( $cached instanceof Pack ) {
			$this->memo[ $key ] = $cached;
			return $cached;
		}

		$contents = self::read_file( $file );
		$pack     = null === $contents ? null : Pack::from_manifest( json_decode( $contents, true ), $dir, $bundled );

		if ( $pack instanceof Pack ) {
			$this->write_cache( $key, $pack );
		}
		$this->memo[ $key ] = $pack;
		return $pack;
	}

	/**
	 * Clears every cached manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->memo = array();
		wp_cache_set( 'generation', microtime( true ), self::GROUP );
		if ( ! wp_using_ext_object_cache() ) {
			$this->delete_transients();
		}
	}

	/**
	 * Builds the cache key for a pack directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir   Pack directory.
	 * @param int    $size  Manifest size in bytes.
	 * @param int    $mtime Manifest modification time.
	 * @return string
	 */
	private function key( string $dir, int $size, int $mtime ): string {
		$generation = wp_cache_get( 'generation', self::GROUP );
		return md5( INFINITE_ICONS_VERSION . '|' . $dir . '|' . $size . '|' . $mtime . '|' . (string) $generation );
	}

	/**
	 * Reads a cached pack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Cache key.
	 * @return Pack|null
	 */
	private function read_cache( string $key ): ?Pack {
		$value = wp_cache_get( $key, self::GROUP );
		if ( false === $value && ! wp_using_ext_object_cache() ) {
			$value = get_transient( self::TRANSIENT_PREFIX . $key );
		}
		return $value instanceof Pack ? $value : null;
	}

	/**
	 * Stores a pack in the cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key  Cache key.
	 * @param Pack   $pack Pack to store.
	 * @return void
	 */
	private function write_cache( string $key, Pack $pack ): void {
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- self::TTL is WEEK_IN_SECONDS.
		wp_cache_set( $key, $pack, self::GROUP, self::TTL );
		if ( ! wp_using_ext_object_cache() ) {
			set_transient( self::TRANSIENT_PREFIX . $key, $pack, self::TTL );
		}
	}

	/**
	 * Deletes every manifest transient.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function delete_transients(): void {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients are deleted by prefix; there is no core API for that.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}
}
