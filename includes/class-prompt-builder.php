<?php
/**
 * Prompt builder class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds prompts and payloads for LLM requests
 */
class WP_AI_Schema_Prompt_Builder {

    /**
     * Content processor instance
     *
     * @var WP_AI_Schema_Content_Processor
     */
    private $content_processor;

    /**
     * Constructor
     *
     * @param WP_AI_Schema_Content_Processor $content_processor Content processor instance.
     */
    public function __construct( WP_AI_Schema_Content_Processor $content_processor ) {
        $this->content_processor = $content_processor;
    }

    /**
     * Build the complete prompt payload
     *
     * @param int         $post_id           Post ID.
     * @param array       $settings          Plugin settings.
     * @param int         $max_content_chars Maximum content characters (from provider model config).
     * @param string|null $override_content  Optional content to use instead of post content (for frontend fetch).
     * @return array Prompt payload.
     */
    public function build_payload( int $post_id, array $settings, int $max_content_chars = 50000, ?string $override_content = null ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array();
        }

        $type_hint = $this->get_type_hint( $post_id );

        return array(
            'page'            => $this->build_page_data( $post, $max_content_chars, $override_content ),
            'site'            => $this->build_site_data(),
            'business'        => $this->build_business_data( $settings ),
            'typeHint'        => $type_hint,
            'schemaReference' => $this->build_schema_reference( $type_hint ),
        );
    }

    /**
     * Build schema reference for the prompt
     *
     * @param string $type_hint Type hint from user.
     * @return string Formatted schema reference.
     */
    private function build_schema_reference( string $type_hint ): string {
        if ( ! class_exists( 'WP_AI_Schema_Reference' ) ) {
            return '';
        }

        $relevant_types = WP_AI_Schema_Reference::get_relevant_types( $type_hint );
        $definitions    = WP_AI_Schema_Reference::get_definitions_for_types( $relevant_types );

        return WP_AI_Schema_Reference::format_for_prompt( $definitions );
    }

    /**
     * Build page data array
     *
     * @param WP_Post     $post              Post object.
     * @param int         $max_content_chars Maximum content characters.
     * @param string|null $override_content  Optional content override (from frontend fetch).
     * @return array Page data.
     */
    private function build_page_data( WP_Post $post, int $max_content_chars, ?string $override_content = null ): array {
        // Use override content if provided, otherwise get best available content
        if ( ! empty( $override_content ) ) {
            $raw_content = $override_content;
        } else {
            $raw_content = $this->content_processor->get_best_content( $post->ID );
        }

        // Process content with structure preservation for better schema generation
        $content_result = $this->content_processor->process_with_structure( $raw_content, $max_content_chars );

        // Get featured image data
        $featured_image = $this->get_featured_image_data( $post->ID );

        // Get categories and tags
        $categories = $this->get_taxonomy_terms( $post->ID, 'category' );
        $tags       = $this->get_taxonomy_terms( $post->ID, 'post_tag' );

        return array(
            'title'            => get_the_title( $post->ID ),
            'url'              => get_permalink( $post->ID ),
            'pageType'         => $post->post_type,
            'content'          => $content_result['content'],
            'contentTruncated' => $content_result['truncated'],
            'originalLength'   => $content_result['original_length'],
            'excerpt'          => $post->post_excerpt ?: null,
            'author'           => get_the_author_meta( 'display_name', $post->post_author ),
            'datePublished'    => get_the_date( 'c', $post->ID ),
            'dateModified'     => get_the_modified_date( 'c', $post->ID ),
            'featuredImage'    => $featured_image,
            'categories'       => $categories,
            'tags'             => $tags,
        );
    }

    /**
     * Build site data array
     *
     * @return array Site data.
     */
    private function build_site_data(): array {
        return array(
            'name'        => get_bloginfo( 'name' ),
            'url'         => home_url(),
            'description' => get_bloginfo( 'description' ),
        );
    }

    /**
     * Build business data array from settings
     *
     * @param array $settings Plugin settings.
     * @return array|null Business data or null if not configured.
     */
    private function build_business_data( array $settings ): ?array {
        // Check if any business data is configured
        $has_data = ! empty( $settings['business_name'] ) ||
                    ! empty( $settings['business_email'] ) ||
                    ! empty( $settings['business_phone'] ) ||
                    ! empty( $settings['business_locations'] );

        if ( ! $has_data ) {
            return null;
        }

        $business = array(
            'name'         => $settings['business_name'] ?? '',
            'description'  => $settings['business_description'] ?? '',
            'logo'         => $settings['business_logo'] ?? '',
            'email'        => $settings['business_email'] ?? '',
            'phone'        => $settings['business_phone'] ?? '',
            'foundingDate' => $settings['business_founding_date'] ?? '',
        );

        // Add social links (only non-empty ones)
        $social_links = array();
        if ( ! empty( $settings['business_social_links'] ) && is_array( $settings['business_social_links'] ) ) {
            foreach ( $settings['business_social_links'] as $platform => $url ) {
                if ( ! empty( $url ) ) {
                    $social_links[] = $url;
                }
            }
        }
        if ( ! empty( $social_links ) ) {
            $business['sameAs'] = $social_links;
        }

        // Add locations
        if ( ! empty( $settings['business_locations'] ) && is_array( $settings['business_locations'] ) ) {
            $locations = array();

            foreach ( $settings['business_locations'] as $location ) {
                $loc_data = array(
                    'name'       => $location['name'] ?? '',
                    'address'    => array(
                        'streetAddress'   => $location['street'] ?? '',
                        'addressLocality' => $location['city'] ?? '',
                        'addressRegion'   => $location['state'] ?? '',
                        'postalCode'      => $location['postal_code'] ?? '',
                        'addressCountry'  => $location['country'] ?? '',
                    ),
                    'telephone'  => $location['phone'] ?? '',
                    'email'      => $location['email'] ?? '',
                );

                // Add opening hours if configured
                if ( ! empty( $location['hours'] ) && is_array( $location['hours'] ) ) {
                    $hours = array();
                    $day_map = array(
                        'monday'    => 'Mo',
                        'tuesday'   => 'Tu',
                        'wednesday' => 'We',
                        'thursday'  => 'Th',
                        'friday'    => 'Fr',
                        'saturday'  => 'Sa',
                        'sunday'    => 'Su',
                    );

                    foreach ( $location['hours'] as $day => $time ) {
                        if ( ! empty( $time ) && isset( $day_map[ $day ] ) ) {
                            $hours[ $day_map[ $day ] ] = $time;
                        }
                    }

                    if ( ! empty( $hours ) ) {
                        $loc_data['openingHours'] = $hours;
                    }
                }

                // Remove empty address fields
                $loc_data['address'] = array_filter( $loc_data['address'] );

                $locations[] = array_filter( $loc_data );
            }

            if ( ! empty( $locations ) ) {
                $business['locations'] = $locations;
            }
        }

        // Remove empty values
        return array_filter( $business );
    }

    /**
     * Get featured image data
     *
     * @param int $post_id Post ID.
     * @return array|null Featured image data or null.
     */
    private function get_featured_image_data( int $post_id ): ?array {
        $thumbnail_id = get_post_thumbnail_id( $post_id );

        if ( ! $thumbnail_id ) {
            return null;
        }

        $image_data = wp_get_attachment_image_src( $thumbnail_id, 'full' );

        if ( ! $image_data ) {
            return null;
        }

        return array(
            'url'    => $image_data[0] ?? null,
            'alt'    => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) ?: null,
            'width'  => $image_data[1] ?? null,
            'height' => $image_data[2] ?? null,
        );
    }

    /**
     * Get taxonomy terms as names array
     *
     * @param int    $post_id  Post ID.
     * @param string $taxonomy Taxonomy name.
     * @return array Array of term names.
     */
    private function get_taxonomy_terms( int $post_id, string $taxonomy ): array {
        $terms = get_the_terms( $post_id, $taxonomy );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        return wp_list_pluck( $terms, 'name' );
    }

    /**
     * Get type hint for post
     *
     * @param int $post_id Post ID.
     * @return string Type hint or 'auto'.
     */
    private function get_type_hint( int $post_id ): string {
        $type_hint = get_post_meta( $post_id, '_wp_ai_schema_type_hint', true );
        return $type_hint ?: 'auto';
    }

    /**
     * Get available schema type options
     *
     * @return array Associative array of value => label.
     */
    public static function get_schema_type_options(): array {
        return array(
            'auto'          => __( 'Auto-detect (recommended)', 'wp-ai-seo-schema-generator' ),
            'Article'       => __( 'Article', 'wp-ai-seo-schema-generator' ),
            'WebPage'       => __( 'WebPage', 'wp-ai-seo-schema-generator' ),
            'Service'       => __( 'Service', 'wp-ai-seo-schema-generator' ),
            'LocalBusiness' => __( 'LocalBusiness', 'wp-ai-seo-schema-generator' ),
            'FAQPage'       => __( 'FAQPage', 'wp-ai-seo-schema-generator' ),
            'Product'       => __( 'Product', 'wp-ai-seo-schema-generator' ),
            'Organization'  => __( 'Organization', 'wp-ai-seo-schema-generator' ),
            'Person'        => __( 'Person', 'wp-ai-seo-schema-generator' ),
            'Event'         => __( 'Event', 'wp-ai-seo-schema-generator' ),
            'HowTo'         => __( 'HowTo', 'wp-ai-seo-schema-generator' ),
        );
    }

    /**
     * Validate type hint value
     *
     * @param string $type_hint Type hint to validate.
     * @return string Valid type hint or 'auto'.
     */
    public static function validate_type_hint( string $type_hint ): string {
        $valid_types = array_keys( self::get_schema_type_options() );

        if ( in_array( $type_hint, $valid_types, true ) ) {
            return $type_hint;
        }

        return 'auto';
    }

    /**
     * Build payload from pre-analyzed content (for two-pass generation)
     *
     * This method builds a payload optimized for schema generation from
     * already-classified content data.
     *
     * NOTE: We do NOT include schemaReference here because:
     * 1. The analyzed data is already structured
     * 2. The get_schema_from_analysis_system_prompt() contains detailed schema type definitions
     * 3. Reduces payload size significantly for faster API calls
     *
     * @param int   $post_id           Post ID.
     * @param array $analyzed_data     Data from content analyzer (Pass 1).
     * @param array $settings          Plugin settings.
     * @param int   $max_content_chars Maximum content characters (not really used here since data is pre-structured).
     * @return array Prompt payload for Pass 2.
     */
    public function build_payload_from_analysis( int $post_id, array $analyzed_data, array $settings, int $max_content_chars = 50000 ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array();
        }

        $type_hint = $this->get_type_hint( $post_id );

        // Note: schemaReference is intentionally excluded - the system prompt
        // for analyzed content already contains all necessary schema type definitions
        return array(
            'page'              => $this->build_page_data_minimal( $post ),
            'site'              => $this->build_site_data(),
            'business'          => $this->build_business_data( $settings ),
            'analyzedContent'   => $analyzed_data,
            'typeHint'          => $type_hint,
            'schemaReference'   => '', // Empty - schema info is in system prompt
            'isFromAnalysis'    => true,
        );
    }

    /**
     * Build minimal page data for analyzed content payload
     *
     * When using pre-analyzed content, we don't need the full content,
     * just the metadata.
     *
     * @param WP_Post $post Post object.
     * @return array Minimal page data.
     */
    private function build_page_data_minimal( WP_Post $post ): array {
        $featured_image = $this->get_featured_image_data( $post->ID );

        return array(
            'title'         => get_the_title( $post->ID ),
            'url'           => get_permalink( $post->ID ),
            'pageType'      => $post->post_type,
            'excerpt'       => $post->post_excerpt ?: null,
            'author'        => get_the_author_meta( 'display_name', $post->post_author ),
            'datePublished' => get_the_date( 'c', $post->ID ),
            'dateModified'  => get_the_modified_date( 'c', $post->ID ),
            'featuredImage' => $featured_image,
        );
    }

    /**
     * Get the system prompt for schema generation from analyzed data
     *
     * This prompt is used in Pass 2 when generating schema from pre-analyzed content.
     *
     * @return string System prompt.
     */
    public static function get_schema_from_analysis_system_prompt(): string {
        return 'You are a schema.org JSON-LD generator. You will receive PRE-ANALYZED content data that has already been classified into structured sections.

YOUR TASK: Convert the analyzed content into comprehensive, valid schema.org JSON-LD markup.

STRICT REQUIREMENTS:
1. Output ONLY valid JSON. No markdown, no code fences, no explanations.
2. The output must be a single JSON object with "@context": "https://schema.org" at the root level.
3. Use @graph format to include multiple related entities.
4. NEVER invent information - only use what is provided in the analyzed data.
5. Convert ALL provided data into appropriate schema types.

INPUT DATA STRUCTURE:
You will receive an "analyzedContent" object with classified sections:
- page_type: Type of page (service_page, about_page, etc.)
- page_summary: Brief description of page purpose
- organization: Business/organization info
- services: Array of services offered
- testimonials: Array of client reviews (MUST become Review objects)
- faqs: Array of Q&A pairs (MUST become FAQPage with Question/Answer)
- team_members: Array of people (MUST become Person objects)
- contact_info: Contact details
- products: Array of products
- events: Array of events
- how_to_steps: Step-by-step instructions
- social_proof: Statistics and credentials

OUTPUT REQUIREMENTS:

For TESTIMONIALS, create Review objects:
{
  "@type": "Review",
  "author": {
    "@type": "Person",
    "name": "[author_name]"
  },
  "reviewBody": "[quote]",
  "reviewRating": {
    "@type": "Rating",
    "ratingValue": [rating],
    "bestRating": [rating_max]
  },
  "itemReviewed": {"@id": "#organization"}
}

For SERVICES, create Service objects:
{
  "@type": "Service",
  "name": "[name]",
  "description": "[description]",
  "provider": {"@id": "#organization"},
  "offers": { "@type": "Offer", "price": "[price]", "priceCurrency": "[currency]" }
}

For FAQs, create FAQPage:
{
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "[question]",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "[answer]"
      }
    }
  ]
}

