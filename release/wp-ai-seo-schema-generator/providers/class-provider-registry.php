<?php
/**
 * Provider registry class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages LLM provider registration and lookup
 */
class WP_AI_Schema_Provider_Registry {

    /**
     * Registered providers
     *
     * @var WP_AI_Schema_Provider_Interface[]
     */
    private $providers = array();

    /**
     * Register a provider
     *
     * @param WP_AI_Schema_Provider_Interface $provider Provider instance.
     * @return bool True if registered successfully.
     */
    public function register( WP_AI_Schema_Provider_Interface $provider ): bool {
        $slug = $provider->get_slug();

        if ( isset( $this->providers[ $slug ] ) ) {
            WP_AI_Schema_Generator::log(
                sprintf( 'Provider "%s" is already registered.', $slug ),
                'warning'
            );
            return false;
        }

        $this->providers[ $slug ] = $provider;
        return true;
    }

    /**
     * Unregister a provider
     *
     * @param string $slug Provider slug.
     * @return bool True if unregistered successfully.
     */
    public function unregister( string $slug ): bool {
        if ( ! isset( $this->providers[ $slug ] ) ) {
            return false;
        }

        unset( $this->providers[ $slug ] );
        return true;
    }

    /**
     * Get a provider by slug
     *
     * @param string $slug Provider slug.
     * @return WP_AI_Schema_Provider_Interface|null Provider instance or null.
     */
    public function get( string $slug ): ?WP_AI_Schema_Provider_Interface {
        return $this->providers[ $slug ] ?? null;
    }

    /**
     * Get all registered providers
     *
     * @return WP_AI_Schema_Provider_Interface[]
     */
    public function get_all(): array {
        return $this->providers;
    }

    /**
     * Get providers as options array for dropdown
     *
     * @return array Associative array of slug => name.
     */
    public function get_options(): array {
        $options = array();

        foreach ( $this->providers as $slug => $provider ) {
            $options[ $slug ] = $provider->get_name();
        }

        return $options;
    }

    /**
     * Check if a provider is registered
     *
     * @param string $slug Provider slug.
     * @return bool True if provider exists.
     */
    public function has( string $slug ): bool {
        return isset( $this->providers[ $slug ] );
    }

    /**
     * Get the currently active provider based on settings
     *
     * @param array $settings Plugin settings (optional).
     * @return WP_AI_Schema_Provider_Interface|null Active provider or null.
     */
    public function get_active( array $settings = array() ): ?WP_AI_Schema_Provider_Interface {
        if ( empty( $settings ) ) {
            $settings = WP_AI_Schema_Generator::get_settings();
        }

        $provider_slug = $settings['provider'] ?? 'deepseek';

        return $this->get( $provider_slug );
    }

    /**
     * Get count of registered providers
     *
     * @return int Number of registered providers.
     */
    public function count(): int {
        return count( $this->providers );
    }

    /**
     * Get all settings fields from all providers
     *
     * @return array Array of settings fields grouped by provider slug.
     */
    public function get_all_settings_fields(): array {
        $fields = array();

        foreach ( $this->providers as $slug => $provider ) {
            $fields[ $slug ] = $provider->get_settings_fields();
        }

        return $fields;
    }
}
