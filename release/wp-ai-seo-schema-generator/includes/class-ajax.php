<?php
/**
 * AJAX handler class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AJAX requests for schema generation
 */
class WP_AI_Schema_Ajax {

    /**
     * Per-post cooldown in seconds
     */
    const COOLDOWN_SECONDS = 30;

    /**
     * Content processor
     *
     * @var WP_AI_Schema_Content_Processor
     */
    private $content_processor;

    /**
     * Prompt builder
     *
     * @var WP_AI_Schema_Prompt_Builder
     */
    private $prompt_builder;

    /**
     * Provider registry
     *
     * @var WP_AI_Schema_Provider_Registry
     */
    private $provider_registry;

    /**
     * Schema validator
     *
     * @var WP_AI_Schema_Schema_Validator
     */
    private $schema_validator;

    /**
     * Encryption handler
     *
     * @var WP_AI_Schema_Encryption
     */
    private $encryption;

    /**
     * Constructor
     *
     * @param WP_AI_Schema_Content_Processor $content_processor Content processor.
     * @param WP_AI_Schema_Prompt_Builder    $prompt_builder    Prompt builder.
     * @param WP_AI_Schema_Provider_Registry $provider_registry Provider registry.
     * @param WP_AI_Schema_Schema_Validator  $schema_validator  Schema validator.
     * @param WP_AI_Schema_Encryption        $encryption        Encryption handler.
     */
    public function __construct(
        WP_AI_Schema_Content_Processor $content_processor,
        WP_AI_Schema_Prompt_Builder $prompt_builder,
        WP_AI_Schema_Provider_Registry $provider_registry,
        WP_AI_Schema_Schema_Validator $schema_validator,
        WP_AI_Schema_Encryption $encryption
    ) {
        $this->content_processor = $content_processor;
        $this->prompt_builder    = $prompt_builder;
        $this->provider_registry = $provider_registry;
        $this->schema_validator  = $schema_validator;
        $this->encryption        = $encryption;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'wp_ajax_wp_ai_schema_generate', array( $this, 'handle_generate' ) );
    }

    /**
     * Handle AJAX generate request
     */
    public function handle_generate() {
        // Get and sanitize post ID
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $post_id ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid post ID.', 'wp-ai-seo-schema-generator' ),
            ) );
        }

        // Verify nonce
        if ( ! check_ajax_referer( 'wp_ai_schema_generate_' . $post_id, 'nonce', false ) ) {
            wp_send_json_error( array(
                'message' => __( 'Security check failed.', 'wp-ai-seo-schema-generator' ),
            ) );
        }

        // Check capabilities
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'You do not have permission to edit this post.', 'wp-ai-seo-schema-generator' ),
            ) );
        }

        // Get force flag
        $force = ! empty( $_POST['force'] );

        // Generate schema
        $result = $this->generate_schema( $post_id, $force );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Generate schema for a post
     *
     * @param int  $post_id Post ID.
     * @param bool $force   Force regeneration.
     * @return array Result array.
     */
    public function generate_schema( int $post_id, bool $force = false ): array {
        $settings = WP_AI_Schema_Generator::get_settings();

        // Check per-post cooldown
        $cooldown_key = 'wp_ai_schema_cooldown_' . $post_id;
        if ( get_transient( $cooldown_key ) && ! $force ) {
            return array(
                'success' => false,
                'message' => __( 'Please wait before regenerating.', 'wp-ai-seo-schema-generator' ),
                'cooldown' => true,
            );
        }

        // Check global rate limit
        $rate_limit_until = get_transient( 'wp_ai_schema_rate_limit_until' );
        if ( $rate_limit_until && time() < $rate_limit_until ) {
            $wait_time = $rate_limit_until - time();
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %d: seconds to wait */
                    __( 'Rate limited. Please try again in %d seconds.', 'wp-ai-seo-schema-generator' ),
                    $wait_time
                ),
                'rate_limited' => true,
                'wait_time' => $wait_time,
            );
        }

        // Check if content is empty
        if ( $this->content_processor->is_content_empty( $post_id ) ) {
            return array(
                'success' => false,
                'message' => __( 'Page content is too short to generate meaningful schema.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Check if we should regenerate
        if ( ! $this->content_processor->should_regenerate( $post_id, $settings, $force ) ) {
            $schema = get_post_meta( $post_id, '_wp_ai_schema_schema', true );
            $hash   = get_post_meta( $post_id, '_wp_ai_schema_schema_hash', true );
            $time   = get_post_meta( $post_id, '_wp_ai_schema_schema_last_generated', true );

            return array(
                'success'      => true,
                'schema'       => $schema,
                'cached'       => true,
                'hash'         => $hash,
                'generated_at' => intval( $time ),
                'message'      => __( 'Using cached schema.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Get provider
        $provider = $this->provider_registry->get_active( $settings );

        if ( ! $provider ) {
            $this->save_error( $post_id, __( 'No provider configured.', 'wp-ai-seo-schema-generator' ) );
            return array(
                'success' => false,
                'message' => __( 'No LLM provider configured.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Check API key
        $api_key = $this->encryption->decrypt( $settings['deepseek_api_key'] ?? '' );
        if ( empty( $api_key ) ) {
            $this->save_error( $post_id, __( 'API key not configured.', 'wp-ai-seo-schema-generator' ) );
            return array(
                'success' => false,
                'message' => __( 'API key is not configured. Please configure it in Settings.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Build payload
        $payload = $this->prompt_builder->build_payload( $post_id, $settings );

        if ( empty( $payload ) ) {
            $this->save_error( $post_id, __( 'Failed to build prompt payload.', 'wp-ai-seo-schema-generator' ) );
            return array(
                'success' => false,
                'message' => __( 'Failed to prepare content for generation.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Set cooldown before API call
        set_transient( $cooldown_key, true, self::COOLDOWN_SECONDS );

        WP_AI_Schema_Generator::log( sprintf( 'Generating schema for post %d', $post_id ) );

        // Call provider
        $response = $provider->generate_schema( $payload, $settings );

        if ( ! $response['success'] ) {
            $this->save_error( $post_id, $response['error'] );
            WP_AI_Schema_Generator::log( sprintf( 'Generation failed for post %d: %s', $post_id, $response['error'] ), 'error' );

            return array(
                'success' => false,
                'message' => $response['error'],
            );
        }

        // Validate schema
        $validation = $this->schema_validator->validate( $response['schema'] );

        if ( ! $validation['valid'] ) {
            $this->save_error( $post_id, $validation['error'] );
            WP_AI_Schema_Generator::log( sprintf( 'Validation failed for post %d: %s', $post_id, $validation['error'] ), 'error' );

            return array(
                'success' => false,
                'message' => $validation['error'],
            );
        }

        // Save schema
        $hash = $this->content_processor->generate_hash( $post_id, $settings );
        $time = time();

        update_post_meta( $post_id, '_wp_ai_schema_schema', $validation['schema'] );
        update_post_meta( $post_id, '_wp_ai_schema_schema_last_generated', $time );
        update_post_meta( $post_id, '_wp_ai_schema_schema_status', 'ok' );
        update_post_meta( $post_id, '_wp_ai_schema_schema_hash', $hash );
        update_post_meta( $post_id, '_wp_ai_schema_schema_error', '' );

        if ( ! empty( $validation['type'] ) ) {
            update_post_meta( $post_id, '_wp_ai_schema_detected_type', $validation['type'] );
        }

        WP_AI_Schema_Generator::log( sprintf( 'Schema generated successfully for post %d', $post_id ) );

        return array(
            'success'       => true,
            'schema'        => $validation['schema'],
            'cached'        => false,
            'hash'          => $hash,
            'generated_at'  => $time,
            'detected_type' => $validation['type'],
            'message'       => __( 'Schema generated successfully!', 'wp-ai-seo-schema-generator' ),
        );
    }

    /**
     * Save error to post meta
     *
     * @param int    $post_id Post ID.
     * @param string $error   Error message.
     */
    private function save_error( int $post_id, string $error ): void {
        update_post_meta( $post_id, '_wp_ai_schema_schema_status', 'error' );
        update_post_meta( $post_id, '_wp_ai_schema_schema_error', $error );
    }

    /**
     * Get remaining cooldown time for a post
     *
     * @param int $post_id Post ID.
     * @return int Remaining seconds or 0 if no cooldown.
     */
    public function get_cooldown_remaining( int $post_id ): int {
        $cooldown_key = 'wp_ai_schema_cooldown_' . $post_id;
        $timeout_key  = '_transient_timeout_' . $cooldown_key;

        $timeout = get_option( $timeout_key );

        if ( ! $timeout ) {
            return 0;
        }

        $remaining = $timeout - time();

        return max( 0, $remaining );
    }
}
