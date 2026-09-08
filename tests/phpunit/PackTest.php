<?php
/**
 * Pack value object tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Pack;

/**
 * Manifest parsing and validation.
 *
 * @covers \InfiniteIcons\Packs\Pack
 */
final class PackTest extends TestCase {

	/**
	 * Builds a minimal valid manifest.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private function manifest( array $overrides = array() ): array {
		return array_merge(
			array(
				'schema'          => 1,
				'slug'            => 'demo',
				'label'           => 'Demo',
				'description'     => 'A demo pack.',
				'version'         => '1.0.0+ii.1',
				'upstream'        => array(
					'name'    => 'demo',
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
						'label'   => 'Outline',
						'default' => true,
					),
					array(
						'key'   => 'solid',
						'label' => 'Solid',
					),
				),
				'icons'           => array(
					array(
						'name'     => 'home',
						'label'    => 'Home',
						'variant'  => '',
						'keywords' => array( 'house' ),
						'file'     => 'icons/home.svg',
					),
					array(
						'name'     => 'home-solid',
						'label'    => 'Home',
						'variant'  => 'solid',
						'keywords' => array(),
						'file'     => 'icons/home-solid.svg',
					),
				),
			),
			$overrides
		);
	}

	public function test_builds_from_a_valid_manifest(): void {
		$pack = Pack::from_manifest( $this->manifest(), '/tmp/demo/', false );

		$this->assertInstanceOf( Pack::class, $pack );
		$this->assertSame( 'demo', $pack->slug );
		$this->assertSame( '/tmp/demo', $pack->dir, 'The trailing slash should be removed.' );
		$this->assertFalse( $pack->bundled );
		$this->assertSame( '', $pack->default_variant() );
		$this->assertSame( array( '', 'solid' ), $pack->variant_keys() );
		$this->assertSame( 2, $pack->icon_count() );
		$this->assertSame( 1, $pack->icon_count( array( 'solid' ) ) );
		$this->assertSame( '/tmp/demo/icons/home.svg', $pack->icon_path( $pack->icons[0] ) );
		$this->assertSame( 'MIT', $pack->license['spdx'] );
	}

	/**
	 * Runs the assertion for one data set.
	 *
	 * @dataProvider data_invalid_manifests
	 *
	 * @param mixed  $manifest Manifest to reject.
	 * @param string $because  Reason, for the failure message.
	 */
	public function test_rejects_invalid_manifests( $manifest, string $because ): void {
		$this->assertNull( Pack::from_manifest( $manifest, '/tmp/demo', false ), $because );
	}

