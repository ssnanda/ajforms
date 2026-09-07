<?php
defined( 'ABSPATH' ) || exit;

final class AJCore_Reviews_Admin {
	public static function init() {
		add_action( 'admin_menu', function() { add_submenu_page( 'ajforms', __( 'Reviews & Testimonials', 'ajcore' ), __( 'Reviews & Testimonials', 'ajcore' ), 'manage_options', 'ajcore-reviews', array( __CLASS__, 'page' ) ); }, 30 );
		add_action( 'admin_post_ajcore_reviews_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_ajcore_reviews_oauth', array( __CLASS__, 'oauth' ) );
	}

	private static function value( $key, $source = null ) {
		$source = $source === null ? $_POST : $source;
		return isset( $source[$key] ) && is_string( $source[$key] ) ? wp_unslash( $source[$key] ) : '';
	}
	public static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage reviews.', 'ajcore' ), '', array( 'response' => 403 ) ); }
	}
	private static function url( $tab = 'overview' ) { return add_query_arg( array( 'page' => 'ajcore-reviews', 'tab' => $tab ), admin_url( 'admin.php' ) ); }
	private static function finish( $result, $tab = 'connection' ) {
		$code = is_wp_error( $result ) ? AJCore_Reviews::safe_code( $result->get_error_code() ) : 'success';
		wp_safe_redirect( add_query_arg( 'result', $code, self::url( $tab ) ) ); exit;
	}

	public static function action() {
		self::authorize();
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { wp_die( esc_html__( 'A POST request is required.', 'ajcore' ), '', array( 'response' => 405 ) ); }
		check_admin_referer( 'ajcore_reviews_action' );
		$operation = sanitize_key( self::value( 'operation' ) );
		if ( $operation === 'sync' ) { self::finish( AJCore_Reviews::sync() ); }
		$result = AJCore_Reviews::locked( function() use ( $operation ) {
			switch ( $operation ) {
				case 'credentials':
					$id = trim( self::value( 'client_id' ) ); $secret = trim( self::value( 'client_secret' ) );
					if ( ! preg_match( '/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D', $id ) || strlen( $secret ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $secret ) ) { return new WP_Error( 'credentials_required' ); }
					$old = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' );
					if ( $secret === '' ) { $secret = $old['client_secret'] ?? ''; }
					if ( $secret === '' ) { return new WP_Error( 'credentials_required' ); }
					$data = array( 'client_id' => $id, 'client_secret' => $secret );
					$sealed = AJCore_Reviews_Vault::seal( $data );
					if ( is_wp_error( $sealed ) ) { return $sealed; }
					if ( $id === ( $old['client_id'] ?? '' ) && $secret === ( $old['client_secret'] ?? '' ) ) { return true; }
					AJCore_Reviews::disconnect();
					return AJCore_Reviews_Vault::write( 'ajcore_reviews_credentials', $data );
				case 'connect':
					$state = bin2hex( random_bytes( 32 ) ); $verifier = bin2hex( random_bytes( 32 ) );
					$data = array( 'hash' => hash( 'sha256', $state ), 'verifier' => $verifier, 'user' => get_current_user_id(), 'session' => hash( 'sha256', wp_get_session_token() ), 'expires_at' => time() + 600 );
					$result = AJCore_Reviews_Vault::write( 'ajcore_reviews_oauth', $data );
					if ( is_wp_error( $result ) ) { return $result; }
					$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
					$url = AJCore_Reviews::provider()->authorization_url( $state, $challenge );
					return is_wp_error( $url ) ? $url : array( 'redirect' => $url );
				case 'disconnect': return AJCore_Reviews::disconnect();
				case 'accounts':
					$accounts = AJCore_Reviews::provider()->accounts();
					return is_wp_error( $accounts ) ? $accounts : AJCore_Reviews_Vault::write( 'ajcore_reviews_choices', array( 'accounts' => $accounts, 'expires_at' => time() + 900 ) );
				case 'locations':
					$choices = self::choices(); $account = self::value( 'account' );
					if ( ! in_array( $account, array_column( $choices['accounts'] ?? array(), 'name' ), true ) ) { return new WP_Error( 'invalid_location' ); }
					$locations = AJCore_Reviews::provider()->locations( $account );
					if ( is_wp_error( $locations ) ) { return $locations; }
					return AJCore_Reviews_Vault::write( 'ajcore_reviews_choices', array( 'accounts' => $choices['accounts'], 'account' => $account, 'locations' => $locations, 'expires_at' => time() + 900 ) );
				case 'location':
					$choices = self::choices(); $location = self::value( 'location' );
					if ( empty( $choices['account'] ) || ! in_array( $location, array_column( $choices['locations'] ?? array(), 'name' ), true ) || ! preg_match( '#^locations/[0-9]+$#D', $location ) ) { return new WP_Error( 'invalid_location' ); }
					if ( AJCore_Reviews::config() !== array( 'account' => $choices['account'], 'location' => $location ) ) {
						foreach ( array( 'snapshot', 'selection', 'sync_meta' ) as $key ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_' . $key ); }
						AJCore_Reviews::unschedule();
						update_option( 'ajcore_reviews_config', array( 'account' => $choices['account'], 'location' => $location ), false );
						do_action( 'ajcore_reviews_content_changed' );
					}
					AJCore_Reviews::schedule(); return true;
				case 'test':
					$config = AJCore_Reviews::config();
					if ( empty( $config['account'] ) || empty( $config['location'] ) ) { return new WP_Error( 'invalid_location' ); }
					$locations = AJCore_Reviews::provider()->locations( $config['account'] );
					if ( is_wp_error( $locations ) ) { return $locations; }
					return in_array( $config['location'], array_column( $locations, 'name' ), true ) ? true : new WP_Error( 'invalid_location' );
				case 'feature':
					return AJCore_Reviews::set_featured( self::value( 'key' ), self::value( 'featured' ) === '1', (int) self::value( 'order' ) );
				case 'display':
					$display = array( 'fallback' => sanitize_text_field( self::value( 'fallback' ) ), 'order' => self::value( 'order' ) === 'date' ? 'date' : 'manual' );
					$prompt = ajcore_sanitize_review_prompt_settings( array( 'prompt_enabled' => self::value( 'prompt_enabled' ) === '1', 'prompt_label' => self::value( 'prompt_label' ), 'feedback_url' => self::value( 'feedback_url' ), 'google_review_url' => self::value( 'google_review_url' ) ) );
					update_option( 'ajcore_reviews_display', array_merge( $display, $prompt ), false );
					do_action( 'ajcore_reviews_content_changed' ); return true;
			}
			return new WP_Error( 'operation_failed' );
		} );
		if ( is_array( $result ) && isset( $result['redirect'] ) && wp_parse_url( $result['redirect'], PHP_URL_HOST ) === 'accounts.google.com' && wp_parse_url( $result['redirect'], PHP_URL_SCHEME ) === 'https' ) { wp_redirect( $result['redirect'] ); exit; }
		self::finish( $result, $operation === 'feature' ? 'inbox' : ( $operation === 'display' ? 'display' : 'connection' ) );
	}

	public static function valid_state( $pending, $state, $user, $session, $now ) {
		return is_array( $pending ) && is_string( $state ) && strlen( $state ) === 64 && ! empty( $pending['hash'] ) && ! empty( $pending['session'] ) && ( $pending['user'] ?? 0 ) === $user && ( $pending['expires_at'] ?? 0 ) > $now && hash_equals( $pending['hash'], hash( 'sha256', $state ) ) && hash_equals( $pending['session'], hash( 'sha256', $session ) );
	}

	public static function oauth() {
		self::authorize(); nocache_headers(); header( 'Referrer-Policy: no-referrer' );
		self::finish( self::complete_oauth( $_GET ) );
	}

	public static function complete_oauth( $query ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'access_denied' ); }
		return AJCore_Reviews::locked( function() use ( $query ) {
			$pending = AJCore_Reviews_Vault::read( 'ajcore_reviews_oauth' );
			if ( ! self::valid_state( $pending, self::value( 'state', $query ), get_current_user_id(), wp_get_session_token(), time() ) ) { return new WP_Error( 'oauth_state_invalid' ); }
			AJCore_Reviews_Vault::delete( 'ajcore_reviews_oauth' ); // Single use, including denial and failed exchange.
			$code = self::value( 'code', $query );
			if ( $code === '' || strlen( $code ) > 4096 || self::value( 'error', $query ) !== '' ) { return new WP_Error( 'authorization_failed' ); }
			$result = AJCore_Reviews::provider()->exchange( $code, $pending['verifier'] );
			if ( ! is_wp_error( $result ) ) {
				foreach ( array( 'snapshot', 'selection', 'choices', 'config', 'sync_meta' ) as $key ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_' . $key ); }
				AJCore_Reviews::unschedule(); do_action( 'ajcore_reviews_content_changed' );
			}
			return $result;
		} );
	}

	private static function choices() { $data = AJCore_Reviews_Vault::read( 'ajcore_reviews_choices' ); return ( $data['expires_at'] ?? 0 ) > time() ? $data : array(); }
	private static function form( $operation ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ajcore_reviews_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		wp_nonce_field( 'ajcore_reviews_action' );
	}
	private static function button( $operation, $label ) { self::form( $operation ); submit_button( $label, 'secondary' ); echo '</form>'; }
	private static function time( $timestamp ) { return $timestamp ? wp_date( 'Y-m-d H:i T', $timestamp ) : __( 'Not available', 'ajcore' ); }

	public static function page() {
		self::authorize();
		$tabs = array( 'overview' => __( 'Overview', 'ajcore' ), 'connection' => __( 'Google Connection', 'ajcore' ), 'inbox' => __( 'Review Inbox', 'ajcore' ), 'featured' => __( 'Featured Reviews', 'ajcore' ), 'manual' => __( 'Manual Testimonials', 'ajcore' ), 'display' => __( 'Display Settings', 'ajcore' ), 'history' => __( 'Sync History', 'ajcore' ) );
		$tab = sanitize_key( self::value( 'tab', $_GET ) ); if ( ! isset( $tabs[$tab] ) ) { $tab = 'overview'; }
		echo '<div class="wrap"><h1>' . esc_html__( 'Reviews & Testimonials', 'ajcore' ) . '</h1><nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Reviews sections', 'ajcore' ) . '">';
		foreach ( $tabs as $key => $label ) { echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav><h2>' . esc_html( $tabs[$tab] ) . '</h2>';
		if ( self::value( 'result', $_GET ) ) { echo '<div class="notice notice-info"><p>' . esc_html( self::message( AJCore_Reviews::safe_code( self::value( 'result', $_GET ) ) ) ) . '</p></div>'; }
		if ( $tab === 'overview' || $tab === 'connection' ) { self::overview(); }
		if ( $tab === 'connection' ) { self::connection(); }
		if ( $tab === 'inbox' || $tab === 'featured' ) { self::inbox( $tab === 'featured' ); }
		if ( $tab === 'manual' ) {
			echo '<p>' . esc_html__( 'Permanent, administrator-managed content. Use the title as the display name, publish the testimonial, and mark it Featured to display it. Google reviews are never converted into testimonials.', 'ajcore' ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=ajcore_testimonial' ) ) . '">' . esc_html__( 'Manage Manual Testimonials', 'ajcore' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=ajcore_testimonial' ) ) . '">' . esc_html__( 'Add Manual Testimonial', 'ajcore' ) . '</a></p>';
		}
		if ( $tab === 'display' ) {
			$data = ajcore_get_reviews_display_settings(); self::form( 'display' );
			echo '<p><label>' . esc_html__( 'Frontend fallback (empty by default)', 'ajcore' ) . '<br><input class="large-text" name="fallback" value="' . esc_attr( $data['fallback'] ) . '"></label></p><p><label>' . esc_html__( 'Default featured ordering', 'ajcore' ) . ' <select name="order"><option value="manual" ' . selected( $data['order'], 'manual', false ) . '>' . esc_html__( 'Business display order', 'ajcore' ) . '</option><option value="date" ' . selected( $data['order'], 'date', false ) . '>' . esc_html__( 'Publication date, newest first', 'ajcore' ) . '</option></select></label></p>';
			$prompt = ajcore_sanitize_review_prompt_settings( get_option( 'ajcore_reviews_display', array() ) );
			echo '<h3>' . esc_html__( 'Rate Us header prompt', 'ajcore' ) . '</h3><p>' . esc_html__( 'In AJNanda, stars 1–4 open the private feedback page and star 5 opens the Google review link. The rating is not submitted to either destination. Feedback is not automatically published as a testimonial.', 'ajcore' ) . '</p>';
			echo '<p><label><input type="checkbox" name="prompt_enabled" value="1" ' . checked( $prompt['prompt_enabled'], true, false ) . '> ' . esc_html__( 'Enable the Rate Us header prompt', 'ajcore' ) . '</label></p>';
			foreach ( array( 'prompt_label' => __( 'Prompt label (defaults to Rate Us)', 'ajcore' ), 'feedback_url' => __( 'Private feedback page URL (HTTPS)', 'ajcore' ), 'google_review_url' => __( 'Google Write a Review URL (HTTPS; optional override)', 'ajcore' ) ) as $key => $label ) {
				echo '<p><label>' . esc_html( $label ) . '<br><input class="large-text" type="' . ( $key === 'prompt_label' ? 'text' : 'url' ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $prompt[$key] ) . '"></label></p>';
			}
			echo '<p>' . esc_html__( 'Without a Google URL override, the current synchronized location supplies its Write a Review link. The prompt stays hidden unless both destinations are available. AJNanda displays it automatically; other themes must use the documented PHP integration. These links do not create or connect a submission form.', 'ajcore' ) . '</p>';
			submit_button(); echo '</form>';
		}
		if ( $tab === 'history' ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'ajcore' ) . '</th><th>' . esc_html__( 'Result', 'ajcore' ) . '</th><th>' . esc_html__( 'Reviews', 'ajcore' ) . '</th><th>' . esc_html__( 'Seconds', 'ajcore' ) . '</th><th>' . esc_html__( 'Trigger', 'ajcore' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( (array) get_option( 'ajcore_reviews_history', array() ) ) as $row ) { echo '<tr><td>' . esc_html( self::time( $row['timestamp'] ) ) . '</td><td>' . esc_html( self::message( $row['status'] ) ) . ' <code>' . esc_html( $row['status'] ) . '</code></td><td>' . (int) $row['count'] . '</td><td>' . esc_html( $row['duration'] ) . '</td><td>' . esc_html( $row['trigger'] ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private static function overview() {
		$status = AJCore_Reviews::status(); $meta = (array) get_option( 'ajcore_reviews_sync_meta', array() );
		echo '<dl><dt>' . esc_html__( 'Connection and content status', 'ajcore' ) . '</dt><dd>' . esc_html( $status['state'] ) . ( $status['stale'] ? ' — ' . esc_html__( 'Stale: refresh needed', 'ajcore' ) : '' ) . '</dd><dt>' . esc_html__( 'Valid synchronized reviews', 'ajcore' ) . '</dt><dd>' . (int) $status['valid_count'] . '</dd><dt>' . esc_html__( 'Last successful sync', 'ajcore' ) . '</dt><dd>' . esc_html( self::time( $status['last_success'] ) ) . '</dd><dt>' . esc_html__( 'Next scheduled sync', 'ajcore' ) . '</dt><dd>' . esc_html( self::time( wp_next_scheduled( AJCore_Reviews::SYNC ) ) ) . '</dd><dt>' . esc_html__( 'Next retry', 'ajcore' ) . '</dt><dd>' . esc_html( self::time( wp_next_scheduled( AJCore_Reviews::RETRY ) ) ) . '</dd><dt>' . esc_html__( 'Content expires', 'ajcore' ) . '</dt><dd>' . esc_html( self::time( $status['expires_at'] ) ) . '</dd></dl>';
		if ( ! empty( $meta['last_error'] ) ) { echo '<p>' . esc_html__( 'Last error:', 'ajcore' ) . ' ' . esc_html( self::message( $meta['last_error'] ) ) . '</p>'; }
		echo '<p>' . esc_html__( 'Refresh runs every 28 days. Selected reviews are disclosed as business-selected. Read docs/reviews-testimonials.md for Google access requirements, retention, cache exclusions, and policy limitations.', 'ajcore' ) . '</p>';
	}

	private static function connection() {
		$c = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' ); $config = AJCore_Reviews::config();
		echo '<p>' . esc_html__( 'Authorized redirect URI:', 'ajcore' ) . ' <code>' . esc_html( AJCore_Google_Review_Provider::redirect_uri() ) . '</code></p><p>' . esc_html__( 'Authorized Google account:', 'ajcore' ) . ' ' . esc_html( $c['email'] ?? __( 'Not identified', 'ajcore' ) ) . '</p><p>' . esc_html__( 'Selected business / location:', 'ajcore' ) . ' <code>' . esc_html( ( $config['account'] ?? '' ) . ' / ' . ( $config['location'] ?? '' ) ) . '</code></p>';
		self::form( 'credentials' );
		echo '<p><label>' . esc_html__( 'OAuth client ID', 'ajcore' ) . '<br><input class="large-text" name="client_id" value="' . esc_attr( $c['client_id'] ?? '' ) . '" required autocomplete="off"></label></p><p><label>' . esc_html__( 'OAuth client secret (leave empty to retain the saved secret)', 'ajcore' ) . '<br><input class="regular-text" type="password" name="client_secret" value="" autocomplete="new-password"></label></p><p>' . esc_html__( 'Changing credentials disconnects the previous account. Secret and tokens are never displayed.', 'ajcore' ) . '</p>';
		submit_button( __( 'Save Credentials', 'ajcore' ) ); echo '</form>';
		foreach ( array( 'connect' => __( 'Connect Google Account', 'ajcore' ), 'disconnect' => __( 'Disconnect Google Account and remove local credentials', 'ajcore' ), 'accounts' => __( 'Load Business Accounts', 'ajcore' ), 'test' => __( 'Test Connection', 'ajcore' ), 'sync' => __( 'Sync Now', 'ajcore' ) ) as $key => $label ) { self::button( $key, $label ); }
		$choices = self::choices();
		if ( ! empty( $choices['accounts'] ) ) {
			self::form( 'locations' ); echo '<label>' . esc_html__( 'Business account', 'ajcore' ) . ' <select name="account">';
			foreach ( $choices['accounts'] as $account ) { echo '<option value="' . esc_attr( $account['name'] ) . '" ' . selected( $choices['account'] ?? '', $account['name'], false ) . '>' . esc_html( ( $account['accountName'] ?? $account['name'] ) . ' (' . $account['name'] . ')' ) . '</option>'; }
			echo '</select></label>'; submit_button( __( 'Load Locations', 'ajcore' ), 'secondary' ); echo '</form>';
		}
		if ( ! empty( $choices['locations'] ) ) {
			self::form( 'location' ); echo '<label>' . esc_html__( 'Location', 'ajcore' ) . ' <select name="location">';
			foreach ( $choices['locations'] as $location ) { echo '<option value="' . esc_attr( $location['name'] ) . '">' . esc_html( ( $location['title'] ?? $location['name'] ) . ' (' . $location['name'] . ')' ) . '</option>'; }
			echo '</select></label>'; submit_button( __( 'Use This Location', 'ajcore' ), 'secondary' ); echo '</form>';
		}
	}

	private static function inbox( $featured_only ) {
		$data = AJCore_Reviews::snapshot(); $selection = (array) get_option( 'ajcore_reviews_selection', array() );
		$reviews = array_filter( $data['reviews'] ?? array(), array( 'AJCore_Reviews', 'valid_review' ) );
		if ( $featured_only ) { $reviews = array_intersect_key( $reviews, $selection ); uasort( $reviews, function( $a, $b ) use ( $selection ) { return $selection[$a['key']] <=> $selection[$b['key']]; } ); }
		$total = count( $reviews ); $page = max( 1, min( max( 1, (int) ceil( $total / 20 ) ), absint( self::value( 'review_page', $_GET ) ) ) );
		echo '<p>' . esc_html__( 'Select reviews neutrally. Nothing is featured automatically. Google review text and attribution cannot be edited. Selection is disclosed publicly.', 'ajcore' ) . '</p>';
		if ( ! $total ) { echo '<p>' . esc_html__( 'No valid reviews here. Connect a location, synchronize, and select reviews in the inbox.', 'ajcore' ) . '</p>'; }
		foreach ( array_slice( $reviews, ( $page - 1 ) * 20, 20, true ) as $key => $review ) {
				echo '<section class="card" style="max-width:900px"><h3>' . esc_html( $review['name'] ?: __( 'Anonymous reviewer', 'ajcore' ) ) . ' — ' . (int) $review['rating'] . '/5</h3><p>' . nl2br( esc_html( $review['text'] ) ) . '</p><p>' . esc_html( $review['date'] ) . '</p><p>' . esc_html__( 'Retrieved:', 'ajcore' ) . ' ' . esc_html( self::time( $review['retrieved_at'] ) ) . ' · ' . esc_html__( 'Expires:', 'ajcore' ) . ' ' . esc_html( self::time( $review['expires_at'] ) ) . '</p>';
				if ( $review['avatar'] ) { echo '<p><img src="' . esc_url( $review['avatar'] ) . '" alt="" width="56" height="56" loading="lazy" referrerpolicy="no-referrer"></p>'; }
				echo '<details><summary>' . esc_html__( 'Source details', 'ajcore' ) . '</summary><p><code>' . esc_html( $review['id'] ) . '</code></p>';
				if ( $review['profile_url'] ) { echo '<p><a href="' . esc_url( $review['profile_url'] ) . '">' . esc_html__( 'Reviewer profile', 'ajcore' ) . '</a></p>'; }
				if ( $review['report_url'] ) { echo '<p><a href="' . esc_url( $review['report_url'] ) . '">' . esc_html__( 'Report review', 'ajcore' ) . '</a></p>'; }
				if ( $review['language'] ) { echo '<p>' . esc_html__( 'Original language:', 'ajcore' ) . ' ' . esc_html( $review['language'] ) . '</p>'; }
				if ( $review['translated_text'] ) { echo '<p>' . esc_html__( 'Translation supplied by Google:', 'ajcore' ) . '</p><p>' . nl2br( esc_html( $review['translated_text'] ) ) . '</p><p>' . esc_html( $review['translation_status'] ) . '</p>'; }
				echo '</details>';
			$source = $review['source_url'] ?: ( $data['summary']['maps_url'] ?? '' );
			if ( $source ) { echo '<p><a href="' . esc_url( $source ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $review['source_url'] ? __( 'Open original review on Google', 'ajcore' ) : __( 'View business on Google Maps (individual review link unavailable)', 'ajcore' ) ) . '</a></p>'; }
			self::form( 'feature' ); echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><label><input type="checkbox" name="featured" value="1" ' . checked( isset( $selection[$key] ), true, false ) . '> ' . esc_html__( 'Featured', 'ajcore' ) . '</label> <label>' . esc_html__( 'Display order', 'ajcore' ) . ' <input type="number" name="order" min="-10000" max="10000" value="' . (int) ( $selection[$key] ?? 0 ) . '"></label>'; submit_button( __( 'Save Selection', 'ajcore' ), 'secondary' ); echo '</form></section>';
		}
			echo '<p>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'review_page', '%#%', self::url( $featured_only ? 'featured' : 'inbox' ) ), 'format' => '', 'current' => $page, 'total' => (int) ceil( $total / 20 ) ) ) ) . '</p>';
	}

	public static function message( $code ) {
		$messages = array(
			'success' => __( 'Operation completed.', 'ajcore' ), 'busy' => __( 'Another reviews operation is running. Try again shortly.', 'ajcore' ),
			'credentials_required' => __( 'Save a valid Google OAuth client ID and secret first.', 'ajcore' ), 'not_connected' => __( 'Connect a Google account first.', 'ajcore' ),
			'authorization_failed' => __( 'Google authorization failed. Reconnect and grant Business Profile access.', 'ajcore' ), 'refresh_token_required' => __( 'Google did not provide offline access. Reconnect with consent.', 'ajcore' ),
			'access_denied' => __( 'Google denied API access. Check project approval, enabled APIs, and location permissions.', 'ajcore' ), 'invalid_location' => __( 'Load business accounts and select an authorized location.', 'ajcore' ),
			'temporary_error' => __( 'Google is temporarily unavailable or rate limited. A bounded retry will be scheduled for sync failures.', 'ajcore' ), 'transport_error' => __( 'Google could not be reached. Check outbound HTTPS access.', 'ajcore' ),
			'snapshot_changed' => __( 'The review collection changed while it was being retrieved. The previous snapshot remains within its original expiry.', 'ajcore' ),
			'invalid_response' => __( 'The provider returned incomplete or invalid data. No partial snapshot was published.', 'ajcore' ), 'sync_limit' => __( 'The request exceeded the supported size or time limit. No partial snapshot was published.', 'ajcore' ),
			'encryption_unavailable' => __( 'Secure storage is unavailable. Enable PHP sodium; plaintext storage is refused.', 'ajcore' ), 'encryption_key_invalid' => __( 'The AJ Core encryption key is invalid. Restore it securely; do not replace it casually.', 'ajcore' ),
			'storage_failed' => __( 'Secure data could not be saved. Check database availability.', 'ajcore' ), 'oauth_state_invalid' => __( 'The authorization session is expired or does not match. Start Connect again in this browser.', 'ajcore' ),
			'disconnected' => __( 'Google disconnected. Local credentials and synchronized content removed.', 'ajcore' ), 'revoke_failed' => __( 'Local credentials and content were removed, but Google revocation could not be confirmed. Remove this app in your Google account connections.', 'ajcore' ),
		);
		return $messages[$code] ?? __( 'The operation could not be completed. Review the setup documentation and try again.', 'ajcore' );
	}
}
