<?php
defined( 'ABSPATH' ) || exit;

/** Provider methods return data or WP_Error codes, never raw HTTP errors/bodies. */
interface AJCore_Review_Provider {
	public function authorization_url( $state, $challenge );
	public function exchange( $code, $verifier );
	public function accounts();
	public function locations( $account );
	public function fetch( $account, $location );
	public function revoke();
}
