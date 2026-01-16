<?php
/**
 * Streaming handler for real-time AI response feedback
 *
 * Uses Server-Sent Events (SSE) to stream AI responses to the frontend,
 * showing users what the AI is doing in real-time.
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles streaming responses from LLM providers
 */
class WP_AI_Schema_Streaming_Handler {

    /**
     * Provider registry
     *
     * @var WP_AI_Schema_Provider_Registry
     */
    private $provider_registry;

    /**
     * Content processor
     *
     * @var WP_AI_Schema_Content_Processor
     */
    private $content_processor;

    /**
     * Encryption handler
     *
     * @var WP_AI_Schema_Encryption
     */
    private $encryption;

    /**
     * Prompt builder
     *
     * @var WP_AI_Schema_Prompt_Builder
     */
    private $prompt_builder;

    /**
     * Constructor
     *
     * @param WP_AI_Schema_Provider_Registry $provider_registry Provider registry.
     * @param WP_AI_Schema_Content_Processor $content_processor Content processor.
     * @param WP_AI_Schema_Encryption        $encryption        Encryption handler.
     * @param WP_AI_Schema_Prompt_Builder    $prompt_builder    Prompt builder.
     */
    public function __construct(
        WP_AI_Schema_Provider_Registry $provider_registry,
        WP_AI_Schema_Content_Processor $content_processor,
        WP_AI_Schema_Encryption $encryption,
        WP_AI_Schema_Prompt_Builder $prompt_builder
    ) {
        $this->provider_registry = $provider_registry;
        $this->content_processor = $content_processor;
        $this->encryption        = $encryption;
        $this->prompt_builder    = $prompt_builder;
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * Register the streaming REST endpoint
     */
    public function register_rest_routes() {
        register_rest_route(
            'wp-ai-schema/v1',
            '/stream',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_stream' ),
                'permission_callback' => array( $this, 'check_permission' ),
            )
        );
    }

    /**
     * Check if user has permission to use streaming endpoint
     *
     * @return bool
     */
    public function check_permission() {
        return current_user_can( 'edit_posts' );
    }

    /**
     * Handle streaming request
     *
     * @param WP_REST_Request $request Request object.
     */
    public function handle_stream( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $nonce   = $request->get_param( 'nonce' );

        // Verify nonce
        if ( ! wp_verify_nonce( $nonce, 'wp_ai_schema_generate_' . $post_id ) ) {
            $this->send_sse_error( 'Invalid security token' );
            exit;
        }

        // Set SSE headers
        $this->set_sse_headers();

        // Get settings
        $settings = get_option( 'wp_ai_schema_settings', array() );

        // Get provider
        $provider = $this->provider_registry->get_active( $settings );
        if ( ! $provider ) {
            $this->send_sse_error( 'No LLM provider configured' );
            exit;
        }

        // Check API key
        $provider_slug = $provider->get_slug();
        $api_key_field = $provider_slug . '_api_key';
        $api_key       = $this->encryption->decrypt( $settings[ $api_key_field ] ?? '' );
        if ( empty( $api_key ) ) {
            $this->send_sse_error( 'API key not configured' );
            exit;
        }

        // Start the streaming process
        $this->stream_two_pass_generation( $post_id, $provider, $settings, $api_key );
    }

    /**
     * Set headers for Server-Sent Events
     */
    private function set_sse_headers() {
        // Disable output buffering
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' ); // Disable nginx buffering

        // Flush headers
        flush();
    }

    /**
     * Send an SSE event
     *
     * @param string $event Event type.
     * @param array  $data  Event data.
     */
    private function send_sse_event( string $event, array $data ) {
        echo "event: {$event}\n";
        echo 'data: ' . wp_json_encode( $data ) . "\n\n";
        flush();
    }

    /**
     * Send an SSE error
     *
     * @param string $message Error message.
     */
    private function send_sse_error( string $message ) {
        $this->set_sse_headers();
        $this->send_sse_event( 'error', array( 'message' => $message ) );
    }

