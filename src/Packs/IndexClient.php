<?php
/**
 * Remote pack index.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Packs;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and caches the list of downloadable packs.
 *
 * The index is a static JSON file published by the infinite-icons-packs
 * repository and served from a CDN, so listing packs costs no GitHub API quota.
 * It is only ever fetched because the user asked for it: opening the Packs
 * screen, or pressing "Check for updates".
 *
 * @since 1.0.0
 */
final class IndexClient {

	/**
	 * Where the index is published.
	 *
	 * GitHub serves the newest release's assets from a stable "latest" path, so
	 * this needs no version in it and no API call. Using the same host the packs
	 * themselves come from means there is a single service to disclose to the
	 * user and a single entry in the download allowlist.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DEFAULT_URL = 'https://github.com/CoderAbhinav/infinite-icons-packs/releases/latest/download/index.json';

	/**
	 * Transient holding the decoded index.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRANSIENT = 'infinite_icons_index';

	/**
	 * How long a fetched index is reused.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Request timeout in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Largest index we will parse, as a guard against a hostile response.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Per-request memoization.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>|null
	 */
	private $index = null;

	/**
	 * Retrieves the index URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function url(): string {
		/**
		 * Filters the URL the pack index is fetched from.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url Absolute https URL.
		 */
		$url = (string) apply_filters( 'infinite_icons_index_url', self::DEFAULT_URL );
		return $url;
	}

	/**
	 * Retrieves the index, from cache unless a refresh is asked for.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $refresh Whether to bypass the cache and fetch again.
	 * @return array<string, mixed>|\WP_Error Decoded index, or an error.
	 */
	public function get( bool $refresh = false ) {
		if ( ! $refresh ) {
			if ( null !== $this->index ) {
				return $this->index;
			}
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				$this->index = $cached;
				return $cached;
			}
		}

		$index = $this->fetch();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$this->index = $index;
		set_transient( self::TRANSIENT, $index, self::TTL );
		return $index;
	}

	/**
	 * Retrieves the entry for one pack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug    Pack slug.
	 * @param bool   $refresh Whether to bypass the cache.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function pack( string $slug, bool $refresh = false ) {
		$index = $this->get( $refresh );
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		foreach ( $index['packs'] as $pack ) {
			if ( $pack['slug'] === $slug ) {
				return $pack;
			}
		}
		return new \WP_Error(
			'infinite_icons_unknown_pack',
			/* translators: %s: Pack slug. */
			sprintf( __( 'The pack "%s" is not in the list of available packs.', 'infinite-icons' ), $slug ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Retrieves the time the cached index was stored, if any.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null Unix timestamp, or null when nothing is cached.
	 */
	public function cached_at(): ?int {
		$timeout = get_option( '_transient_timeout_' . self::TRANSIENT );
		if ( ! is_numeric( $timeout ) ) {
			return null;
		}
		return (int) $timeout - self::TTL;
	}

	/**
	 * Discards the cached index.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->index = null;
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Performs the HTTP request and validates the payload.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function fetch() {
		$url = $this->url();
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new \WP_Error(
				'infinite_icons_insecure_index',
				__( 'The pack index must be served over HTTPS.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		$args = array(
			'timeout'    => self::TIMEOUT,
			'sslverify'  => true,
			'user-agent' => 'InfiniteIcons/' . INFINITE_ICONS_VERSION . '; ' . home_url( '/' ),
			'headers'    => array( 'Accept' => 'application/json' ),
		);

		// WordPress VIP provides a hardened fetcher that fails fast and caches
		// failures; use it where it exists and plain wp_remote_get elsewhere.
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $url, '', 3, self::TIMEOUT, 20, $args );
		} else {
			$response = wp_remote_get( $url, $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get is used when available.
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'infinite_icons_index_unreachable',
				sprintf(
					/* translators: %s: Underlying error message. */
					__( 'The list of packs could not be downloaded: %s', 'infinite-icons' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'infinite_icons_index_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The list of packs could not be downloaded (HTTP %d).', 'infinite-icons' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'infinite_icons_index_too_large',
				__( 'The list of packs is unexpectedly large and was rejected.', 'infinite-icons' ),
				array( 'status' => 502 )
			);
		}

		return $this->parse( $body );
	}

	/**
	 * Validates and normalizes an index document.
	 *
	 * Everything here arrives over the network, so each field is checked and
	 * copied rather than passed through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body Raw JSON.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( string $body ) {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || 1 !== ( $data['schema'] ?? null ) ) {
			return new \WP_Error(
				'infinite_icons_index_invalid',
				__( 'The list of packs is not in a format this version understands.', 'infinite-icons' ),
				array( 'status' => 502 )
			);
		}

		$packs = array();
		foreach ( (array) ( $data['packs'] ?? array() ) as $raw ) {
			$pack = $this->parse_pack( $raw );
			if ( null !== $pack ) {
				$packs[] = $pack;
			}
		}

		return array(
			'schema'    => 1,
			'generated' => (string) ( $data['generated'] ?? '' ),
			'packs'     => $packs,
		);
	}

	/**
	 * Validates one pack entry.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Entry from the index.
	 * @return array<string, mixed>|null Null when the entry is unusable.
	 */
	private function parse_pack( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$slug = is_string( $raw['slug'] ?? null ) ? $raw['slug'] : '';
		$url  = is_string( $raw['url'] ?? null ) ? $raw['url'] : '';
		$sha  = is_string( $raw['sha256'] ?? null ) ? strtolower( $raw['sha256'] ) : '';

		if ( ! Pack::is_valid_name( $slug ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			return null;
		}
		if ( ! self::is_allowed_download_url( $url ) ) {
			return null;
		}

		$variants = array();
		foreach ( (array) ( $raw['variants'] ?? array() ) as $variant ) {
			$variant = (string) $variant;
			if ( '' === $variant || Pack::is_valid_name( $variant ) ) {
				$variants[] = $variant;
			}
		}

		$preview = array();
		foreach ( (array) ( $raw['preview'] ?? array() ) as $name ) {
			if ( is_string( $name ) && Pack::is_valid_name( $name ) ) {
				$preview[] = $name;
			}
		}

		return array(
			'slug'            => $slug,
			'label'           => (string) ( $raw['label'] ?? $slug ),
			'description'     => (string) ( $raw['description'] ?? '' ),
			'version'         => (string) ( $raw['version'] ?? '' ),
			'icon_count'      => (int) ( $raw['icon_count'] ?? 0 ),
			'variants'        => $variants,
			'license'         => (string) ( $raw['license'] ?? '' ),
			'requires_plugin' => (string) ( $raw['requires_plugin'] ?? '' ),
			'size_bytes'      => (int) ( $raw['size_bytes'] ?? 0 ),
			'sha256'          => $sha,
			'url'             => $url,
			'preview'         => $preview,
		);
	}

	/**
	 * Retrieves the hosts packs may be downloaded from.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	public static function allowed_download_hosts(): array {
		/**
		 * Filters the hosts a pack may be downloaded from.
		 *
		 * Anything not on this list is refused before a request is made, so a
		 * tampered index cannot point the site at an arbitrary server.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $hosts Lower-case host names.
		 */
		$hosts = apply_filters(
			'infinite_icons_allowed_download_hosts',
			array( 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com' )
		);

		$clean = array();
		foreach ( (array) $hosts as $host ) {
			if ( is_string( $host ) && '' !== $host ) {
				$clean[] = strtolower( $host );
			}
		}
		return $clean;
	}

	/**
	 * Checks a download URL against the scheme and host rules.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_allowed_download_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['host'] ), self::allowed_download_hosts(), true );
	}
}
