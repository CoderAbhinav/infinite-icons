<?php
/**
 * Pack management endpoints.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Rest;

use InfiniteIcons\Packs\Downloader;
use InfiniteIcons\Packs\IndexClient;
use InfiniteIcons\Packs\Pack;
use InfiniteIcons\Packs\PackLocator;
use InfiniteIcons\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, installs, removes and configures packs.
 *
 * @since 1.0.0
 */
final class PacksController extends \WP_REST_Controller {

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
	 * Downloader.
	 *
	 * @since 1.0.0
	 * @var Downloader
	 */
	private $downloader;

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
	 * @param PackLocator $locator    Pack locator.
	 * @param IndexClient $index      Remote index.
	 * @param Downloader  $downloader Downloader.
	 * @param Options     $options    Settings.
	 */
	public function __construct( PackLocator $locator, IndexClient $index, Downloader $downloader, Options $options ) {
		$this->locator    = $locator;
		$this->index      = $index;
		$this->downloader = $downloader;
		$this->options    = $options;
		$this->namespace  = 'infinite-icons/v1';
		$this->rest_base  = 'packs';
	}

	/**
	 * Registers the routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'refresh' => array(
							'description' => __( 'Fetch the list of available packs again instead of using the cached copy.', 'infinite-icons' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/install',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'slug' => array(
							'description'       => __( 'Slug of the pack to install.', 'infinite-icons' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_slug' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/refresh-index',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_index' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'enabled_packs'            => array(
							'description' => __( 'Map of pack slug to whether it is enabled.', 'infinite-icons' ),
							'type'        => 'object',
						),
						'enabled_variants'         => array(
							'description' => __( 'Map of pack slug to the list of enabled variant keys.', 'infinite-icons' ),
							'type'        => 'object',
						),
						'remove_data_on_uninstall' => array(
							'description' => __( 'Whether to delete downloaded packs when the plugin is uninstalled.', 'infinite-icons' ),
							'type'        => 'boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug>[a-z0-9][a-z0-9_-]*)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'slug' => array(
							'description'       => __( 'Slug of the pack to remove.', 'infinite-icons' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_slug' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Checks the caller may manage packs.
	 *
	 * Installing a pack writes to the uploads directory and makes an outbound
	 * request, so this is deliberately the same capability as installing a plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_check( $request ) {
		unset( $request );
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error(
			'infinite_icons_forbidden',
			__( 'You are not allowed to manage icon packs.', 'infinite-icons' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Validates a pack slug.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_slug( $value ): bool {
		return is_string( $value ) && Pack::is_valid_name( $value );
	}

	/**
	 * Returns installed and available packs.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		return rest_ensure_response( $this->state( (bool) $request->get_param( 'refresh' ) ) );
	}

	/**
	 * Installs a pack.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function install_item( $request ) {
		$pack = $this->downloader->install( (string) $request->get_param( 'slug' ) );
		if ( is_wp_error( $pack ) ) {
			return $pack;
		}
		$this->options->invalidate();

		// Keyed "pack", not "installed": state() already uses "installed" for the
		// full list, and merging would silently replace it.
		return rest_ensure_response(
			array_merge(
				array( 'pack' => $this->installed_pack( $pack ) ),
				$this->state( false )
			)
		);
	}

	/**
	 * Removes a pack.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$removed = $this->downloader->remove( (string) $request->get_param( 'slug' ) );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		$this->options->invalidate();
		return rest_ensure_response( array_merge( array( 'deleted' => true ), $this->state( false ) ) );
	}

	/**
	 * Fetches the list of available packs again.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refresh_index( $request ) {
		unset( $request );
		$index = $this->index->get( true );
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		return rest_ensure_response( $this->state( false ) );
	}

	/**
	 * Saves pack settings.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$values = array();

		$packs = $request->get_param( 'enabled_packs' );
		if ( is_array( $packs ) ) {
			$values['enabled_packs'] = $packs;
		}
		$variants = $request->get_param( 'enabled_variants' );
		if ( is_array( $variants ) ) {
			$values['enabled_variants'] = $variants;
		}
		if ( null !== $request->get_param( 'remove_data_on_uninstall' ) ) {
			$values['remove_data_on_uninstall'] = (bool) $request->get_param( 'remove_data_on_uninstall' );
		}

		// Options::sanitize drops unknown keys and anything malformed.
		$this->options->update( $values );

		return rest_ensure_response( $this->state( false ) );
	}

	/**
	 * Builds the payload the settings screen renders from.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $refresh Whether to refetch the remote index.
	 * @return array<string, mixed>
	 */
	private function state( bool $refresh ): array {
		$this->locator->invalidate();
		$installed = array();
		foreach ( $this->locator->all( true ) as $pack ) {
			$installed[] = $this->installed_pack( $pack );
		}

		$index       = $this->index->get( $refresh );
		$index_error = null;
		$available   = array();
		$generated   = '';

		if ( is_wp_error( $index ) ) {
			$index_error = array(
				'code'    => $index->get_error_code(),
				'message' => $index->get_error_message(),
			);
		} else {
			$generated = (string) $index['generated'];
			$by_slug   = wp_list_pluck( $installed, 'version', 'slug' );
			foreach ( $index['packs'] as $entry ) {
				$entry['installed'] = isset( $by_slug[ $entry['slug'] ] );
				$entry['update']    = $entry['installed'] && $this->is_newer( (string) $entry['version'], (string) $by_slug[ $entry['slug'] ] );
				$available[]        = $entry;
			}
		}

		return array(
			'installed'          => $installed,
			'available'          => $available,
			'index'              => array(
				'generated'  => $generated,
				'checked_at' => $this->index->cached_at(),
				'url'        => $this->index->url(),
				'error'      => $index_error,
			),
			'settings'           => array(
				'enabled_packs'            => (array) $this->options->get( 'enabled_packs', array() ),
				'enabled_variants'         => (array) $this->options->get( 'enabled_variants', array() ),
				'remove_data_on_uninstall' => (bool) $this->options->get( 'remove_data_on_uninstall', false ),
			),
			'canInstall'         => $this->uploads_writable(),
			'downloadsSupported' => Downloader::downloads_supported(),
			'previewBase'        => INFINITE_ICONS_URL . 'assets/previews/',
		);
	}