    /**
     * Stream the two-pass generation process
     *
     * @param int    $post_id  Post ID.
     * @param object $provider LLM provider.
     * @param array  $settings Plugin settings.
     * @param string $api_key  Decrypted API key.
     */
    private function stream_two_pass_generation( int $post_id, $provider, array $settings, string $api_key ) {
        $post = get_post( $post_id );

        if ( ! $post ) {
            $this->send_sse_event( 'error', array( 'message' => 'Post not found' ) );
            return;
        }

        // Phase 1: Fetching content
        $this->send_sse_event( 'status', array(
            'phase'   => 'fetch',
            'message' => 'Fetching page content...',
        ) );

        // Get frontend content
        $raw_html = null;
        if ( 'publish' === $post->post_status ) {
            $raw_html = $this->content_processor->fetch_frontend_content( $post_id );
            if ( is_wp_error( $raw_html ) ) {
                $raw_html = null;
            }
        }

        if ( empty( $raw_html ) ) {
            $raw_html = $this->content_processor->get_best_content( $post_id );
        }

        // Minimal cleaning
        $cleaned_html = $this->content_processor->prepare_raw_html( $raw_html );
        $html_size_kb = round( strlen( $cleaned_html ) / 1024, 1 );

        $this->send_sse_event( 'status', array(
            'phase'   => 'fetch',
            'message' => "Page loaded ({$html_size_kb} KB)",
        ) );

        // Phase 2: Pass 1 - Content Analysis with streaming
        $this->send_sse_event( 'status', array(
            'phase'   => 'pass1',
            'message' => 'AI analyzing page structure...',
        ) );

        // Build analysis payload
        $analysis_payload = $this->build_analysis_payload( $post, $cleaned_html, $settings );

        // Stream the analysis
        $analysis_result = $this->stream_provider_request(
            $provider,
            $api_key,
            $settings,
            $analysis_payload,
            'pass1',
            true // is analysis
        );

        if ( ! $analysis_result['success'] ) {
            $this->send_sse_event( 'error', array(
                'message' => 'Content analysis failed: ' . $analysis_result['error'],
            ) );
            return;
        }

        // Parse analysis result
        $analysis_data = $this->parse_analysis_json( $analysis_result['content'] );
        if ( ! $analysis_data ) {
            $this->send_sse_event( 'error', array(
                'message' => 'Failed to parse content analysis',
            ) );
            return;
        }

        // Report what was found
        $this->report_analysis_findings( $analysis_data );

        // Phase 3: Pass 2 - Schema Generation with streaming
        $this->send_sse_event( 'status', array(
            'phase'   => 'pass2',
            'message' => 'Generating JSON-LD schema...',
        ) );

        // Build schema generation payload
        $schema_payload = $this->prompt_builder->build_payload_from_analysis(
            $post_id,
            $analysis_data,
            $settings
        );

        // Stream the schema generation
        $schema_result = $this->stream_provider_request(
            $provider,
            $api_key,
            $settings,
            $schema_payload,
            'pass2',
            false // is not analysis
        );

        if ( ! $schema_result['success'] ) {
            $this->send_sse_event( 'error', array(
                'message' => 'Schema generation failed: ' . $schema_result['error'],
            ) );
            return;
        }

        // Extract and validate schema
        $schema = $this->extract_schema( $schema_result['content'] );

        if ( empty( $schema ) ) {
            $this->send_sse_event( 'error', array(
                'message' => 'No valid schema in response',
            ) );
            return;
        }

        // Save schema
        $this->save_schema( $post_id, $schema, $settings );

        // Send completion
        $this->send_sse_event( 'complete', array(
            'schema'  => $schema,
            'message' => 'Schema generated successfully!',
        ) );
    }

    /**
     * Build analysis payload for pass 1
     *
     * @param WP_Post $post         Post object.
     * @param string  $cleaned_html Cleaned HTML content.
     * @param array   $settings     Plugin settings.
     * @return array Analysis payload.
     */
    private function build_analysis_payload( WP_Post $post, string $cleaned_html, array $settings ): array {
        $type_hint = get_post_meta( $post->ID, '_wp_ai_schema_type_hint', true );

        return array(
            'page' => array(
                'title'    => get_the_title( $post->ID ),
                'url'      => get_permalink( $post->ID ),
                'pageType' => $post->post_type,
                'content'  => $cleaned_html,
            ),
            'site' => array(
                'name'        => get_bloginfo( 'name' ),
                'url'         => home_url(),
                'description' => get_bloginfo( 'description' ),
            ),
            'typeHint'     => $type_hint ?: 'auto',
            'businessData' => $this->get_business_data( $settings ),
            'isForAnalysis' => true,
        );
    }

