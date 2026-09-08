<?php
/**
 * Registration tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Registrar;
use InfiniteIcons\Settings\Options;

/**
 * Icon and collection registration.
 *
 * @covers \InfiniteIcons\Packs\Registrar
 */
final class RegistrarTest extends TestCase {

	/**
	 * Runs registration for a set of packs.
	 *
	 * Collections registered here are cleaned up by TestCase, which restores both icon
	 * registries to their pre-test state.
	 *
	 * @param string       $bundled Bundled pack directory.
	 * @param Options|null $options Settings to use.
	 * @return Registrar
	 */
	private function register_from( string $bundled, ?Options $options = null ): Registrar {
		$registrar = new Registrar( $this->locator_for( $bundled ), $options ?? new Options() );
		$registrar->register_packs();
		return $registrar;
	}

	public function test_registers_a_collection_and_its_icons(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack(
			$dir,
			'demo',
			array(
				array(
					'name'  => 'home',
					'label' => 'Home',
				),
				array(
					'name'  => 'star',
					'label' => 'Star',
				),
			)
		);

		$registrar = $this->register_from( $dir, null );

		$this->assertTrue( \WP_Icon_Collections_Registry::get_instance()->is_registered( 'demo' ) );
		$this->assertSame( array( 'demo' ), $registrar->registered_packs() );
		$this->assertSame( 2, $this->count_icons( 'demo' ) );
		$this->assertTrue( $registrar->has( 'demo/home' ) );
		$this->assertStringContainsString( '<svg', wp_get_icon( 'demo/home' ) );
	}

	public function test_skips_a_pack_whose_collection_slug_is_taken(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack( $dir, 'taken', array( array( 'name' => 'home' ) ) );

		// Another plugin got there first.
		wp_register_icon_collection( 'taken', array( 'label' => 'Someone else' ) );

		$this->setExpectedIncorrectUsage( 'WP_Icon_Collections_Registry::register' );
		$registrar = $this->register_from( $dir );

		$this->assertSame( array(), $registrar->registered_packs() );
		$this->assertSame( 0, $this->count_icons( 'taken' ), 'No icons should be added to a collection we do not own.' );
	}

	public function test_skips_a_pack_whose_files_are_missing(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack(
			$dir,
			'demo',
			array(
				array(
					'name'       => 'absent',
					'write_file' => false,
				),
				array( 'name' => 'present' ),
			)
		);

		$registrar = $this->register_from( $dir, null );

		$this->assertFalse( $registrar->has( 'demo/present' ) );
		$this->assertSame( 0, $this->count_icons( 'demo' ) );
		$this->assertFalse(
			\WP_Icon_Collections_Registry::get_instance()->is_registered( 'demo' ),
			'A pack that failed its file probe should not leave an empty collection behind.'
		);
	}

	/**
	 * A single icon file that goes missing after installation is left registered on purpose:
	 * checking every path costs a filesystem stat per icon on every request. Core reads
	 * file_path lazily and renders an empty string, so the failure stays contained.
	 */
	public function test_registers_icons_without_stating_every_file(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack(
			$dir,
			'demo',
			array(
				array( 'name' => 'present' ),
				array(
					'name'       => 'absent',
					'write_file' => false,
				),
			)
		);

		$registrar = $this->register_from( $dir, null );

		$this->assertTrue( $registrar->has( 'demo/present' ) );
		$this->assertTrue( $registrar->has( 'demo/absent' ) );
		$this->assertSame( 2, $this->count_icons( 'demo' ) );
	}

	public function test_registers_only_enabled_variants(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack(
			$dir,
			'demo',
			array(
				array( 'name' => 'home' ),
				array(
					'name'    => 'home-solid',
					'variant' => 'solid',
				),
				array(
					'name'    => 'home-mini',
					'variant' => 'mini',
				),
			),
			array(
				'variants' => array(
					array(
						'key'     => '',
						'label'   => 'Outline',
						'default' => true,
					),
					array(
						'key'   => 'solid',
						'label' => 'Solid',
					),
					array(
						'key'   => 'mini',
						'label' => 'Mini',
					),
				),
			)
		);

		// Nothing stored: only the default variant is registered.
		$registrar = $this->register_from( $dir, null );
		$this->assertSame( array( 'demo/home' ), array_keys( $registrar->registered_icons() ) );

		$this->unregister_collection( 'demo' );

		$options = new Options();
		$options->update( array( 'enabled_variants' => array( 'demo' => array( '', 'mini' ) ) ) );
		$registrar = $this->register_from( $dir, $options );

		$this->assertSame( array( 'demo/home', 'demo/home-mini' ), array_keys( $registrar->registered_icons() ) );
	}

	public function test_skips_disabled_packs(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack( $dir, 'on', array( array( 'name' => 'a' ) ) );
		$this->make_pack( $dir, 'off', array( array( 'name' => 'b' ) ) );

		$options = new Options();
		$options->update( array( 'enabled_packs' => array( 'off' => false ) ) );

		$registrar = $this->register_from( $dir, $options );

		$this->assertSame( array( 'on' ), $registrar->registered_packs() );
		$this->assertFalse( \WP_Icon_Collections_Registry::get_instance()->is_registered( 'off' ) );
	}

	public function test_registration_runs_only_once(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack( $dir, 'demo', array( array( 'name' => 'home' ) ) );

		$registrar = new Registrar( $this->locator_for( $dir ), new Options() );
		$registrar->register_packs();
		// A second call must not re-register, which core would flag as
		// "already registered" via _doing_it_wrong().
		$registrar->register_packs();

		$this->assertSame( 1, $this->count_icons( 'demo' ) );
	}

	public function test_keeps_a_lookup_map_for_search(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack(
			$dir,
			'demo',
			array(
				array(
					'name'     => 'home',
					'label'    => 'Home',
					'keywords' => array( 'house' ),
				),
			)
		);

		$registrar = $this->register_from( $dir, null );
		$entry     = $registrar->registered_icons()['demo/home'];

		$this->assertSame( 'demo', $entry['pack'] );
		$this->assertSame( '', $entry['variant'] );
		$this->assertSame( 'Home', $entry['label'] );
		$this->assertSame( array( 'house' ), $entry['keywords'] );
		$this->assertFileExists( $entry['file'] );
	}

	public function test_register_icon_args_filter_is_applied(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack(
			$dir,
			'demo',
			array(
				array(
					'name'  => 'home',
					'label' => 'Home',
				),
			)
		);

		add_filter(
			'infinite_icons_register_icon_args',
			static function ( array $args ): array {
				$args['label'] = 'Filtered';
				return $args;
			}
		);

		$this->register_from( $dir, null );

		$this->assertStringContainsString( 'Filtered', wp_get_icon( 'demo/home', array( 'label' => 'Filtered' ) ) );
	}

	public function test_icons_are_not_read_from_disk_until_rendered(): void {
		$dir = $this->make_temp_dir();
		$this->make_pack( $dir, 'demo', array( array( 'name' => 'home' ) ) );

		$this->register_from( $dir, null );

		$registry = \WP_Icons_Registry::get_instance();
		$property = new \ReflectionProperty( $registry, 'registered_icons' );
		$icons    = $property->getValue( $registry );

		$this->assertArrayNotHasKey( 'content', $icons['demo/home'], 'Registration must stay lazy: 1800 icons should cost no file reads.' );
		$this->assertArrayHasKey( 'file_path', $icons['demo/home'] );
	}
}
