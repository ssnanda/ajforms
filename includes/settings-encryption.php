<?php
/** Shared AJ Core settings encryption, extracted without changing legacy behavior. */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ajcore_get_settings_encryption_key' ) ) {
	/**
	 * A dedicated, DB-persisted key for encrypting settings secrets — deliberately NOT derived
	 * from wp_salt('auth')/AUTH_KEY (see the "ajenc1:" legacy scheme below for why that was a
	 * mistake). wp-config.php's salts can change for reasons entirely outside this plugin's
	 * control — a security-hardening plugin rotating them on a schedule, a hosting panel
	 * regenerating wp-config.php, a migration that doesn't carry the file over — and every secret
	 * encrypted under the old salt becomes permanently undecryptable the moment that happens,
	 * silently reading back as an empty string. From the settings screen that looks exactly like
	 * "the key got removed", even though the (now-unusable) ciphertext is still sitting untouched
	 * in the database. Generated once, stored in wp_options (autoload=false — read only during
	 * encrypt/decrypt, not on every page load), and never regenerated afterward, so it survives
	 * wp-config.php changes/redeploys/salt rotation entirely.
	 */
	function ajcore_get_settings_encryption_key() {
		// BUG FIX (was silently rotating the key on every single call — see the "critical" note in
		// git history/PR discussion): this used to gate reuse of the stored key behind
		// function_exists('sodium_crypto_secretbox_keybytes'), but that's the name of a CONSTANT
		// (SODIUM_CRYPTO_SECRETBOX_KEYBYTES) in PHP's native sodium extension, not a callable
		// function — function_exists() on it returns false on a standard install, so the "reuse the
		// stored key" branch below never ran. Every encrypt/decrypt call fell through to generating
		// and persisting a brand-new random key, overwriting the one anything previously encrypted
		// actually needs — i.e. the exact "silently undecryptable" failure this whole ajenc2: scheme
		// was built to prevent, just via a different mechanism. function_exists('sodium_crypto_secretbox')
		// (the actual function used below and elsewhere in this file) is the right guard for "is
		// libsodium available", and the key is validated on its own merits (decodes, right length).
		$stored = get_option( 'ajcore_settings_encryption_key', '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = base64_decode( $stored, true );
			if ( false !== $decoded && function_exists( 'sodium_crypto_secretbox' ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $decoded ) ) {
				return $decoded;
			}
		}
		$key = function_exists( 'random_bytes' ) ? random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) : hash( 'sha256', wp_salt( 'auth' ) . microtime(), true );
		update_option( 'ajcore_settings_encryption_key', base64_encode( $key ), false );
		return $key;
	}
}

if ( ! function_exists( 'ajcore_encrypt_setting_value' ) ) {
	/**
	 * Encrypt one settings value with the dedicated DB-persisted key above.
	 * Format: "ajenc2:" + base64( nonce + secretbox ). Values that are empty or already on this
	 * scheme pass through unchanged, so the transform is idempotent. A value still on the old
	 * "ajenc1:" (wp_salt-keyed) scheme gets opportunistically decrypted and re-encrypted under
	 * ajenc2: the next time it's saved — see ajcore_decrypt_setting_value()'s legacy branch.
	 */
	function ajcore_encrypt_setting_value( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, 'ajenc2:' ) ) {
			return $value;
		}
		if ( 0 === strpos( $value, 'ajenc1:' ) ) {
			$value = ajcore_decrypt_setting_value( $value );
			if ( '' === $value ) {
				return ''; // Already unrecoverable under the old scheme — nothing to carry forward.
			}
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $value;
		}
		$key   = ajcore_get_settings_encryption_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'ajenc2:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) );
	}
}

if ( ! function_exists( 'ajcore_decrypt_setting_value' ) ) {
	function ajcore_decrypt_setting_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( 0 === strpos( $value, 'ajenc2:' ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $value, 7 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$key   = ajcore_get_settings_encryption_key();
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);
			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $value, 'ajenc1:' ) ) {
			// Legacy scheme, keyed off wp_salt('auth') — this is the one that made secrets go
			// undecryptable whenever the site's salts changed. Kept only so a value still on this
			// scheme decrypts one more time (IF the salt hasn't already rotated away from what
			// encrypted it) so it can be transparently upgraded to ajenc2: on next save; see
			// ajcore_encrypt_setting_value() above.
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || ! function_exists( 'wp_salt' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $value, 7 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$key   = hash( 'sha256', wp_salt( 'auth' ) . '|ajcore-settings-v1', true );
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);
			return false === $plain ? '' : $plain;
		}
		return $value; // Plaintext (pre-migration) passes through.
	}
}

