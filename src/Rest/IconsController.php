<?php
/**
 * Icon search endpoint.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Rest;

use InfiniteIcons\Packs\Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the icon picker.
 *
 * Core's own icons endpoint returns every registered icon with its markup,
 * which for twenty thousand icons is far too much to send. This one searches
 * names, labels and keywords and returns a page of results.
 *
 * @since 1.0.0
 */
final class IconsController extends \WP_REST_Controller {

	/**
	 * Largest page a client may ask for.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_PER_PAGE = 120;

	/**
	 * Registrar holding the icon lookup map.
	 *
	 * @since 1.0.0
	 * @var Registrar
	 */
	private $registrar;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Registrar $registrar Registrar.
	 */
	public function __construct( Registrar $registrar ) {
		$this->registrar = $registrar;
		$this->namespace = 'infinite-icons/v1';
		$this->rest_base = 'icons';
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
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks the caller may browse icons.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new \WP_Error(
			'infinite_icons_forbidden',
			__( 'You are not allowed to browse icons.', 'infinite-icons' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns a page of matching icons.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$search     = (string) $request->get_param( 'search' );
		$collection = (string) $request->get_param( 'collection' );
		$variant    = $request->get_param( 'variant' );
		$page       = max( 1, (int) $request->get_param( 'page' ) );
		$per_page   = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$split   = preg_split( '/[\s,]+/', strtolower( trim( $search ) ) );
		$tokens  = array_filter( is_array( $split ) ? $split : array() );
		$matches = array();

		foreach ( $this->registrar->registered_icons() as $name => $icon ) {
			if ( '' !== $collection && $icon['pack'] !== $collection ) {
				continue;
			}
			if ( null !== $variant && (string) $variant !== $icon['variant'] ) {
				continue;
			}
			if ( $tokens && ! $this->matches( $name, $icon, $tokens ) ) {
				continue;
			}
			$matches[ $name ] = $icon;
		}

		$total  = count( $matches );
		$pages  = (int) ceil( $total / $per_page );
		$offset = ( $page - 1 ) * $per_page;

		$items = array();
		foreach ( array_slice( $matches, $offset, $per_page, true ) as $name => $icon ) {
			$items[] = array(
				'name'       => $name,
				'label'      => $icon['label'],
				'collection' => $icon['pack'],
				'variant'    => $icon['variant'],
				// Rendered through core so the markup is exactly what will be
				// output, already sanitized.
				'content'    => wp_get_icon( $name, array( 'size' => null ) ),
			);
		}

		$response = rest_ensure_response(
			array(
				'total' => $total,
				'pages' => $pages,
				'items' => $items,
			)
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );
		return $response;
	}

	/**
	 * Checks an icon against every search token.
	 *
	 * All tokens must match somewhere, so "arrow left" narrows rather than widens.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $name   Qualified icon name.
	 * @param array<string, mixed> $icon   Icon entry.
	 * @param array<int, string>   $tokens Lower-case search tokens.
	 * @return bool
	 */
	private function matches( string $name, array $icon, array $tokens ): bool {
		$haystack = strtolower(
			$name . ' ' . str_replace( '-', ' ', $name ) . ' ' . $icon['label'] . ' ' . implode( ' ', $icon['keywords'] )
		);
		foreach ( $tokens as $token ) {
			if ( false === strpos( $haystack, $token ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Describes the query parameters.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'search'     => array(
				'description'       => __( 'Words to match against icon names, labels and keywords.', 'infinite-icons' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'collection' => array(
				'description'       => __( 'Limit results to one pack.', 'infinite-icons' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'variant'    => array(
				'description'       => __( 'Limit results to one variant. Pass an empty string for the default variant.', 'infinite-icons' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'page'       => array(
				'description'       => __( 'Page of results to return.', 'infinite-icons' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'   => array(
				'description'       => __( 'Number of icons per page.', 'infinite-icons' ),
				'type'              => 'integer',
				'default'           => 60,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Describes one icon.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'infinite-icon',
			'type'       => 'object',
			'properties' => array(
				'name'       => array(
					'description' => __( 'Qualified icon name, for example "lucide/heart".', 'infinite-icons' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'label'      => array(
					'description' => __( 'Human-readable icon name.', 'infinite-icons' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'collection' => array(
					'description' => __( 'Pack the icon belongs to.', 'infinite-icons' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'variant'    => array(
					'description' => __( 'Variant key, empty for the default variant.', 'infinite-icons' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'content'    => array(
					'description' => __( 'Sanitized SVG markup.', 'infinite-icons' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