    /**
     * Get business data from settings
     *
     * @param array $settings Plugin settings.
     * @return array|null Business data.
     */
    private function get_business_data( array $settings ): ?array {
        if ( empty( $settings['business_name'] ) ) {
            return null;
        }

        return array(
            'name'        => $settings['business_name'] ?? '',
            'description' => $settings['business_description'] ?? '',
            'url'         => $settings['business_url'] ?? '',
            'logo'        => $settings['business_logo'] ?? '',
            'email'       => $settings['business_email'] ?? '',
            'phone'       => $settings['business_phone'] ?? '',
            'address'     => array(
                'street'      => $settings['business_street'] ?? '',
                'city'        => $settings['business_city'] ?? '',
                'state'       => $settings['business_state'] ?? '',
                'postal_code' => $settings['business_postal_code'] ?? '',
                'country'     => $settings['business_country'] ?? '',
            ),
            'social'      => array(
                'facebook'  => $settings['social_facebook'] ?? '',
                'twitter'   => $settings['social_twitter'] ?? '',
                'instagram' => $settings['social_instagram'] ?? '',
                'linkedin'  => $settings['social_linkedin'] ?? '',
                'youtube'   => $settings['social_youtube'] ?? '',
            ),
        );
    }

    /**
     * Stream a request to the provider
     *
     * @param object $provider    LLM provider.
     * @param string $api_key     Decrypted API key.
     * @param array  $settings    Plugin settings.
     * @param array  $payload     Request payload.
     * @param string $phase       Current phase (pass1/pass2).
     * @param bool   $is_analysis Whether this is an analysis request.
     * @return array Result with success, content, error.
     */
    private function stream_provider_request( $provider, string $api_key, array $settings, array $payload, string $phase, bool $is_analysis ): array {
        $provider_slug = $provider->get_slug();
        $model_key     = $provider_slug . '_model';
        $model         = $settings[ $model_key ] ?? '';

        // Fallback to default model if not set
        if ( empty( $model ) ) {
            $model = 'deepseek' === $provider_slug ? 'deepseek-chat' : 'gpt-4o-mini';
            WP_AI_Schema_Generator::log( "Model not set, using default: {$model}" );
        }

        // Get API endpoint
        $endpoint = $this->get_provider_endpoint( $provider_slug );

        // Build messages
        $messages = $is_analysis
            ? $this->build_analysis_messages( $provider, $payload )
            : $this->build_schema_messages( $provider, $payload );

        // Get max tokens from provider
        $max_tokens = 8000;
        if ( method_exists( $provider, 'get_model_config' ) ) {
            $config = $provider->get_model_config( $model );
            $max_tokens = $config['max_output'] ?? 8000;
        }

        // Build request body with streaming enabled
        $body = array(
            'model'       => $model,
            'messages'    => $messages,
            'stream'      => true,
            'temperature' => 0.3,
        );

        // OpenAI uses max_completion_tokens, DeepSeek uses max_tokens
        if ( 'openai' === $provider_slug ) {
            $body['max_completion_tokens'] = $max_tokens;
        } else {
            $body['max_tokens'] = $max_tokens;
        }

        WP_AI_Schema_Generator::log( "Streaming request to {$endpoint} with model {$model}, max_tokens: {$max_tokens}" );

        // Make streaming request
        return $this->make_streaming_request( $endpoint, $api_key, $body, $phase );
    }

    /**
     * Get API endpoint for provider
     *
     * @param string $provider_slug Provider slug.
     * @return string API endpoint URL.
     */
    private function get_provider_endpoint( string $provider_slug ): string {
        $endpoints = array(
            'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
            'openai'   => 'https://api.openai.com/v1/chat/completions',
        );

        return $endpoints[ $provider_slug ] ?? $endpoints['openai'];
    }

