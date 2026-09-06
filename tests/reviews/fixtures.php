<?php
/** Synthetic data only; never use real account data in fixtures. */
function ajcore_reviews_test_review( $id = 'fixture-review-1', $rating = 'THREE' ) {
	return array( 'reviewId' => $id, 'starRating' => $rating, 'reviewer' => array( 'displayName' => 'Fixture Reviewer', 'profilePhotoUrl' => 'https://example.test/avatar.png', 'isAnonymous' => false ), 'comment' => "Original fixture text.\nSecond line <script>fixture</script>", 'createTime' => '2026-01-01T12:00:00Z', 'updateTime' => '2026-01-02T12:00:00Z' );
}

class AJCore_Reviews_Fixture_Provider implements AJCore_Review_Provider {
	public $rows;
	public $error = null;
	public $exchanges = 0;
	public function __construct() { $this->rows = array( ajcore_reviews_test_review() ); }
	public function authorization_url( $state, $challenge ) { return 'https://accounts.google.com/o/oauth2/v2/auth'; }
	public function exchange( $code, $verifier ) { ++$this->exchanges; return true; }
	public function accounts() { return array( array( 'name' => 'accounts/100', 'accountName' => 'Fixture business' ) ); }
	public function locations( $account ) { return array( array( 'name' => 'locations/200', 'title' => 'Fixture location' ) ); }
	public function revoke() { return $this->error ?: true; }
	public function fetch( $account, $location ) {
		if ( $this->error ) { return $this->error; }
		$now = time(); $reviews = array();
		foreach ( $this->rows as $row ) { $r = AJCore_Google_Review_Provider::normalize( $row, $location, $now ); if ( is_wp_error( $r ) ) { return $r; } $reviews[$r['key']] = $r; }
		return array( 'reviews' => $reviews, 'summary' => array( 'title' => 'Fixture location', 'rating' => 3, 'total' => count( $reviews ), 'location' => $location, 'maps_url' => 'https://example.test/maps', 'write_url' => 'https://example.test/write' ), 'retrieved_at' => $now, 'expires_at' => $now + AJCore_Reviews::TTL );
	}
}
