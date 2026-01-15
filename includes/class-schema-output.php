<?php
/**
 * Schema output class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles frontend output of JSON-LD schema
 */
class AI_JSONLD_Schema_Output {

    /**
     * Conflict detector
     *
     * @var AI_JSONLD_Conflict_Detector
     */
    private $conflict_detector;

    /**
     * Constructor
     *
     * @param AI_JSONLD_Conflict_Detector $conflict_detector Conflict detector instance.
     */
    public function __construct( AI_JSONLD_Conflict_Detector $conflict_detector ) {
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
        $settings = AI_JSONLD_Generator::get_settings();

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
        $settings = AI_JSONLD_Generator::get_settings();

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
        $settings = AI_JSONLD_Generator::get_settings();

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
        $schema = get_post_meta( $post_id, '_ai_jsonld_schema', true );

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
        $should_output_filtered = apply_filters( 'ai_jsonld_should_output', true, $post_id );

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
        $schema = get_post_meta( $post_id, '_ai_jsonld_schema', true );
        return ! empty( $schema );
    }

    /**
     * Get schema for a post
     *
     * @param int $post_id Post ID.
     * @return string|null Schema JSON or null.
     */
    public function get_schema( int $post_id ): ?string {
        $schema = get_post_meta( $post_id, '_ai_jsonld_schema', true );
        return $schema ?: null;
    }

    /**
     * Delete schema for a post
     *
     * @param int $post_id Post ID.
     */
    public function delete_schema( int $post_id ): void {
        delete_post_meta( $post_id, '_ai_jsonld_schema' );
        delete_post_meta( $post_id, '_ai_jsonld_schema_last_generated' );
        delete_post_meta( $post_id, '_ai_jsonld_schema_status' );
        delete_post_meta( $post_id, '_ai_jsonld_schema_error' );
        delete_post_meta( $post_id, '_ai_jsonld_schema_hash' );
        delete_post_meta( $post_id, '_ai_jsonld_detected_type' );
    }
}
