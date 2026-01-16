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
     * @var WP_AI_Schema_Validator
     */
    private $schema_validator;

    /**
     * Encryption handler
     *
     * @var WP_AI_Schema_Encryption
     */
    private $encryption;

    /**
     * Content analyzer for two-pass generation
     *
     * @var WP_AI_Schema_Content_Analyzer|null
     */
    private $content_analyzer;

    /**
     * Constructor
     *
     * @param WP_AI_Schema_Content_Processor $content_processor Content processor.
     * @param WP_AI_Schema_Prompt_Builder    $prompt_builder    Prompt builder.
     * @param WP_AI_Schema_Provider_Registry $provider_registry Provider registry.
     * @param WP_AI_Schema_Validator  $schema_validator  Schema validator.
     * @param WP_AI_Schema_Encryption        $encryption        Encryption handler.
     */
    public function __construct(
        WP_AI_Schema_Content_Processor $content_processor,
        WP_AI_Schema_Prompt_Builder $prompt_builder,
        WP_AI_Schema_Provider_Registry $provider_registry,
        WP_AI_Schema_Validator $schema_validator,
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
     * Set the content analyzer instance
     *
     * @param WP_AI_Schema_Content_Analyzer $analyzer Content analyzer.
     */
    public function set_content_analyzer( WP_AI_Schema_Content_Analyzer $analyzer ): void {
        $this->content_analyzer = $analyzer;
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'wp_ajax_wp_ai_schema_generate', array( $this, 'handle_generate' ) );
        add_action( 'wp_ajax_wp_ai_schema_diagnose', array( $this, 'handle_diagnose' ) );
        add_action( 'wp_ajax_wp_ai_schema_verify_frontend', array( $this, 'handle_verify_frontend' ) );
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

        // Get flags
        $force          = ! empty( $_POST['force'] );
        $fetch_frontend = ! empty( $_POST['fetch_frontend'] );
        $deep_analysis  = ! empty( $_POST['deep_analysis'] );

        // Generate schema (use two-pass if deep analysis enabled)
        if ( $deep_analysis && $this->content_analyzer ) {
            $result = $this->generate_schema_two_pass( $post_id, $force, $fetch_frontend );
        } else {
            $result = $this->generate_schema( $post_id, $force, $fetch_frontend );
        }

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Generate schema for a post
     *
     * @param int  $post_id        Post ID.
     * @param bool $force          Force regeneration.
     * @param bool $fetch_frontend Fetch content from frontend (for page builders).
     * @return array Result array.
     */
    public function generate_schema( int $post_id, bool $force = false, bool $fetch_frontend = false ): array {
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

        // If fetch_frontend is requested, try to get content from the live page
        $frontend_content = null;
        if ( $fetch_frontend ) {
            $post = get_post( $post_id );
            if ( $post && 'publish' === $post->post_status ) {
                $result = $this->content_processor->fetch_frontend_content( $post_id );
                if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
                    $frontend_content = $result;
                    WP_AI_Schema_Generator::log( sprintf( 'Fetched frontend content for post %d: %d chars', $post_id, mb_strlen( $result ) ) );
                } else {
                    WP_AI_Schema_Generator::log(
                        sprintf( 'Failed to fetch frontend for post %d: %s', $post_id, is_wp_error( $result ) ? $result->get_error_message() : 'empty result' ),
                        'warning'
                    );
                }
            }
        }

        // Check if content is empty (unless we have frontend content)
        if ( empty( $frontend_content ) && $this->content_processor->is_content_empty( $post_id ) ) {
            return array(
                'success' => false,
                'message' => __( 'Page content is too short to generate meaningful schema. Try enabling "Fetch from frontend" if using a page builder.', 'wp-ai-seo-schema-generator' ),
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

        // Get the model being used for this provider
        $provider_slug = $provider->get_slug();
        $model_key     = $provider_slug . '_model';
        $model         = $settings[ $model_key ] ?? '';

        // Check API key for the active provider
        $api_key_field = $provider_slug . '_api_key';
        $api_key       = $this->encryption->decrypt( $settings[ $api_key_field ] ?? '' );
        if ( empty( $api_key ) ) {
            $this->save_error( $post_id, __( 'API key not configured.', 'wp-ai-seo-schema-generator' ) );
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: provider name */
                    __( '%s API key is not configured. Please configure it in Settings.', 'wp-ai-seo-schema-generator' ),
                    $provider->get_name()
                ),
            );
        }

        // Get max content chars from provider's model config
        $max_content_chars = $provider->get_max_content_chars( $model );

        // Build payload with provider-specific content limit
        // Pass frontend content if we fetched it
        $payload = $this->prompt_builder->build_payload( $post_id, $settings, $max_content_chars, $frontend_content );

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
     * Generate schema using two-pass content analysis
     *
     * Pass 1: Analyze content and classify into structured sections
     * Pass 2: Generate schema from the analyzed/classified data
     *
     * @param int  $post_id        Post ID.
     * @param bool $force          Force regeneration.
     * @param bool $fetch_frontend Fetch content from frontend.
     * @return array Result array.
     */
    public function generate_schema_two_pass( int $post_id, bool $force = false, bool $fetch_frontend = false ): array {
        $settings = WP_AI_Schema_Generator::get_settings();

        // Check per-post cooldown
        $cooldown_key = 'wp_ai_schema_cooldown_' . $post_id;
        if ( get_transient( $cooldown_key ) && ! $force ) {
            return array(
                'success'  => false,
                'message'  => __( 'Please wait before regenerating.', 'wp-ai-seo-schema-generator' ),
                'cooldown' => true,
            );
        }

        // Check global rate limit
        $rate_limit_until = get_transient( 'wp_ai_schema_rate_limit_until' );
        if ( $rate_limit_until && time() < $rate_limit_until ) {
            $wait_time = $rate_limit_until - time();
            return array(
                'success'      => false,
                'message'      => sprintf(
                    /* translators: %d: seconds to wait */
                    __( 'Rate limited. Please try again in %d seconds.', 'wp-ai-seo-schema-generator' ),
                    $wait_time
                ),
                'rate_limited' => true,
                'wait_time'    => $wait_time,
            );
        }

        // If fetch_frontend is requested, try to get content from the live page
        $frontend_content = null;
        if ( $fetch_frontend ) {
            $post = get_post( $post_id );
            if ( $post && 'publish' === $post->post_status ) {
                $result = $this->content_processor->fetch_frontend_content( $post_id );
                if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
                    $frontend_content = $result;
                    WP_AI_Schema_Generator::log( sprintf( 'Two-pass: Fetched frontend content for post %d: %d chars', $post_id, mb_strlen( $result ) ) );
                }
            }
        }

        // Check if content is empty
        if ( empty( $frontend_content ) && $this->content_processor->is_content_empty( $post_id ) ) {
            return array(
                'success' => false,
                'message' => __( 'Page content is too short to generate meaningful schema.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Check if we should regenerate (skip cache check if forced)
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
        $provider_slug = $provider->get_slug();
        $api_key_field = $provider_slug . '_api_key';
        $api_key       = $this->encryption->decrypt( $settings[ $api_key_field ] ?? '' );
        if ( empty( $api_key ) ) {
            $this->save_error( $post_id, __( 'API key not configured.', 'wp-ai-seo-schema-generator' ) );
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: provider name */
                    __( '%s API key is not configured.', 'wp-ai-seo-schema-generator' ),
                    $provider->get_name()
                ),
            );
        }

        // Set cooldown before API calls
        set_transient( $cooldown_key, true, self::COOLDOWN_SECONDS * 2 ); // Double cooldown for two-pass

        // Track timing for debugging
        $debug_timing          = array();
        $two_pass_start        = microtime( true );

        WP_AI_Schema_Generator::log( sprintf( 'Starting two-pass schema generation for post %d', $post_id ) );

        // ========================
        // PASS 1: Content Analysis
        // ========================
        $pass1_start = microtime( true );
        WP_AI_Schema_Generator::log( 'Pass 1: Analyzing content...' );

        $analysis_result = $this->content_analyzer->analyze( $post_id, $settings, $frontend_content );

        $pass1_duration              = round( microtime( true ) - $pass1_start, 2 );
        $debug_timing['pass1_seconds'] = $pass1_duration;

        if ( ! $analysis_result['success'] ) {
            $this->save_error( $post_id, 'Pass 1 failed: ' . $analysis_result['error'] );
            WP_AI_Schema_Generator::log( sprintf( 'Pass 1 failed for post %d after %.2fs: %s', $post_id, $pass1_duration, $analysis_result['error'] ), 'error' );

            return array(
                'success' => false,
                'message' => __( 'Content analysis failed: ', 'wp-ai-seo-schema-generator' ) . $analysis_result['error'],
                'pass'    => 1,
                'debug'   => array( 'timing' => $debug_timing ),
            );
        }

        $analyzed_data = $analysis_result['data'];
        WP_AI_Schema_Generator::log( sprintf( 'Pass 1 completed in %.2fs. Keys: %s', $pass1_duration, implode( ', ', array_keys( $analyzed_data ) ) ) );

        // Store analysis result for debugging (optional)
        update_post_meta( $post_id, '_wp_ai_schema_analysis', wp_json_encode( $analyzed_data ) );

        // ========================
        // PASS 2: Schema Generation
        // ========================
        $pass2_start = microtime( true );
        WP_AI_Schema_Generator::log( 'Pass 2: Generating schema from analyzed data...' );

        // Get model config
        $model_key         = $provider_slug . '_model';
        $model             = $settings[ $model_key ] ?? '';
        $max_content_chars = $provider->get_max_content_chars( $model );

        // Build payload from analyzed data
        $payload = $this->prompt_builder->build_payload_from_analysis( $post_id, $analyzed_data, $settings, $max_content_chars );

        if ( empty( $payload ) ) {
            $this->save_error( $post_id, __( 'Failed to build schema prompt from analysis.', 'wp-ai-seo-schema-generator' ) );
            return array(
                'success' => false,
                'message' => __( 'Failed to prepare analyzed data for schema generation.', 'wp-ai-seo-schema-generator' ),
                'pass'    => 2,
                'debug'   => array( 'timing' => $debug_timing ),
            );
        }

        // Calculate payload size for debugging
        $payload_json                      = wp_json_encode( $payload );
        $payload_size_kb                   = round( strlen( $payload_json ) / 1024, 1 );
        $debug_timing['pass2_payload_kb']  = $payload_size_kb;

        WP_AI_Schema_Generator::log( sprintf( 'Pass 2 payload size: %.1f KB', $payload_size_kb ) );

        // Call provider to generate schema
        $response = $provider->generate_schema( $payload, $settings );

        $pass2_duration                = round( microtime( true ) - $pass2_start, 2 );
        $debug_timing['pass2_seconds'] = $pass2_duration;

        WP_AI_Schema_Generator::log( sprintf( 'Pass 2 API call completed in %.2fs', $pass2_duration ) );

        if ( ! $response['success'] ) {
            $this->save_error( $post_id, 'Pass 2 failed: ' . $response['error'] );
            WP_AI_Schema_Generator::log( sprintf( 'Pass 2 failed for post %d after %.2fs: %s', $post_id, $pass2_duration, $response['error'] ), 'error' );

            return array(
                'success' => false,
                'message' => __( 'Schema generation failed: ', 'wp-ai-seo-schema-generator' ) . $response['error'],
                'pass'    => 2,
                'debug'   => array( 'timing' => $debug_timing ),
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
                'pass'    => 2,
                'debug'   => array( 'timing' => $debug_timing ),
            );
        }

        // Calculate total time
        $total_duration                = round( microtime( true ) - $two_pass_start, 2 );
        $debug_timing['total_seconds'] = $total_duration;

        // Save schema
        $hash = $this->content_processor->generate_hash( $post_id, $settings );
        $time = time();

        update_post_meta( $post_id, '_wp_ai_schema_schema', $validation['schema'] );
        update_post_meta( $post_id, '_wp_ai_schema_schema_last_generated', $time );
        update_post_meta( $post_id, '_wp_ai_schema_schema_status', 'ok' );
        update_post_meta( $post_id, '_wp_ai_schema_schema_hash', $hash );
        update_post_meta( $post_id, '_wp_ai_schema_schema_error', '' );
        update_post_meta( $post_id, '_wp_ai_schema_generation_mode', 'two_pass' );

        if ( ! empty( $validation['type'] ) ) {
            update_post_meta( $post_id, '_wp_ai_schema_detected_type', $validation['type'] );
        }

        WP_AI_Schema_Generator::log( sprintf( 'Two-pass schema generation completed for post %d', $post_id ) );

        $result = array(
            'success'        => true,
            'schema'         => $validation['schema'],
            'cached'         => false,
            'hash'           => $hash,
            'generated_at'   => $time,
            'detected_type'  => $validation['type'],
            'message'        => __( 'Schema generated successfully using deep content analysis!', 'wp-ai-seo-schema-generator' ),
            'two_pass'       => true,
            'analysis_keys'  => array_keys( $analyzed_data ),
        );

        // Include debug data when debug logging is enabled
        if ( ! empty( $settings['debug_logging'] ) ) {
            $result['debug'] = array(
                'timing'         => $debug_timing,
                'pass1_analysis' => $analyzed_data,
                'pass2_payload'  => array(
                    'page'            => $payload['page'] ?? null,
                    'site'            => $payload['site'] ?? null,
                    'business'        => $payload['business'] ?? null,
                    'typeHint'        => $payload['typeHint'] ?? null,
                    'analyzedContent' => $payload['analyzedContent'] ?? null,
                    // Note: schemaReference is large, so we just note it was included
                    'hasSchemaRef'    => ! empty( $payload['schemaReference'] ),
                    'schemaRefSize'   => ! empty( $payload['schemaReference'] ) ? round( strlen( $payload['schemaReference'] ) / 1024, 1 ) . ' KB' : '0 KB',
                ),
                'provider'       => $provider_slug,
                'model'          => $model,
            );
        }

        WP_AI_Schema_Generator::log( sprintf( 
            'Two-pass completed for post %d - Pass 1: %.2fs, Pass 2: %.2fs, Total: %.2fs', 
            $post_id, 
            $debug_timing['pass1_seconds'], 
            $debug_timing['pass2_seconds'], 
            $debug_timing['total_seconds'] 
        ) );

        return $result;
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

    /**
     * Handle AJAX diagnose request
     *
     * Runs diagnostic checks to determine why schema might not appear on frontend.
     */
    public function handle_diagnose() {
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
                'message' => __( 'You do not have permission to view this post.', 'wp-ai-seo-schema-generator' ),
            ) );
        }

        // Get the schema output component
        $schema_output = wp_ai_schema_generator()->get_component( 'schema_output' );

        if ( ! $schema_output ) {
            wp_send_json_error( array(
                'message' => __( 'Schema output component not available.', 'wp-ai-seo-schema-generator' ),
            ) );
        }

        // Run diagnostics
        $diagnostics = $schema_output->diagnose( $post_id );

        wp_send_json_success( $diagnostics );
    }

    /**
     * Handle AJAX verify frontend request
     *
     * Fetches the frontend page and checks if JSON-LD schema is present.
     */
    public function handle_verify_frontend() {
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
                'message' => __( 'You do not have permission to view this post.', 'wp-ai-seo-schema-generator' ),
            ) );
        }

        // Get the schema output component
        $schema_output = wp_ai_schema_generator()->get_component( 'schema_output' );

        if ( ! $schema_output ) {
            wp_send_json_error( array(
                'message' => __( 'Schema output component not available.', 'wp-ai-seo-schema-generator' ),
            ) );
        }

        // Verify frontend
        $result = $schema_output->verify_frontend( $post_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            // Even failures should be returned as success with the result data
            // so the JS can handle them appropriately
            wp_send_json_success( $result );
        }
    }
}
