<?php
/**
 * IndexClient tests.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Tests;

use InfiniteIcons\Packs\IndexClient;

/**
 * Tests the remote pack index client.
 *
 * @covers \InfiniteIcons\Packs\IndexClient
 */
final class IndexClientTest extends TestCase {

	/**
	 * A minimal, valid index entry.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function entry( array $overrides = array() ): array {
		return array_merge(
			array(
				'slug'            => 'demo',
				'label'           => 'Demo',
				'description'     => 'A demo pack.',
				'version'         => '1.0.0+ii.1',
				'icon_count'      => 2,
				'variants'        => array( '', 'solid' ),
				'license'         => 'MIT',
				'requires_plugin' => '>=1.0.0',
				'size_bytes'      => 1234,
				'sha256'          => str_repeat( 'a', 64 ),
				'url'             => 'https://github.com/owner/repo/releases/download/v1/demo.zip',
				'preview'         => array( 'heart' ),
			),
			$overrides
		);
	}

	/**
	 * Serves a canned HTTP response for the index request.
	 *
	 * @param mixed $body     Body to return; arrays are JSON encoded.
	 * @param int   $status   HTTP status.
	 * @return void
	 */
	private function fake_response( $body, int $status = 200 ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $body, $status ) {
				return array(
					'headers'  => array(),
					'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	public function tear_down(): void {
		( new IndexClient() )->flush();
		parent::tear_down();
	}

	public function test_parses_and_caches_a_valid_index(): void {
		$this->fake_response(
			array(
				'schema'    => 1,
				'generated' => '2026-09-08T00:00:00Z',
				'packs'     => array( $this->entry() ),
			)
		);

		$client = new IndexClient();
		$index  = $client->get();

		$this->assertIsArray( $index );
		$this->assertCount( 1, $index['packs'] );
		$this->assertSame( 'demo', $index['packs'][0]['slug'] );
		$this->assertIsArray( get_transient( IndexClient::TRANSIENT ) );
	}

	public function test_rejects_an_index_with_the_wrong_schema(): void {
		$this->fake_response(
			array(
				'schema' => 2,
				'packs'  => array(),
			)
		);

		$result = ( new IndexClient() )->get();

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_index_invalid', $result->get_error_code() );
	}

	public function test_rejects_a_non_json_body(): void {
		$this->fake_response( '<html>nope</html>' );

		$this->assertWPError( ( new IndexClient() )->get() );
	}

	public function test_reports_an_http_error(): void {
		$this->fake_response( '', 503 );

		$result = ( new IndexClient() )->get();

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_index_http_error', $result->get_error_code() );
	}

	public function test_drops_entries_pointing_at_a_host_that_is_not_allowed(): void {
		$this->fake_response(
			array(
				'schema' => 1,
				'packs'  => array(
					$this->entry( array( 'url' => 'https://evil.example.com/demo.zip' ) ),
					$this->entry( array( 'slug' => 'good' ) ),
				),
			)
		);

		$index = ( new IndexClient() )->get();

		$this->assertCount( 1, $index['packs'] );
		$this->assertSame( 'good', $index['packs'][0]['slug'] );
	}

	public function test_drops_entries_with_a_malformed_checksum_or_slug(): void {
		$this->fake_response(
			array(
				'schema' => 1,
				'packs'  => array(
					$this->entry( array( 'sha256' => 'not-a-hash' ) ),
					$this->entry( array( 'slug' => '../escape' ) ),
					$this->entry( array( 'url' => 'http://github.com/x.zip' ) ),
				),
			)
		);

		$this->assertSame( array(), ( new IndexClient() )->get()['packs'] );
	}

	public function test_allowed_hosts_can_be_filtered(): void {
		$this->assertFalse( IndexClient::is_allowed_download_url( 'https://example.com/a.zip' ) );

		add_filter(
			'infinite_icons_allowed_download_hosts',
			static function () {
				return array( 'example.com' );
			}
		);

		$this->assertTrue( IndexClient::is_allowed_download_url( 'https://example.com/a.zip' ) );
		$this->assertFalse( IndexClient::is_allowed_download_url( 'https://github.com/a.zip' ) );
	}

	public function test_plain_http_is_never_allowed(): void {
		add_filter(
			'infinite_icons_allowed_download_hosts',
			static function () {
				return array( 'example.com' );
			}
		);

		$this->assertFalse( IndexClient::is_allowed_download_url( 'http://example.com/a.zip' ) );
	}

	public function test_the_index_url_is_https_and_filterable(): void {
		$client = new IndexClient();
		$this->assertStringStartsWith( 'https://', $client->url() );

		add_filter(
			'infinite_icons_index_url',
			static function () {
				return 'https://example.test/index.json';
			}
		);
		$this->assertSame( 'https://example.test/index.json', ( new IndexClient() )->url() );
	}

	public function test_a_non_https_index_url_is_refused_before_any_request(): void {
		add_filter(
			'infinite_icons_index_url',
			static function () {
				return 'http://example.test/index.json';
			}
		);
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'No request should be made.' );
			}
		);

		$result = ( new IndexClient() )->get();

		$this->assertWPError( $result );
		$this->assertSame( 'infinite_icons_insecure_index', $result->get_error_code() );
	}
}