	/**
	 * Supplies the data sets.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function data_invalid_manifests(): array {
		return array(
			'not an array'      => array( 'nope', 'a string is not a manifest' ),
			'wrong schema'      => array( $this->manifest( array( 'schema' => 2 ) ), 'schema 2 is not supported' ),
			'missing schema'    => array( array_diff_key( $this->manifest(), array( 'schema' => null ) ), 'schema is required' ),
			'bad slug'          => array( $this->manifest( array( 'slug' => 'Demo Pack' ) ), 'slugs may not contain spaces or capitals' ),
			'slug with slash'   => array( $this->manifest( array( 'slug' => 'a/b' ) ), 'slugs may not contain slashes' ),
			'empty label'       => array( $this->manifest( array( 'label' => '' ) ), 'a label is required' ),
			'no variants'       => array( $this->manifest( array( 'variants' => array() ) ), 'at least one variant is required' ),
			'no icons'          => array( $this->manifest( array( 'icons' => array() ) ), 'at least one icon is required' ),
			'all icons invalid' => array(
				$this->manifest(
					array(
						'icons' => array(
							array(
								'name'  => 'Bad Name',
								'label' => 'x',
							),
						),
					)
				),
				'invalid icon names are dropped',
			),
		);
	}

	public function test_drops_icons_whose_file_path_is_not_derived_from_the_name(): void {
		$pack = Pack::from_manifest(
			$this->manifest(
				array(
					'icons' => array(
						array(
							'name'     => 'ok',
							'label'    => 'Ok',
							'variant'  => '',
							'keywords' => array(),
							'file'     => 'icons/ok.svg',
						),
						array(
							'name'     => 'evil',
							'label'    => 'Evil',
							'variant'  => '',
							'keywords' => array(),
							'file'     => '../../../wp-config.php',
						),
						array(
							'name'     => 'sneaky',
							'label'    => 'Sneaky',
							'variant'  => '',
							'keywords' => array(),
							'file'     => 'icons/../../evil.svg',
						),
					),
				)
			),
			'/tmp/demo',
			false
		);

		$this->assertInstanceOf( Pack::class, $pack );
		$this->assertSame( array( 'ok' ), wp_list_pluck( $pack->icons, 'name' ) );
	}

	public function test_drops_icons_with_an_unknown_variant(): void {
		$pack = Pack::from_manifest(
			$this->manifest(
				array(
					'icons' => array(
						array(
							'name'     => 'a',
							'label'    => 'A',
							'variant'  => '',
							'keywords' => array(),
							'file'     => 'icons/a.svg',
						),
						array(
							'name'     => 'b',
							'label'    => 'B',
							'variant'  => 'nope',
							'keywords' => array(),
							'file'     => 'icons/b.svg',
						),
					),
				)
			),
			'/tmp/demo',
			false
		);

		$this->assertSame( array( 'a' ), wp_list_pluck( $pack->icons, 'name' ) );
	}

	public function test_first_variant_becomes_the_default_when_none_is_marked(): void {
		$pack = Pack::from_manifest(
			$this->manifest(
				array(
					'variants' => array(
						array(
							'key'   => 'outline',
							'label' => 'Outline',
						),
						array(
							'key'   => 'solid',
							'label' => 'Solid',
						),
					),
					'icons'    => array(
						array(
							'name'     => 'a',
							'label'    => 'A',
							'variant'  => 'outline',
							'keywords' => array(),
							'file'     => 'icons/a.svg',
						),
					),
				)
			),
			'/tmp/demo',
			false
		);

		$this->assertSame( 'outline', $pack->default_variant() );
	}

	public function test_keeps_only_string_keywords(): void {
		$pack = Pack::from_manifest(
			$this->manifest(
				array(
					'icons' => array(
						array(
							'name'     => 'a',
							'label'    => 'A',
							'variant'  => '',
							'keywords' => array( 'good', '', 42, array( 'x' ), 'fine' ),
							'file'     => 'icons/a.svg',
						),
					),
				)
			),
			'/tmp/demo',
			false
		);

		$this->assertSame( array( 'good', 'fine' ), $pack->icons[0]['keywords'] );
	}

	/**
	 * Runs the assertion for one data set.
	 *
	 * @dataProvider data_names
	 *
	 * @param string $name  Name to check.
	 * @param bool   $valid Expected result.
	 */
	public function test_is_valid_name( string $name, bool $valid ): void {
		$this->assertSame( $valid, Pack::is_valid_name( $name ) );
	}

	/**
	 * Supplies the data sets.
	 *
	 * @return array<int, array{0: string, 1: bool}>
	 */
	public function data_names(): array {
		return array(
			array( 'lucide', true ),
			array( 'a', true ),
			array( '1', true ),
			array( 'arrow-left', true ),
			array( 'arrow_left', true ),
			array( 'a-1_b', true ),
			array( '', false ),
			array( '-lead', false ),
			array( 'trail-', false ),
			array( '_lead', false ),
			array( 'Upper', false ),
			array( 'with space', false ),
			array( 'with/slash', false ),
			array( 'dot.name', false ),
			array( '../evil', false ),
		);
	}

	public function test_to_array_omits_the_icon_list(): void {
		$pack  = Pack::from_manifest( $this->manifest(), '/tmp/demo', true );
		$array = $pack->to_array();

		$this->assertArrayNotHasKey( 'icons', $array );
		$this->assertSame( 2, $array['icon_count'] );
		$this->assertTrue( $array['bundled'] );
	}
}
