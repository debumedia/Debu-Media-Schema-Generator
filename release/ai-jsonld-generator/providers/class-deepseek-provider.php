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
        $schema_reference = $payload['schemaReference'] ?? '';
        $system_prompt    = $this->get_system_prompt( $schema_reference );
        $user_message     = $this->build_user_message( $payload );

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
     * @param string $schema_reference Optional schema reference documentation.
     * @return string
     */
    private function get_system_prompt( string $schema_reference = '' ): string {
        $base_prompt = 'You are a schema.org JSON-LD generator that creates COMPREHENSIVE, RICH structured data for web pages.

YOUR GOAL: Generate detailed, complete schema markup that fully describes the page content. Include ALL information you can extract from the content.

STRICT REQUIREMENTS:
1. Output ONLY valid JSON. No markdown code fences, no explanations, no commentary.
2. The output must be a single JSON object with "@context": "https://schema.org" at the root level.
3. NEVER invent or hallucinate information not present in the content.
4. For URLs, only use URLs explicitly provided in the input.
5. For contact information (email, phone), only include if explicitly present in the content.

CONTENT STRUCTURE MARKERS:
The content includes special markers to help you understand the page structure:
- ## [Heading] ## indicates a section heading
- [LIST START] / [LIST END] indicates a list of items
- [NUMBERED LIST START] / [NUMBERED LIST END] indicates ordered steps
- [SECTION] / [/SECTION] indicates a content section
- [ARTICLE] / [/ARTICLE] indicates article content
- **text** indicates important/emphasized text
- Links include their URLs in parentheses: text (https://...)

Use these markers to identify:
- Services being offered (often under headings like "Services", "What We Do")
- Contact information (under "Contact", "Get in Touch")
- Team members or founders (under "Team", "About Us")
- Pricing or offers
- FAQs (Question/Answer patterns)

OUTPUT FORMAT:
Use @graph format to include multiple related entities:
{"@context": "https://schema.org", "@graph": [...]}

The @graph should typically include:
- A WebPage or Article as the main entity
- Organization or LocalBusiness if business info is present
- Service objects for each distinct service mentioned
- Person objects for team members/founders mentioned
- ContactPoint for contact information
- PostalAddress if address is provided
- FAQPage with Question/Answer if FAQ content exists

Use @id references to link related entities:
- "@id": "#organization" on the Organization
- "provider": {"@id": "#organization"} on Services
- "publisher": {"@id": "#organization"} on Articles

SCHEMA TYPE SELECTION:
- WebPage: Default for informational pages
- Article: Blog posts, news, editorial content with clear authorship
- Service/ProfessionalService: Pages describing services (use one Service per distinct service)
- LocalBusiness: Business pages with physical location/contact
- Organization: Company/organization information
- FAQPage: Pages with clear Question/Answer pairs
- Product: Product pages with pricing
- HowTo: Step-by-step instructions
- Event: Event announcements with dates

COMPLETENESS PRINCIPLE:
Extract and include ALL relevant information from the content:
- Every service mentioned should become a Service object
- Business name, description, and any contact info should be included
- Team members or founders should become Person objects
- Areas served or target audience should be included
- Any pricing or offers should be captured

Remember: Completeness with accuracy. Include all information that IS present in the content.';

        if ( ! empty( $schema_reference ) ) {
            $base_prompt .= "\n\nSCHEMA.ORG REFERENCE:\nUse the following schema types and properties:\n\n" . $schema_reference;
        }

        return $base_prompt;
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
