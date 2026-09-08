<?php
/**
 * Pack installation and removal.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Packs;

use InfiniteIcons\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads, verifies and installs packs.
 *
 * Every step here assumes the archive is hostile until proved otherwise: the
 * URL comes from the cached index rather than the browser, the host must be on
 * an allowlist, the bytes must match the checksum recorded in the index, and
 * the extracted tree is walked and checked before anything is moved into place.
 *
 * @since 1.0.0
 */
final class Downloader {

	/**
	 * Largest icon file accepted, in bytes.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_ICON_BYTES = 256 * KB_IN_BYTES;

	/**
	 * Largest manifest accepted, in bytes.
	 *
	 * Tabler's manifest is around 1.8 MB, so this cannot share the icon limit.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_MANIFEST_BYTES = 16 * MB_IN_BYTES;

	/**
	 * Largest Elementor stylesheet accepted, in bytes.
	 *
	 * One mask-image rule per icon adds up: Tabler's is around 8.6 MB.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_ELEMENTOR_BYTES = 48 * MB_IN_BYTES;

	/**
	 * Largest extracted pack accepted, in bytes.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_TOTAL_BYTES = 192 * MB_IN_BYTES;

	/**
	 * Largest number of files accepted in a pack.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_FILES = 60000;

	/**
	 * How many extracted icons are checked against the sanitizer allowlist.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const SPOT_CHECK_ICONS = 20;

	/**
	 * Pack locator.
	 *
	 * @since 1.0.0
	 * @var PackLocator
	 */
	private $locator;

	/**
	 * Remote index.
	 *
	 * @since 1.0.0
	 * @var IndexClient
	 */
	private $index;

	/**
	 * Settings.
	 *
	 * @since 1.0.0
	 * @var Options
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param PackLocator      $locator Pack locator.
	 * @param IndexClient|null $index   Index client.
	 * @param Options|null     $options Settings.
	 */
	public function __construct( PackLocator $locator, ?IndexClient $index = null, ?Options $options = null ) {
		$this->locator = $locator;
		$this->index   = $index ?? new IndexClient();
		$this->options = $options ?? new Options();
	}

