<?php
/**
 * Pack value object.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Packs;

defined( 'ABSPATH' ) || exit;

/**
 * An installed icon pack, built from its manifest.json.
 *
 * @since 1.0.0
 */
final class Pack {

	/**
	 * Pack slug, also the icon collection slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $slug;

	/**
	 * Human-readable label.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $label;

	/**
	 * Human-readable description.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $description;

	/**
	 * Pack version, e.g. "1.42.0+ii.1".
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $version;

	/**
	 * Absolute path to the pack directory, without a trailing slash.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $dir;

	/**
	 * Whether the pack ships inside the plugin (and so cannot be removed).
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	public $bundled;

	/**
	 * License information: spdx, attribution.
	 *
	 * @since 1.0.0
	 * @var array{spdx: string, attribution: string}
	 */
	public $license;

	/**
	 * Upstream information: name, version, url.
	 *
	 * @since 1.0.0
	 * @var array{name: string, version: string, url: string}
	 */
	public $upstream;

	/**
	 * Variants: list of arrays with key, label and default.
	 *
	 * @since 1.0.0
	 * @var array<int, array{key: string, label: string, default: bool}>
	 */
	public $variants;

	/**
	 * Icons: list of arrays with name, label, variant, keywords and file.
	 *
	 * @since 1.0.0
	 * @var array<int, array{name: string, label: string, variant: string, keywords: array<int, string>, file: string}>
	 */
	public $icons;

	/**
	 * Minimum plugin version this pack requires, e.g. ">=1.0.0".
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $requires_plugin;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Values matching the public properties.
	 */
	private function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}

	/**
	 * Builds a Pack from a decoded manifest.
	 *
	 * Every field is validated; an invalid manifest yields null rather than a
	 * half-built object, because the values end up in registration calls and in
	 * REST responses.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $manifest Decoded manifest.json.
	 * @param string $dir      Absolute path to the pack directory.
	 * @param bool   $bundled  Whether the pack ships with the plugin.
	 * @return Pack|null The pack, or null when the manifest is unusable.
	 */
	public static function from_manifest( $manifest, string $dir, bool $bundled ): ?Pack {
		if ( ! is_array( $manifest ) || 1 !== ( $manifest['schema'] ?? null ) ) {
			return null;
		}
		$slug = is_string( $manifest['slug'] ?? null ) ? $manifest['slug'] : '';
		if ( ! self::is_valid_name( $slug ) ) {
			return null;
		}
		$label = is_string( $manifest['label'] ?? null ) ? $manifest['label'] : '';
		if ( '' === $label ) {
			return null;
		}

		$variants   = array();
		$has_default = false;
		foreach ( (array) ( $manifest['variants'] ?? array() ) as $variant ) {
			if ( ! is_array( $variant ) || ! isset( $variant['key'], $variant['label'] ) ) {
				continue;
			}
			$key = (string) $variant['key'];
			if ( '' !== $key && ! self::is_valid_name( $key ) ) {
				continue;
			}
			$is_default = ! empty( $variant['default'] );
			$has_default = $has_default || $is_default;
			$variants[]  = array(
				'key'     => $key,
				'label'   => (string) $variant['label'],
				'default' => $is_default,
			);
		}
		if ( ! $variants ) {
			return null;
		}
		if ( ! $has_default ) {
			$variants[0]['default'] = true;
		}
		$variant_keys = wp_list_pluck( $variants, 'key' );

		$icons = array();
		foreach ( (array) ( $manifest['icons'] ?? array() ) as $icon ) {
			if ( ! is_array( $icon ) || ! isset( $icon['name'], $icon['label'] ) ) {
				continue;
			}
			$name = (string) $icon['name'];
			if ( ! self::is_valid_name( $name ) ) {
				continue;
			}
			$variant = isset( $icon['variant'] ) ? (string) $icon['variant'] : '';
			if ( ! in_array( $variant, $variant_keys, true ) ) {
				continue;
			}
			$file = isset( $icon['file'] ) ? (string) $icon['file'] : "icons/{$name}.svg";
			// The manifest is data, not a path source: only ever "icons/<name>.svg".
			if ( "icons/{$name}.svg" !== $file ) {
				continue;
			}
			$keywords = array();
			foreach ( (array) ( $icon['keywords'] ?? array() ) as $keyword ) {
				if ( is_string( $keyword ) && '' !== $keyword ) {
					$keywords[] = $keyword;
				}
			}
			$icons[ $name ] = array(
				'name'     => $name,
				'label'    => (string) $icon['label'],
				'variant'  => $variant,
				'keywords' => $keywords,
				'file'     => $file,
			);
		}
		if ( ! $icons ) {
			return null;
		}

		$license  = is_array( $manifest['license'] ?? null ) ? $manifest['license'] : array();
		$upstream = is_array( $manifest['upstream'] ?? null ) ? $manifest['upstream'] : array();

		return new self(
			array(
				'slug'            => $slug,
				'label'           => $label,
				'description'     => (string) ( $manifest['description'] ?? '' ),
				'version'         => (string) ( $manifest['version'] ?? '0.0.0' ),
				'dir'             => untrailingslashit( $dir ),
				'bundled'         => $bundled,
				'license'         => array(
					'spdx'        => (string) ( $license['spdx'] ?? '' ),
					'attribution' => (string) ( $license['attribution'] ?? '' ),
				),
				'upstream'        => array(
					'name'    => (string) ( $upstream['name'] ?? '' ),
					'version' => (string) ( $upstream['version'] ?? '' ),
					'url'     => (string) ( $upstream['url'] ?? '' ),
				),
				'variants'        => $variants,
				'icons'           => array_values( $icons ),
				'requires_plugin' => (string) ( $manifest['requires_plugin'] ?? '' ),
			)
		);
	}

	/**
	 * Checks a slug or icon name against the rule WordPress core enforces.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Name to check.
	 * @return bool
	 */
	public static function is_valid_name( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $name );
	}

	/**
	 * Retrieves the key of the default variant.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function default_variant(): string {
		foreach ( $this->variants as $variant ) {
			if ( $variant['default'] ) {
				return $variant['key'];
			}
		}
		return $this->variants[0]['key'] ?? '';
	}

	/**
	 * Retrieves every variant key.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	public function variant_keys(): array {
		return array_map(
			static function ( array $variant ): string {
				return $variant['key'];
			},
			$this->variants
		);
	}

	/**
	 * Builds the absolute path to an icon file.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $icon Icon entry from {@see Pack::$icons}.
	 * @return string
	 */
	public function icon_path( array $icon ): string {
		return $this->dir . '/' . $icon['file'];
	}

	/**
	 * Counts icons, optionally limited to the given variants.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>|null $variants Variant keys, or null for all.
	 * @return int
	 */
	public function icon_count( ?array $variants = null ): int {
		if ( null === $variants ) {
			return count( $this->icons );
		}
		$count = 0;
		foreach ( $this->icons as $icon ) {
			if ( in_array( $icon['variant'], $variants, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Converts the pack to an array for REST responses (without the icon list).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'slug'        => $this->slug,
			'label'       => $this->label,
			'description' => $this->description,
			'version'     => $this->version,
			'bundled'     => $this->bundled,
			'license'     => $this->license,
			'upstream'    => $this->upstream,
			'variants'    => $this->variants,
			'icon_count'  => count( $this->icons ),
		);
	}
}
