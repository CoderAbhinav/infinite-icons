<?php
/**
 * Downloader tests.
 *
 * Every download is faked: pre_http_request short-circuits the index fetch, and
 * download_url is served a local zip built by the test, so nothing leaves the
 * machine and the archive contents can be made hostile on purpose.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Downloader;
use InfiniteIcons\Packs\IndexClient;
use InfiniteIcons\Packs\Pack;
use InfiniteIcons\Packs\PackLocator;
use InfiniteIcons\Settings\Options;

/**
 * Tests pack installation and removal.
 *
 * @covers \InfiniteIcons\Packs\Downloader
 */
final class DownloaderTest extends TestCase {

	/**
	 * Directory standing in for uploads/infinite-icons.
	 *
	 * @var string
	 */
	private $uploads = '';

	/**
	 * Directory standing in for the plugin's bundled packs.
	 *
	 * @var string
	 */
	private $bundled = '';

	public function set_up(): void {
		parent::set_up();
		$this->uploads = $this->make_temp_dir( 'ii-uploads-' );
		$this->bundled = $this->make_temp_dir( 'ii-bundled-' );
	}

	public function tear_down(): void {
		( new IndexClient() )->flush();
		parent::tear_down();
	}

	/**
	 * Builds a downloader wired to the temporary directories.
	 *
	 * @param Options|null $options Settings.
	 * @return array{0: Downloader, 1: PackLocator}
	 */
	private function downloader( ?Options $options = null ): array {
		$locator    = new PackLocator( null, $this->bundled, $this->uploads );
		$downloader = new Downloader( $locator, new IndexClient(), $options ?? new Options() );
		return array( $downloader, $locator );
	}

	/**
	 * Zips a directory tree described as relative path => contents.
	 *
	 * @param array<string, string> $files Files to write into the archive.
	 * @return string Absolute path to the zip.
	 */
	private function make_zip( array $files ): string {
		$dir = $this->make_temp_dir( 'ii-zip-' );
		$zip = $dir . '/pack.zip';

		$archive = new \ZipArchive();
		$archive->open( $zip, \ZipArchive::CREATE );
		foreach ( $files as $path => $contents ) {
			$archive->addFromString( $path, $contents );
		}
		$archive->close();

		return $zip;
	}

