<?php
/**
 * Settings tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Pack;
use InfiniteIcons\Settings\Options;

/**
 * Settings behaviour.
 *
 * @covers \InfiniteIcons\Settings\Options
 */
final class OptionsTest extends TestCase {

	/**
	 * Builds a pack with three variants.
	 *
	 * @return Pack
	 */
	private function pack(): Pack {
		return Pack::from_manifest(
			array(
				'schema'   => 1,
				'slug'     => 'demo',
				'label'    => 'Demo',
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
				'icons'    => array(
					array(
						'name'    => 'a',
						'label'   => 'A',
						'variant' => '',
						'file'    => 'icons/a.svg',
					),
				),
			),
			'/tmp/demo',
			false
		);
	}

	public function test_defaults_are_returned_when_nothing_is_stored(): void {
		$options = new Options();

		$this->assertSame( array(), $options->get( 'enabled_packs' ) );
		$this->assertFalse( $options->get( 'auto_check_index' ) );
		$this->assertFalse( $options->get( 'remove_data_on_uninstall' ) );
		$this->assertNull( $options->get( 'nope' ) );
		$this->assertSame( 'fallback', $options->get( 'nope', 'fallback' ) );
	}

	public function test_a_pack_with_no_stored_preference_is_enabled(): void {
		$options = new Options();

		$this->assertTrue( $options->is_pack_enabled( 'anything' ) );

		$options->update( array( 'enabled_packs' => array( 'off' => false ) ) );

		$this->assertFalse( $options->is_pack_enabled( 'off' ) );
		$this->assertTrue( $options->is_pack_enabled( 'other' ) );
	}

	public function test_enabled_variants_falls_back_to_the_default_variant(): void {
		$options = new Options();

		$this->assertSame( array( '' ), $options->enabled_variants( $this->pack() ) );
	}

	public function test_enabled_variants_uses_stored_values(): void {
		$options = new Options();
		$options->update( array( 'enabled_variants' => array( 'demo' => array( 'solid', 'mini' ) ) ) );

		$this->assertSame( array( 'solid', 'mini' ), $options->enabled_variants( $this->pack() ) );
	}

	public function test_enabled_variants_drops_variants_the_pack_does_not_have(): void {
		$options = new Options();
		$options->update( array( 'enabled_variants' => array( 'demo' => array( 'solid', 'ghost' ) ) ) );

		$this->assertSame( array( 'solid' ), $options->enabled_variants( $this->pack() ) );
	}

	public function test_the_default_variants_filter_applies_only_without_stored_values(): void {
		add_filter(
			'infinite_icons_default_enabled_variants',
			static function (): array {
				return array( 'solid' );
			}
		);

		$options = new Options();
		$this->assertSame( array( 'solid' ), $options->enabled_variants( $this->pack() ) );

		$options->update( array( 'enabled_variants' => array( 'demo' => array( 'mini' ) ) ) );
		$this->assertSame( array( 'mini' ), $options->enabled_variants( $this->pack() ) );
	}

	public function test_enable_new_pack_records_the_default_variant(): void {
		$options = new Options();
		$options->enable_new_pack( $this->pack() );

		$this->assertTrue( $options->is_pack_enabled( 'demo' ) );
		$this->assertSame( array( 'demo' => array( '' ) ), $options->get( 'enabled_variants' ) );
	}

	public function test_forget_pack_removes_every_stored_preference(): void {
		$options = new Options();
		$options->enable_new_pack( $this->pack() );
		$options->forget_pack( 'demo' );

		$this->assertSame( array(), $options->get( 'enabled_packs' ) );
		$this->assertSame( array(), $options->get( 'enabled_variants' ) );
	}

	public function test_sanitize_drops_invalid_slugs_and_variants(): void {
		$options = new Options();
		$clean   = $options->sanitize(
			array(
				'enabled_packs'            => array(
					'good'     => '1',
					'Bad Slug' => true,
					'../evil'  => true,
					'off'      => 0,
				),
				'enabled_variants'         => array(
					'good'     => array( '', 'solid', 'Bad Variant', 'ok-1' ),
					'Bad Slug' => array( 'x' ),
					'notarray' => 'solid',
				),
				'auto_check_index'         => 'yes',
				'remove_data_on_uninstall' => '',
				'unknown_key'              => 'dropped',
			)
		);

		$this->assertSame(
			array(
				'good' => true,
				'off'  => false,
			),
			$clean['enabled_packs']
		);
		$this->assertSame( array( 'good' => array( '', 'solid', 'ok-1' ) ), $clean['enabled_variants'] );
		$this->assertTrue( $clean['auto_check_index'] );
		$this->assertFalse( $clean['remove_data_on_uninstall'] );
		$this->assertArrayNotHasKey( 'unknown_key', $clean );
	}

	public function test_a_corrupt_stored_option_falls_back_to_defaults(): void {
		update_option( Options::OPTION, 'not an array' );

		$options = new Options();

		$this->assertSame( Options::defaults(), $options->all() );
	}

	public function test_update_persists_and_reloads(): void {
		$options = new Options();
		$options->update( array( 'remove_data_on_uninstall' => true ) );

		$this->assertTrue( ( new Options() )->get( 'remove_data_on_uninstall' ) );
	}
}
