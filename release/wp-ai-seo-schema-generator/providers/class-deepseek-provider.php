<?php
/**
 * DeepSeek provider class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DeepSeek LLM provider implementation
 */
class WP_AI_Schema_DeepSeek_Provider extends WP_AI_Schema_Abstract_Provider {

    /**
     * API endpoint
     */
    const API_ENDPOINT = 'https://api.deepseek.com/v1/chat/completions';

    /**
     * Default model
     */
    const DEFAULT_MODEL = 'deepseek-chat';

    /**
     * Context window size (total input + output tokens)
     */
    const CONTEXT_WINDOW = 64000;

    /**
     * Maximum output tokens supported by model
     */
    const MAX_OUTPUT_TOKENS = 8192;

    /**
     * Minimum output tokens to ensure useful response
     */
    const MIN_OUTPUT_TOKENS = 1000;

    /**
     * Safety buffer for token estimation (tokens reserved)
     */
    const TOKEN_SAFETY_BUFFER = 2000;

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
                    __( 'Rate limited. Please try again in %d seconds.', 'wp-ai-seo-schema-generator' ),
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
                'error'       => __( 'DeepSeek API key is not configured.', 'wp-ai-seo-schema-generator' ),
                'headers'     => array(),
            );
        }

        // Build messages
        $messages = $this->build_messages( $payload );

        // Calculate safe max_tokens based on input size
        $requested_max_tokens = intval( $settings['max_tokens'] ?? 8000 );
        $safe_max_tokens      = $this->calculate_safe_max_tokens( $messages, $requested_max_tokens );

        // Build request body
        $body = array(
            'model'       => $settings['deepseek_model'] ?? self::DEFAULT_MODEL,
            'messages'    => $messages,
            'temperature' => floatval( $settings['temperature'] ?? 0.2 ),
            'max_tokens'  => $safe_max_tokens,
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
                'error'   => __( 'API key is required.', 'wp-ai-seo-schema-generator' ),
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
            'message' => __( 'Connection successful! API key is valid.', 'wp-ai-seo-schema-generator' ),
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
                'label'       => __( 'API Key', 'wp-ai-seo-schema-generator' ),
                'type'        => 'password',
                'description' => __( 'Your DeepSeek API key.', 'wp-ai-seo-schema-generator' ),
                'required'    => true,
            ),
            'deepseek_model' => array(
                'label'       => __( 'Model', 'wp-ai-seo-schema-generator' ),
                'type'        => 'text',
                'default'     => self::DEFAULT_MODEL,
                'description' => __( 'The DeepSeek model to use.', 'wp-ai-seo-schema-generator' ),
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

BUSINESS DATA PRIORITY:
If BUSINESS DATA is provided in the input, use it as the authoritative source for:
- Organization/LocalBusiness name, description, logo
- Contact information (email, phone)
- Physical addresses and locations (PostalAddress)
- Opening hours (OpeningHoursSpecification)
- Social media links (sameAs property)
- Founding date
This data has been verified by the site owner and should take precedence over information extracted from page content.

For multiple locations: Create separate LocalBusiness or Place objects for each location, all linked to the main Organization via @id references.

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
        $page_data     = $payload['page'] ?? array();
        $site_data     = $payload['site'] ?? array();
        $business_data = $payload['business'] ?? null;
        $type_hint     = $payload['typeHint'] ?? 'auto';

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

        // Include business data if available
        if ( ! empty( $business_data ) ) {
            $message .= '

BUSINESS DATA (use this verified information for Organization/LocalBusiness schemas):
' . wp_json_encode( $business_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

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
                'error'       => __( 'Failed to parse API response.', 'wp-ai-seo-schema-generator' ),
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
                'error'       => __( 'Empty response from API.', 'wp-ai-seo-schema-generator' ),
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

    /**
     * Calculate safe max_tokens based on input size
     *
     * Ensures we don't exceed the context window by dynamically
     * adjusting output tokens based on input token estimate.
     *
     * @param array $messages   The messages array to be sent.
     * @param int   $requested  The requested max_tokens from settings.
     * @return int Safe max_tokens value.
     */
    private function calculate_safe_max_tokens( array $messages, int $requested ): int {
        // Estimate input tokens
        $input_tokens = $this->estimate_tokens( $messages );

        // Calculate available tokens for output
        $available = self::CONTEXT_WINDOW - $input_tokens - self::TOKEN_SAFETY_BUFFER;

        // Ensure we have at least minimum tokens for a useful response
        if ( $available < self::MIN_OUTPUT_TOKENS ) {
            // Log warning if debug enabled
            WP_AI_Schema_Generator::log(
                sprintf(
                    'Input too large: ~%d tokens estimated, only %d available for output',
                    $input_tokens,
                    $available
                ),
                'warning'
            );
            return self::MIN_OUTPUT_TOKENS;
        }

        // Cap at model's maximum output and requested amount
        $max_allowed = min( self::MAX_OUTPUT_TOKENS, $available );

        return min( $requested, $max_allowed );
    }

    /**
     * Estimate token count for messages
     *
     * Uses a rough estimate of ~4 characters per token for English text.
     * This is conservative to avoid underestimating.
     *
     * @param array $messages Messages array.
     * @return int Estimated token count.
     */
    private function estimate_tokens( array $messages ): int {
        $total_chars = 0;

        foreach ( $messages as $message ) {
            $content = $message['content'] ?? '';
            $total_chars += mb_strlen( $content );

            // Add overhead for message structure
            $total_chars += 10;
        }

        // Estimate: ~4 characters per token (conservative for mixed content)
        // JSON and code tend to have more tokens per character
        return (int) ceil( $total_chars / 3.5 );
    }
}
