<?php
/**
 * Abstract provider base class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for LLM providers
 *
 * Provides shared functionality like HTTP requests, retry logic, and rate limiting.
 */
abstract class AI_JSONLD_Abstract_Provider implements AI_JSONLD_Provider_Interface {

    /**
     * Encryption handler
     *
     * @var AI_JSONLD_Encryption
     */
    protected $encryption;

    /**
     * Retry configuration
     */
    protected const MAX_RETRIES      = 3;
    protected const BASE_DELAY_MS    = 1000;
    protected const MAX_DELAY_MS     = 10000;
    protected const BACKOFF_MULTIPLIER = 2;

    /**
     * Retryable HTTP status codes
     */
    protected const RETRYABLE_CODES = array( 429, 500, 502, 503, 504 );

    /**
     * Constructor
     *
     * @param AI_JSONLD_Encryption $encryption Encryption handler.
     */
    public function __construct( AI_JSONLD_Encryption $encryption ) {
        $this->encryption = $encryption;
    }

    /**
     * Make an HTTP POST request with retry logic
     *
     * @param string $url     The API endpoint URL.
     * @param array  $headers Request headers.
     * @param array  $body    Request body (will be JSON encoded).
     * @param int    $timeout Request timeout in seconds.
     * @return array Response array with success, body, status_code, headers, error.
     */
    protected function make_request( string $url, array $headers, array $body, int $timeout = 30 ): array {
        $delay   = self::BASE_DELAY_MS;
        $attempt = 0;

        while ( $attempt < self::MAX_RETRIES ) {
            $attempt++;

            $response = wp_remote_post(
                $url,
                array(
                    'headers' => $headers,
                    'body'    => wp_json_encode( $body ),
                    'timeout' => $timeout,
                )
            );

            // Check for WP error
            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();

                if ( $attempt >= self::MAX_RETRIES ) {
                    return array(
                        'success'     => false,
                        'body'        => null,
                        'status_code' => 0,
                        'headers'     => array(),
                        'error'       => $error_message,
                    );
                }

                // Wait and retry for connection errors
                usleep( $delay * 1000 );
                $delay = min( $delay * self::BACKOFF_MULTIPLIER, self::MAX_DELAY_MS );
                continue;
            }

            $status_code     = wp_remote_retrieve_response_code( $response );
            $response_body   = wp_remote_retrieve_body( $response );
            $response_headers = wp_remote_retrieve_headers( $response );

            // Convert headers to array
            $headers_array = array();
            if ( $response_headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || is_object( $response_headers ) ) {
                $headers_array = $response_headers->getAll();
            } elseif ( is_array( $response_headers ) ) {
                $headers_array = $response_headers;
            }

            // Success
            if ( $status_code >= 200 && $status_code < 300 ) {
                return array(
                    'success'     => true,
                    'body'        => $response_body,
                    'status_code' => $status_code,
                    'headers'     => $headers_array,
                    'error'       => null,
                );
            }

            // Check if we should retry
            $is_retryable = in_array( $status_code, self::RETRYABLE_CODES, true );

            if ( ! $is_retryable || $attempt >= self::MAX_RETRIES ) {
                // Handle rate limiting
                if ( 429 === $status_code ) {
                    $this->handle_rate_limit( $headers_array );
                }

                return array(
                    'success'     => false,
                    'body'        => $response_body,
                    'status_code' => $status_code,
                    'headers'     => $headers_array,
                    'error'       => $this->get_error_from_response( $response_body, $status_code ),
                );
            }

            // Get delay from Retry-After header if present
            if ( isset( $headers_array['retry-after'] ) ) {
                $retry_after = intval( $headers_array['retry-after'] );
                if ( $retry_after > 0 ) {
                    $delay = $retry_after * 1000;
                }
            }

            // Log retry attempt
            AI_JSONLD_Generator::log(
                sprintf( 'API request failed with status %d, retrying (attempt %d/%d)', $status_code, $attempt, self::MAX_RETRIES ),
                'warning'
            );

            // Wait before retry
            usleep( $delay * 1000 );
            $delay = min( $delay * self::BACKOFF_MULTIPLIER, self::MAX_DELAY_MS );
        }

        return array(
            'success'     => false,
            'body'        => null,
            'status_code' => 0,
            'headers'     => array(),
            'error'       => __( 'Max retries exceeded', 'ai-jsonld-generator' ),
        );
    }

    /**
     * Handle rate limiting by setting global transient
     *
     * @param array $headers Response headers.
     */
    protected function handle_rate_limit( array $headers ): void {
        $retry_after = 60; // Default 60 seconds

        if ( isset( $headers['retry-after'] ) ) {
            $retry_after = intval( $headers['retry-after'] );
        }

        $until = time() + $retry_after;
        set_transient( 'ai_jsonld_rate_limit_until', $until, $retry_after + 10 );

        AI_JSONLD_Generator::log(
            sprintf( 'Rate limited. Blocking requests until %s', gmdate( 'Y-m-d H:i:s', $until ) ),
            'warning'
        );
    }

    /**
     * Check if we're currently rate limited
     *
     * @return bool|int False if not limited, or timestamp when limit expires.
     */
    protected function is_rate_limited() {
        $until = get_transient( 'ai_jsonld_rate_limit_until' );

        if ( false === $until ) {
            return false;
        }

        if ( time() >= $until ) {
            delete_transient( 'ai_jsonld_rate_limit_until' );
            return false;
        }

        return $until;
    }

    /**
     * Extract error message from API response
     *
     * @param string $body        Response body.
     * @param int    $status_code HTTP status code.
     * @return string Error message.
     */
    protected function get_error_from_response( string $body, int $status_code ): string {
        $decoded = json_decode( $body, true );

        if ( json_last_error() === JSON_ERROR_NONE && isset( $decoded['error'] ) ) {
            if ( is_string( $decoded['error'] ) ) {
                return $decoded['error'];
            }
            if ( isset( $decoded['error']['message'] ) ) {
                return $decoded['error']['message'];
            }
        }

        // Generic error messages based on status code
        switch ( $status_code ) {
            case 400:
                return __( 'Bad request. Please check your settings.', 'ai-jsonld-generator' );
            case 401:
                return __( 'Invalid API key. Please check your credentials.', 'ai-jsonld-generator' );
            case 403:
                return __( 'Access forbidden. Please check your API key permissions.', 'ai-jsonld-generator' );
            case 404:
                return __( 'API endpoint not found.', 'ai-jsonld-generator' );
            case 429:
                return __( 'Rate limit exceeded. Please try again later.', 'ai-jsonld-generator' );
            case 500:
            case 502:
            case 503:
            case 504:
                return __( 'Server error. Please try again later.', 'ai-jsonld-generator' );
            default:
                return sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Request failed with status code %d.', 'ai-jsonld-generator' ),
                    $status_code
                );
        }
    }

    /**
     * Decrypt API key from settings
     *
     * @param array  $settings Plugin settings.
     * @param string $key_name Settings key name for the API key.
     * @return string Decrypted API key.
     */
    protected function get_api_key( array $settings, string $key_name ): string {
        if ( empty( $settings[ $key_name ] ) ) {
            return '';
        }

        return $this->encryption->decrypt( $settings[ $key_name ] );
    }
}