	/**
	 * Checks whether packs can be installed at runtime on this host.
	 *
	 * Some managed platforms -- WordPress VIP most notably -- serve the uploads
	 * directory from an object store rather than a local disk, and expect code
	 * and assets to arrive through a deploy. Unpacking thousands of files there
	 * at runtime is slow at best and blocked at worst, so downloads are turned
	 * off and packs are expected to be committed into the plugin's packs/
	 * directory instead. Everything else in the plugin works unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function downloads_supported(): bool {
		$supported = ! ( defined( 'VIP_GO_APP_ENVIRONMENT' ) || defined( 'WPCOM_IS_VIP_ENV' ) );

		/**
		 * Filters whether packs may be downloaded and unpacked at runtime.
		 *
		 * Set this to false on hosts with a read-only or remote filesystem, and
		 * ship packs inside the plugin's packs/ directory instead.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $supported Whether runtime installation is possible.
		 */
		return (bool) apply_filters( 'infinite_icons_downloads_supported', $supported );
	}

	/**
	 * Installs a pack by slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Pack slug, as listed in the index.
	 * @return Pack|\WP_Error The installed pack, or an error.
	 */
	public function install( string $slug ) {
		if ( ! self::downloads_supported() ) {
			return new \WP_Error(
				'infinite_icons_downloads_unsupported',
				__( 'This site does not allow icon packs to be downloaded. Add the pack to the plugin\'s packs directory in your deploy instead.', 'infinite-icons' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Pack::is_valid_name( $slug ) ) {
			return new \WP_Error(
				'infinite_icons_invalid_slug',
				__( 'That pack name is not valid.', 'infinite-icons' ),
				array( 'status' => 400 )
			);
		}

		// The entry, and with it the URL and checksum, comes from the index we
		// fetched ourselves. Nothing the browser sent is trusted here.
		$entry = $this->index->pack( $slug );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$supported = $this->check_plugin_requirement( $entry );
		if ( is_wp_error( $supported ) ) {
			return $supported;
		}

		if ( ! IndexClient::is_allowed_download_url( $entry['url'] ) ) {
			return new \WP_Error(
				'infinite_icons_host_not_allowed',
				sprintf(
					/* translators: %s: Host name. */
					__( 'Packs cannot be downloaded from %s.', 'infinite-icons' ),
					(string) wp_parse_url( $entry['url'], PHP_URL_HOST )
				),
				array( 'status' => 400 )
			);
		}

		$archive = $this->download( $entry );
		if ( is_wp_error( $archive ) ) {
			return $archive;
		}

		$staged = $this->extract_and_validate( $archive, $entry );
		wp_delete_file( $archive );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		$installed = $this->swap_into_place( $slug, $staged );
		if ( is_wp_error( $installed ) ) {
			$this->rmdir( dirname( $staged ) );
			return $installed;
		}

		$this->locator->cache()->flush();
		$this->locator->invalidate();

		$pack = $this->locator->get( $slug );
		if ( ! $pack instanceof Pack ) {
			return new \WP_Error(
				'infinite_icons_install_failed',
				__( 'The pack was installed but could not be read back.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		$this->options->enable_new_pack( $pack );

		/**
		 * Fires after a pack has been installed.
		 *
		 * @since 1.0.0
		 *
		 * @param Pack $pack The installed pack.
		 */
		do_action( 'infinite_icons_pack_installed', $pack );

		return $pack;
	}

	/**
	 * Removes a downloaded pack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Pack slug.
	 * @return true|\WP_Error
	 */
	public function remove( string $slug ) {
		$pack = $this->locator->get( $slug );
		if ( ! $pack instanceof Pack ) {
			return new \WP_Error(
				'infinite_icons_not_installed',
				__( 'That pack is not installed.', 'infinite-icons' ),
				array( 'status' => 404 )
			);
		}
		if ( $pack->bundled ) {
			return new \WP_Error(
				'infinite_icons_pack_bundled',
				sprintf(
					/* translators: %s: Pack label. */
					__( '%s ships with the plugin and cannot be removed. You can turn it off instead.', 'infinite-icons' ),
					$pack->label
				),
				array( 'status' => 400 )
			);
		}

		$root = $this->locator->uploads_dir() . '/packs';
		if ( ! $this->is_inside( $pack->dir, $root ) ) {
			return new \WP_Error(
				'infinite_icons_outside_uploads',
				__( 'That pack is not inside the uploads directory and was not removed.', 'infinite-icons' ),
				array( 'status' => 400 )
			);
		}

		$this->rmdir( $pack->dir );

		$this->locator->cache()->flush();
		$this->locator->invalidate();
		$this->options->forget_pack( $slug );

		/**
		 * Fires after a pack has been removed.
		 *
		 * @since 1.0.0
		 *
		 * @param string $slug Slug of the removed pack.
		 */
		do_action( 'infinite_icons_pack_removed', $slug );

		return true;
	}

	/**
	 * Checks the pack's minimum plugin version.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $entry Index entry.
	 * @return true|\WP_Error
	 */
	private function check_plugin_requirement( array $entry ) {
		$requires = (string) ( $entry['requires_plugin'] ?? '' );
		if ( 1 !== preg_match( '/^>=\s*([0-9][0-9.]*)$/', $requires, $matches ) ) {
			return true;
		}
		if ( version_compare( INFINITE_ICONS_VERSION, $matches[1], '>=' ) ) {
			return true;
		}
		return new \WP_Error(
			'infinite_icons_plugin_too_old',
			sprintf(
				/* translators: 1: Pack label, 2: Required plugin version. */
				__( '%1$s needs Infinite Icons %2$s or newer. Update the plugin first.', 'infinite-icons' ),
				(string) $entry['label'],
				$matches[1]
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Downloads the archive and verifies its checksum.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $entry Index entry.
	 * @return string|\WP_Error Path to the downloaded file.
	 */
	private function download( array $entry ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $entry['url'], 5 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $file ) ) {
			return new \WP_Error(
				'infinite_icons_download_failed',
				sprintf(
					/* translators: 1: Pack label, 2: Underlying error message. */
					__( '%1$s could not be downloaded: %2$s', 'infinite-icons' ),
					(string) $entry['label'],
					$file->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$actual = hash_file( 'sha256', $file );
		if ( ! hash_equals( (string) $entry['sha256'], (string) $actual ) ) {
			wp_delete_file( $file );
			return new \WP_Error(
				'infinite_icons_checksum_mismatch',
				sprintf(
					/* translators: %s: Pack label. */
					__( 'The download of %s did not match its expected checksum and was discarded.', 'infinite-icons' ),
					(string) $entry['label']
				),
				array( 'status' => 502 )
			);
		}

		return $file;
	}

	/**
	 * Unzips into a staging directory and validates every extracted file.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $archive Path to the downloaded zip.
	 * @param array<string, mixed> $entry   Index entry.
	 * @return string|\WP_Error Path to the validated pack directory inside the staging area.
	 */
	private function extract_and_validate( string $archive, array $entry ) {
		$slug = (string) $entry['slug'];

		$base = $this->prepare_uploads_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$staging = $base . '/tmp/' . $slug . '-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $staging ) ) {
			return new \WP_Error(
				'infinite_icons_tmp_failed',
				__( 'A temporary directory for the download could not be created.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$unzipped = unzip_file( $archive, $staging );
		if ( is_wp_error( $unzipped ) ) {
			$this->rmdir( $staging );
			return new \WP_Error(
				'infinite_icons_unzip_failed',
				sprintf(
					/* translators: %s: Underlying error message. */
					__( 'The download could not be unpacked: %s', 'infinite-icons' ),
					$unzipped->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$root = $staging . '/' . $slug;
		if ( ! is_dir( $root ) ) {
			$this->rmdir( $staging );
			return new \WP_Error(
				'infinite_icons_bad_archive',
				sprintf(
					/* translators: %s: Expected directory name. */
					__( 'The download does not contain a "%s" directory.', 'infinite-icons' ),
					$slug
				),
				array( 'status' => 502 )
			);
		}

		$valid = $this->validate_tree( $root, $slug );
		if ( is_wp_error( $valid ) ) {
			$this->rmdir( $staging );
			return $valid;
		}

		return $root;
	}

	/**
	 * Walks an extracted pack and rejects anything unexpected.
	 *
	 * @since 1.0.0
	 *
	 * @param string $root Pack directory inside the staging area.
	 * @param string $slug Expected pack slug.
	 * @return true|\WP_Error
	 */
	private function validate_tree( string $root, string $slug ) {
		$real_root = realpath( $root );
		if ( false === $real_root ) {
			return new \WP_Error( 'infinite_icons_bad_archive', __( 'The unpacked pack could not be read.', 'infinite-icons' ), array( 'status' => 500 ) );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real_root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$total = 0;
		$count = 0;
		$icons = array();

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			$path = $item->getPathname();

			// A symlink inside the archive could point anywhere on the filesystem.
			if ( $item->isLink() ) {
				return $this->reject( __( 'The pack contains a symbolic link.', 'infinite-icons' ) );
			}

			$real = realpath( $path );
			if ( false === $real || ! $this->is_inside( $real, $real_root ) ) {
				return $this->reject( __( 'The pack contains a path that points outside it.', 'infinite-icons' ) );
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $real, strlen( $real_root ) ) ), '/' );

			if ( $item->isDir() ) {
				if ( ! in_array( $relative, array( 'icons', 'elementor' ), true ) ) {
					return $this->reject(
						sprintf(
							/* translators: %s: Directory name inside the pack. */
							__( 'The pack contains an unexpected directory: %s', 'infinite-icons' ),
							$relative
						)
					);
				}
				continue;
			}

			++$count;
			if ( $count > self::MAX_FILES ) {
				return $this->reject( __( 'The pack contains more files than expected.', 'infinite-icons' ) );
			}

			$size   = (int) $item->getSize();
			$total += $size;
			if ( $total > self::MAX_TOTAL_BYTES ) {
				return $this->reject( __( 'The pack is larger than expected.', 'infinite-icons' ) );
			}

			$limit = $this->size_limit_for( $relative );
			if ( null === $limit ) {
				return $this->reject(
					sprintf(
						/* translators: %s: File name inside the pack. */
						__( 'The pack contains a file that does not belong in it: %s', 'infinite-icons' ),
						$relative
					)
				);
			}
			if ( $size > $limit ) {
				return $this->reject(
					sprintf(
						/* translators: %s: File name inside the pack. */
						__( 'The pack contains an oversized file: %s', 'infinite-icons' ),
						$relative
					)
				);
			}

			if ( 0 === strpos( $relative, 'icons/' ) ) {
				$icons[] = $real;
			}
		}

		if ( ! $icons ) {
			return $this->reject( __( 'The pack contains no icons.', 'infinite-icons' ) );
		}

		$manifest = $this->validate_manifest( $real_root, $slug );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		return $this->spot_check_icons( $icons );
	}

	/**
	 * Retrieves the size limit for a path inside a pack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Path relative to the pack root, using forward slashes.
	 * @return int|null Limit in bytes, or null when the file is not allowed at all.
	 */
	private function size_limit_for( string $relative ): ?int {
		if ( 'manifest.json' === $relative ) {
			return self::MAX_MANIFEST_BYTES;
		}
		if ( 'LICENSE' === $relative || 'ATTRIBUTION.md' === $relative ) {
			return self::MAX_ICON_BYTES;
		}
		if ( 1 === preg_match( '#^icons/[a-z0-9]([a-z0-9_-]*[a-z0-9])?\.svg$#', $relative ) ) {
			return self::MAX_ICON_BYTES;
		}
		// Generated by the pipeline for Elementor's icon library; one CSS rule
		// per icon, so it is far larger than any other file in the pack.
		if ( 'elementor/elementor.css' === $relative ) {
			return self::MAX_ELEMENTOR_BYTES;
		}
		if ( 'elementor/icons.json' === $relative ) {
			return self::MAX_MANIFEST_BYTES;
		}
		return null;
	}

	/**
	 * Checks the extracted manifest describes the pack we asked for.
	 *
	 * @since 1.0.0
	 *
	 * @param string $root Pack directory.
	 * @param string $slug Expected slug.
	 * @return true|\WP_Error
	 */
	private function validate_manifest( string $root, string $slug ) {
		$file = $root . '/manifest.json';
		if ( ! is_readable( $file ) ) {
			return $this->reject( __( 'The pack has no manifest.', 'infinite-icons' ) );
		}

		$decoded = json_decode( (string) ManifestCache::read_file( $file ), true );
		$pack    = Pack::from_manifest( $decoded, $root, false );
		if ( ! $pack instanceof Pack ) {
			return $this->reject( __( 'The pack manifest is not valid.', 'infinite-icons' ) );
		}
		if ( $pack->slug !== $slug ) {
			return $this->reject( __( 'The pack manifest describes a different pack.', 'infinite-icons' ) );
		}

		foreach ( $pack->icons as $icon ) {
			if ( ! is_readable( $pack->icon_path( $icon ) ) ) {
				return $this->reject(
					sprintf(
						/* translators: %s: Icon name. */
						__( 'The pack manifest lists an icon that is missing: %s', 'infinite-icons' ),
						$icon['name']
					)
				);
			}
		}

		return true;
	}

	/**
	 * Checks a sample of icons against the sanitizer allowlist.
	 *
	 * A pack that survives this still cannot inject anything, because core runs
	 * every icon through wp_kses before rendering. The check is here to fail an
	 * obviously wrong archive loudly at install time rather than silently
	 * rendering nothing later.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $icons Absolute paths to extracted icons.
	 * @return true|\WP_Error
	 */
	private function spot_check_icons( array $icons ) {
		shuffle( $icons );
		foreach ( array_slice( $icons, 0, self::SPOT_CHECK_ICONS ) as $file ) {
			$svg = (string) ManifestCache::read_file( $file );
			if ( '' === trim( $svg ) ) {
				return $this->reject( __( 'The pack contains an empty icon.', 'infinite-icons' ) );
			}
			if ( 1 !== preg_match( '/^\s*<svg[\s>]/i', $svg ) ) {
				return $this->reject( __( 'The pack contains a file that is not an SVG.', 'infinite-icons' ) );
			}

			// Checking the sanitized markup is non-empty is not enough: kses
			// keeps the <svg> wrapper and any stray text, so a file whose only
			// content is a <script> survives as a blank <svg>. What matters is
			// that something is still drawable once core has had its way.
			$sanitized = wp_kses( $svg, self::icon_allowed_html() );
			if ( 1 !== preg_match( '/<(path|polygon)[\s>]/i', $sanitized ) ) {
				return $this->reject( __( 'The pack contains an icon WordPress would render as nothing.', 'infinite-icons' ) );
			}
		}
		return true;
	}

	/**
	 * The element and attribute allowlist core applies to icons.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function icon_allowed_html(): array {
		return array(
			'svg'     => array(
				'class'       => true,
				'xmlns'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'aria-hidden' => true,
				'role'        => true,
				'focusable'   => true,
			),
			'path'    => array(
				'fill'      => true,
				'fill-rule' => true,
				'd'         => true,
				'transform' => true,
			),
			'polygon' => array(
				'fill'      => true,
				'fill-rule' => true,
				'points'    => true,
				'transform' => true,
				'focusable' => true,
			),
		);
	}

	/**
	 * Moves a validated pack into place, keeping the old copy until it lands.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug   Pack slug.
	 * @param string $staged Validated pack directory.
	 * @return true|\WP_Error
	 */
	private function swap_into_place( string $slug, string $staged ) {
		$packs = $this->locator->uploads_dir() . '/packs';
		if ( ! wp_mkdir_p( $packs ) ) {
			return new \WP_Error(
				'infinite_icons_packs_dir_failed',
				__( 'The packs directory could not be created.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		$target = $packs . '/' . $slug;
		$backup = $packs . '/.' . $slug . '-old-' . wp_generate_password( 8, false );

		// WP_Filesystem::move() rather than rename(): it is the API managed
		// hosts expect, and it does not need error silencing, which the
		// WordPress VIP standards forbid.
		$filesystem = $this->filesystem();
		if ( ! $filesystem ) {
			return new \WP_Error(
				'infinite_icons_filesystem_unavailable',
				__( 'WordPress could not get access to the filesystem to install the pack.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		if ( is_dir( $target ) && ! $filesystem->move( $target, $backup, true ) ) {
			return new \WP_Error(
				'infinite_icons_replace_failed',
				__( 'The previously installed copy of this pack could not be moved aside.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		if ( ! $filesystem->move( $staged, $target, true ) ) {
			if ( is_dir( $backup ) ) {
				$filesystem->move( $backup, $target, true );
			}
			return new \WP_Error(
				'infinite_icons_move_failed',
				__( 'The pack could not be moved into place.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		if ( is_dir( $backup ) ) {
			$this->rmdir( $backup );
		}
		$this->rmdir( dirname( $staged ) );

		return true;
	}

	/**
	 * Creates the uploads directory and the guards that keep it inert.
	 *
	 * @since 1.0.0
	 *
	 * @return string|\WP_Error Absolute path to uploads/infinite-icons.
	 */
	public function prepare_uploads_dir() {
		$base = $this->locator->uploads_dir();
		if ( ! wp_mkdir_p( $base ) || ! wp_mkdir_p( $base . '/packs' ) || ! wp_mkdir_p( $base . '/tmp' ) ) {
			return new \WP_Error(
				'infinite_icons_uploads_failed',
				__( 'The Infinite Icons directory could not be created inside your uploads folder.', 'infinite-icons' ),
				array( 'status' => 500 )
			);
		}

		// Packs hold SVG and JSON only, but the directory is inside a
		// web-accessible uploads folder, so refuse to serve anything executable.
		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Added by Infinite Icons.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
				. "<FilesMatch \"\\.(svg|json|css|md|txt)$\">\n"
				. "\t<IfModule mod_authz_core.c>\n\t\tRequire all granted\n\t</IfModule>\n"
				. "\t<IfModule !mod_authz_core.c>\n\t\tAllow from all\n\t</IfModule>\n"
				. "</FilesMatch>\n"
				. "<FilesMatch \"\\.(php|phtml|phar|php[0-9]?|cgi|pl|py|sh|htaccess)$\">\n"
				. "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n"
				. "\t<IfModule !mod_authz_core.c>\n\t\tDeny from all\n\t</IfModule>\n"
				. "</FilesMatch>\n"
				. "php_flag engine off\n";
			$this->write_guard( $htaccess, $rules );
		}

		$index = $base . '/index.php';
		if ( ! file_exists( $index ) ) {
			$this->write_guard( $index, "<?php\n// Silence is golden.\n" );
		}

		return $base;
	}

	/**
	 * Writes a small guard file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file     Absolute path.
	 * @param string $contents File contents.
	 * @return void
	 */
	private function write_guard( string $file, string $contents ): void {
		$filesystem = $this->filesystem();
		if ( $filesystem ) {
			$filesystem->put_contents( $file, $contents, FS_CHMOD_FILE );
		}
	}

	/**
	 * Retrieves the WP_Filesystem instance, initialising it if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private function filesystem() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		return $wp_filesystem ? $wp_filesystem : null;
	}

	/**
	 * Builds a rejection error.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Reason the pack was refused.
	 * @return \WP_Error
	 */
	private function reject( string $message ): \WP_Error {
		return new \WP_Error( 'infinite_icons_rejected_archive', $message, array( 'status' => 502 ) );
	}

	/**
	 * Checks a path is inside a directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path to test.
	 * @param string $base Directory it must be inside.
	 * @return bool
	 */
	private function is_inside( string $path, string $base ): bool {
		$path = realpath( $path );
		$base = realpath( $base );
		if ( false === $path || false === $base ) {
			return false;
		}
		return $path === $base || 0 === strpos( $path, rtrim( $base, '/' ) . '/' );
	}

	/**
	 * Recursively deletes a directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private function rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$filesystem = $this->filesystem();
		if ( $filesystem ) {
			$filesystem->delete( $dir, true );
		}
	}
}
