<?php
/**
 * Content processor class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles content preparation and truncation for LLM processing
 */
class WP_AI_Schema_Content_Processor {

    /**
     * Default maximum content characters
     */
    const DEFAULT_MAX_CHARS = 50000;

    /**
     * Minimum percentage of max_chars to break at sentence
     */
    const SENTENCE_BREAK_THRESHOLD = 0.7;

    /**
     * Minimum content length to consider non-empty
     */
    const MIN_CONTENT_LENGTH = 50;

    /**
     * Prepare content for LLM processing (plain text)
     *
     * @param string $content Raw content (HTML).
     * @return string Cleaned content.
     */
    public function prepare( string $content ): string {
        if ( empty( $content ) ) {
            return '';
        }

        // Step 1: Strip HTML tags
        $content = wp_strip_all_tags( $content );

        // Step 2: Decode HTML entities
        $content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

        // Step 3: Normalize whitespace
        $content = preg_replace( '/\s+/', ' ', $content );

        // Step 4: Trim
        $content = trim( $content );

        return $content;
    }

    /**
     * Prepare content preserving semantic structure
     *
     * Converts HTML structure to readable markers that help the LLM
     * understand page organization (headings, lists, sections).
     *
     * @param string $content Raw HTML content.
     * @return string Content with semantic structure preserved.
     */
    public function prepare_with_structure( string $content ): string {
        if ( empty( $content ) ) {
            return '';
        }

        // Step 1: Remove scripts, styles, and comments
        $content = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $content );
        $content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );
        $content = preg_replace( '/<!--.*?-->/s', '', $content );

        // Step 2: Remove potentially harmful/noisy tags
        $content = preg_replace( '/<(iframe|object|embed|form|input|button|select|textarea)[^>]*>.*?<\/\1>/is', '', $content );
        $content = preg_replace( '/<(iframe|object|embed|input|button|br|hr|img)[^>]*\/?>/is', '', $content );

        // Step 3: Convert headings to readable markers
        $content = preg_replace_callback(
            '/<h([1-6])[^>]*>(.*?)<\/h\1>/is',
            function ( $matches ) {
                $text = wp_strip_all_tags( $matches[2] );
                $text = trim( $text );
                if ( empty( $text ) ) {
                    return '';
                }
                return "\n\n## [" . $text . "] ##\n\n";
            },
            $content
        );

        // Step 4: Convert lists to readable format
        $content = preg_replace( '/<ul[^>]*>/i', "\n[LIST START]\n", $content );
        $content = preg_replace( '/<\/ul>/i', "[LIST END]\n", $content );
        $content = preg_replace( '/<ol[^>]*>/i', "\n[NUMBERED LIST START]\n", $content );
        $content = preg_replace( '/<\/ol>/i', "[NUMBERED LIST END]\n", $content );
        $content = preg_replace_callback(
            '/<li[^>]*>(.*?)<\/li>/is',
            function ( $matches ) {
                $text = wp_strip_all_tags( $matches[1] );
                $text = trim( $text );
                return "- " . $text . "\n";
            },
            $content
        );

        // Step 5: Detect and mark testimonials/reviews
        $content = $this->detect_and_mark_testimonials( $content );

        // Step 6: Detect and mark FAQ sections
        $content = $this->detect_and_mark_faqs( $content );

        // Step 7: Convert semantic sections
        $content = preg_replace( '/<section[^>]*>/i', "\n[SECTION]\n", $content );
        $content = preg_replace( '/<\/section>/i', "\n[/SECTION]\n", $content );
        $content = preg_replace( '/<article[^>]*>/i', "\n[ARTICLE]\n", $content );
        $content = preg_replace( '/<\/article>/i', "\n[/ARTICLE]\n", $content );
        $content = preg_replace( '/<aside[^>]*>/i', "\n[ASIDE]\n", $content );
        $content = preg_replace( '/<\/aside>/i', "\n[/ASIDE]\n", $content );
        $content = preg_replace( '/<nav[^>]*>/i', "\n[NAV]\n", $content );
        $content = preg_replace( '/<\/nav>/i', "\n[/NAV]\n", $content );
        $content = preg_replace( '/<footer[^>]*>/i', "\n[FOOTER]\n", $content );
        $content = preg_replace( '/<\/footer>/i', "\n[/FOOTER]\n", $content );
        $content = preg_replace( '/<header[^>]*>/i', "\n[HEADER]\n", $content );
        $content = preg_replace( '/<\/header>/i', "\n[/HEADER]\n", $content );

        // Step 8: Preserve emphasis
        $content = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $content );
        $content = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $content );

        // Step 9: Convert paragraphs and line breaks
        $content = preg_replace( '/<p[^>]*>/i', "\n", $content );
        $content = preg_replace( '/<\/p>/i', "\n", $content );
        $content = preg_replace( '/<div[^>]*>/i', "\n", $content );
        $content = preg_replace( '/<\/div>/i', "\n", $content );

        // Step 10: Extract links with their URLs for context
        $content = preg_replace_callback(
            '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            function ( $matches ) {
                $url  = $matches[1];
                $text = wp_strip_all_tags( $matches[2] );
                $text = trim( $text );
                if ( empty( $text ) ) {
                    return '';
                }
                // Only include URL if it looks useful (email, tel, or external)
                if ( preg_match( '/^(mailto:|tel:|https?:\/\/)/i', $url ) ) {
                    return $text . ' (' . $url . ')';
                }
                return $text;
            },
            $content
        );

        // Step 11: Strip remaining HTML tags
        $content = wp_strip_all_tags( $content );

        // Step 12: Decode HTML entities
        $content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

        // Step 13: Normalize whitespace (but preserve structure markers)
        $content = preg_replace( '/[ \t]+/', ' ', $content );
        $content = preg_replace( '/\n{3,}/', "\n\n", $content );

        // Step 14: Clean up marker formatting
        $content = preg_replace( '/\n +/', "\n", $content );
        $content = preg_replace( '/ +\n/', "\n", $content );

        return trim( $content );
    }

    /**
     * Process content with structure preservation
     *
     * @param string $content   Raw content.
     * @param int    $max_chars Maximum characters.
     * @return array Result array with structured and truncated content.
     */
    public function process_with_structure( string $content, int $max_chars = self::DEFAULT_MAX_CHARS ): array {
        $structured = $this->prepare_with_structure( $content );
        return $this->truncate( $structured, $max_chars );
    }

    /**
     * Truncate content intelligently at sentence boundaries
     *
     * @param string $content   Content to truncate.
     * @param int    $max_chars Maximum characters (default 8000).
     * @return array {
     *     Result array.
     *
     *     @type string $content         Truncated content.
     *     @type bool   $truncated       Whether content was truncated.
     *     @type int    $original_length Original content length.
     *     @type int    $truncated_length Truncated content length.
     * }
     */
    public function truncate( string $content, int $max_chars = self::DEFAULT_MAX_CHARS ): array {
        $original_length = mb_strlen( $content );

        if ( $original_length <= $max_chars ) {
            return array(
                'content'          => $content,
                'truncated'        => false,
                'original_length'  => $original_length,
                'truncated_length' => $original_length,
            );
        }

        // Get initial truncation
        $truncated = mb_substr( $content, 0, $max_chars );

        // Find last sentence boundaries
        $last_period   = mb_strrpos( $truncated, '. ' );
        $last_question = mb_strrpos( $truncated, '? ' );
        $last_exclaim  = mb_strrpos( $truncated, '! ' );

        // Get the furthest sentence boundary
        $break_point = max( $last_period, $last_question, $last_exclaim );

        // Only break at sentence if it's past the threshold
        $threshold = $max_chars * self::SENTENCE_BREAK_THRESHOLD;

        if ( false !== $break_point && $break_point > $threshold ) {
            $truncated = mb_substr( $content, 0, $break_point + 1 );
        }

        return array(
            'content'          => $truncated,
            'truncated'        => true,
            'original_length'  => $original_length,
            'truncated_length' => mb_strlen( $truncated ),
        );
    }

    /**
     * Prepare and truncate content in one step
     *
     * @param string $content   Raw content.
     * @param int    $max_chars Maximum characters.
     * @return array Result array with prepared and truncated content.
     */
    public function process( string $content, int $max_chars = self::DEFAULT_MAX_CHARS ): array {
        $prepared = $this->prepare( $content );
        return $this->truncate( $prepared, $max_chars );
    }

    /**
     * Generate a content hash for caching
     *
     * Hash includes provider and model so cache is invalidated when switching providers.
     * Now also includes page builder content for accurate cache invalidation.
     *
     * @param int   $post_id  Post ID.
     * @param array $settings Plugin settings.
     * @return string SHA256 hash.
     */
    public function generate_hash( int $post_id, array $settings ): string {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return '';
        }

        $type_hint = get_post_meta( $post_id, '_wp_ai_schema_type_hint', true );

        // Include provider and model in hash so cache invalidates when switching
        $provider    = $settings['provider'] ?? 'deepseek';
        $model_key   = $provider . '_model';
        $model       = $settings[ $model_key ] ?? '';

        // Get the best content (includes page builder content)
        $best_content = $this->get_best_content( $post_id );

        // Also include builder-specific meta for cache invalidation
        $builder = $this->detect_page_builder( $post_id );
        $builder_meta = '';

        if ( 'elementor' === $builder ) {
            $builder_meta = get_post_meta( $post_id, '_elementor_data', true );
        } elseif ( 'bricks' === $builder ) {
            $builder_meta = maybe_serialize( get_post_meta( $post_id, '_bricks_page_content_2', true ) );
        }

        $hash_input = wp_json_encode(
            array(
                'content'          => $best_content,
                'builder_meta'     => $builder_meta ? md5( $builder_meta ) : '', // Hash the meta to keep size reasonable
                'title'            => $post->post_title,
                'excerpt'          => $post->post_excerpt,
                'modified'         => $post->post_modified,
                'settings_version' => $settings['settings_version'] ?? '1.0',
                'provider'         => $provider,
                'model'            => $model,
                'type_hint'        => $type_hint ?: 'auto',
            )
        );

        return hash( 'sha256', $hash_input );
    }

    /**
     * Check if schema should be regenerated
     *
     * @param int   $post_id  Post ID.
     * @param array $settings Plugin settings.
     * @param bool  $force    Force regeneration.
     * @return bool True if should regenerate.
     */
    public function should_regenerate( int $post_id, array $settings, bool $force = false ): bool {
        if ( $force ) {
            return true;
        }

        $existing_schema = get_post_meta( $post_id, '_wp_ai_schema_schema', true );

        if ( empty( $existing_schema ) ) {
            return true;
        }

        $stored_hash  = get_post_meta( $post_id, '_wp_ai_schema_schema_hash', true );
        $current_hash = $this->generate_hash( $post_id, $settings );

        return $stored_hash !== $current_hash;
    }

    /**
     * Get cache status for a post
     *
     * @param int   $post_id  Post ID.
     * @param array $settings Plugin settings.
     * @return array {
     *     Cache status array.
     *
     *     @type bool   $has_schema    Whether schema exists.
     *     @type bool   $is_current    Whether cache is current.
     *     @type int    $generated_at  Unix timestamp of generation.
     *     @type string $status        Schema status (ok/error).
     *     @type string $error         Error message if status is error.
     * }
     */
    public function get_cache_status( int $post_id, array $settings ): array {
        $schema      = get_post_meta( $post_id, '_wp_ai_schema_schema', true );
        $generated   = get_post_meta( $post_id, '_wp_ai_schema_schema_last_generated', true );
        $status      = get_post_meta( $post_id, '_wp_ai_schema_schema_status', true );
        $error       = get_post_meta( $post_id, '_wp_ai_schema_schema_error', true );
        $stored_hash = get_post_meta( $post_id, '_wp_ai_schema_schema_hash', true );

        $has_schema = ! empty( $schema );
        $is_current = false;

        if ( $has_schema && ! empty( $stored_hash ) ) {
            $current_hash = $this->generate_hash( $post_id, $settings );
            $is_current   = ( $stored_hash === $current_hash );
        }

        return array(
            'has_schema'   => $has_schema,
            'is_current'   => $is_current,
            'generated_at' => $generated ? intval( $generated ) : 0,
            'status'       => $status ?: '',
            'error'        => $error ?: '',
        );
    }

    /**
     * Check if content is empty or too short
     *
     * Now checks for page builder content as well.
     *
     * @param int $post_id Post ID.
     * @return bool True if content is empty or too short.
     */
    public function is_content_empty( int $post_id ): bool {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return true;
        }

        // First try standard content
        $content = $this->prepare( $post->post_content );

        if ( mb_strlen( $content ) >= self::MIN_CONTENT_LENGTH ) {
            return false;
        }

        // Check for page builder content
        $builder_content = $this->get_page_builder_content( $post_id );

        if ( ! empty( $builder_content ) ) {
            $prepared = $this->prepare( $builder_content );
            if ( mb_strlen( $prepared ) >= self::MIN_CONTENT_LENGTH ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the best available content for a post
     *
     * Tries multiple sources: standard content, rendered content, page builder data.
     *
     * @param int $post_id Post ID.
     * @return string The best available content.
     */
    public function get_best_content( int $post_id ): string {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return '';
        }

        // 1. Try standard post_content first
        $standard_content = $post->post_content;
        $standard_prepared = $this->prepare( $standard_content );

        // 2. Try to get rendered content (applies shortcodes and some builders)
        $rendered_content = $this->get_rendered_content( $post_id );
        $rendered_prepared = $this->prepare( $rendered_content );

        // 3. Try page builder specific extraction
        $builder_content = $this->get_page_builder_content( $post_id );
        $builder_prepared = $this->prepare( $builder_content );

        // Return the longest/most complete content
        $contents = array(
            'standard' => array(
                'raw'      => $standard_content,
                'prepared' => $standard_prepared,
                'length'   => mb_strlen( $standard_prepared ),
            ),
            'rendered' => array(
                'raw'      => $rendered_content,
                'prepared' => $rendered_prepared,
                'length'   => mb_strlen( $rendered_prepared ),
            ),
            'builder'  => array(
                'raw'      => $builder_content,
                'prepared' => $builder_prepared,
                'length'   => mb_strlen( $builder_prepared ),
            ),
        );

        // Find the source with the most content
        $best_source = 'standard';
        $best_length = $contents['standard']['length'];

        foreach ( $contents as $source => $data ) {
            if ( $data['length'] > $best_length ) {
                $best_source = $source;
                $best_length = $data['length'];
            }
        }

        WP_AI_Schema_Generator::log(
            sprintf(
                'Content source for post %d: %s (%d chars). Standard: %d, Rendered: %d, Builder: %d',
                $post_id,
                $best_source,
                $best_length,
                $contents['standard']['length'],
                $contents['rendered']['length'],
                $contents['builder']['length']
            )
        );

        return $contents[ $best_source ]['raw'];
    }

    /**
     * Get rendered content by applying WordPress filters
     *
     * This handles shortcodes and some page builders that hook into the_content.
     *
     * @param int $post_id Post ID.
     * @return string Rendered content.
     */
    public function get_rendered_content( int $post_id ): string {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return '';
        }

        // Set up post data for filters that depend on it
        $original_post = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        // Get content and apply the_content filters
        // This will process shortcodes and many page builder outputs
        $content = $post->post_content;

        // Apply the_content filters but remove actions that might cause issues
        remove_filter( 'the_content', 'wpautop' ); // Don't need auto paragraphs
        $content = apply_filters( 'the_content', $content );
        add_filter( 'the_content', 'wpautop' ); // Restore

        // Restore original post
        if ( $original_post ) {
            $GLOBALS['post'] = $original_post;
            setup_postdata( $original_post );
        } else {
            wp_reset_postdata();
        }

        return $content;
    }

    /**
     * Detect which page builder is used for a post
     *
     * @param int $post_id Post ID.
     * @return string|null Builder name or null if none detected.
     */
    public function detect_page_builder( int $post_id ): ?string {
        // Elementor
        if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' ) {
            return 'elementor';
        }

        // Bricks Builder
        if ( get_post_meta( $post_id, '_bricks_page_content_2', true ) ) {
            return 'bricks';
        }

        // Beaver Builder
        if ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
            return 'beaver';
        }

        // Divi Builder
        if ( get_post_meta( $post_id, '_et_pb_use_builder', true ) === 'on' ) {
            return 'divi';
        }

        // WPBakery / Visual Composer (uses shortcodes in content)
        $post = get_post( $post_id );
        if ( $post && preg_match( '/\[vc_row/', $post->post_content ) ) {
            return 'wpbakery';
        }

        // Oxygen Builder
        if ( get_post_meta( $post_id, 'ct_builder_shortcodes', true ) ) {
            return 'oxygen';
        }

        // Brizy
        if ( get_post_meta( $post_id, 'brizy_post_uid', true ) ) {
            return 'brizy';
        }

        return null;
    }

    /**
     * Get content from page builder meta data
     *
     * @param int $post_id Post ID.
     * @return string Extracted content from page builder.
     */
    public function get_page_builder_content( int $post_id ): string {
        $builder = $this->detect_page_builder( $post_id );

        if ( ! $builder ) {
            return '';
        }

        switch ( $builder ) {
            case 'elementor':
                return $this->extract_elementor_content( $post_id );

            case 'bricks':
                return $this->extract_bricks_content( $post_id );

            case 'beaver':
                return $this->extract_beaver_content( $post_id );

            case 'divi':
                return $this->extract_divi_content( $post_id );

            case 'oxygen':
                return $this->extract_oxygen_content( $post_id );

            case 'brizy':
                return $this->extract_brizy_content( $post_id );

            case 'wpbakery':
                // WPBakery uses shortcodes, rely on rendered content
                return $this->get_rendered_content( $post_id );

            default:
                return '';
        }
    }

    /**
     * Extract content from Elementor data
     *
     * @param int $post_id Post ID.
     * @return string Extracted content.
     */
    private function extract_elementor_content( int $post_id ): string {
        $data = get_post_meta( $post_id, '_elementor_data', true );

        if ( empty( $data ) ) {
            return '';
        }

        // Decode if JSON string
        if ( is_string( $data ) ) {
            $data = json_decode( $data, true );
        }

        if ( ! is_array( $data ) ) {
            return '';
        }

        return $this->extract_text_from_elementor_elements( $data );
    }

    /**
     * Recursively extract text from Elementor elements
     *
     * @param array $elements Elementor elements array.
     * @return string Extracted text content.
     */
    private function extract_text_from_elementor_elements( array $elements ): string {
        $text_parts = array();

        foreach ( $elements as $element ) {
            // Extract text from settings
            if ( isset( $element['settings'] ) ) {
                $settings = $element['settings'];

                // Common text fields in Elementor widgets
                $text_fields = array(
                    'title', 'editor', 'description', 'text', 'content',
                    'heading', 'sub_heading', 'title_text', 'description_text',
                    'button_text', 'link_text', 'testimonial_content',
                    'testimonial_name', 'testimonial_job', 'alert_title',
                    'alert_description', 'tab_title', 'tab_content',
                    'accordion_title', 'accordion_content', 'item_description',
                    'field_label', 'placeholder', 'price', 'period',
                );

                foreach ( $text_fields as $field ) {
                    if ( isset( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
                        $text_parts[] = $settings[ $field ];
                    }
                }

                // Handle repeater fields (lists, FAQs, etc.)
                $repeater_fields = array( 'tabs', 'items', 'slides', 'social_icon_list', 'icon_list', 'faq_list' );
                foreach ( $repeater_fields as $repeater ) {
                    if ( isset( $settings[ $repeater ] ) && is_array( $settings[ $repeater ] ) ) {
                        foreach ( $settings[ $repeater ] as $item ) {
                            if ( is_array( $item ) ) {
                                foreach ( $text_fields as $field ) {
                                    if ( isset( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
                                        $text_parts[] = $item[ $field ];
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Recursively process nested elements
            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $nested_text = $this->extract_text_from_elementor_elements( $element['elements'] );
                if ( ! empty( $nested_text ) ) {
                    $text_parts[] = $nested_text;
                }
            }
        }

        return implode( "\n\n", array_filter( $text_parts ) );
    }

    /**
     * Extract content from Bricks Builder data
     *
     * @param int $post_id Post ID.
     * @return string Extracted content.
     */
    private function extract_bricks_content( int $post_id ): string {
        $data = get_post_meta( $post_id, '_bricks_page_content_2', true );

        if ( empty( $data ) ) {
            // Try older meta key
            $data = get_post_meta( $post_id, '_bricks_page_content', true );
        }

        if ( empty( $data ) || ! is_array( $data ) ) {
            return '';
        }

        return $this->extract_text_from_bricks_elements( $data );
    }

    /**
     * Recursively extract text from Bricks elements
     *
     * @param array $elements Bricks elements array.
     * @return string Extracted text content.
     */
    private function extract_text_from_bricks_elements( array $elements ): string {
        $text_parts = array();

        foreach ( $elements as $element ) {
            // Extract text from settings
            if ( isset( $element['settings'] ) ) {
                $settings = $element['settings'];

                // Common text fields in Bricks
                $text_fields = array(
                    'text', 'title', 'subtitle', 'content', 'description',
                    'heading', 'tag', 'label', 'button', 'link_text',
                    'author', 'quote', 'cite', 'name', 'job', 'company',
                    'price', 'currency', 'period', 'features',
                );

                foreach ( $text_fields as $field ) {
                    if ( isset( $settings[ $field ] ) ) {
                        $value = $settings[ $field ];
                        if ( is_string( $value ) ) {
                            $text_parts[] = $value;
                        } elseif ( is_array( $value ) ) {
                            // Handle arrays (like feature lists)
                            $text_parts[] = $this->flatten_array_to_text( $value );
                        }
                    }
                }

                // Handle items/repeater fields
                if ( isset( $settings['items'] ) && is_array( $settings['items'] ) ) {
                    foreach ( $settings['items'] as $item ) {
                        if ( is_array( $item ) ) {
                            $text_parts[] = $this->flatten_array_to_text( $item );
                        }
                    }
                }
            }

            // Recursively process children
            if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
                // Children might be IDs or nested elements
                // For now, skip - Bricks stores flat structure with parent references
            }
        }

        return implode( "\n\n", array_filter( $text_parts ) );
    }

    /**
     * Flatten an array to text, extracting string values
     *
     * @param array $array Array to flatten.
     * @return string Flattened text.
     */
    private function flatten_array_to_text( array $array ): string {
        $texts = array();

        array_walk_recursive(
            $array,
            function ( $value ) use ( &$texts ) {
                if ( is_string( $value ) && ! empty( trim( $value ) ) ) {
                    // Skip URLs and technical strings
                    if ( ! preg_match( '/^(https?:\/\/|#|data:|javascript:)/i', $value ) ) {
                        $texts[] = $value;
                    }
                }
            }
        );

        return implode( ' ', $texts );
    }

    /**
     * Extract content from Beaver Builder data
     *
     * @param int $post_id Post ID.
     * @return string Extracted content.
     */
    private function extract_beaver_content( int $post_id ): string {
        $data = get_post_meta( $post_id, '_fl_builder_data', true );

        if ( empty( $data ) || ! is_array( $data ) ) {
            return '';
        }

        $text_parts = array();

        foreach ( $data as $node ) {
            if ( isset( $node->settings ) ) {
                $settings = (array) $node->settings;

                $text_fields = array(
                    'text', 'heading', 'content', 'description', 'title',
                    'btn_text', 'link_text', 'testimonial', 'name', 'company',
                );

                foreach ( $text_fields as $field ) {
                    if ( isset( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
                        $text_parts[] = $settings[ $field ];
                    }
                }
            }
        }

        return implode( "\n\n", array_filter( $text_parts ) );
    }

    /**
     * Extract content from Divi Builder
     *
     * Divi stores content in post_content with shortcodes.
     *
     * @param int $post_id Post ID.
     * @return string Extracted content.
     */
    private function extract_divi_content( int $post_id ): string {
        // Divi primarily uses shortcodes, so rely on rendered content
        return $this->get_rendered_content( $post_id );
    }

    /**
     * Extract content from Oxygen Builder
     *
     * @param int $post_id Post ID.
     * @return string Extracted content.
     */
    private function extract_oxygen_content( int $post_id ): string {
        $shortcodes = get_post_meta( $post_id, 'ct_builder_shortcodes', true );

        if ( empty( $shortcodes ) ) {
            return '';
        }

        // Oxygen stores as shortcodes, render them
        return do_shortcode( $shortcodes );
    }

    /**
     * Extract content from Brizy
     *
     * @param int $post_id Post ID.
     * @return string Extracted content.
     */
    private function extract_brizy_content( int $post_id ): string {
        // Try to get compiled HTML
        $compiled = get_post_meta( $post_id, 'brizy_post_compiled_html', true );

        if ( ! empty( $compiled ) ) {
            return $compiled;
        }

        // Fall back to editor data
        $editor_data = get_post_meta( $post_id, 'brizy_post_editor_data', true );

        if ( empty( $editor_data ) ) {
            return '';
        }

        // Decode and extract text
        if ( is_string( $editor_data ) ) {
            $editor_data = json_decode( $editor_data, true );
        }

        if ( is_array( $editor_data ) ) {
            return $this->flatten_array_to_text( $editor_data );
        }

        return '';
    }

    /**
     * Fetch content directly from the frontend
     *
     * This is the most reliable method but requires an HTTP request.
     *
     * @param int $post_id Post ID.
     * @return string|WP_Error Frontend content or error.
     */
    public function fetch_frontend_content( int $post_id ) {
        $url = get_permalink( $post_id );

        if ( ! $url ) {
            return new WP_Error( 'no_url', __( 'Could not get post URL.', 'wp-ai-seo-schema-generator' ) );
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout'    => 30,
                'sslverify'  => false,
                'user-agent' => 'WP AI Schema Content Fetcher',
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            return new WP_Error(
                'http_error',
                sprintf( __( 'HTTP %d error fetching page.', 'wp-ai-seo-schema-generator' ), $status_code )
            );
        }

        $body = wp_remote_retrieve_body( $response );

        // Extract main content area (try common selectors)
        $content = $this->extract_main_content_from_html( $body );

        return $content;
    }

    /**
     * Extract main content from HTML, excluding header/footer/sidebar
     *
     * @param string $html Full page HTML.
     * @return string Main content HTML.
     */
    private function extract_main_content_from_html( string $html ): string {
        // Try to find main content area using common selectors
        $selectors = array(
            '/<main[^>]*>(.*?)<\/main>/is',
            '/<article[^>]*>(.*?)<\/article>/is',
            '/<div[^>]*(?:id|class)=["\'][^"\']*(?:content|main|primary|entry)[^"\']*["\'][^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class=["\'][^"\']*(?:brxe-|elementor-widget-)[^"\']*["\'][^>]*>(.*?)<\/div>/is',
        );

        foreach ( $selectors as $pattern ) {
            if ( preg_match( $pattern, $html, $matches ) ) {
                return $matches[1];
            }
        }

        // Fallback: remove obvious non-content areas
        $html = preg_replace( '/<header[^>]*>.*?<\/header>/is', '', $html );
        $html = preg_replace( '/<footer[^>]*>.*?<\/footer>/is', '', $html );
        $html = preg_replace( '/<nav[^>]*>.*?<\/nav>/is', '', $html );
        $html = preg_replace( '/<aside[^>]*>.*?<\/aside>/is', '', $html );
        $html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
        $html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );

        // Extract body content
        if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $html, $matches ) ) {
            return $matches[1];
        }

        return $html;
    }

    /**
     * Get content info for debugging/display
     *
     * @param int $post_id Post ID.
     * @return array Content information.
     */
    public function get_content_info( int $post_id ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array(
                'has_content'  => false,
                'builder'      => null,
                'sources'      => array(),
            );
        }

        $builder = $this->detect_page_builder( $post_id );

        $standard_content = $this->prepare( $post->post_content );
        $rendered_content = $this->prepare( $this->get_rendered_content( $post_id ) );
        $builder_content  = $this->prepare( $this->get_page_builder_content( $post_id ) );

        return array(
            'has_content'       => ! $this->is_content_empty( $post_id ),
            'builder'           => $builder,
            'builder_label'     => $this->get_builder_label( $builder ),
            'standard_length'   => mb_strlen( $standard_content ),
            'rendered_length'   => mb_strlen( $rendered_content ),
            'builder_length'    => mb_strlen( $builder_content ),
            'best_source'       => $this->get_best_content_source( $post_id ),
            'can_fetch_frontend' => ( 'publish' === $post->post_status ),
        );
    }

    /**
     * Get human-readable builder label
     *
     * @param string|null $builder Builder slug.
     * @return string Builder label.
     */
    private function get_builder_label( ?string $builder ): string {
        $labels = array(
            'elementor' => 'Elementor',
            'bricks'    => 'Bricks Builder',
            'beaver'    => 'Beaver Builder',
            'divi'      => 'Divi Builder',
            'wpbakery'  => 'WPBakery',
            'oxygen'    => 'Oxygen Builder',
            'brizy'     => 'Brizy',
        );

        return $labels[ $builder ] ?? __( 'None detected', 'wp-ai-seo-schema-generator' );
    }

    /**
     * Get the name of the best content source
     *
     * @param int $post_id Post ID.
     * @return string Source name.
     */
    private function get_best_content_source( int $post_id ): string {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return 'none';
        }

        $standard = mb_strlen( $this->prepare( $post->post_content ) );
        $rendered = mb_strlen( $this->prepare( $this->get_rendered_content( $post_id ) ) );
        $builder  = mb_strlen( $this->prepare( $this->get_page_builder_content( $post_id ) ) );

        if ( $builder >= $standard && $builder >= $rendered ) {
            return 'builder';
        }

        if ( $rendered >= $standard ) {
            return 'rendered';
        }

        return 'standard';
    }

    /**
     * Get truncation indicator text for prompts
     *
     * @param int $truncated_length Length of truncated content.
     * @param int $original_length  Original content length.
     * @return string Truncation indicator.
     */
    public function get_truncation_indicator( int $truncated_length, int $original_length ): string {
        return sprintf(
            '[Content truncated: showing %d of %d characters]',
            $truncated_length,
            $original_length
        );
    }

    /**
     * Detect and mark testimonial/review sections in HTML content
     *
     * Looks for common testimonial patterns including:
     * - Elements with testimonial/review-related class names
     * - Blockquote elements (often used for quotes/testimonials)
     * - Star rating patterns
     * - Common page builder testimonial widgets
     *
     * @param string $content HTML content to process.
     * @return string Content with testimonial markers added.
     */
    private function detect_and_mark_testimonials( string $content ): string {
        // Pattern 1: Elements with testimonial/review class names
        // Matches divs, sections, etc. with classes containing testimonial-related keywords
        $testimonial_class_pattern = '/<([a-z0-9]+)[^>]*class=["\'][^"\']*(?:testimonial|review|client-quote|client-feedback|customer-review|customer-feedback|quote-box|quote-card|feedback-item|testimonial-item|review-item|rating-box|star-rating)[^"\']*["\'][^>]*>(.*?)<\/\1>/is';

        $content = preg_replace_callback(
            $testimonial_class_pattern,
            function ( $matches ) {
                $inner_content = $matches[2];
                // Extract author name if present (common patterns)
                $author = $this->extract_testimonial_author( $inner_content );
                $rating = $this->extract_star_rating( $inner_content );
                $text   = $this->extract_testimonial_text( $inner_content );

                $marker = "\n[TESTIMONIAL START]\n";
                if ( ! empty( $text ) ) {
                    $marker .= "Quote: " . trim( $text ) . "\n";
                }
                if ( ! empty( $author ) ) {
                    $marker .= "Author: " . trim( $author ) . "\n";
                }
                if ( ! empty( $rating ) ) {
                    $marker .= "Rating: " . $rating . "\n";
                }
                $marker .= "[TESTIMONIAL END]\n";

                return $marker;
            },
            $content
        );

        // Pattern 2: Blockquote elements (often used for testimonials)
        $content = preg_replace_callback(
            '/<blockquote[^>]*>(.*?)<\/blockquote>/is',
            function ( $matches ) {
                $inner = $matches[1];
                $text  = wp_strip_all_tags( $inner );
                $text  = trim( $text );

                // Look for citation
                $author = '';
                if ( preg_match( '/<cite[^>]*>(.*?)<\/cite>/is', $inner, $cite_match ) ) {
                    $author = wp_strip_all_tags( $cite_match[1] );
                } elseif ( preg_match( '/<footer[^>]*>(.*?)<\/footer>/is', $inner, $footer_match ) ) {
                    $author = wp_strip_all_tags( $footer_match[1] );
                }

                // Only mark as testimonial if it looks like one (has content)
                if ( mb_strlen( $text ) > 20 ) {
                    $marker = "\n[QUOTE START]\n";
                    $marker .= "Text: " . $text . "\n";
                    if ( ! empty( $author ) ) {
                        $marker .= "Attribution: " . trim( $author ) . "\n";
                    }
                    $marker .= "[QUOTE END]\n";
                    return $marker;
                }

                return $matches[0];
            },
            $content
        );

        // Pattern 3: Elementor testimonial widgets
        $content = preg_replace_callback(
            '/<[^>]*class=["\'][^"\']*elementor-testimonial[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            function ( $matches ) {
                return $this->format_testimonial_marker( $matches[1] );
            },
            $content
        );

        // Pattern 4: Bricks testimonial elements
        $content = preg_replace_callback(
            '/<[^>]*class=["\'][^"\']*brxe-testimonial[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            function ( $matches ) {
                return $this->format_testimonial_marker( $matches[1] );
            },
            $content
        );

        // Pattern 5: Common slider/carousel testimonials (Swiper, Slick, etc.)
        $content = preg_replace_callback(
            '/<[^>]*class=["\'][^"\']*(?:swiper-slide|slick-slide)[^"\']*["\'][^>]*>.*?(?:testimonial|review|quote)[^<]*<[^>]*>(.*?)<\/[^>]+>/is',
            function ( $matches ) {
                return $this->format_testimonial_marker( $matches[1] );
            },
            $content
        );

        return $content;
    }

    /**
     * Extract author name from testimonial HTML
     *
     * @param string $html Testimonial HTML content.
     * @return string Author name or empty string.
     */
    private function extract_testimonial_author( string $html ): string {
        // Common author patterns
        $patterns = array(
            '/<[^>]*class=["\'][^"\']*(?:author|name|client-name|reviewer|testimonial-name|review-author)[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            '/<cite[^>]*>(.*?)<\/cite>/is',
            '/<strong[^>]*class=["\'][^"\']*name[^"\']*["\'][^>]*>(.*?)<\/strong>/is',
            '/<span[^>]*class=["\'][^"\']*name[^"\']*["\'][^>]*>(.*?)<\/span>/is',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $html, $matches ) ) {
                $author = wp_strip_all_tags( $matches[1] );
                $author = trim( $author );
                if ( ! empty( $author ) && mb_strlen( $author ) < 100 ) {
                    return $author;
                }
            }
        }

        return '';
    }

    /**
     * Extract star rating from testimonial HTML
     *
     * @param string $html Testimonial HTML content.
     * @return string Rating string (e.g., "5/5" or "4.5 stars") or empty.
     */
    private function extract_star_rating( string $html ): string {
        // Pattern 1: Data attribute ratings
        if ( preg_match( '/data-rating=["\']([0-9.]+)["\']/', $html, $matches ) ) {
            return $matches[1] . '/5';
        }

        // Pattern 2: Count filled star icons/classes
        $filled_stars = preg_match_all( '/(?:star-filled|fas fa-star|icon-star-full|star active|rating-star--filled)/i', $html );
        if ( $filled_stars > 0 && $filled_stars <= 5 ) {
            return $filled_stars . '/5 stars';
        }

        // Pattern 3: Numeric rating in text
        if ( preg_match( '/(\d(?:\.\d)?)\s*(?:\/\s*5|out of 5|stars?)/i', $html, $matches ) ) {
            return $matches[1] . '/5';
        }

        return '';
    }

    /**
     * Extract main testimonial text from HTML
     *
     * @param string $html Testimonial HTML content.
     * @return string Testimonial text or empty string.
     */
    private function extract_testimonial_text( string $html ): string {
        // Try to find content in common testimonial text containers
        $patterns = array(
            '/<[^>]*class=["\'][^"\']*(?:testimonial-content|review-text|quote-text|testimonial-text|feedback-text|review-content|testimonial-description)[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            '/<p[^>]*class=["\'][^"\']*(?:content|text|quote)[^"\']*["\'][^>]*>(.*?)<\/p>/is',
            '/<blockquote[^>]*>(.*?)<\/blockquote>/is',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $html, $matches ) ) {
                $text = wp_strip_all_tags( $matches[1] );
                $text = trim( $text );
                if ( mb_strlen( $text ) > 20 ) {
                    return $text;
                }
            }
        }

        // Fallback: get all text content and look for the longest paragraph
        $stripped = wp_strip_all_tags( $html );
        $stripped = trim( $stripped );

        if ( mb_strlen( $stripped ) > 30 ) {
            // Remove author name if we can identify it
            $author = $this->extract_testimonial_author( $html );
            if ( ! empty( $author ) ) {
                $stripped = str_replace( $author, '', $stripped );
                $stripped = trim( $stripped );
            }
            return $stripped;
        }

        return '';
    }

    /**
     * Format a testimonial marker from HTML content
     *
     * @param string $html Raw testimonial HTML.
     * @return string Formatted testimonial marker.
     */
    private function format_testimonial_marker( string $html ): string {
        $author = $this->extract_testimonial_author( $html );
        $rating = $this->extract_star_rating( $html );
        $text   = $this->extract_testimonial_text( $html );

        if ( empty( $text ) ) {
            // No meaningful content, return original
            return $html;
        }

        $marker = "\n[TESTIMONIAL START]\n";
        $marker .= "Quote: " . trim( $text ) . "\n";
        if ( ! empty( $author ) ) {
            $marker .= "Author: " . trim( $author ) . "\n";
        }
        if ( ! empty( $rating ) ) {
            $marker .= "Rating: " . $rating . "\n";
        }
        $marker .= "[TESTIMONIAL END]\n";

        return $marker;
    }

    /**
     * Detect and mark FAQ sections in HTML content
     *
     * Looks for common FAQ patterns including:
     * - Accordion elements
     * - FAQ schema markup
     * - Question/Answer pairs
     *
     * @param string $content HTML content to process.
     * @return string Content with FAQ markers added.
     */
    private function detect_and_mark_faqs( string $content ): string {
        // Pattern 1: Elements with FAQ/accordion class names
        $faq_class_pattern = '/<([a-z0-9]+)[^>]*class=["\'][^"\']*(?:faq-item|accordion-item|question-answer|qa-item|faq-entry)[^"\']*["\'][^>]*>(.*?)<\/\1>/is';

        $content = preg_replace_callback(
            $faq_class_pattern,
            function ( $matches ) {
                $inner = $matches[2];
                $qa    = $this->extract_question_answer( $inner );

                if ( ! empty( $qa['question'] ) && ! empty( $qa['answer'] ) ) {
                    $marker  = "\n[FAQ ITEM START]\n";
                    $marker .= "Question: " . trim( $qa['question'] ) . "\n";
                    $marker .= "Answer: " . trim( $qa['answer'] ) . "\n";
                    $marker .= "[FAQ ITEM END]\n";
                    return $marker;
                }

                return $matches[0];
            },
            $content
        );

        // Pattern 2: Details/summary elements (HTML5 accordion)
        $content = preg_replace_callback(
            '/<details[^>]*>(.*?)<\/details>/is',
            function ( $matches ) {
                $inner = $matches[1];

                $question = '';
                $answer   = '';

                // Extract summary as question
                if ( preg_match( '/<summary[^>]*>(.*?)<\/summary>/is', $inner, $summary_match ) ) {
                    $question = wp_strip_all_tags( $summary_match[1] );
                    $answer   = str_replace( $summary_match[0], '', $inner );
                    $answer   = wp_strip_all_tags( $answer );
                }

                if ( ! empty( $question ) && ! empty( $answer ) ) {
                    $marker  = "\n[FAQ ITEM START]\n";
                    $marker .= "Question: " . trim( $question ) . "\n";
                    $marker .= "Answer: " . trim( $answer ) . "\n";
                    $marker .= "[FAQ ITEM END]\n";
                    return $marker;
                }

                return $matches[0];
            },
            $content
        );

        // Pattern 3: Elementor accordion/toggle widgets
        $content = preg_replace_callback(
            '/<[^>]*class=["\'][^"\']*(?:elementor-accordion-item|elementor-toggle-item)[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            function ( $matches ) {
                $inner = $matches[1];
                $qa    = $this->extract_question_answer( $inner );

                if ( ! empty( $qa['question'] ) && ! empty( $qa['answer'] ) ) {
                    $marker  = "\n[FAQ ITEM START]\n";
                    $marker .= "Question: " . trim( $qa['question'] ) . "\n";
                    $marker .= "Answer: " . trim( $qa['answer'] ) . "\n";
                    $marker .= "[FAQ ITEM END]\n";
                    return $marker;
                }

                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * Extract question and answer from FAQ HTML
     *
     * @param string $html FAQ item HTML.
     * @return array Array with 'question' and 'answer' keys.
     */
    private function extract_question_answer( string $html ): array {
        $result = array(
            'question' => '',
            'answer'   => '',
        );

        // Question patterns
        $question_patterns = array(
            '/<[^>]*class=["\'][^"\']*(?:question|faq-question|accordion-title|toggle-title|accordion-header)[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            '/<h[2-6][^>]*>(.*?)<\/h[2-6]>/is',
            '/<summary[^>]*>(.*?)<\/summary>/is',
            '/<dt[^>]*>(.*?)<\/dt>/is',
        );

        foreach ( $question_patterns as $pattern ) {
            if ( preg_match( $pattern, $html, $matches ) ) {
                $result['question'] = wp_strip_all_tags( $matches[1] );
                $result['question'] = trim( $result['question'] );
                break;
            }
        }

        // Answer patterns
        $answer_patterns = array(
            '/<[^>]*class=["\'][^"\']*(?:answer|faq-answer|accordion-content|toggle-content|accordion-body|accordion-panel)[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            '/<dd[^>]*>(.*?)<\/dd>/is',
        );

        foreach ( $answer_patterns as $pattern ) {
            if ( preg_match( $pattern, $html, $matches ) ) {
                $result['answer'] = wp_strip_all_tags( $matches[1] );
                $result['answer'] = trim( $result['answer'] );
                break;
            }
        }

        // If we found a question but no answer, try to get remaining content
        if ( ! empty( $result['question'] ) && empty( $result['answer'] ) ) {
            $stripped = wp_strip_all_tags( $html );
            $stripped = str_replace( $result['question'], '', $stripped );
            $stripped = trim( $stripped );
            if ( mb_strlen( $stripped ) > 20 ) {
                $result['answer'] = $stripped;
            }
        }

        return $result;
    }
}
