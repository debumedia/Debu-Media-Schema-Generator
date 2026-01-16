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
        return 'You are a meticulous content analysis AI. Your job is to read through a webpage\'s content from TOP to BOTTOM and extract EVERY piece of structured information you find.

CRITICAL INSTRUCTION: You must find and extract ALL items of each type. If there are 5 testimonials, extract all 5. If there are 10 services, extract all 10. DO NOT stop at the first one. DO NOT summarize or consolidate multiple items into one.

OUTPUT: Valid JSON only. No markdown, no code fences, no explanations.

PROCESS THE CONTENT IN ORDER:
1. Read through the entire content from start to finish
2. As you encounter each element (testimonial, service, FAQ, etc.), add it to the appropriate array
3. Maintain the order in which items appear on the page
4. Count items as you go - at the end, verify you captured all of them

JSON STRUCTURE:
{
    "page_type": "service_page|about_page|contact_page|blog_post|product_page|landing_page|faq_page|testimonials_page|other",
    "page_summary": "Brief 1-2 sentence summary",
    "content_sections_found": ["hero", "services", "testimonials", "faq", "contact", "team", "etc"],
    
    "organization": {
        "name": "Business name",
        "description": "Business description",
        "industry": "Industry/sector"
    },
    
    "services": [
        {
            "position": 1,
            "name": "Service name",
            "description": "Full service description",
            "features": ["feature1", "feature2"],
            "price": "Price if mentioned",
            "price_currency": "Currency code"
        }
    ],
    
    "testimonials": [
        {
            "position": 1,
            "quote": "COMPLETE testimonial text - copy the FULL quote exactly as written",
            "author_name": "Person name",
            "author_title": "Job title if shown",
            "author_company": "Company if shown",
            "rating": 5,
            "rating_max": 5
        }
    ],
    
    "faqs": [
        {
            "position": 1,
            "question": "The exact question",
            "answer": "The exact answer"
        }
    ],
    
    "team_members": [
        {
            "position": 1,
            "name": "Full name",
            "job_title": "Role/position",
            "description": "Bio text",
            "email": "Email if shown",
            "phone": "Phone if shown"
        }
    ],
    
    "contact_info": {
        "email": "Email address",
        "phone": "Phone number",
        "address": {
            "street": "Street",
            "city": "City",
            "state": "State/Province",
            "postal_code": "ZIP/Postal",
            "country": "Country"
        },
        "hours": "Business hours"
    },
    
    "products": [
        {
            "position": 1,
            "name": "Product name",
            "description": "Product description",
            "price": "Price",
            "price_currency": "Currency",
            "sku": "SKU"
        }
    ],
    
    "events": [
        {
            "position": 1,
            "name": "Event name",
            "description": "Description",
            "start_date": "Date/time",
            "end_date": "End date if available",
            "location": "Location"
        }
    ],
    
    "how_to_steps": [
        {
            "step_number": 1,
            "title": "Step title",
            "description": "Step instructions"
        }
    ],
    
    "statistics": {
        "client_count": "e.g., 500+ clients",
        "years_in_business": "e.g., 15 years",
        "projects_completed": "e.g., 1000+ projects",
        "awards": ["Award 1", "Award 2"],
        "certifications": ["Cert 1"]
    },
    
    "item_counts": {
        "testimonials_found": 0,
        "services_found": 0,
        "faqs_found": 0,
        "team_members_found": 0,
        "products_found": 0
    }
}

TESTIMONIAL DETECTION - VERY IMPORTANT:
Look for testimonials in these formats:
- [TESTIMONIAL START] ... [TESTIMONIAL END] markers (most reliable)
- [QUOTE START] ... [QUOTE END] markers  
- Text that appears to be a customer quote/review
- Sections under headings like "Testimonials", "What Our Clients Say", "Reviews", "Client Feedback"
- Quoted text followed by a person\'s name
- Star ratings associated with text

For EACH testimonial you find:
- Copy the COMPLETE quote text - do not truncate or summarize
- Extract the author name exactly as shown
- Include job title and company if present
- Include rating if shown (convert stars to numbers)

EXTRACTION RULES:
1. Extract ALL items - if you see 3 testimonials, output 3 testimonials
2. Preserve exact text - do not paraphrase quotes or descriptions
3. Include position numbers to maintain order
4. Only include sections that have data (omit empty arrays)
5. NEVER invent information - only extract what is explicitly present
6. The "item_counts" section helps verify you found everything

CONTENT STRUCTURE MARKERS:
- [TESTIMONIAL START/END] = testimonial/review (EXTRACT ALL)
- [FAQ ITEM START/END] = FAQ Q&A pair (EXTRACT ALL)  
- [QUOTE START/END] = general quote
- ## [Heading] ## = section heading
- [LIST START/END] = bullet list
- [SECTION] = content section boundary

FINAL CHECK: Before outputting, count items in each array and put counts in "item_counts". If the page clearly shows multiple testimonials but you only have 1, you missed some - go back and find them all.';
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

        $message = 'Analyze this webpage content and extract ALL structured information.

IMPORTANT: Find and extract EVERY testimonial, service, FAQ, team member, etc. Do not stop at just one of each type.

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
KNOWN BUSINESS INFO (verified):
- Business Name: ' . ( $business_data['name'] ?? '' ) . '
- Description: ' . ( $business_data['description'] ?? '' ) . '
- Email: ' . ( $business_data['email'] ?? '' ) . '
- Phone: ' . ( $business_data['phone'] ?? '' ) . '
';
        }

        if ( 'auto' !== $type_hint ) {
            $message .= '
PAGE TYPE HINT: ' . $type_hint . '
';
        }

        // Count markers in content to help the AI know what to expect
        $content         = $page_data['content'] ?? '';
        $testimonial_markers = substr_count( $content, '[TESTIMONIAL START]' );
        $quote_markers       = substr_count( $content, '[QUOTE START]' );
        $faq_markers         = substr_count( $content, '[FAQ ITEM START]' );

        if ( $testimonial_markers > 0 || $quote_markers > 0 || $faq_markers > 0 ) {
            $message .= '
CONTENT MARKERS DETECTED:';
            if ( $testimonial_markers > 0 ) {
                $message .= '
- ' . $testimonial_markers . ' [TESTIMONIAL] markers found - extract ALL ' . $testimonial_markers . ' testimonials';
            }
            if ( $quote_markers > 0 ) {
                $message .= '
- ' . $quote_markers . ' [QUOTE] markers found - extract ALL ' . $quote_markers . ' quotes';
            }
            if ( $faq_markers > 0 ) {
                $message .= '
- ' . $faq_markers . ' [FAQ ITEM] markers found - extract ALL ' . $faq_markers . ' FAQ items';
            }
            $message .= '
';
        }

        $message .= '
PAGE CONTENT (read through completely, extract ALL items):
"""
' . $content . '
"""

TASK: Read the content from start to finish. For each testimonial, service, FAQ, team member, etc. you encounter, add it to the appropriate array. Count your items at the end and include in "item_counts".

Output valid JSON only.';

        return $message;
    }
}
