<?php
/**
 * Encryption class for API key security
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles encryption and decryption of sensitive data (API keys)
 */
class WP_AI_Schema_Encryption {

    /**
     * Encryption cipher
     */
    const CIPHER = 'aes-256-cbc';

    /**
     * Check if OpenSSL is available
     *
     * @return bool
     */
    public function is_available() {
        return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
    }

    /**
     * Get encryption key derived from WordPress salts
     *
     * @return string Binary key
     */
    private function get_key() {
        return hash( 'sha256', wp_salt( 'auth' ) . 'wp_ai_schema_key', true );
    }

    /**
     * Get initialization vector derived from WordPress salts
     *
     * @return string Binary IV (16 bytes)
     */
    private function get_iv() {
        return substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
    }

    /**
     * Encrypt a string
     *
     * @param string $plain_text The plain text to encrypt.
     * @return string Encrypted and base64 encoded string
     */
    public function encrypt( $plain_text ) {
        if ( empty( $plain_text ) ) {
            return '';
        }

        // Fallback if OpenSSL not available
        if ( ! $this->is_available() ) {
            WP_AI_Schema_Generator::log( 'OpenSSL not available - API key stored unencrypted', 'warning' );
            return base64_encode( $plain_text );
        }

        $encrypted = openssl_encrypt(
            $plain_text,
            self::CIPHER,
            $this->get_key(),
            0,
            $this->get_iv()
        );

        if ( false === $encrypted ) {
            WP_AI_Schema_Generator::log( 'Encryption failed', 'error' );
            return '';
        }

        return base64_encode( $encrypted );
    }

    /**
     * Decrypt a string
     *
     * @param string $encrypted_text The encrypted and base64 encoded string.
     * @return string Decrypted plain text
     */
    public function decrypt( $encrypted_text ) {
        if ( empty( $encrypted_text ) ) {
            return '';
        }

        $decoded = base64_decode( $encrypted_text );

        if ( false === $decoded ) {
            return '';
        }

        // Fallback if OpenSSL not available
        if ( ! $this->is_available() ) {
            return $decoded;
        }

        $decrypted = openssl_decrypt(
            $decoded,
            self::CIPHER,
            $this->get_key(),
            0,
            $this->get_iv()
        );

        if ( false === $decrypted ) {
            WP_AI_Schema_Generator::log( 'Decryption failed', 'error' );
            return '';
        }

        return $decrypted;
    }

    /**
     * Mask an API key for display
     * Shows first 4 and last 4 characters with bullets in between
     *
     * @param string $key The API key to mask.
     * @return string Masked key (e.g., "sk-a1••••••••z9x2")
     */
    public function mask_key( $key ) {
        if ( empty( $key ) ) {
            return '';
        }

        $length = strlen( $key );

        if ( $length <= 8 ) {
            // Too short to mask meaningfully
            return str_repeat( '•', $length );
        }

        $first = substr( $key, 0, 4 );
        $last  = substr( $key, -4 );
        $dots  = str_repeat( '•', min( 8, $length - 8 ) );

        return $first . $dots . $last;
    }

    /**
     * Check if a value appears to be encrypted
     *
     * @param string $value The value to check.
     * @return bool
     */
    public function is_encrypted( $value ) {
        if ( empty( $value ) ) {
            return false;
        }

        // Check if it's base64 encoded
        $decoded = base64_decode( $value, true );

        if ( false === $decoded ) {
            return false;
        }

        // If OpenSSL is available, try to decrypt to verify
        if ( $this->is_available() ) {
            $decrypted = openssl_decrypt(
                $decoded,
                self::CIPHER,
                $this->get_key(),
                0,
                $this->get_iv()
            );

            return false !== $decrypted;
        }

        // Without OpenSSL, assume base64 = encrypted
        return true;
    }

    /**
     * Get admin notice about OpenSSL availability
     *
     * @return string|null Admin notice HTML or null if OpenSSL available
     */
    public function get_openssl_notice() {
        if ( $this->is_available() ) {
            return null;
        }

        return sprintf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__(
                'OpenSSL extension is not available. API keys will be stored with basic encoding only. For better security, please enable the OpenSSL PHP extension.',
                'wp-ai-seo-schema-generator'
            )
        );
    }
}