    /**
     * Build messages for analysis request
     *
     * @param object $provider Provider instance.
     * @param array  $payload  Request payload.
     * @return array Messages array.
     */
    private function build_analysis_messages( $provider, array $payload ): array {
        return array(
            array(
                'role'    => 'system',
                'content' => WP_AI_Schema_Content_Analyzer::get_analysis_system_prompt(),
            ),
            array(
                'role'    => 'user',
                'content' => WP_AI_Schema_Content_Analyzer::get_analysis_user_prompt( $payload ),
            ),
        );
    }

    /**
     * Build messages for schema generation request
     *
     * @param object $provider Provider instance.
     * @param array  $payload  Request payload.
     * @return array Messages array.
     */
    private function build_schema_messages( $provider, array $payload ): array {
        // Use provider's own message building
        if ( method_exists( $provider, 'build_messages' ) ) {
            return $provider->build_messages( $payload );
        }

        // Fallback
        return array(
            array(
                'role'    => 'system',
                'content' => $provider->get_system_prompt(),
            ),
            array(
                'role'    => 'user',
                'content' => $provider->build_user_message( $payload ),
            ),
        );
    }

    /**
     * Error response body captured during streaming
     *
     * @var string
     */
    private $error_response = '';

    /**
     * Make a streaming HTTP request and forward events
     *
     * @param string $endpoint API endpoint.
     * @param string $api_key  API key.
     * @param array  $body     Request body.
     * @param string $phase    Current phase.
     * @return array Result with success, content, error.
     */
    private function make_streaming_request( string $endpoint, string $api_key, array $body, string $phase ): array {
        // Reset state
        $this->stream_buffer = '';
        $this->accumulated_content = '';
        $this->error_response = '';

        // Use cURL for streaming support
        $ch = curl_init( $endpoint );

        curl_setopt_array( $ch, array(
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ),
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_WRITEFUNCTION  => function( $ch, $data ) use ( $phase ) {
                // Check HTTP code - if error, capture response for debugging
                $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                if ( $http_code >= 400 ) {
                    $this->error_response .= $data;
                    return strlen( $data );
                }
                return $this->process_stream_chunk( $data, $phase );
            },
        ) );

        curl_exec( $ch );

        $error = curl_error( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

        curl_close( $ch );

        if ( $error ) {
            WP_AI_Schema_Generator::log( "cURL error: {$error}", 'error' );
            return array(
                'success' => false,
                'content' => null,
                'error'   => $error,
            );
        }

        if ( $http_code >= 400 ) {
            // Try to parse error response for more details
            $error_detail = $this->error_response;
            $error_json = json_decode( $error_detail, true );

            if ( $error_json && isset( $error_json['error']['message'] ) ) {
                $error_msg = $error_json['error']['message'];
            } elseif ( $error_json && isset( $error_json['message'] ) ) {
                $error_msg = $error_json['message'];
            } else {
                $error_msg = "HTTP {$http_code}";
            }

            WP_AI_Schema_Generator::log( "API error {$http_code}: {$error_detail}", 'error' );

            return array(
                'success' => false,
                'content' => null,
                'error'   => $error_msg,
            );
        }

        return array(
            'success' => true,
            'content' => $this->accumulated_content,
            'error'   => null,
        );
    }

    /**
     * Buffer for incomplete stream data
     *
     * @var string
     */
    private $stream_buffer = '';

    /**
     * Accumulated content from stream
     *
     * @var string
     */
    private $accumulated_content = '';