For TEAM MEMBERS, create Person objects:
{
  "@type": "Person",
  "name": "[name]",
  "jobTitle": "[job_title]",
  "description": "[description]",
  "worksFor": {"@id": "#organization"}
}

Use @id references to link entities:
- Organization: "@id": "#organization"
- Main page: "@id": "#webpage"
- Reviews link to organization via itemReviewed

CRITICAL: Convert EVERY item in every array. Do not skip any testimonials, services, FAQs, or team members.

Generate complete, linked JSON-LD from the analyzed data now.';
    }

    /**
     * Build user message for schema generation from analysis
     *
     * @param array $payload Payload with analyzed content.
     * @return string User message.
     */
    public static function get_schema_from_analysis_user_prompt( array $payload ): string {
        $page_data       = $payload['page'] ?? array();
        $site_data       = $payload['site'] ?? array();
        $business_data   = $payload['business'] ?? null;
        $analyzed_data   = $payload['analyzedContent'] ?? array();
        $type_hint       = $payload['typeHint'] ?? 'auto';

        $message = 'Generate JSON-LD schema from the following pre-analyzed content:

PAGE METADATA:
' . wp_json_encode( $page_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '

SITE INFORMATION:
' . wp_json_encode( $site_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( ! empty( $business_data ) ) {
            $message .= '

VERIFIED BUSINESS DATA (use this for Organization/LocalBusiness):
' . wp_json_encode( $business_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        $message .= '

ANALYZED CONTENT (pre-classified by content analyzer):
' . wp_json_encode( $analyzed_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( 'auto' !== $type_hint ) {
            $message .= '

PREFERRED PRIMARY SCHEMA TYPE: ' . $type_hint;
        }

        // Summarize what was found to help guide generation
        $found_items = array();
        if ( ! empty( $analyzed_data['testimonials'] ) ) {
            $found_items[] = count( $analyzed_data['testimonials'] ) . ' testimonials (MUST create Review for each)';
        }
        if ( ! empty( $analyzed_data['services'] ) ) {
            $found_items[] = count( $analyzed_data['services'] ) . ' services';
        }
        if ( ! empty( $analyzed_data['faqs'] ) ) {
            $found_items[] = count( $analyzed_data['faqs'] ) . ' FAQ items';
        }
        if ( ! empty( $analyzed_data['team_members'] ) ) {
            $found_items[] = count( $analyzed_data['team_members'] ) . ' team members';
        }
        if ( ! empty( $analyzed_data['products'] ) ) {
            $found_items[] = count( $analyzed_data['products'] ) . ' products';
        }

        if ( ! empty( $found_items ) ) {
            $message .= '

ITEMS TO INCLUDE IN SCHEMA:
- ' . implode( "\n- ", $found_items );
        }

        $message .= '

Generate complete JSON-LD schema now. Include ALL analyzed items.';

        return $message;
    }
}
