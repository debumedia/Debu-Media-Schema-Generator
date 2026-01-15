<?php
/**
 * DeepSeek provider class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DeepSeek LLM provider implementation
 */
class AI_JSONLD_DeepSeek_Provider extends AI_JSONLD_Abstract_Provider {

    /**
     * API endpoint
     */
    const API_ENDPOINT = 'https://api.deepseek.com/v1/chat/completions';

    /**
     * Default model
     */
    const DEFAULT_MODEL = 'deepseek-chat';

    /**
     * Get provider name
     *
     * @return string
     */
    public function get_name(): string {
        return 'DeepSeek';
    }

    /**
     * Get provider slug
     *
     * @return string
     */
    public function get_slug(): string {
        return 'deepseek';
    }

    /**
     * Generate JSON-LD schema
     *
     * @param array $payload  Prompt payload with page and site data.
     * @param array $settings Plugin settings.
     * @return array Response array.
     */
    public function generate_schema( array $payload, array $settings ): array {
        // Check rate limit
        $rate_limited = $this->is_rate_limited();
        if ( false !== $rate_limited ) {
            return array(
                'success'     => false,
                'schema'      => '',
                'status_code' => 429,
                'error'       => sprintf(
                    /* translators: %d: seconds until rate limit expires */
                    __( 'Rate limited. Please try again in %d seconds.', 'ai-jsonld-generator' ),
                    $rate_limited - time()
                ),
                'headers'     => array(),
            );
        }

        // Get API key
        $api_key = $this->get_api_key( $settings, 'deepseek_api_key' );

        if ( empty( $api_key ) ) {
            return array(
                'success'     => false,
                'schema'      => '',
                'status_code' => 0,
                'error'       => __( 'DeepSeek API key is not configured.', 'ai-jsonld-generator' ),
                'headers'     => array(),
            );
        }

        // Build messages
        $messages = $this->build_messages( $payload );

        // Build request body
        $body = array(
            'model'       => $settings['deepseek_model'] ?? self::DEFAULT_MODEL,
            'messages'    => $messages,
            'temperature' => floatval( $settings['temperature'] ?? 0.2 ),
            'max_tokens'  => intval( $settings['max_tokens'] ?? 1200 ),
        );

        // Make request
        $response = $this->make_request(
            self::API_ENDPOINT,
            array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            $body,
            60 // 60 second timeout for generation
        );

        if ( ! $response['success'] ) {
            return array(
                'success'     => false,
                'schema'      => '',
                'status_code' => $response['status_code'],
                'error'       => $response['error'],
                'headers'     => $response['headers'],
            );
        }

        // Parse response
        return $this->parse_response( $response['body'] );
    }

    /**
     * Test API connection
     *
     * @param array $settings Plugin settings.
     * @return array Response array.
     */
    public function test_connection( array $settings ): array {
        $api_key = $this->get_api_key( $settings, 'deepseek_api_key' );

        if ( empty( $api_key ) ) {
            return array(
                'success' => false,
                'message' => '',
                'error'   => __( 'API key is required.', 'ai-jsonld-generator' ),
            );
        }

        // Make a minimal request to test the connection
        $body = array(
            'model'       => $settings['deepseek_model'] ?? self::DEFAULT_MODEL,
            'messages'    => array(
                array(
                    'role'    => 'user',
                    'content' => 'Say "OK" and nothing else.',
                ),
            ),
            'max_tokens'  => 10,
            'temperature' => 0,
        );

        $response = $this->make_request(
            self::API_ENDPOINT,
            array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            $body,
            30
        );

        if ( ! $response['success'] ) {
            return array(
                'success' => false,
                'message' => '',
                'error'   => $response['error'],
            );
        }

        return array(
            'success' => true,
            'message' => __( 'Connection successful! API key is valid.', 'ai-jsonld-generator' ),
            'error'   => '',
        );
    }

    /**
     * Get settings fields for this provider
     *
     * @return array
     */
    public function get_settings_fields(): array {
        return array(
            'deepseek_api_key' => array(
                'label'       => __( 'API Key', 'ai-jsonld-generator' ),
                'type'        => 'password',
                'description' => __( 'Your DeepSeek API key.', 'ai-jsonld-generator' ),
                'required'    => true,
            ),
            'deepseek_model' => array(
                'label'       => __( 'Model', 'ai-jsonld-generator' ),
                'type'        => 'text',
                'default'     => self::DEFAULT_MODEL,
                'description' => __( 'The DeepSeek model to use.', 'ai-jsonld-generator' ),
            ),
        );
    }