	/**
	 * Describes an installed pack.
	 *
	 * @since 1.0.0
	 *
	 * @param Pack $pack Pack.
	 * @return array<string, mixed>
	 */
	private function installed_pack( Pack $pack ): array {
		$enabled_variants = $this->options->enabled_variants( $pack );

		$variants = array();
		foreach ( $pack->variants as $variant ) {
			$variants[] = array(
				'key'     => $variant['key'],
				'label'   => $variant['label'],
				'default' => $variant['default'],
				'enabled' => in_array( $variant['key'], $enabled_variants, true ),
				'count'   => $pack->icon_count( array( $variant['key'] ) ),
			);
		}

		return array(
			'slug'         => $pack->slug,
			'label'        => $pack->label,
			'description'  => $pack->description,
			'version'      => $pack->version,
			'bundled'      => $pack->bundled,
			'license'      => $pack->license,
			'upstream'     => $pack->upstream,
			'variants'     => $variants,
			'icon_count'   => count( $pack->icons ),
			'active_count' => $pack->icon_count( $enabled_variants ),
			'enabled'      => $this->options->is_pack_enabled( $pack->slug ),
		);
	}

	/**
	 * Compares two pack versions of the form "1.2.3+ii.4".
	 *
	 * @since 1.0.0
	 *
	 * @param string $candidate Version that may be newer.
	 * @param string $current   Installed version.
	 * @return bool
	 */
	private function is_newer( string $candidate, string $current ): bool {
		$normalize = static function ( string $version ): string {
			return str_replace( '+ii.', '.', $version );
		};
		return version_compare( $normalize( $candidate ), $normalize( $current ), '>' );
	}

	/**
	 * Checks whether packs can be written to the uploads directory.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function uploads_writable(): bool {
		if ( ! Downloader::downloads_supported() ) {
			return false;
		}
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}
		$dir = $this->locator->uploads_dir();
		return wp_is_writable( is_dir( $dir ) ? $dir : dirname( $dir ) );
	}
}
