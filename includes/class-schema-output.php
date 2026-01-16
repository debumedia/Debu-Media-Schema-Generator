<?php
/**
 * Schema output class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles frontend output of JSON-LD schema
 */
class WP_AI_Schema_Output {

    /**
     * Conflict detector
     *
     * @var WP_AI_Schema_Conflict_Detector
     */
    private $conflict_detector;

    /**
     * Constructor
     *
     * @param WP_AI_Schema_Conflict_Detector $conflict_detector Conflict detector instance.
     */
    public function __construct( WP_AI_Schema_Conflict_Detector $conflict_detector ) {
        $this->conflict_detector = $conflict_detector;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'wp_head', array( $this, 'output_in_head' ), 1 );
        add_filter( 'the_content', array( $this, 'output_after_content' ), 99 );
    }

    /**
     * Output schema in head
     */
    public function output_in_head() {
        $settings = WP_AI_Schema_Generator::get_settings();

        // Check if head output is enabled
        if ( 'head' !== $settings['output_location'] ) {
            return;
        }

        $this->output_schema();
    }

    /**
     * Output schema after content
     *
     * @param string $content Post content.
     * @return string Modified content.
     */
    public function output_after_content( $content ) {
        $settings = WP_AI_Schema_Generator::get_settings();

        // Check if after_content output is enabled
        if ( 'after_content' !== $settings['output_location'] ) {
            return $content;
        }

        // Only on singular pages in the main query
        if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
            return $content;
        }

        $schema_output = $this->get_schema_output();

        if ( $schema_output ) {
            $content .= "\n" . $schema_output;
        }

        return $content;
    }

    /**
     * Output schema (used by both methods)
     */
    private function output_schema() {
        $output = $this->get_schema_output();

        if ( $output ) {
            echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Get schema output HTML
     *
     * @return string Schema script tag or empty string.
     */
    private function get_schema_output(): string {
        // Only on singular pages
        if ( ! is_singular() ) {
            return '';
        }

        $post_id  = get_the_ID();
        $settings = WP_AI_Schema_Generator::get_settings();

        // Check if this post type is enabled
        $enabled_types = $settings['enabled_post_types'] ?? array( 'page' );
        $post_type     = get_post_type( $post_id );

        if ( ! in_array( $post_type, $enabled_types, true ) ) {
            return '';
        }

        // Check conflict detection
        $should_output = $this->conflict_detector->should_output( $post_id, $settings );

        if ( ! $should_output['should_output'] ) {
            // Output debug comment if debug is enabled
            if ( ! empty( $settings['debug_logging'] ) ) {
                return $this->conflict_detector->get_debug_comment( $should_output['reason'] );
            }
            return '';
        }

        // Get schema
        $schema = get_post_meta( $post_id, '_wp_ai_schema_schema', true );

        if ( empty( $schema ) ) {
            return '';
        }

        // Validate it's still valid JSON
        $decoded = json_decode( $schema );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            if ( ! empty( $settings['debug_logging'] ) ) {
                return '<!-- AI JSON-LD: Invalid JSON in stored schema -->';
            }
            return '';
        }

        // Apply filter for customization
        $should_output_filtered = apply_filters( 'wp_ai_schema_should_output', true, $post_id );

        if ( ! $should_output_filtered ) {
            if ( ! empty( $settings['debug_logging'] ) ) {
                return '<!-- AI JSON-LD: Output disabled by filter -->';
            }
            return '';
        }

        // Return script tag
        return '<script type="application/ld+json">' . $schema . '</script>';
    }

    /**
     * Check if schema exists for a post
     *
     * @param int $post_id Post ID.
     * @return bool True if schema exists.
     */
    public function has_schema( int $post_id ): bool {
        $schema = get_post_meta( $post_id, '_wp_ai_schema_schema', true );
        return ! empty( $schema );
    }

    /**
     * Get schema for a post
     *
     * @param int $post_id Post ID.
     * @return string|null Schema JSON or null.
     */
    public function get_schema( int $post_id ): ?string {
        $schema = get_post_meta( $post_id, '_wp_ai_schema_schema', true );
        return $schema ?: null;
    }

    /**
     * Delete schema for a post
     *
     * @param int $post_id Post ID.
     */
    public function delete_schema( int $post_id ): void {
        delete_post_meta( $post_id, '_wp_ai_schema_schema' );
        delete_post_meta( $post_id, '_wp_ai_schema_schema_last_generated' );
        delete_post_meta( $post_id, '_wp_ai_schema_schema_status' );
        delete_post_meta( $post_id, '_wp_ai_schema_schema_error' );
        delete_post_meta( $post_id, '_wp_ai_schema_schema_hash' );
        delete_post_meta( $post_id, '_wp_ai_schema_detected_type' );
    }

    /**
     * Run diagnostic checks for schema output
     *
     * Returns detailed information about all conditions that affect
     * whether schema will be output on the frontend.
     *
     * @param int $post_id Post ID to diagnose.
     * @return array {
     *     Diagnostic results array.
     *
     *     @type array  $checks      Array of individual check results.
     *     @type bool   $will_output Whether schema will output based on all checks.
     *     @type string $summary     Human-readable summary.
     * }
     */
    public function diagnose( int $post_id ): array {
        $settings = WP_AI_Schema_Generator::get_settings();
        $checks   = array();
        $all_pass = true;

        // Check 1: Schema exists in database
        $schema = get_post_meta( $post_id, '_wp_ai_schema_schema', true );
        $checks['schema_exists'] = array(
            'pass'    => ! empty( $schema ),
            'label'   => __( 'Schema in database', 'wp-ai-seo-schema-generator' ),
            'message' => empty( $schema )
                ? __( 'No schema generated yet. Click "Generate JSON-LD" to create one.', 'wp-ai-seo-schema-generator' )
                : __( 'Schema exists in database.', 'wp-ai-seo-schema-generator' ),
        );
        if ( empty( $schema ) ) {
            $all_pass = false;
        }

        // Check 2: Valid JSON
        $valid_json = false;
        $validation_message = '';
        if ( ! empty( $schema ) ) {
            // Use the schema validator for consistent validation
            $validator = wp_ai_schema_generator()->get_component( 'schema_validator' );
            if ( $validator ) {
                $validation = $validator->validate( $schema );
                $valid_json = $validation['valid'];
                if ( ! $valid_json && ! empty( $validation['errors'] ) ) {
                    $validation_message = ' Errors: ' . implode( ', ', $validation['errors'] );
                }
            } else {
                // Fallback to simple JSON decode
                json_decode( $schema );
                $valid_json = ( json_last_error() === JSON_ERROR_NONE );
                if ( ! $valid_json ) {
                    $validation_message = ' Error: ' . json_last_error_msg();
                }
            }
        }
        $checks['valid_json'] = array(
            'pass'    => $valid_json,
            'label'   => __( 'Valid JSON', 'wp-ai-seo-schema-generator' ),
            'message' => $valid_json
                ? __( 'Schema is valid JSON.', 'wp-ai-seo-schema-generator' )
                : __( 'Schema contains invalid JSON. Try regenerating.', 'wp-ai-seo-schema-generator' ) . $validation_message,
        );
        if ( ! $valid_json && ! empty( $schema ) ) {
            $all_pass = false;
        }

        // Check 3: Post type enabled
        $post_type     = get_post_type( $post_id );
        $enabled_types = $settings['enabled_post_types'] ?? array( 'page' );
        $type_enabled  = in_array( $post_type, $enabled_types, true );
        $checks['post_type_enabled'] = array(
            'pass'    => $type_enabled,
            'label'   => __( 'Post type enabled', 'wp-ai-seo-schema-generator' ),
            'message' => $type_enabled
                ? sprintf(
                    /* translators: %s: post type name */
                    __( 'Post type "%s" is enabled for schema output.', 'wp-ai-seo-schema-generator' ),
                    $post_type
                )
                : sprintf(
                    /* translators: %s: post type name */
                    __( 'Post type "%s" is not enabled. Enable it in Settings > AI JSON-LD.', 'wp-ai-seo-schema-generator' ),
                    $post_type
                ),
        );
        if ( ! $type_enabled ) {
            $all_pass = false;
        }

        // Check 4: Post is published
        $post        = get_post( $post_id );
        $is_published = ( $post && 'publish' === $post->post_status );
        $post_status  = $post ? $post->post_status : 'unknown';
        $checks['post_published'] = array(
            'pass'    => $is_published,
            'label'   => __( 'Post published', 'wp-ai-seo-schema-generator' ),
            'message' => $is_published
                ? __( 'Post is published and publicly accessible.', 'wp-ai-seo-schema-generator' )
                : sprintf(
                    /* translators: %s: post status */
                    __( 'Post status is "%s". Schema only outputs on published posts.', 'wp-ai-seo-schema-generator' ),
                    $post_status
                ),
            'warning' => ! $is_published, // This is a warning, not necessarily a blocker for preview
        );
        // Note: Don't set all_pass = false for drafts, as they might be previewing

        // Check 5: SEO plugin conflict
        $conflict_result = $this->conflict_detector->should_output( $post_id, $settings );
        $no_conflict     = $conflict_result['should_output'];
        $checks['no_seo_conflict'] = array(
            'pass'    => $no_conflict,
            'label'   => __( 'No SEO plugin conflict', 'wp-ai-seo-schema-generator' ),
            'message' => $no_conflict
                ? __( 'No conflicting SEO plugin schema detected.', 'wp-ai-seo-schema-generator' )
                : $conflict_result['reason'],
        );
        if ( ! $no_conflict ) {
            $all_pass = false;
        }

        // Check 6: Output location configured
        $output_location = $settings['output_location'] ?? 'head';
        $checks['output_location'] = array(
            'pass'    => true, // Always passes, just informational
            'label'   => __( 'Output location', 'wp-ai-seo-schema-generator' ),
            'message' => sprintf(
                /* translators: %s: output location (head or after_content) */
                __( 'Schema will be injected in: %s', 'wp-ai-seo-schema-generator' ),
                'head' === $output_location
                    ? __( 'HTML head', 'wp-ai-seo-schema-generator' )
                    : __( 'after post content', 'wp-ai-seo-schema-generator' )
            ),
            'info'    => true,
        );

        // Check 7: Filter check (we can only check if a filter exists, not what it returns at runtime)
        $has_filter = has_filter( 'wp_ai_schema_should_output' );
        $checks['no_filter_blocking'] = array(
            'pass'    => ! $has_filter,
            'label'   => __( 'No blocking filters', 'wp-ai-seo-schema-generator' ),
            'message' => $has_filter
                ? __( 'A filter is attached to wp_ai_schema_should_output. This may conditionally block output.', 'wp-ai-seo-schema-generator' )
                : __( 'No custom filters are blocking output.', 'wp-ai-seo-schema-generator' ),
            'warning' => $has_filter, // It's a warning, not a definite fail
        );

        // Generate summary
        $blocking_checks = array_filter(
            $checks,
            function ( $check ) {
                return ! $check['pass'] && empty( $check['info'] ) && empty( $check['warning'] );
            }
        );

        if ( empty( $blocking_checks ) && ! empty( $schema ) ) {
            $summary = __( 'All checks passed. Schema should appear on the frontend.', 'wp-ai-seo-schema-generator' );
        } elseif ( empty( $schema ) ) {
            $summary = __( 'No schema generated yet.', 'wp-ai-seo-schema-generator' );
        } else {
            $summary = sprintf(
                /* translators: %d: number of issues */
                _n(
                    '%d issue found that may prevent schema output.',
                    '%d issues found that may prevent schema output.',
                    count( $blocking_checks ),
                    'wp-ai-seo-schema-generator'
                ),
                count( $blocking_checks )
            );
        }

        return array(
            'checks'      => $checks,
            'will_output' => $all_pass && ! empty( $schema ),
            'summary'     => $summary,
            'post_url'    => get_permalink( $post_id ),
            'post_status' => $post_status,
        );
    }

    /**
     * Verify schema presence on frontend by fetching the page
     *
     * @param int $post_id Post ID to verify.
     * @return array {
     *     Verification result.
     *
     *     @type bool   $success       Whether verification succeeded.
     *     @type bool   $schema_found  Whether schema was found on page.
     *     @type bool   $schema_match  Whether found schema matches stored schema.
     *     @type string $message       Human-readable result message.
     *     @type string $found_schema  The schema found on the page (if any).
     * }
     */
    public function verify_frontend( int $post_id ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array(
                'success'      => false,
                'schema_found' => false,
                'schema_match' => false,
                'message'      => __( 'Post not found.', 'wp-ai-seo-schema-generator' ),
                'found_schema' => '',
            );
        }

        // Check if post is published
        if ( 'publish' !== $post->post_status ) {
            return array(
                'success'      => false,
                'schema_found' => false,
                'schema_match' => false,
                'message'      => sprintf(
                    /* translators: %s: post status */
                    __( 'Cannot verify - post is not published (status: %s). Use browser preview instead.', 'wp-ai-seo-schema-generator' ),
                    $post->post_status
                ),
                'found_schema' => '',
                'use_js_verify' => true,
            );
        }

        // Get the post URL
        $url = get_permalink( $post_id );

        if ( ! $url ) {
            return array(
                'success'      => false,
                'schema_found' => false,
                'schema_match' => false,
                'message'      => __( 'Could not get post URL.', 'wp-ai-seo-schema-generator' ),
                'found_schema' => '',
            );
        }

        // Fetch the page
        $response = wp_remote_get(
            $url,
            array(
                'timeout'    => 15,
                'sslverify'  => false, // Allow self-signed certs for local dev
                'user-agent' => 'WP AI Schema Verifier',
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success'      => false,
                'schema_found' => false,
                'schema_match' => false,
                'message'      => sprintf(
                    /* translators: %s: error message */
                    __( 'Could not fetch page: %s', 'wp-ai-seo-schema-generator' ),
                    $response->get_error_message()
                ),
                'found_schema' => '',
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            return array(
                'success'      => false,
                'schema_found' => false,
                'schema_match' => false,
                'message'      => sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Page returned HTTP status %d.', 'wp-ai-seo-schema-generator' ),
                    $status_code
                ),
                'found_schema' => '',
            );
        }

        $body = wp_remote_retrieve_body( $response );

        // Look for JSON-LD script tag
        $pattern = '/<script\s+type=["\']application\/ld\+json["\']>(.*?)<\/script>/is';

        if ( ! preg_match_all( $pattern, $body, $matches ) ) {
            return array(
                'success'      => true,
                'schema_found' => false,
                'schema_match' => false,
                'message'      => __( 'No JSON-LD schema found on page. Check the diagnostic panel for issues.', 'wp-ai-seo-schema-generator' ),
                'found_schema' => '',
            );
        }

        // Get stored schema for comparison
        $stored_schema = get_post_meta( $post_id, '_wp_ai_schema_schema', true );

        // Check each found schema
        foreach ( $matches[1] as $found_schema ) {
            $found_schema = trim( $found_schema );

            // Normalize both for comparison (decode and re-encode)
            $stored_decoded = json_decode( $stored_schema, true );
            $found_decoded  = json_decode( $found_schema, true );

            if ( $stored_decoded && $found_decoded && $stored_decoded === $found_decoded ) {
                return array(
                    'success'      => true,
                    'schema_found' => true,
                    'schema_match' => true,
                    'message'      => __( 'Schema verified! Found on frontend and matches stored schema.', 'wp-ai-seo-schema-generator' ),
                    'found_schema' => $found_schema,
                );
            }
        }

        // Schema found but doesn't match
        return array(
            'success'      => true,
            'schema_found' => true,
            'schema_match' => false,
            'message'      => sprintf(
                /* translators: %d: number of schemas found */
                __( 'Found %d JSON-LD schema(s) on page, but none match the stored schema. This might be from another plugin.', 'wp-ai-seo-schema-generator' ),
                count( $matches[1] )
            ),
            'found_schema' => $matches[1][0],
        );
    }

    /**
     * Get the conflict detector instance
     *
     * @return WP_AI_Schema_Conflict_Detector
     */
    public function get_conflict_detector(): WP_AI_Schema_Conflict_Detector {
        return $this->conflict_detector;
    }
}