    /**
     * Build messages array for API request
     *
     * @param array $payload Prompt payload.
     * @return array Messages array.
     */
    private function build_messages( array $payload ): array {
        $system_prompt = $this->get_system_prompt();
        $user_message  = $this->build_user_message( $payload );

        return array(
            array(
                'role'    => 'system',
                'content' => $system_prompt,
            ),
            array(
                'role'    => 'user',
                'content' => $user_message,
            ),
        );
    }

    /**
     * Get system prompt
     *
     * @return string
     */
    private function get_system_prompt(): string {
        return 'You are a schema.org JSON-LD generator for web pages.

STRICT REQUIREMENTS:
1. Output ONLY valid JSON. No markdown code fences, no explanations, no commentary.
2. Every object must include "@context": "https://schema.org"
3. Use appropriate schema.org types based on the content.
4. NEVER invent or hallucinate information. If data is not provided, omit that field entirely.
5. For URLs, only use URLs explicitly provided in the input.
6. For dates, only use dates explicitly provided.
7. For contact information, only include if explicitly provided.

OUTPUT FORMAT:
- Single schema object: {"@context": "https://schema.org", "@type": "...", ...}
- Multiple schemas: Use @graph format: {"@context": "https://schema.org", "@graph": [...]}

SCHEMA TYPE SELECTION:
- Article: Blog posts, news articles, editorial content
- WebPage: Generic informational pages
- Service: Pages describing services offered
- LocalBusiness: Business location/contact pages
- FAQPage: ONLY if content contains clear Question/Answer pairs
- Product: Product description pages
- HowTo: Step-by-step instruction content
- Event: Event announcements with dates/locations

Remember: Accuracy over completeness. Omit uncertain fields rather than guess.';
    }

    /**
     * Build user message from payload
     *
     * @param array $payload Prompt payload.
     * @return string User message.
     */
    private function build_user_message( array $payload ): string {
        $page_data = $payload['page'] ?? array();
        $site_data = $payload['site'] ?? array();
        $type_hint = $payload['typeHint'] ?? 'auto';

        // Build truncation indicator if content was truncated
        $truncation_note = '';
        if ( ! empty( $page_data['contentTruncated'] ) ) {
            $truncation_note = sprintf(
                "\n[Content truncated: showing %d of %d characters]",
                mb_strlen( $page_data['content'] ?? '' ),
                $page_data['originalLength'] ?? 0
            );
        }

        // Build type hint instruction
        $type_hint_instruction = '';
        if ( 'auto' !== $type_hint && ! empty( $type_hint ) ) {
            $type_hint_instruction = sprintf(
                "\nPREFERRED SCHEMA TYPE: %s\nUse this schema type if the content supports it. If the content clearly does not match this type, choose the most appropriate alternative.",
                $type_hint
            );
        }

        // Build the message
        $message = 'Generate JSON-LD schema for the following page:

PAGE DATA:
' . wp_json_encode( $page_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '

SITE DATA:
' . wp_json_encode( $site_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( $truncation_note ) {
            $message .= $truncation_note;
        }

        if ( $type_hint_instruction ) {
            $message .= $type_hint_instruction;
        }

        $message .= "\n\nGenerate the JSON-LD now:";

        return $message;
    }

    /**
     * Parse API response
     *
     * @param string $body Response body.
     * @return array Parsed response.
     */
    private function parse_response( string $body ): array {
        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array(
                'success'     => false,
                'schema'      => '',
                'status_code' => 200,
                'error'       => __( 'Failed to parse API response.', 'ai-jsonld-generator' ),
                'headers'     => array(),
            );
        }

        // Extract content from response
        $content = $decoded['choices'][0]['message']['content'] ?? '';

        if ( empty( $content ) ) {
            return array(
                'success'     => false,
                'schema'      => '',
                'status_code' => 200,
                'error'       => __( 'Empty response from API.', 'ai-jsonld-generator' ),
                'headers'     => array(),
            );
        }

        return array(
            'success'     => true,
            'schema'      => $content,
            'status_code' => 200,
            'error'       => '',
            'headers'     => array(),
        );
    }
}
