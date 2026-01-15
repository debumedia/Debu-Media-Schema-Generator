<?php
/**
 * Prompt builder class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds prompts and payloads for LLM requests
 */
class AI_JSONLD_Prompt_Builder {

    /**
     * Content processor instance
     *
     * @var AI_JSONLD_Content_Processor
     */
    private $content_processor;

    /**
     * Constructor
     *
     * @param AI_JSONLD_Content_Processor $content_processor Content processor instance.
     */
    public function __construct( AI_JSONLD_Content_Processor $content_processor ) {
        $this->content_processor = $content_processor;
    }

    /**
     * Build the complete prompt payload
     *
     * @param int   $post_id  Post ID.
     * @param array $settings Plugin settings.
     * @return array Prompt payload.
     */
    public function build_payload( int $post_id, array $settings ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array();
        }

        return array(
            'page'     => $this->build_page_data( $post, $settings ),
            'site'     => $this->build_site_data(),
            'typeHint' => $this->get_type_hint( $post_id ),
        );
    }

    /**
     * Build page data array
     *
     * @param WP_Post $post     Post object.
     * @param array   $settings Plugin settings.
     * @return array Page data.
     */
    private function build_page_data( WP_Post $post, array $settings ): array {
        $max_chars = intval( $settings['max_content_chars'] ?? 8000 );

        // Process content
        $content_result = $this->content_processor->process( $post->post_content, $max_chars );

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
        $type_hint = get_post_meta( $post_id, '_ai_jsonld_type_hint', true );
        return $type_hint ?: 'auto';
    }

    /**
     * Get available schema type options
     *
     * @return array Associative array of value => label.
     */
    public static function get_schema_type_options(): array {
        return array(
            'auto'          => __( 'Auto-detect (recommended)', 'ai-jsonld-generator' ),
            'Article'       => __( 'Article', 'ai-jsonld-generator' ),
            'WebPage'       => __( 'WebPage', 'ai-jsonld-generator' ),
            'Service'       => __( 'Service', 'ai-jsonld-generator' ),
            'LocalBusiness' => __( 'LocalBusiness', 'ai-jsonld-generator' ),
            'FAQPage'       => __( 'FAQPage', 'ai-jsonld-generator' ),
            'Product'       => __( 'Product', 'ai-jsonld-generator' ),
            'Organization'  => __( 'Organization', 'ai-jsonld-generator' ),
            'Person'        => __( 'Person', 'ai-jsonld-generator' ),
            'Event'         => __( 'Event', 'ai-jsonld-generator' ),
            'HowTo'         => __( 'HowTo', 'ai-jsonld-generator' ),
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
