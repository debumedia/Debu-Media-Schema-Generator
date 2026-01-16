<?php
/**
 * Content Analyzer class for two-pass schema generation
 *
 * This class handles the first pass of content analysis,
 * classifying and structuring page content before schema generation.
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI-powered content analyzer for extracting structured data from pages
 *
 * Pass 1: Analyzes raw page content and classifies it into structured sections
 * (services, testimonials, FAQs, team members, etc.)
 *
 * Pass 2: The schema generator then uses this structured data to create accurate JSON-LD
 */
class WP_AI_Schema_Content_Analyzer {

    /**
     * Provider registry instance
     *
     * @var WP_AI_Schema_Provider_Registry
     */
    private $provider_registry;

    /**
     * Content processor instance
     *
     * @var WP_AI_Schema_Content_Processor
     */
    private $content_processor;

    /**
     * Constructor
     *
     * @param WP_AI_Schema_Provider_Registry $provider_registry Provider registry.
     * @param WP_AI_Schema_Content_Processor $content_processor Content processor.
     */
    public function __construct(
        WP_AI_Schema_Provider_Registry $provider_registry,
        WP_AI_Schema_Content_Processor $content_processor
    ) {
        $this->provider_registry = $provider_registry;
        $this->content_processor = $content_processor;
    }

