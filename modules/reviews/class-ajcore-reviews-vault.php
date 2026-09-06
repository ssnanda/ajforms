<?php
/** Fail-closed adapter around AJ Core's existing sodium encryption. */
defined( 'ABSPATH' ) || exit;

final class AJCore_Reviews_Vault {
	public static function seal( $data ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'ajcore_encrypt_setting_value' ) ) {
			return new WP_Error( 'encryption_unavailable' );
		}
		$key = get_option( 'ajcore_settings_encryption_key', '' );
		if ( $key && ( ! is_string( $key ) || strlen( (string) base64_decode( $key, true ) ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) {
			return new WP_Error( 'encryption_key_invalid' );
		}
		try {
			$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) { return new WP_Error( 'invalid_data' ); }
			$value = ajcore_encrypt_setting_value( $json );
			return strpos( $value, 'ajenc2:' ) === 0 ? $value : new WP_Error( 'encryption_unavailable' );
		} catch ( Throwable $e ) {
			return new WP_Error( 'encryption_unavailable' );
		}
	}

	public static function open( $value ) {
		if ( ! is_string( $value ) || strpos( $value, 'ajenc2:' ) !== 0 || ! function_exists( 'sodium_crypto_secretbox_open' ) ) { return array(); }
		$key = get_option( 'ajcore_settings_encryption_key', '' );
		if ( ! is_string( $key ) || strlen( (string) base64_decode( $key, true ) ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) { return array(); }
		try {
			$data = json_decode( ajcore_decrypt_setting_value( $value ), true );
			return is_array( $data ) ? $data : array();
		} catch ( Throwable $e ) { return array(); }
	}

	private static function temporary( $option ) { return in_array( $option, array( 'ajcore_reviews_snapshot', 'ajcore_reviews_choices', 'ajcore_reviews_oauth' ), true ); }
	public static function read( $option ) { return self::open( self::temporary( $option ) ? get_transient( $option ) : get_option( $option, '' ) ); }
	public static function delete( $option ) {
		if ( ! self::temporary( $option ) ) { return delete_option( $option ); }
		$result = delete_transient( $option );
		// Remove database shadows as well if the host switched to an external object cache.
		delete_option( '_transient_' . $option ); delete_option( '_transient_timeout_' . $option );
		return $result;
	}
	public static function cleanup_database_copies() {
		foreach ( array( 'ajcore_reviews_snapshot', 'ajcore_reviews_choices', 'ajcore_reviews_oauth' ) as $option ) {
			$expiry = (int) get_option( '_transient_timeout_' . $option, 0 );
			if ( wp_using_ext_object_cache() || ( $expiry && $expiry <= time() ) ) {
				delete_option( '_transient_' . $option ); delete_option( '_transient_timeout_' . $option );
			}
		}
	}

	public static function write( $option, $data ) {
		$value = self::seal( $data );
		if ( is_wp_error( $value ) ) { return $value; }
		if ( self::temporary( $option ) ) {
			$ttl = (int) ( $data['expires_at'] ?? 0 ) - time();
			if ( $ttl <= 0 || $ttl > AJCore_Reviews::TTL ) { return new WP_Error( 'invalid_data' ); }
			set_transient( $option, $value, $ttl );
			return get_transient( $option ) === $value ? true : new WP_Error( 'storage_failed' );
		}
		update_option( $option, $value, false );
		return get_option( $option ) === $value ? true : new WP_Error( 'storage_failed' );
	}
}
