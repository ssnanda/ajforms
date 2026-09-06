<?php
class AJCore_Reviews_Test extends WP_UnitTestCase {
	private $provider;
	private $provider_filter;
	private $http_filter;

	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) { $this->markTestSkipped( 'PHP sodium is required.' ); }
		$this->provider = new AJCore_Reviews_Fixture_Provider();
		$this->provider_filter = function() { return $this->provider; };
		add_filter( 'ajcore_reviews_provider', $this->provider_filter );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'ajcore_reviews_config', array( 'account' => 'accounts/100', 'location' => 'locations/200' ), false );
		AJCore_Reviews_Vault::write( 'ajcore_reviews_credentials', array( 'client_id' => 'fixture.apps.googleusercontent.com', 'client_secret' => 'fixture-secret-not-valid', 'refresh_token' => 'fixture-refresh-not-valid', 'access_token' => 'fixture-access-not-valid', 'expires_at' => time() + 3600 ) );
	}
	public function tear_down() {
		remove_filter( 'ajcore_reviews_provider', $this->provider_filter );
		if ( $this->http_filter ) { remove_filter( 'pre_http_request', $this->http_filter, 10 ); }
		AJCore_Reviews::unschedule();
		foreach ( array( 'snapshot', 'choices', 'oauth', 'credentials', 'config', 'selection', 'sync_meta', 'history', 'display' ) as $key ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_' . $key ); }
		$_POST = array(); $_REQUEST = array();
		parent::tear_down();
	}
	private function key( $id = 'fixture-review-1' ) { return hash( 'sha256', 'locations/200/' . $id ); }
	private function select( $id = 'fixture-review-1', $order = 0 ) { return AJCore_Reviews::locked( function() use ( $id, $order ) { return AJCore_Reviews::set_featured( $this->key( $id ), true, $order ); } ); }
	private function http( $callback ) { $this->http_filter = $callback; add_filter( 'pre_http_request', $callback, 10, 3 ); }
	private function response( $data, $status = 200 ) { return array( 'response' => array( 'code' => $status ), 'body' => wp_json_encode( $data ), 'headers' => array() ); }

	public function test_encryption_and_public_redaction() {
		$cipher = get_option( 'ajcore_reviews_credentials' );
		$this->assertStringStartsWith( 'ajenc2:', $cipher );
		$this->assertStringNotContainsString( 'fixture-secret', $cipher );
		$this->assertSame( 'fixture-secret-not-valid', AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' )['client_secret'] );
		$this->assertSame( array(), AJCore_Reviews_Vault::open( '{"access_token":"plaintext"}' ) );
		AJCore_Reviews::sync(); $this->select();
		$public = wp_json_encode( array( ajcore_get_reviews_status(), ajcore_get_review_collections(), ajcore_get_google_location_summary() ) );
		$this->assertStringNotContainsString( 'fixture-secret', $public );
		$this->assertStringNotContainsString( 'refresh_token', $public );
		$this->assertStringNotContainsString( 'client_secret', $public );
	}
	public function test_corrupt_key_fails_closed_without_rotation() {
		update_option( 'ajcore_settings_encryption_key', 'not-a-key', false );
		$this->assertWPError( AJCore_Reviews_Vault::seal( array( 'secret' => 'fixture' ) ) );
		$this->assertSame( array(), AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' ) );
		$this->assertSame( 'not-a-key', get_option( 'ajcore_settings_encryption_key' ) );
	}
	public function test_normalization_preserves_original_text_and_does_not_fabricate_links() {
		$raw = ajcore_reviews_test_review();
		$r = AJCore_Google_Review_Provider::normalize( $raw, 'locations/200', time() );
		$this->assertSame( $raw['comment'], $r['text'] );
		$this->assertSame( 3, $r['rating'] );
		$this->assertSame( '', $r['source_url'] );
		$this->assertSame( '', $r['report_url'] );
		$raw['googleMapsUri'] = 'https://example.test/review'; $raw['translatedText'] = 'Fixture translation';
		$this->assertSame( $raw['translatedText'], AJCore_Google_Review_Provider::normalize( $raw, 'locations/200', time() )['translated_text'] );
		$raw['starRating'] = 'SIX';
		$this->assertWPError( AJCore_Google_Review_Provider::normalize( $raw, 'locations/200', time() ) );
	}
	public function test_sync_deduplicates_and_requires_explicit_selection() {
		$this->provider->rows[] = ajcore_reviews_test_review();
		$this->assertSame( 1, AJCore_Reviews::sync() );
		$this->assertSame( array(), ajcore_get_featured_google_reviews() );
		$this->assertSame( 1, ajcore_get_google_review_count() );
		$this->assertTrue( $this->select() );
		$this->assertCount( 1, ajcore_get_featured_google_reviews() );
		$this->assertStringNotContainsString( 'Original fixture', get_transient( 'ajcore_reviews_snapshot' ) );
	}
	public function test_refresh_keeps_selection_but_removals_disappear() {
		AJCore_Reviews::sync(); $this->select();
		$this->provider->rows[0]['comment'] = 'Refreshed fixture text';
		AJCore_Reviews::sync();
		$this->assertSame( 'Refreshed fixture text', ajcore_get_featured_google_reviews()[0]['text'] );
		$this->provider->rows = array(); AJCore_Reviews::sync();
		$this->assertSame( array(), ajcore_get_featured_google_reviews() );
		$this->assertSame( array(), get_option( 'ajcore_reviews_selection' ) );
	}
	public function test_expiry_is_enforced_even_when_cron_has_not_run() {
		AJCore_Reviews::sync(); $this->select(); $data = AJCore_Reviews::snapshot();
		$data['retrieved_at'] = time() - AJCore_Reviews::TTL - 20; $data['expires_at'] = time() - 20;
		set_transient( 'ajcore_reviews_snapshot', AJCore_Reviews_Vault::seal( $data ), 3600 );
		$this->assertSame( array(), ajcore_get_featured_google_reviews() );
		$this->assertSame( array(), ajcore_get_google_location_summary() );
		$this->assertSame( 'expired', ajcore_get_reviews_status()['state'] );
		AJCore_Reviews::cleanup();
		$this->assertFalse( get_transient( 'ajcore_reviews_snapshot' ) );
	}
	public function test_28_day_schedule_is_idempotent_and_expiry_below_30_days() {
		AJCore_Reviews::schedule(); $event = wp_get_scheduled_event( AJCore_Reviews::SYNC );
		AJCore_Reviews::schedule();
		$this->assertSame( 28 * DAY_IN_SECONDS, $event->interval );
		$this->assertSame( $event->timestamp, wp_next_scheduled( AJCore_Reviews::SYNC ) );
		$this->assertLessThan( 30 * DAY_IN_SECONDS, AJCore_Reviews::TTL );
	}
	public function test_failed_sync_keeps_original_expiry_and_redacts_history() {
		AJCore_Reviews::sync(); $this->select(); $old = get_transient( 'ajcore_reviews_snapshot' );
		$this->provider->error = new WP_Error( 'temporary_error', 'Sensitive fixture access token and full raw response', array( 'secret' => 'fixture-secret' ) );
		$this->assertWPError( AJCore_Reviews::sync() );
		$this->assertSame( $old, get_transient( 'ajcore_reviews_snapshot' ) );
		$this->assertNotFalse( wp_next_scheduled( AJCore_Reviews::RETRY ) );
		$this->assertTrue( ajcore_get_reviews_status()['stale'] );
		$history = wp_json_encode( get_option( 'ajcore_reviews_history' ) );
		$this->assertStringNotContainsString( 'Sensitive', $history );
		$this->assertStringNotContainsString( 'fixture-secret', $history );
		$this->assertStringNotContainsString( 'Original fixture', $history );
	}
	public function test_retries_back_off_and_stop_after_five() {
		$this->provider->error = new WP_Error( 'temporary_error' );
		for ( $attempt = 1; $attempt <= 6; ++$attempt ) {
			wp_clear_scheduled_hook( AJCore_Reviews::RETRY );
			AJCore_Reviews::sync( $attempt === 1 ? 'scheduled' : 'retry' );
			$next = wp_next_scheduled( AJCore_Reviews::RETRY );
			if ( $attempt <= 5 ) { $this->assertGreaterThanOrEqual( time() + 900 * ( 2 ** ( $attempt - 1 ) ) - 2, $next ); }
			else { $this->assertFalse( $next ); }
		}
	}
	public function test_lock_prevents_reentrant_sync_and_releases_after_exception() {
		$this->assertSame( 'busy', AJCore_Reviews::locked( function() { return AJCore_Reviews::sync(); } )->get_error_code() );
		$this->assertWPError( AJCore_Reviews::locked( function() { throw new RuntimeException( 'Sensitive fixture' ); } ) );
		$this->assertSame( 1, AJCore_Reviews::sync() );
	}
	public function test_a_second_database_connection_cannot_sync_concurrently() {
		global $wpdb;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name = 'ajcore_reviews_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
		try {
			$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
			$this->assertSame( 'busy', AJCore_Reviews::sync()->get_error_code() );
		} finally { $other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); $other->close(); }
	}
	public function test_oauth_state_is_user_session_bound_and_single_use() {
		$state = str_repeat( 'a', 64 );
		$pending = array( 'hash' => hash( 'sha256', $state ), 'verifier' => str_repeat( 'b', 64 ), 'user' => get_current_user_id(), 'session' => hash( 'sha256', wp_get_session_token() ), 'expires_at' => time() + 600 );
		AJCore_Reviews_Vault::write( 'ajcore_reviews_oauth', $pending );
		$this->assertFalse( AJCore_Reviews_Admin::valid_state( $pending, $state, get_current_user_id() + 1, wp_get_session_token(), time() ) );
		$this->assertFalse( AJCore_Reviews_Admin::valid_state( $pending, $state, get_current_user_id(), 'wrong-session', time() ) );
		$this->assertFalse( AJCore_Reviews_Admin::valid_state( $pending, $state, get_current_user_id(), wp_get_session_token(), time() + 601 ) );
		$this->assertTrue( AJCore_Reviews_Admin::complete_oauth( array( 'state' => $state, 'code' => 'fixture-code' ) ) );
		$this->assertSame( 'oauth_state_invalid', AJCore_Reviews_Admin::complete_oauth( array( 'state' => $state, 'code' => 'fixture-code' ) )->get_error_code() );
		$this->assertSame( 1, $this->provider->exchanges );
	}
	public function test_admin_permission_required() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( 'WPDieException' );
		AJCore_Reviews_Admin::action();
	}
	public function test_admin_nonce_required_before_mutation() {
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( 'operation' => 'disconnect' ); $_REQUEST = $_POST;
		$this->expectException( 'WPDieException' );
		AJCore_Reviews_Admin::action();
	}
	public function test_editor_cannot_moderate_and_bad_records_are_excluded() {
		AJCore_Reviews::sync(); $this->select();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertWPError( $this->select() );
		$data = AJCore_Reviews::snapshot(); $data['reviews'][$this->key()]['rating'] = 99;
		AJCore_Reviews_Vault::write( 'ajcore_reviews_snapshot', $data );
		$this->assertSame( array(), ajcore_get_featured_google_reviews() );
	}
	public function test_disconnect_erases_local_data_even_if_revocation_fails() {
		AJCore_Reviews::sync(); $this->select();
		$post = self::factory()->post->create( array( 'post_type' => AJCore_Testimonials::TYPE, 'post_status' => 'publish' ) );
		$this->provider->error = new WP_Error( 'temporary_error' );
		$this->assertWPError( AJCore_Reviews::locked( array( 'AJCore_Reviews', 'disconnect' ) ) );
		$this->assertFalse( get_option( 'ajcore_reviews_credentials' ) );
		$this->assertFalse( get_transient( 'ajcore_reviews_snapshot' ) );
		$this->assertFalse( wp_next_scheduled( AJCore_Reviews::SYNC ) );
		$this->assertNotNull( get_post( $post ) );
	}
	public function test_manual_query_only_returns_published_featured_records_without_notes() {
		foreach ( array( 'publish', 'draft', 'private' ) as $status ) {
			$id = self::factory()->post->create( array( 'post_type' => AJCore_Testimonials::TYPE, 'post_status' => $status, 'post_title' => 'Fixture author', 'post_content' => 'Permission-based fixture' ) );
			update_post_meta( $id, AJCore_Testimonials::META, array( 'featured' => true, 'rating' => 4, 'notes' => 'Internal fixture note' ) );
		}
		$this->assertCount( 1, ajcore_get_featured_testimonials() );
		$this->assertArrayNotHasKey( 'notes', ajcore_get_featured_testimonials()[0] );
		$this->assertFalse( get_post_type_object( AJCore_Testimonials::TYPE )->publicly_queryable );
		$this->assertFalse( get_post_type_object( AJCore_Testimonials::TYPE )->show_in_rest );
	}
	public function test_rest_status_denies_anonymous_and_never_returns_secrets() {
		wp_set_current_user( 0 );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ajcore/v1/reviews/status' ) );
		$this->assertSame( 401, $response->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ajcore/v1/reviews/status' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'fixture-secret', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
	}
	public function test_google_provider_paginates_and_never_publishes_partial_data() {
		$count = 0;
		$this->http( function( $pre, $args, $url ) use ( &$count ) {
			$this->assertSame( 0, $args['redirection'] );
			if ( strpos( $url, 'mybusinessbusinessinformation.googleapis.com' ) !== false ) { return $this->response( array( 'name' => 'locations/200', 'title' => 'Fixture' ) ); }
			++$count;
			if ( $count === 1 ) { return $this->response( array( 'reviews' => array( ajcore_reviews_test_review() ), 'averageRating' => 3, 'totalReviewCount' => 2, 'nextPageToken' => 'fixture-page-2' ) ); }
			$this->assertStringContainsString( 'fixture-page-2', $url );
			return $this->response( array( 'error' => 'fixture-private-body' ), 503 );
		} );
		$result = AJCore_Reviews::locked( function() { return ( new AJCore_Google_Review_Provider() )->fetch( 'accounts/100', 'locations/200' ); } );
		$this->assertSame( 'temporary_error', $result->get_error_code() );
		$this->assertSame( 2, $count );
		$this->assertSame( '', $result->get_error_message() );
	}
	public function test_refresh_token_is_used_server_side_and_saved_encrypted() {
		$c = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' ); $c['expires_at'] = 1; AJCore_Reviews_Vault::write( 'ajcore_reviews_credentials', $c );
		$this->http( function( $pre, $args, $url ) {
			if ( $url === 'https://oauth2.googleapis.com/token' ) {
				$this->assertSame( 'refresh_token', $args['body']['grant_type'] );
				$this->assertSame( 'fixture-refresh-not-valid', $args['body']['refresh_token'] );
				return $this->response( array( 'access_token' => 'fixture-renewed-not-valid', 'expires_in' => 3600 ) );
			}
			$this->assertSame( 'Bearer fixture-renewed-not-valid', $args['headers']['Authorization'] );
			return $this->response( array( 'accounts' => array() ) );
		} );
		$this->assertSame( array(), AJCore_Reviews::locked( function() { return ( new AJCore_Google_Review_Provider() )->accounts(); } ) );
		$this->assertStringNotContainsString( 'fixture-renewed', get_option( 'ajcore_reviews_credentials' ) );
	}
}
