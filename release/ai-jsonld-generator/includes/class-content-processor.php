<?php
/**
 * Content processor class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles content preparation and truncation for LLM processing
 */
class AI_JSONLD_Content_Processor {

    /**
     * Default maximum content characters
     */
    const DEFAULT_MAX_CHARS = 8000;

    /**
     * Minimum percentage of max_chars to break at sentence
     */
    const SENTENCE_BREAK_THRESHOLD = 0.7;

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

        // Step 5: Convert semantic sections
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

        // Step 6: Preserve emphasis
        $content = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $content );
        $content = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $content );

        // Step 7: Convert paragraphs and line breaks
        $content = preg_replace( '/<p[^>]*>/i', "\n", $content );
        $content = preg_replace( '/<\/p>/i', "\n", $content );
        $content = preg_replace( '/<div[^>]*>/i', "\n", $content );
        $content = preg_replace( '/<\/div>/i', "\n", $content );

        // Step 8: Extract links with their URLs for context
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

        // Step 9: Strip remaining HTML tags
        $content = wp_strip_all_tags( $content );

        // Step 10: Decode HTML entities
        $content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

        // Step 11: Normalize whitespace (but preserve structure markers)
        $content = preg_replace( '/[ \t]+/', ' ', $content );
        $content = preg_replace( '/\n{3,}/', "\n\n", $content );

        // Step 12: Clean up marker formatting
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
     * @param int   $post_id  Post ID.
     * @param array $settings Plugin settings.
     * @return string SHA256 hash.
     */
    public function generate_hash( int $post_id, array $settings ): string {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return '';
        }

        $type_hint = get_post_meta( $post_id, '_ai_jsonld_type_hint', true );

        $hash_input = wp_json_encode(
            array(
                'content'          => $post->post_content,
                'title'            => $post->post_title,
                'excerpt'          => $post->post_excerpt,
                'modified'         => $post->post_modified,
                'settings_version' => $settings['settings_version'] ?? '1.0',
                'max_content_chars' => $settings['max_content_chars'] ?? self::DEFAULT_MAX_CHARS,
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

        $existing_schema = get_post_meta( $post_id, '_ai_jsonld_schema', true );

        if ( empty( $existing_schema ) ) {
            return true;
        }

        $stored_hash  = get_post_meta( $post_id, '_ai_jsonld_schema_hash', true );
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
        $schema      = get_post_meta( $post_id, '_ai_jsonld_schema', true );
        $generated   = get_post_meta( $post_id, '_ai_jsonld_schema_last_generated', true );
        $status      = get_post_meta( $post_id, '_ai_jsonld_schema_status', true );
        $error       = get_post_meta( $post_id, '_ai_jsonld_schema_error', true );
        $stored_hash = get_post_meta( $post_id, '_ai_jsonld_schema_hash', true );

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
     * @param int $post_id Post ID.
     * @return bool True if content is empty or too short.
     */
    public function is_content_empty( int $post_id ): bool {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return true;
        }

        $content = $this->prepare( $post->post_content );

        // Consider content empty if less than 50 characters
        return mb_strlen( $content ) < 50;
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
}
