<?php
/**
 * Pack discovery tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Pack;

/**
 * Pack discovery and caching.
 *
 * @covers \InfiniteIcons\Packs\PackLocator
 * @covers \InfiniteIcons\Packs\ManifestCache
 */
final class PackLocatorTest extends TestCase {

	public function test_finds_bundled_and_downloaded_packs(): void {
		$bundled = $this->make_temp_dir();
		$uploads = $this->make_temp_dir();
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ) );
		$this->make_pack( $this->downloaded_packs_dir( $uploads ), 'beta', array( array( 'name' => 'b' ) ) );

		$packs = $this->locator_for( $bundled, $uploads )->all();

		$this->assertSame( array( 'alpha', 'beta' ), array_keys( $packs ) );
		$this->assertTrue( $packs['alpha']->bundled );
		$this->assertFalse( $packs['beta']->bundled );
	}

	public function test_a_downloaded_pack_overrides_a_bundled_pack_with_the_same_slug(): void {
		$bundled = $this->make_temp_dir();
		$uploads = $this->make_temp_dir();
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ), array( 'version' => '1.0.0+ii.1' ) );
		$this->make_pack( $this->downloaded_packs_dir( $uploads ), 'alpha', array( array( 'name' => 'a' ), array( 'name' => 'b' ) ), array( 'version' => '2.0.0+ii.1' ) );

		$packs = $this->locator_for( $bundled, $uploads )->all();

		$this->assertCount( 1, $packs );
		$this->assertSame( '2.0.0+ii.1', $packs['alpha']->version );
		$this->assertFalse( $packs['alpha']->bundled, 'The uploads copy wins, so the pack is no longer bundled.' );
		$this->assertSame( 2, $packs['alpha']->icon_count() );
	}

	public function test_packs_are_sorted_by_label(): void {
		$bundled = $this->make_temp_dir();
		$this->make_pack( $bundled, 'zulu', array( array( 'name' => 'a' ) ), array( 'label' => 'Alpha' ) );
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ), array( 'label' => 'Zulu' ) );

		$this->assertSame( array( 'zulu', 'alpha' ), array_keys( $this->locator_for( $bundled )->all() ) );
	}

	public function test_ignores_directories_that_are_not_packs(): void {
		$bundled = $this->make_temp_dir();
		mkdir( $bundled . '/empty' );
		mkdir( $bundled . '/.hidden' );
		file_put_contents( $bundled . '/loose.txt', 'x' );
		file_put_contents( $bundled . '/broken.json', 'x' );
		mkdir( $bundled . '/bad-json' );
		file_put_contents( $bundled . '/bad-json/manifest.json', '{not json' );
		$this->make_pack( $bundled, 'good', array( array( 'name' => 'a' ) ) );

		$this->assertSame( array( 'good' ), array_keys( $this->locator_for( $bundled )->all() ) );
	}

	public function test_ignores_a_pack_whose_directory_disagrees_with_its_manifest(): void {
		$bundled = $this->make_temp_dir();
		// A pack in a directory named "impostor" claiming to be "lucide" could
		// hijack another collection's icon names.
		$this->make_pack( $bundled, 'impostor', array( array( 'name' => 'a' ) ), array( 'slug' => 'lucide' ) );

		$this->assertSame( array(), $this->locator_for( $bundled )->all() );
	}

	public function test_get_returns_one_pack_or_null(): void {
		$bundled = $this->make_temp_dir();
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ) );
		$locator = $this->locator_for( $bundled );

		$this->assertInstanceOf( Pack::class, $locator->get( 'alpha' ) );
		$this->assertNull( $locator->get( 'missing' ) );
	}

	public function test_the_packs_filter_can_add_and_remove_packs(): void {
		$bundled = $this->make_temp_dir();
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ) );
		$this->make_pack( $bundled, 'beta', array( array( 'name' => 'b' ) ) );

		add_filter(
			'infinite_icons_packs',
			static function ( array $packs ): array {
				unset( $packs['beta'] );
				return $packs;
			}
		);

		$this->assertSame( array( 'alpha' ), array_keys( $this->locator_for( $bundled )->all() ) );
	}

	public function test_the_packs_filter_cannot_inject_non_pack_values(): void {
		$bundled = $this->make_temp_dir();
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ) );

		add_filter(
			'infinite_icons_packs',
			static function ( array $packs ): array {
				$packs['junk'] = 'not a pack';
				return $packs;
			}
		);

		$this->assertSame( array( 'alpha' ), array_keys( $this->locator_for( $bundled )->all() ) );
	}

	public function test_results_are_memoized_until_invalidated(): void {
		$bundled = $this->make_temp_dir();
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ) );
		$locator = $this->locator_for( $bundled );

		$this->assertCount( 1, $locator->all() );

		$this->make_pack( $bundled, 'beta', array( array( 'name' => 'b' ) ) );
		$this->assertCount( 1, $locator->all(), 'A second call should reuse the first scan.' );

		$locator->invalidate();
		$this->assertCount( 2, $locator->all(), 'After invalidation the directory is scanned again.' );
	}

	public function test_a_changed_manifest_is_picked_up_by_the_cache(): void {
		$bundled = $this->make_temp_dir();
		$dir     = $this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ) ), array( 'version' => '1.0.0+ii.1' ) );

		$this->assertSame( '1.0.0+ii.1', $this->locator_for( $bundled )->get( 'alpha' )->version );

		// The cache key includes the manifest's size and mtime.
		$this->make_pack( $bundled, 'alpha', array( array( 'name' => 'a' ), array( 'name' => 'b' ) ), array( 'version' => '22.0.0+ii.1' ) );
		touch( $dir . '/manifest.json', time() + 10 );

		$this->assertSame( '22.0.0+ii.1', $this->locator_for( $bundled )->get( 'alpha' )->version );
	}

	public function test_uploads_dir_lives_under_the_uploads_directory(): void {
		$locator = new \InfiniteIcons\Packs\PackLocator();
		$uploads = wp_get_upload_dir();

		$this->assertStringStartsWith( untrailingslashit( $uploads['basedir'] ), $locator->uploads_dir() );
		$this->assertStringEndsWith( '/infinite-icons', $locator->uploads_dir() );
	}
}
