<?php
/**
 * Conflict detector class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects conflicts with SEO plugins that output their own JSON-LD schema
 */
class AI_JSONLD_Conflict_Detector {

    /**
     * Detected SEO plugin
     *
     * @var string|null
     */
    private $detected_plugin = null;

    /**
     * Check for Yoast SEO
     *
     * @return bool True if Yoast SEO is active.
     */
    public function has_yoast(): bool {
        return defined( 'WPSEO_VERSION' );
    }

    /**
     * Check for RankMath
     *
     * @return bool True if RankMath is active.
     */
    public function has_rankmath(): bool {
        return class_exists( 'RankMath' );
    }

    /**
     * Check for All in One SEO
     *
     * @return bool True if AIOSEO is active.
     */
    public function has_aioseo(): bool {
        return defined( 'AIOSEO_VERSION' );
    }

    /**
     * Check for SEOPress
     *
     * @return bool True if SEOPress is active.
     */
    public function has_seopress(): bool {
        return defined( 'SEOPRESS_VERSION' );
    }

    /**
     * Get detected SEO plugin name
     *
     * @return string|null Plugin name or null if none detected.
     */
    public function get_detected_plugin(): ?string {
        if ( null !== $this->detected_plugin ) {
            return $this->detected_plugin;
        }

        if ( $this->has_yoast() ) {
            $this->detected_plugin = 'Yoast SEO';
        } elseif ( $this->has_rankmath() ) {
            $this->detected_plugin = 'RankMath';
        } elseif ( $this->has_aioseo() ) {
            $this->detected_plugin = 'All in One SEO';
        } elseif ( $this->has_seopress() ) {
            $this->detected_plugin = 'SEOPress';
        } else {
            $this->detected_plugin = '';
        }

        return $this->detected_plugin ?: null;
    }

    /**
     * Check if any SEO plugin has schema output enabled
     *
     * @return bool True if SEO plugin is outputting schema.
     */
    public function is_seo_schema_active(): bool {
        // Check Yoast
        if ( $this->has_yoast() ) {
            return $this->is_yoast_schema_enabled();
        }

        // Check RankMath
        if ( $this->has_rankmath() ) {
            return $this->is_rankmath_schema_enabled();
        }

        // Check AIOSEO
        if ( $this->has_aioseo() ) {
            return $this->is_aioseo_schema_enabled();
        }

        // Check SEOPress
        if ( $this->has_seopress() ) {
            return $this->is_seopress_schema_enabled();
        }

        return false;
    }

    /**
     * Check if Yoast schema is enabled
     *
     * @return bool True if Yoast schema is enabled.
     */
    private function is_yoast_schema_enabled(): bool {
        if ( ! class_exists( 'WPSEO_Options' ) ) {
            return true; // Assume enabled if we can't check
        }

        // Check if schema is disabled
        $disabled = WPSEO_Options::get( 'disable-schema' );
        return ! $disabled;
    }

    /**
     * Check if RankMath schema is enabled
     *
     * @return bool True if RankMath schema is enabled.
     */
    private function is_rankmath_schema_enabled(): bool {
        if ( ! class_exists( 'RankMath\\Helper' ) ) {
            return true; // Assume enabled if we can't check
        }

        // Check if schema module is enabled
        return \RankMath\Helper::is_module_active( 'rich-snippet' );
    }

    /**
     * Check if AIOSEO schema is enabled
     *
     * @return bool True if AIOSEO schema is enabled.
     */
    private function is_aioseo_schema_enabled(): bool {
        // AIOSEO typically has schema enabled by default
        return true;
    }

    /**
     * Check if SEOPress schema is enabled
     *
     * @return bool True if SEOPress schema is enabled.
     */
    private function is_seopress_schema_enabled(): bool {
        // Check SEOPress schema settings
        $schema_enabled = get_option( 'seopress_toggle', array() );
        return ! empty( $schema_enabled['toggle-local-business'] ) || ! empty( $schema_enabled['toggle-rich-snippets'] );
    }

    /**
     * Check if we should output our schema for a post
     *
     * @param int   $post_id  Post ID.
     * @param array $settings Plugin settings.
     * @return array {
     *     Result array.
     *
     *     @type bool   $should_output Whether to output schema.
     *     @type string $reason        Reason if not outputting.
     * }
     */
    public function should_output( int $post_id, array $settings ): array {
        // Check if skip_if_schema_exists is enabled
        if ( empty( $settings['skip_if_schema_exists'] ) ) {
            return array(
                'should_output' => true,
                'reason'        => '',
            );
        }

        // Check for SEO plugin schema
        if ( $this->is_seo_schema_active() ) {
            $plugin = $this->get_detected_plugin();

            // Allow filter to override
            $should_output = false;

            if ( $this->has_yoast() ) {
                $should_output = apply_filters( 'ai_jsonld_output_with_yoast', false, $post_id );
            } elseif ( $this->has_rankmath() ) {
                $should_output = apply_filters( 'ai_jsonld_output_with_rankmath', false, $post_id );
            }

            if ( ! $should_output ) {
                return array(
                    'should_output' => false,
                    'reason'        => sprintf(
                        /* translators: %s: SEO plugin name */
                        __( 'Skipped - %s schema is active', 'ai-jsonld-generator' ),
                        $plugin
                    ),
                );
            }
        }

        return array(
            'should_output' => true,
            'reason'        => '',
        );
    }

    /**
     * Get admin notice about detected SEO plugins
     *
     * @param array $settings Plugin settings.
     * @return string|null Admin notice HTML or null.
     */
    public function get_admin_notice( array $settings ): ?string {
        $plugin = $this->get_detected_plugin();

        if ( ! $plugin ) {
            return null;
        }

        if ( $this->is_seo_schema_active() && empty( $settings['skip_if_schema_exists'] ) ) {
            return sprintf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                sprintf(
                    /* translators: %s: SEO plugin name */
                    esc_html__(
                        '%s detected with schema output enabled. Enable "Skip if schema exists" in settings to prevent duplicate schema.',
                        'ai-jsonld-generator'
                    ),
                    esc_html( $plugin )
                )
            );
        }

        return null;
    }

    /**
     * Get debug comment for skipped output
     *
     * @param string $reason Reason for skipping.
     * @return string HTML comment.
     */
    public function get_debug_comment( string $reason ): string {
        return sprintf( '<!-- AI JSON-LD: %s -->', esc_html( $reason ) );
    }
}
