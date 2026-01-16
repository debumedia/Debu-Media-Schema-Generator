<?php
/**
 * Provider interface
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for LLM providers
 *
 * All LLM providers must implement this interface to be compatible with the plugin.
 */
interface WP_AI_Schema_Provider_Interface {

    /**
     * Get the provider's display name
     *
     * @return string Provider name (e.g., "DeepSeek")
     */
    public function get_name(): string;

    /**
     * Get the provider's unique slug
     *
     * @return string Provider slug (e.g., "deepseek")
     */
    public function get_slug(): string;

    /**
     * Generate JSON-LD schema
     *
     * @param array $payload  The prompt payload containing page and site data.
     * @param array $settings Plugin settings including API keys and model config.
     * @return array {
     *     Response array.
     *
     *     @type bool   $success     Whether the request was successful.
     *     @type string $schema      The generated JSON-LD string (on success).
     *     @type int    $status_code HTTP status code.
     *     @type string $error       Error message (on failure).
     *     @type array  $headers     Response headers (for retry-after parsing).
     * }
     */
    public function generate_schema( array $payload, array $settings ): array;

    /**
     * Test the API connection
     *
     * @param array $settings Plugin settings including API key.
     * @return array {
     *     Response array.
     *
     *     @type bool   $success Whether the connection test was successful.
     *     @type string $message Status message.
     *     @type string $error   Error message (on failure).
     * }
     */
    public function test_connection( array $settings ): array;

    /**
     * Get the settings fields for this provider
     *
     * @return array Array of settings field definitions.
     */
    public function get_settings_fields(): array;
}