	/**
	 * A manifest for a two-icon pack.
	 *
	 * @param string $slug Pack slug.
	 * @return string JSON.
	 */
	private function manifest( string $slug = 'demo' ): string {
		return (string) wp_json_encode(
			array(
				'schema'          => 1,
				'slug'            => $slug,
				'label'           => ucfirst( $slug ),
				'description'     => 'A demo pack.',
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
					array(
						'key'   => 'solid',
						'label' => 'Solid',
					),
				),
				'icons'           => array(
					array(
						'name'     => 'heart',
						'label'    => 'Heart',
						'variant'  => '',
						'keywords' => array( 'love' ),
						'file'     => 'icons/heart.svg',
					),
					array(
						'name'     => 'heart-solid',
						'label'    => 'Heart',
						'variant'  => 'solid',
						'keywords' => array(),
						'file'     => 'icons/heart-solid.svg',
					),
				),
			)
		);
	}

	/**
	 * The files of a well-formed pack archive.
	 *
	 * @param string $slug Pack slug.
	 * @return array<string, string>
	 */
	private function good_files( string $slug = 'demo' ): array {
		return array(
			"{$slug}/manifest.json"           => $this->manifest( $slug ),
			"{$slug}/LICENSE"                 => 'MIT',
			"{$slug}/ATTRIBUTION.md"          => '# Demo',
			"{$slug}/icons/heart.svg"         => self::svg(),
			"{$slug}/icons/heart-solid.svg"   => self::svg(),
			"{$slug}/elementor/elementor.css" => '.ii-demo{}',
			"{$slug}/elementor/icons.json"    => '{"icons":["heart"]}',
		);
	}

	/**
	 * Publishes an index entry for a zip and serves both over faked HTTP.
	 *
	 * @param string               $zip       Path to the archive to serve.
	 * @param array<string, mixed> $overrides Index entry fields to change.
	 * @return void
	 */
	private function serve( string $zip, array $overrides = array() ): void {
		$entry = array_merge(
			array(
				'slug'            => 'demo',
				'label'           => 'Demo',
				'description'     => 'A demo pack.',
				'version'         => '1.0.0+ii.1',
				'icon_count'      => 2,
				'variants'        => array( '', 'solid' ),
				'license'         => 'MIT',
				'requires_plugin' => '>=1.0.0',
				'size_bytes'      => (int) filesize( $zip ),
				'sha256'          => (string) hash_file( 'sha256', $zip ),
				'url'             => 'https://github.com/owner/repo/releases/download/v1/demo.zip',
				'preview'         => array( 'heart' ),
			),
			$overrides
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $entry, $zip ) {
				unset( $preempt );

				// The index request.
				if ( false !== strpos( $url, 'index.json' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'schema'    => 1,
								'generated' => '2026-09-08T00:00:00Z',
								'packs'     => array( $entry ),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				// download_url() streams to a file it created for us.
				if ( ! empty( $args['filename'] ) ) {
					copy( $zip, $args['filename'] );
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => $args['filename'],
					);
				}

				return array(
					'headers'  => array(),
					'body'     => (string) file_get_contents( $zip ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	public function test_installs_a_well_formed_pack(): void {
		$this->serve( $this->make_zip( $this->good_files() ) );
		list( $downloader, $locator ) = $this->downloader();

		$pack = $downloader->install( 'demo' );

		$this->assertInstanceOf( Pack::class, $pack );
		$this->assertSame( 'demo', $pack->slug );
		$this->assertCount( 2, $pack->icons );
		$this->assertFileExists( $this->uploads . '/packs/demo/manifest.json' );
		$this->assertFileExists( $this->uploads . '/packs/demo/icons/heart.svg' );
		// Shipped for Elementor, so it has to survive validation.
		$this->assertFileExists( $this->uploads . '/packs/demo/elementor/elementor.css' );
		$this->assertInstanceOf( Pack::class, $locator->get( 'demo' ) );
	}

	public function test_writes_guards_into_the_uploads_directory(): void {
		$this->serve( $this->make_zip( $this->good_files() ) );
		list( $downloader ) = $this->downloader();

		$downloader->install( 'demo' );

		$this->assertFileExists( $this->uploads . '/index.php' );
		$this->assertFileExists( $this->uploads . '/.htaccess' );
		$this->assertStringContainsString( 'php_flag engine off', (string) file_get_contents( $this->uploads . '/.htaccess' ) );
	}

	public function test_a_new_pack_is_enabled_with_only_its_default_variant(): void {
		$this->serve( $this->make_zip( $this->good_files() ) );
		$options            = new Options();
		list( $downloader ) = $this->downloader( $options );

		$pack = $downloader->install( 'demo' );

		$this->assertTrue( $options->is_pack_enabled( 'demo' ) );
		$this->assertSame( array( '' ), $options->enabled_variants( $pack ) );
	}

	public function test_rejects_a_checksum_mismatch_and_leaves_nothing_behind(): void {
		$this->serve(
			$this->make_zip( $this->good_files() ),
			array( 'sha256' => str_repeat( 'b', 64 ) )
		);
		list( $downloader ) = $this->downloader();

		$result = $downloader->install( 'demo' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_checksum_mismatch', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/packs/demo' );
	}

	public function test_refuses_a_download_host_that_is_not_allowed(): void {
		// The entry is dropped while the index is parsed, so the pack never
		// even appears as available.
		$this->serve(
			$this->make_zip( $this->good_files() ),
			array( 'url' => 'https://evil.example.com/demo.zip' )
		);
		list( $downloader ) = $this->downloader();

		$result = $downloader->install( 'demo' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_unknown_pack', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/packs/demo' );
	}

	public function test_refuses_a_host_that_slips_past_the_index_parser(): void {
		$this->serve( $this->make_zip( $this->good_files() ) );
		// Narrow the allowlist after the index was parsed, so the entry is
		// present but the URL is no longer acceptable.
		add_filter(
			'infinite_icons_allowed_download_hosts',
			static function () {
				return array( 'github.com' );
			}
		);
		( new IndexClient() )->get();
		add_filter(
			'infinite_icons_allowed_download_hosts',
			static function () {
				return array( 'nowhere.example' );
			},
			20
		);

		list( $downloader ) = $this->downloader();
		$result             = $downloader->install( 'demo' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_host_not_allowed', $result->get_error_code() );
	}

	public function test_rejects_an_archive_containing_php(): void {
		$files                  = $this->good_files();
		$files['demo/evil.php'] = '<?php echo 1;';
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$result = $downloader->install( 'demo' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_rejected_archive', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/packs/demo' );
	}

	public function test_rejects_an_archive_with_an_unexpected_directory(): void {
		$files                          = $this->good_files();
		$files['demo/scripts/build.sh'] = 'echo hi';
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$this->assertWPError( $downloader->install( 'demo' ) );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/packs/demo' );
	}

	/**
	 * Core's unzip_file() drops entries containing "..", so a traversal entry
	 * never reaches the validator. What is asserted here is the property that
	 * actually matters: the file does not land anywhere outside the pack.
	 */
	public function test_a_traversal_entry_never_escapes_the_pack(): void {
		$files                                = $this->good_files();
		$files['demo/icons/../../escape.svg'] = self::svg();
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$downloader->install( 'demo' );

		$this->assertFileDoesNotExist( $this->uploads . '/escape.svg' );
		$this->assertFileDoesNotExist( dirname( $this->uploads ) . '/escape.svg' );
		$this->assertFileDoesNotExist( $this->uploads . '/packs/escape.svg' );
		$this->assertFileDoesNotExist( $this->uploads . '/packs/demo/escape.svg' );
	}

	/**
	 * A file that is not an SVG at all, in a place icons are allowed.
	 */
	public function test_rejects_an_icon_that_is_not_an_svg(): void {
		$files                         = $this->good_files();
		$files['demo/icons/heart.svg'] = 'GIF89a not an svg';
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$this->assertWPError( $downloader->install( 'demo' ) );
	}

	public function test_rejects_an_oversized_icon(): void {
		$files                         = $this->good_files();
		$files['demo/icons/heart.svg'] = '<svg>' . str_repeat( 'x', Downloader::MAX_ICON_BYTES + 1 ) . '</svg>';
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$this->assertWPError( $downloader->install( 'demo' ) );
	}

	public function test_rejects_a_manifest_describing_a_different_pack(): void {
		$files                       = $this->good_files();
		$files['demo/manifest.json'] = $this->manifest( 'other' );
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$this->assertWPError( $downloader->install( 'demo' ) );
	}

	public function test_rejects_a_manifest_listing_a_missing_icon(): void {
		$files = $this->good_files();
		unset( $files['demo/icons/heart-solid.svg'] );
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$this->assertWPError( $downloader->install( 'demo' ) );
	}

	public function test_rejects_an_archive_whose_icons_the_sanitizer_would_empty(): void {
		$files = $this->good_files();
		// kses keeps the <svg> wrapper and the script's text, so this is not
		// empty afterwards -- it is simply an icon that draws nothing.
		$files['demo/icons/heart.svg']       = '<svg xmlns="http://www.w3.org/2000/svg"><script>x</script></svg>';
		$files['demo/icons/heart-solid.svg'] = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="1" cy="1" r="1"/></svg>';
		$this->serve( $this->make_zip( $files ) );
		list( $downloader ) = $this->downloader();

		$this->assertWPError( $downloader->install( 'demo' ) );
	}

	public function test_rejects_a_pack_needing_a_newer_plugin(): void {
		$this->serve(
			$this->make_zip( $this->good_files() ),
			array( 'requires_plugin' => '>=99.0.0' )
		);
		list( $downloader ) = $this->downloader();

		$result = $downloader->install( 'demo' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_plugin_too_old', $result->get_error_code() );
	}

	public function test_reinstalling_replaces_the_existing_copy(): void {
		$this->serve( $this->make_zip( $this->good_files() ) );
		list( $downloader ) = $this->downloader();

		$downloader->install( 'demo' );
		file_put_contents( $this->uploads . '/packs/demo/icons/stale.svg', self::svg() );

		$second = $downloader->install( 'demo' );

		$this->assertInstanceOf( Pack::class, $second );
		$this->assertFileDoesNotExist( $this->uploads . '/packs/demo/icons/stale.svg' );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/tmp/demo' );
	}

	public function test_removes_a_downloaded_pack(): void {
		$this->serve( $this->make_zip( $this->good_files() ) );
		$options            = new Options();
		list( $downloader ) = $this->downloader( $options );
		$downloader->install( 'demo' );

		$this->assertTrue( $downloader->remove( 'demo' ) );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/packs/demo' );
		$this->assertArrayNotHasKey( 'demo', (array) $options->get( 'enabled_packs', array() ) );
	}

	public function test_refuses_to_remove_a_bundled_pack(): void {
		$this->make_pack( $this->bundled, 'built-in', array( array( 'name' => 'heart' ) ) );
		list( $downloader ) = $this->downloader();

		$result = $downloader->remove( 'built-in' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_pack_bundled', $result->get_error_code() );
		$this->assertDirectoryExists( $this->bundled . '/built-in' );
	}

	public function test_removing_an_unknown_pack_is_an_error(): void {
		list( $downloader ) = $this->downloader();

		$result = $downloader->remove( 'nope' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_not_installed', $result->get_error_code() );
	}

	public function test_downloads_can_be_switched_off_for_the_platform(): void {
		add_filter( 'infinite_icons_downloads_supported', '__return_false' );
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'No request should be made.' );
			}
		);
		list( $downloader ) = $this->downloader();

		$result = $downloader->install( 'demo' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_downloads_unsupported', $result->get_error_code() );
	}

	public function test_rejects_an_invalid_slug_without_any_request(): void {
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'No request should be made.' );
			}
		);
		list( $downloader ) = $this->downloader();

		$result = $downloader->install( 'Not A Slug' );

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_invalid_slug', $result->get_error_code() );
	}
}