    /**
     * Process a chunk of streaming data
     *
     * @param string $data  Raw data chunk.
     * @param string $phase Current phase.
     * @return int Number of bytes processed.
     */
    private function process_stream_chunk( string $data, string $phase ): int {
        $this->stream_buffer .= $data;

        // Process complete lines
        while ( ( $pos = strpos( $this->stream_buffer, "\n" ) ) !== false ) {
            $line = substr( $this->stream_buffer, 0, $pos );
            $this->stream_buffer = substr( $this->stream_buffer, $pos + 1 );

            $line = trim( $line );

            // Skip empty lines
            if ( empty( $line ) ) {
                continue;
            }

            // Skip SSE comments
            if ( strpos( $line, ':' ) === 0 ) {
                continue;
            }

            // Parse data lines
            if ( strpos( $line, 'data: ' ) === 0 ) {
                $json_str = substr( $line, 6 );

                // Check for stream end
                if ( '[DONE]' === $json_str ) {
                    continue;
                }

                $json = json_decode( $json_str, true );

                if ( $json && isset( $json['choices'][0]['delta']['content'] ) ) {
                    $content = $json['choices'][0]['delta']['content'];
                    $this->accumulated_content .= $content;

                    // Send content event
                    $this->send_sse_event( 'content', array(
                        'phase' => $phase,
                        'chunk' => $content,
                        'total' => strlen( $this->accumulated_content ),
                    ) );
                }
            }
        }

        return strlen( $data );
    }

    /**
     * Report findings from analysis
     *
     * @param array $analysis_data Parsed analysis data.
     */
    private function report_analysis_findings( array $analysis_data ) {
        $findings = array();

        if ( ! empty( $analysis_data['testimonials'] ) ) {
            $count = count( $analysis_data['testimonials'] );
            $findings[] = "{$count} testimonial" . ( $count > 1 ? 's' : '' );
        }

        if ( ! empty( $analysis_data['faqs'] ) ) {
            $count = count( $analysis_data['faqs'] );
            $findings[] = "{$count} FAQ" . ( $count > 1 ? 's' : '' );
        }

        if ( ! empty( $analysis_data['services'] ) ) {
            $count = count( $analysis_data['services'] );
            $findings[] = "{$count} service" . ( $count > 1 ? 's' : '' );
        }

        if ( ! empty( $analysis_data['team_members'] ) ) {
            $count = count( $analysis_data['team_members'] );
            $findings[] = "{$count} team member" . ( $count > 1 ? 's' : '' );
        }

        if ( ! empty( $analysis_data['products'] ) ) {
            $count = count( $analysis_data['products'] );
            $findings[] = "{$count} product" . ( $count > 1 ? 's' : '' );
        }

        $message = empty( $findings )
            ? 'Content analyzed'
            : 'Found: ' . implode( ', ', $findings );

        $this->send_sse_event( 'status', array(
            'phase'    => 'pass1',
            'message'  => $message,
            'findings' => $analysis_data['item_counts'] ?? array(),
        ) );
    }

    /**
     * Parse analysis JSON from response
     *
     * @param string $content Response content.
     * @return array|null Parsed data or null.
     */
    private function parse_analysis_json( string $content ): ?array {
        // Try to extract JSON from response
        $content = trim( $content );

        // Remove markdown code fences if present
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/i', $content, $matches ) ) {
            $content = trim( $matches[1] );
        }

        $data = json_decode( $content, true );

        if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
            return $data;
        }

        return null;
    }

    /**
     * Extract JSON-LD schema from response
     *
     * @param string $content Response content.
     * @return string|null Schema JSON or null.
     */
    private function extract_schema( string $content ): ?string {
        $content = trim( $content );

        // Remove markdown code fences
        if ( preg_match( '/```(?:json|json-ld)?\s*([\s\S]*?)```/i', $content, $matches ) ) {
            $content = trim( $matches[1] );
        }

        // Validate it's proper JSON
        $decoded = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return null;
        }

        // Re-encode with proper formatting
        return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Save schema to post meta
     *
     * @param int    $post_id  Post ID.
     * @param string $schema   Schema JSON.
     * @param array  $settings Plugin settings.
     */
    private function save_schema( int $post_id, string $schema, array $settings ) {
        // Generate content hash for caching
        $provider_slug = $settings['provider'] ?? 'deepseek';
        $model_key     = $provider_slug . '_model';
        $model         = $settings[ $model_key ] ?? '';
        $hash_input    = $schema . $provider_slug . $model;
        $hash          = hash( 'sha256', $hash_input );

        update_post_meta( $post_id, '_wp_ai_schema_schema', $schema );
        update_post_meta( $post_id, '_wp_ai_schema_schema_hash', $hash );
        update_post_meta( $post_id, '_wp_ai_schema_schema_last_generated', time() );
        delete_post_meta( $post_id, '_wp_ai_schema_schema_error' );
    }
}
