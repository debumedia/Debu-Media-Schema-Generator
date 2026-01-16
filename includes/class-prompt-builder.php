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
     * @param int   $post_id           Post ID.
     * @param array $settings          Plugin settings.
     * @param int   $max_content_chars Maximum content characters (from provider model config).
     * @return array Prompt payload.
     */
    public function build_payload( int $post_id, array $settings, int $max_content_chars = 50000 ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array();
        }

        $type_hint = $this->get_type_hint( $post_id );

        return array(
            'page'            => $this->build_page_data( $post, $max_content_chars ),
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
     * @param WP_Post $post               Post object.
     * @param int     $max_content_chars  Maximum content characters.
     * @return array Page data.
     */
    private function build_page_data( WP_Post $post, int $max_content_chars ): array {
        // Process content with structure preservation for better schema generation
        $content_result = $this->content_processor->process_with_structure( $post->post_content, $max_content_chars );

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
}
