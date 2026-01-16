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

        // Get raw HTML content
        if ( ! empty( $override_content ) ) {
            $raw_html = $override_content;
        } else {
            // Fetch frontend content for best results with page builders
            $raw_html = $this->content_processor->fetch_frontend_content( $post_id );
            if ( is_wp_error( $raw_html ) || empty( $raw_html ) ) {
                // Fallback to post content
                $raw_html = $this->content_processor->get_best_content( $post_id );
            }
        }

        // Get model config for content limits
        $model_key         = $provider_slug . '_model';
        $model             = $settings[ $model_key ] ?? '';
        $max_content_chars = $provider->get_max_content_chars( $model );

        // Minimal HTML cleaning - only remove scripts/styles, keep structure
        $cleaned_html = $this->content_processor->prepare_raw_html( $raw_html );

        // Truncate if needed (but keep HTML intact)
        $original_length = mb_strlen( $cleaned_html );
        if ( $original_length > $max_content_chars ) {
            $cleaned_html = mb_substr( $cleaned_html, 0, $max_content_chars );
            WP_AI_Schema_Generator::log( sprintf( 'HTML truncated from %d to %d chars', $original_length, $max_content_chars ) );
        }

        // Build analysis payload with raw HTML
        $payload = $this->build_analysis_payload_html( $post, $cleaned_html, $original_length, $settings );

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
     * Build payload for content analysis request with raw HTML
     *
     * @param WP_Post $post            Post object.
     * @param string  $html_content    Cleaned HTML content.
     * @param int     $original_length Original content length before truncation.
     * @param array   $settings        Plugin settings.
     * @return array Analysis payload.
     */
    private function build_analysis_payload_html( WP_Post $post, string $html_content, int $original_length, array $settings ): array {
        $type_hint = get_post_meta( $post->ID, '_wp_ai_schema_type_hint', true );

        return array(
            'page' => array(
                'title'          => get_the_title( $post->ID ),
                'url'            => get_permalink( $post->ID ),
                'pageType'       => $post->post_type,
                'content'        => $html_content,
                'originalLength' => $original_length,
                'excerpt'        => $post->post_excerpt ?: null,
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
        return 'You are an expert HTML content analyzer. You will receive RAW HTML from a webpage. Your job is to analyze the HTML structure, classes, and content to extract ALL structured information.

CRITICAL: You must find and extract ALL items of each type. If there are 5 testimonials, extract all 5. If there are 10 services, extract all 10. DO NOT stop at the first one.

OUTPUT: Valid JSON only. No markdown, no code fences, no explanations.

## HOW TO ANALYZE HTML

You are receiving actual HTML with tags, classes, and IDs intact. Use these to identify content:

### TESTIMONIALS - Look for:
- Elements with classes containing: testimonial, review, quote, feedback, client-say, customer-review, rating
- <blockquote> elements (often used for quotes)
- <cite> elements (author attribution)
- Repeated card/item structures with quote text + person name
- Star rating elements (★, star icons, rating classes)
- Sections with IDs/classes like: testimonials, reviews, what-clients-say, feedback

### FAQs - Look for:
- <details>/<summary> HTML5 elements
- Accordion structures (classes containing: accordion, faq, collapse, toggle)
- Repeated question/answer patterns
- Elements with classes: faq, question, answer, q-and-a

### SERVICES - Look for:
- Repeated card structures with title + description
- Sections with classes: services, offerings, what-we-do, our-services
- Feature lists within service blocks

### TEAM MEMBERS - Look for:
- Repeated person cards with photo, name, title
- Classes containing: team, staff, member, person, about-us
- Bio/description text associated with names

### CONTACT INFO - Look for:
- Email addresses (mailto: links or text)
- Phone numbers (tel: links or text patterns)
- Address blocks
- Classes: contact, address, location

### PAGE STRUCTURE - Look at:
- <header>, <main>, <footer>, <section>, <article> tags
- <nav> for navigation (skip this content)
- <h1>-<h6> for section headings
- Class names that indicate sections (hero, about, services, etc.)

## LANGUAGE AGNOSTIC
Do NOT rely on English keywords. Look at HTML STRUCTURE and PATTERNS:
- Repeated elements = list of items (services, testimonials, team, etc.)
- Quote marks around text + name nearby = testimonial
- Question mark in heading + text below = FAQ
- Card layouts with images + titles = services or team

## JSON OUTPUT STRUCTURE
{
    "page_type": "service_page|about_page|contact_page|blog_post|product_page|landing_page|faq_page|testimonials_page|other",
    "page_summary": "Brief 1-2 sentence summary of what this page is about",
    "sections_detected": ["hero", "about", "services", "testimonials", "faq", "team", "contact", "footer"],
    
    "organization": {
        "name": "Business/company name found in content",
        "description": "Business description if found",
        "industry": "Industry/sector if identifiable"
    },
    
    "services": [
        {
            "position": 1,
            "name": "Service name",
            "description": "Full description text",
            "features": ["feature 1", "feature 2"],
            "price": "Price if shown",
            "price_currency": "Currency"
        }
    ],
    
    "testimonials": [
        {
            "position": 1,
            "quote": "The COMPLETE testimonial text exactly as written",
            "author_name": "Person name",
            "author_title": "Job title if shown",
            "author_company": "Company name if shown",
            "rating": 5,
            "rating_max": 5
        }
    ],
    
    "faqs": [
        {
            "position": 1,
            "question": "The exact question text",
            "answer": "The exact answer text"
        }
    ],
    
    "team_members": [
        {
            "position": 1,
            "name": "Full name",
            "job_title": "Role/position",
            "description": "Bio text if present"
        }
    ],
    
    "contact_info": {
        "email": "Email if found",
        "phone": "Phone if found",
        "address": {
            "street": "Street address",
            "city": "City",
            "state": "State/Province",
            "postal_code": "ZIP/Postal code",
            "country": "Country"
        }
    },
    
    "products": [
        {
            "position": 1,
            "name": "Product name",
            "description": "Product description",
            "price": "Price",
            "price_currency": "Currency"
        }
    ],
    
    "statistics": {
        "client_count": "e.g., 500+ clients",
        "years_in_business": "e.g., 15 years",
        "projects_completed": "e.g., 1000+ projects"
    },
    
    "item_counts": {
        "testimonials_found": 0,
        "services_found": 0,
        "faqs_found": 0,
        "team_members_found": 0,
        "products_found": 0
    }
}

## EXTRACTION RULES
1. Extract ALL items - count them and verify in item_counts
2. Preserve exact text - copy quotes exactly, do not paraphrase
3. Use position numbers to maintain the order items appear
4. Omit empty sections - only include what you actually find
5. NEVER invent information - only extract what exists in the HTML

## FINAL CHECK
Before outputting, verify:
- Did you check the entire HTML from top to bottom?
- Did you count all testimonials? All services? All FAQs?
- Are your item_counts accurate?
- Did you extract the COMPLETE text of each quote?';
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
        $content       = $page_data['content'] ?? '';

        $message = 'Analyze the following HTML and extract ALL structured information.

PAGE: ' . ( $page_data['title'] ?? 'Unknown' ) . '
URL: ' . ( $page_data['url'] ?? 'Unknown' ) . '
SITE: ' . ( $site_data['name'] ?? 'Unknown' ) . '
';

        if ( ! empty( $business_data['name'] ) ) {
            $message .= 'BUSINESS: ' . $business_data['name'] . '
';
        }

        if ( 'auto' !== $type_hint ) {
            $message .= 'PAGE TYPE HINT: ' . $type_hint . '
';
        }

        // Estimate content size for context
        $html_size_kb = round( strlen( $content ) / 1024, 1 );
        $message .= 'HTML SIZE: ' . $html_size_kb . ' KB

';

        $message .= 'HTML CONTENT:
' . $content . '

TASK: Analyze this HTML structure. Look at the tags, classes, and content patterns to identify:
- Testimonials/reviews (look for quote patterns, star ratings, author names)
- Services (repeated card structures)
- FAQs (accordion patterns, Q&A structures)
- Team members (person cards with names and titles)
- Contact information (emails, phones, addresses)

Extract ALL items you find. Count them and report in item_counts.

Output valid JSON only.';

        return $message;
    }
}