    /**
     * Analyze content and return structured classification
     *
     * @param int         $post_id          Post ID to analyze.
     * @param array       $settings         Plugin settings.
     * @param string|null $override_content Optional content override (from frontend fetch).
     * @return array Analysis result with 'success', 'data', and 'error' keys.
     */
    public function analyze( int $post_id, array $settings, ?string $override_content = null ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array(
                'success' => false,
                'data'    => null,
                'error'   => __( 'Post not found.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Get provider
        $provider_slug = $settings['provider'] ?? 'deepseek';
        $provider      = $this->provider_registry->get( $provider_slug );

        if ( ! $provider ) {
            return array(
                'success' => false,
                'data'    => null,
                'error'   => __( 'Provider not found.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Get content
        if ( ! empty( $override_content ) ) {
            $raw_content = $override_content;
        } else {
            $raw_content = $this->content_processor->get_best_content( $post_id );
        }

        // Get model config for content limits
        $model_key         = $provider_slug . '_model';
        $model             = $settings[ $model_key ] ?? '';
        $max_content_chars = $provider->get_max_content_chars( $model );

        // Process content with structure preservation
        $content_result = $this->content_processor->process_with_structure( $raw_content, $max_content_chars );

        // Build analysis payload
        $payload = $this->build_analysis_payload( $post, $content_result, $settings );

        // Call provider to analyze
        $result = $provider->analyze_content( $payload, $settings );

        if ( ! $result['success'] ) {
            return array(
                'success' => false,
                'data'    => null,
                'error'   => $result['error'],
            );
        }

        // Parse and validate the analysis result
        $analysis = $this->parse_analysis_result( $result['analysis'] );

        if ( ! $analysis['success'] ) {
            return array(
                'success' => false,
                'data'    => null,
                'error'   => $analysis['error'],
            );
        }

        return array(
            'success' => true,
            'data'    => $analysis['data'],
            'error'   => null,
        );
    }

    /**
     * Build payload for content analysis request
     *
     * @param WP_Post $post           Post object.
     * @param array   $content_result Processed content result.
     * @param array   $settings       Plugin settings.
     * @return array Analysis payload.
     */
    private function build_analysis_payload( WP_Post $post, array $content_result, array $settings ): array {
        $type_hint = get_post_meta( $post->ID, '_wp_ai_schema_type_hint', true );

        return array(
            'page' => array(
                'title'            => get_the_title( $post->ID ),
                'url'              => get_permalink( $post->ID ),
                'pageType'         => $post->post_type,
                'content'          => $content_result['content'],
                'contentTruncated' => $content_result['truncated'],
                'originalLength'   => $content_result['original_length'],
                'excerpt'          => $post->post_excerpt ?: null,
            ),
            'site' => array(
                'name'        => get_bloginfo( 'name' ),
                'url'         => home_url(),
                'description' => get_bloginfo( 'description' ),
            ),
            'typeHint'     => $type_hint ?: 'auto',
            'businessData' => $this->get_business_data( $settings ),
        );
    }

    /**
     * Get business data from settings
     *
     * @param array $settings Plugin settings.
     * @return array|null Business data or null.
     */
    private function get_business_data( array $settings ): ?array {
        $has_data = ! empty( $settings['business_name'] ) ||
                    ! empty( $settings['business_email'] ) ||
                    ! empty( $settings['business_phone'] );

        if ( ! $has_data ) {
            return null;
        }

        return array(
            'name'        => $settings['business_name'] ?? '',
            'description' => $settings['business_description'] ?? '',
            'email'       => $settings['business_email'] ?? '',
            'phone'       => $settings['business_phone'] ?? '',
        );
    }

    /**
     * Parse and validate analysis result from AI
     *
     * @param string $raw_analysis Raw analysis JSON string.
     * @return array Parsed result with 'success', 'data', 'error' keys.
     */
    private function parse_analysis_result( string $raw_analysis ): array {
        // Clean up the response (remove markdown fences if present)
        $cleaned = $this->clean_json_response( $raw_analysis );

        // Attempt to decode JSON
        $decoded = json_decode( $cleaned, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WP_AI_Schema_Generator::log(
                'Content analysis JSON parse error: ' . json_last_error_msg() . ' - Raw: ' . substr( $raw_analysis, 0, 500 ),
                'error'
            );

            return array(
                'success' => false,
                'data'    => null,
                'error'   => __( 'Failed to parse content analysis result.', 'wp-ai-seo-schema-generator' ),
            );
        }

        // Validate structure
        if ( ! $this->validate_analysis_structure( $decoded ) ) {
            WP_AI_Schema_Generator::log(
                'Content analysis structure validation failed: ' . wp_json_encode( $decoded ),
                'error'
            );

            return array(
                'success' => false,
                'data'    => null,
                'error'   => __( 'Invalid content analysis structure.', 'wp-ai-seo-schema-generator' ),
            );
        }

        return array(
            'success' => true,
            'data'    => $decoded,
            'error'   => null,
        );
    }

    /**
     * Clean JSON response from AI (remove markdown fences, etc.)
     *
     * @param string $response Raw response.
     * @return string Cleaned JSON.
     */
    private function clean_json_response( string $response ): string {
        $response = trim( $response );

        // Remove markdown code fences
        if ( preg_match( '/^```(?:json)?\s*\n?(.*?)\n?```$/s', $response, $matches ) ) {
            $response = $matches[1];
        }

        // Remove any leading/trailing non-JSON content
        $start = strpos( $response, '{' );
        $end   = strrpos( $response, '}' );

        if ( false !== $start && false !== $end && $end > $start ) {
            $response = substr( $response, $start, $end - $start + 1 );
        }

        return trim( $response );
    }

    /**
     * Validate analysis structure has required sections
     *
     * @param array $data Decoded analysis data.
     * @return bool True if valid.
     */
    private function validate_analysis_structure( $data ): bool {
        if ( ! is_array( $data ) ) {
            return false;
        }

        // Must have at least page_type
        if ( empty( $data['page_type'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Get the system prompt for content analysis
     *
     * @return string System prompt.
     */
    public static function get_analysis_system_prompt(): string {
        return 'You are a content analysis AI that extracts and classifies structured information from web pages.

YOUR TASK: Analyze the page content and extract ALL identifiable information into a structured JSON format.
This data will be used to generate schema.org structured data, so be thorough and accurate.

OUTPUT FORMAT: Valid JSON only. No markdown, no explanations, no code fences.

REQUIRED OUTPUT STRUCTURE:
{
    "page_type": "service_page|about_page|contact_page|blog_post|product_page|landing_page|faq_page|other",
    "page_summary": "Brief 1-2 sentence summary of the page purpose",
    
    "organization": {
        "name": "Business/organization name if found",
        "description": "Business description if found",
        "industry": "Industry/sector if identifiable"
    },
    
    "services": [
        {
            "name": "Service name",
            "description": "Service description",
            "features": ["feature1", "feature2"],
            "price": "Price if mentioned",
            "price_currency": "USD/EUR/etc if found"
        }
    ],
    
    "testimonials": [
        {
            "quote": "The full testimonial text",
            "author_name": "Name of the person",
            "author_title": "Job title if available",
            "author_company": "Company name if available",
            "rating": 5,
            "rating_max": 5
        }
    ],
    
    "faqs": [
        {
            "question": "The question text",
            "answer": "The answer text"
        }
    ],
    
    "team_members": [
        {
            "name": "Full name",
            "job_title": "Position/role",
            "description": "Bio if available",
            "email": "Email if provided",
            "phone": "Phone if provided"
        }
    ],
    
    "contact_info": {
        "email": "Email address if found",
        "phone": "Phone number if found",
        "address": {
            "street": "Street address",
            "city": "City",
            "state": "State/Province",
            "postal_code": "ZIP/Postal code",
            "country": "Country"
        },
        "hours": "Business hours if mentioned"
    },
    
    "products": [
        {
            "name": "Product name",
            "description": "Product description",
            "price": "Price",
            "price_currency": "Currency",
            "sku": "SKU if available"
        }
    ],
    
    "events": [
        {
            "name": "Event name",
            "description": "Event description",
            "start_date": "Start date/time",
            "end_date": "End date/time if available",
            "location": "Event location"
        }
    ],
    
    "how_to_steps": [
        {
            "step_number": 1,
            "title": "Step title",
            "description": "Step instructions"
        }
    ],
    
    "social_proof": {
        "client_count": "Number of clients if mentioned (e.g., \'500+ clients\')",
        "years_in_business": "Years of experience if mentioned",
        "awards": ["Award 1", "Award 2"],
        "certifications": ["Certification 1"]
    },
    
    "calls_to_action": [
        {
            "text": "CTA button/link text",
            "url": "URL if available"
        }
    ]
}

EXTRACTION RULES:
1. ONLY include sections that have actual data. Omit empty arrays and null values.
2. NEVER invent information - only extract what is clearly present in the content.
3. For testimonials: Look for quoted text, client names, star ratings, review markers.
4. For services: Each distinct service mentioned should be a separate entry.
5. For FAQs: Only include if there are clear question/answer pairs.
6. For contact info: Extract emails, phones, and addresses when explicitly shown.
7. Preserve the exact text of testimonials/quotes - do not paraphrase.
8. If rating is shown as stars (e.g., 5 stars), convert to numeric (rating: 5, rating_max: 5).

CONTENT MARKERS TO LOOK FOR:
- [TESTIMONIAL START/END] markers indicate testimonials
- [FAQ ITEM START/END] markers indicate FAQ entries
- [QUOTE START/END] markers indicate quotes
- ## [Heading] ## markers indicate section headings
- [LIST START/END] markers indicate lists

Remember: Accuracy over completeness. Only include what you can clearly identify from the content.';
    }

    /**
     * Get the user prompt for content analysis
     *
     * @param array $payload Analysis payload.
     * @return string User prompt.
     */
    public static function get_analysis_user_prompt( array $payload ): string {
        $page_data     = $payload['page'] ?? array();
        $site_data     = $payload['site'] ?? array();
        $business_data = $payload['businessData'] ?? null;
        $type_hint     = $payload['typeHint'] ?? 'auto';

        $message = 'Analyze the following page content and extract structured information:

PAGE INFORMATION:
- Title: ' . ( $page_data['title'] ?? 'Unknown' ) . '
- URL: ' . ( $page_data['url'] ?? 'Unknown' ) . '
- Type: ' . ( $page_data['pageType'] ?? 'page' ) . '

SITE INFORMATION:
- Site Name: ' . ( $site_data['name'] ?? 'Unknown' ) . '
- Site URL: ' . ( $site_data['url'] ?? 'Unknown' ) . '
- Site Description: ' . ( $site_data['description'] ?? '' ) . '
';

        if ( ! empty( $business_data ) ) {
            $message .= '
KNOWN BUSINESS INFORMATION (verified by site owner - use this as reference):
- Business Name: ' . ( $business_data['name'] ?? '' ) . '
- Business Description: ' . ( $business_data['description'] ?? '' ) . '
- Business Email: ' . ( $business_data['email'] ?? '' ) . '
- Business Phone: ' . ( $business_data['phone'] ?? '' ) . '
';
        }

        if ( 'auto' !== $type_hint ) {
            $message .= '
HINT: The site owner suggests this is primarily a ' . $type_hint . ' page.
';
        }

        $message .= '
PAGE CONTENT:
"""
' . ( $page_data['content'] ?? '' ) . '
"""

Extract and classify all identifiable information from this content. Output valid JSON only.';

        return $message;
    }
}
