<?php
/**
 * REST endpoint tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\Downloader;
use InfiniteIcons\Packs\IndexClient;
use InfiniteIcons\Packs\PackLocator;
use InfiniteIcons\Packs\Registrar;
use InfiniteIcons\Rest\IconsController;
use InfiniteIcons\Rest\PacksController;
use InfiniteIcons\Settings\Options;

/**
 * Tests the plugin's REST endpoints.
 *
 * @covers \InfiniteIcons\Rest\PacksController
 * @covers \InfiniteIcons\Rest\IconsController
 */
final class RestControllersTest extends TestCase {

	/**
	 * Directory standing in for the bundled packs.
	 *
	 * @var string
	 */
	private $bundled = '';

	/**
	 * Directory standing in for uploads/infinite-icons.
	 *
	 * @var string
	 */
	private $uploads = '';

	public function set_up(): void {
		parent::set_up();
		$this->bundled = $this->make_temp_dir( 'ii-bundled-' );
		$this->uploads = $this->make_temp_dir( 'ii-uploads-' );

		// No network in tests: the index is always unavailable unless a test
		// says otherwise, which also exercises the error path in the payload.
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Offline.' );
			}
		);
	}

	/**
	 * Builds a REST server holding only this test's routes.
	 *
	 * Called by each test after it has written its packs: the controllers scan
	 * the pack directories as they are constructed, so booting earlier would
	 * register an empty set. The plugin's own controllers are dropped first,
	 * because they point at the real packs directory and would otherwise make
	 * assertions depend on whatever is installed on the machine running them.
	 *
	 * @return void
	 */
	private function boot(): void {
		remove_all_actions( 'rest_api_init' );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	public function tear_down(): void {
		( new IndexClient() )->flush();
		parent::tear_down();
	}

	/**
	 * Registers the controllers against the temporary directories.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$options = new Options();
		$locator = new PackLocator( null, $this->bundled, $this->uploads );
		$index   = new IndexClient();

		$registrar = new Registrar( $locator, $options );
		$registrar->register_packs();

		( new PacksController( $locator, $index, new Downloader( $locator, $index, $options ), $options ) )->register_routes();
		( new IconsController( $registrar ) )->register_routes();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $params Request parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/infinite-icons/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Creates a user of the given role and signs them in.
	 *
	 * @param string $role Role name.
	 * @return int User ID.
	 */
	private function login( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user );
		return $user;
	}

	public function test_managing_packs_requires_manage_options(): void {
		$this->boot();
		$this->login( 'editor' );

		foreach ( array(
			array( 'GET', '/packs' ),
			array( 'POST', '/packs/install' ),
			array( 'POST', '/packs/refresh-index' ),
			array( 'POST', '/packs/settings' ),
			array( 'DELETE', '/packs/demo' ),
		) as list( $method, $route ) ) {
			$response = $this->dispatch( $method, $route, array( 'slug' => 'demo' ) );
			$this->assertSame( 403, $response->get_status(), "$method $route" );
		}
	}

	public function test_browsing_icons_requires_edit_posts(): void {
		$this->boot();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->dispatch( 'GET', '/icons' )->get_status() );

		$this->login( 'subscriber' );
		$this->assertSame( 403, $this->dispatch( 'GET', '/icons' )->get_status() );

		$this->login( 'contributor' );
		$this->assertSame( 200, $this->dispatch( 'GET', '/icons' )->get_status() );
	}

	public function test_packs_payload_describes_installed_packs(): void {
		$this->make_pack(
			$this->bundled,
			'demo',
			array(
				array( 'name' => 'heart' ),
				array(
					'name'    => 'heart-solid',
					'variant' => 'solid',
				),
			),
			array(
				'variants' => array(
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
			)
		);
		$this->boot();
		$this->login( 'administrator' );

		$data = $this->dispatch( 'GET', '/packs' )->get_data();

		$this->assertCount( 1, $data['installed'] );
		$this->assertSame( 'demo', $data['installed'][0]['slug'] );
		$this->assertTrue( $data['installed'][0]['bundled'] );
		$this->assertSame( 2, $data['installed'][0]['icon_count'] );
		$this->assertSame( 1, $data['installed'][0]['active_count'] );
		$this->assertArrayHasKey( 'settings', $data );
		$this->assertArrayHasKey( 'previewBase', $data );
	}

	public function test_an_unreachable_index_is_reported_without_failing_the_request(): void {
		$this->boot();
		$this->login( 'administrator' );

		$response = $this->dispatch( 'GET', '/packs' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $data['available'] );
		$this->assertNotNull( $data['index']['error'] );
	}

	public function test_settings_are_saved_and_sanitized(): void {
		$this->make_pack( $this->bundled, 'demo', array( array( 'name' => 'heart' ) ) );
		$this->boot();
		$this->login( 'administrator' );

		$this->dispatch(
			'POST',
			'/packs/settings',
			array(
				'enabled_packs'            => array(
					'demo'       => false,
					'Not A Slug' => true,
				),
				'remove_data_on_uninstall' => true,
			)
		);

		$options = new Options();
		$this->assertFalse( $options->is_pack_enabled( 'demo' ) );
		$this->assertArrayNotHasKey( 'Not A Slug', (array) $options->get( 'enabled_packs', array() ) );
		$this->assertTrue( (bool) $options->get( 'remove_data_on_uninstall' ) );
	}

	public function test_a_bundled_pack_cannot_be_deleted(): void {
		$this->make_pack( $this->bundled, 'demo', array( array( 'name' => 'heart' ) ) );
		$this->boot();
		$this->login( 'administrator' );

		$response = $this->dispatch( 'DELETE', '/packs/demo', array( 'slug' => 'demo' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'infinite_icons_pack_bundled', $response->get_data()['code'] );
		$this->assertDirectoryExists( $this->bundled . '/demo' );
	}

	public function test_icon_search_matches_names_labels_and_keywords(): void {
		$this->make_pack(
			$this->bundled,
			'demo',
			array(
				array(
					'name'     => 'heart',
					'label'    => 'Heart',
					'keywords' => array( 'love', 'favourite' ),
				),
				array(
					'name'  => 'arrow-left',
					'label' => 'Arrow Left',
				),
			)
		);
		$this->boot();
		$this->login( 'administrator' );

		$by_keyword = $this->dispatch( 'GET', '/icons', array( 'search' => 'favourite' ) )->get_data();
		$this->assertSame( 1, $by_keyword['total'] );
		$this->assertSame( 'demo/heart', $by_keyword['items'][0]['name'] );
		$this->assertStringContainsString( '<svg', $by_keyword['items'][0]['content'] );

		// Hyphenated names are searchable as separate words.
		$by_words = $this->dispatch( 'GET', '/icons', array( 'search' => 'arrow left' ) )->get_data();
		$this->assertSame( 1, $by_words['total'] );

		// Every token has to match, so this narrows to nothing.
		$narrow = $this->dispatch( 'GET', '/icons', array( 'search' => 'heart arrow' ) )->get_data();
		$this->assertSame( 0, $narrow['total'] );
	}

	public function test_icon_search_paginates(): void {
		$icons = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$icons[] = array( 'name' => 'icon-' . $i );
		}
		$this->make_pack( $this->bundled, 'demo', $icons );
		$this->boot();
		$this->login( 'administrator' );

		$first = $this->dispatch( 'GET', '/icons', array( 'per_page' => 10 ) )->get_data();
		$this->assertSame( 25, $first['total'] );
		$this->assertSame( 3, $first['pages'] );
		$this->assertCount( 10, $first['items'] );

		$last = $this->dispatch(
			'GET',
			'/icons',
			array(
				'per_page' => 10,
				'page'     => 3,
			)
		)->get_data();
		$this->assertCount( 5, $last['items'] );
	}

	public function test_icon_search_filters_by_collection(): void {
		$this->make_pack( $this->bundled, 'demo', array( array( 'name' => 'heart' ) ) );
		$this->make_pack( $this->bundled, 'other', array( array( 'name' => 'heart' ) ) );
		$this->boot();
		$this->login( 'administrator' );

		$all = $this->dispatch( 'GET', '/icons' )->get_data();
		$this->assertSame( 2, $all['total'] );

		$one = $this->dispatch( 'GET', '/icons', array( 'collection' => 'other' ) )->get_data();
		$this->assertSame( 1, $one['total'] );
		$this->assertSame( 'other/heart', $one['items'][0]['name'] );
	}

	public function test_install_rejects_a_malformed_slug(): void {
		$this->boot();
		$this->login( 'administrator' );

		$response = $this->dispatch( 'POST', '/packs/install', array( 'slug' => 'Not A Slug' ) );

		$this->assertSame( 400, $response->get_status() );
	}
}
