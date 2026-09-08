<?php
/**
 * Plugin settings.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Settings;

use InfiniteIcons\Packs\Pack;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single `infinite_icons_settings` option.
 *
 * @since 1.0.0
 */
final class Options {

	/**
	 * Option name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION = 'infinite_icons_settings';

	/**
	 * Memoized settings for this request.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>|null
	 */
	private $settings = null;

	/**
	 * Retrieves the default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled_packs'            => array(),
			'enabled_variants'         => array(),
			'auto_check_index'         => false,
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Retrieves every setting, merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( self::OPTION, array() );
			$this->settings = $this->sanitize( is_array( $stored ) ? $stored : array() );
		}
		return $this->settings;
	}

	/**
	 * Retrieves one setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default_value Value returned when the setting is missing.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Updates settings and persists them.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $values Settings to merge in.
	 * @return void
	 */
	public function update( array $values ): void {
		$this->settings = $this->sanitize( array_merge( $this->all(), $values ) );
		update_option( self::OPTION, $this->settings );
	}

	/**
	 * Forgets the memoized settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function invalidate(): void {
		$this->settings = null;
	}

	/**
	 * Checks whether a pack is enabled.
	 *
	 * A pack with no stored preference is treated as enabled, so a pack that
	 * appears on disk (bundled, or restored from a backup) works without the
	 * user having to visit the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Pack slug.
	 * @return bool
	 */
	public function is_pack_enabled( string $slug ): bool {
		$enabled = (array) $this->get( 'enabled_packs', array() );
		return ! array_key_exists( $slug, $enabled ) || (bool) $enabled[ $slug ];
	}

	/**
	 * Retrieves the enabled variant keys for a pack.
	 *
	 * Falls back to the pack's default variant when nothing is stored.
	 *
	 * @since 1.0.0
	 *
	 * @param Pack $pack Pack to inspect.
	 * @return array<int, string>
	 */
	public function enabled_variants( Pack $pack ): array {
		$stored = (array) $this->get( 'enabled_variants', array() );
		$keys   = $pack->variant_keys();

		if ( isset( $stored[ $pack->slug ] ) && is_array( $stored[ $pack->slug ] ) ) {
			$variants = array_values( array_intersect( $keys, array_map( 'strval', $stored[ $pack->slug ] ) ) );
			if ( $variants ) {
				return $variants;
			}
		}

		/**
		 * Filters the variants enabled for a pack that has no stored preference.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $variants Variant keys.
		 * @param Pack               $pack     The pack.
		 */
		$defaults = apply_filters( 'infinite_icons_default_enabled_variants', array( $pack->default_variant() ), $pack );
		return array_values( array_intersect( $keys, array_map( 'strval', (array) $defaults ) ) );
	}

	/**
	 * Records the settings for a freshly installed pack.
	 *
	 * @since 1.0.0
	 *
	 * @param Pack $pack Pack that was installed.
	 * @return void
	 */
	public function enable_new_pack( Pack $pack ): void {
		$enabled  = (array) $this->get( 'enabled_packs', array() );
		$variants = (array) $this->get( 'enabled_variants', array() );

		$enabled[ $pack->slug ] = true;
		if ( ! isset( $variants[ $pack->slug ] ) ) {
			$variants[ $pack->slug ] = array( $pack->default_variant() );
		}

		$this->update(
			array(
				'enabled_packs'    => $enabled,
				'enabled_variants' => $variants,
			)
		);
	}

	/**
	 * Removes every stored preference for a pack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Pack slug.
	 * @return void
	 */
	public function forget_pack( string $slug ): void {
		$enabled  = (array) $this->get( 'enabled_packs', array() );
		$variants = (array) $this->get( 'enabled_variants', array() );
		unset( $enabled[ $slug ], $variants[ $slug ] );
		$this->update(
			array(
				'enabled_packs'    => $enabled,
				'enabled_variants' => $variants,
			)
		);
	}

	/**
	 * Sanitizes a settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$output = self::defaults();

		foreach ( (array) ( $input['enabled_packs'] ?? array() ) as $slug => $enabled ) {
			if ( is_string( $slug ) && Pack::is_valid_name( $slug ) ) {
				$output['enabled_packs'][ $slug ] = (bool) $enabled;
			}
		}

		foreach ( (array) ( $input['enabled_variants'] ?? array() ) as $slug => $variants ) {
			if ( ! is_string( $slug ) || ! Pack::is_valid_name( $slug ) || ! is_array( $variants ) ) {
				continue;
			}
			$clean = array();
			foreach ( $variants as $variant ) {
				$variant = (string) $variant;
				if ( '' === $variant || Pack::is_valid_name( $variant ) ) {
					$clean[] = $variant;
				}
			}
			$output['enabled_variants'][ $slug ] = array_values( array_unique( $clean ) );
		}

		$output['auto_check_index']         = ! empty( $input['auto_check_index'] );
		$output['remove_data_on_uninstall'] = ! empty( $input['remove_data_on_uninstall'] );

		return $output;
	}
}
