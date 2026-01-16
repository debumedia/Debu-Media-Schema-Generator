<?php
/**
 * Schema validator class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates and sanitizes JSON-LD schema output
 */
class WP_AI_Schema_Schema_Validator {

    /**
     * Maximum schema size in bytes (50 KB)
     */
    const MAX_SIZE = 51200;

    /**
     * Validate and clean JSON-LD schema
     *
     * @param string $schema Raw schema string from LLM.
     * @return array {
     *     Validation result.
     *
     *     @type bool   $valid   Whether the schema is valid.
     *     @type string $schema  Cleaned schema JSON (if valid).
     *     @type string $error   Error message (if invalid).
     *     @type string $type    Detected schema type.
     * }
     */
    public function validate( string $schema ): array {
        // Strip any script tags or HTML
        $schema = $this->strip_html( $schema );

        // Try to extract JSON if wrapped in markdown or text
        $schema = $this->extract_json( $schema );

        if ( empty( $schema ) ) {
            return array(
                'valid'  => false,
                'schema' => '',
                'error'  => __( 'Empty or no valid JSON found in response.', 'wp-ai-seo-schema-generator' ),
                'type'   => '',
            );
        }

        // Check size limit
        if ( strlen( $schema ) > self::MAX_SIZE ) {
            return array(
                'valid'  => false,
                'schema' => '',
                'error'  => sprintf(
                    /* translators: %d: Maximum size in KB */
                    __( 'Schema exceeds maximum size of %d KB.', 'wp-ai-seo-schema-generator' ),
                    self::MAX_SIZE / 1024
                ),
                'type'   => '',
            );
        }

        // Validate JSON
        $decoded = json_decode( $schema, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array(
                'valid'  => false,
                'schema' => '',
                'error'  => sprintf(
                    /* translators: %s: JSON error message */
                    __( 'Invalid JSON: %s', 'wp-ai-seo-schema-generator' ),
                    json_last_error_msg()
                ),
                'type'   => '',
            );
        }

        // Validate structure
        $structure_result = $this->validate_structure( $decoded );

        if ( ! $structure_result['valid'] ) {
            return $structure_result;
        }

        // Re-encode to ensure consistent formatting
        $clean_schema = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        return array(
            'valid'  => true,
            'schema' => $clean_schema,
            'error'  => '',
            'type'   => $structure_result['type'],
        );
    }

    /**
     * Strip HTML and script tags from schema
     *
     * @param string $schema Raw schema.
     * @return string Cleaned schema.
     */
    private function strip_html( string $schema ): string {
        // Remove script tags
        $schema = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $schema );

        // Remove any remaining HTML tags
        $schema = wp_strip_all_tags( $schema );

        return trim( $schema );
    }

    /**
     * Extract JSON from mixed content
     *
     * Handles cases where the LLM returns JSON wrapped in markdown code blocks
     * or with explanatory text.
     *
     * @param string $content Raw content from LLM.
     * @return string Extracted JSON or empty string.
     */
    private function extract_json( string $content ): string {
        $content = trim( $content );

        // If it already looks like valid JSON, return it
        if ( $this->is_json_string( $content ) ) {
            return $content;
        }

        // Try to extract from markdown code blocks
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $content, $matches ) ) {
            $extracted = trim( $matches[1] );
            if ( $this->is_json_string( $extracted ) ) {
                return $extracted;
            }
        }

        // Try to find JSON object or array in the content
        // Look for { ... } pattern
        if ( preg_match( '/(\{[\s\S]*\})/', $content, $matches ) ) {
            $extracted = $matches[1];
            if ( $this->is_json_string( $extracted ) ) {
                return $extracted;
            }
        }

        // Look for [ ... ] pattern (array)
        if ( preg_match( '/(\[[\s\S]*\])/', $content, $matches ) ) {
            $extracted = $matches[1];
            if ( $this->is_json_string( $extracted ) ) {
                return $extracted;
            }
        }

        return '';
    }

    /**
     * Check if a string is valid JSON
     *
     * @param string $string String to check.
     * @return bool True if valid JSON.
     */
    private function is_json_string( string $string ): bool {
        if ( empty( $string ) ) {
            return false;
        }

        // Must start with { or [
        $first_char = substr( trim( $string ), 0, 1 );
        if ( '{' !== $first_char && '[' !== $first_char ) {
            return false;
        }

        json_decode( $string );
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Validate JSON-LD structure
     *
     * @param array $decoded Decoded JSON array.
     * @return array Validation result with 'valid', 'error', and 'type'.
     */
    private function validate_structure( array $decoded ): array {
        // Check for @context
        $has_context = false;
        $schema_type = '';

        // Check if it's a single object or @graph
        if ( isset( $decoded['@context'] ) ) {
            $has_context = true;

            if ( isset( $decoded['@type'] ) ) {
                $schema_type = $decoded['@type'];
            } elseif ( isset( $decoded['@graph'] ) ) {
                $schema_type = '@graph';
                // Validate @graph is an array
                if ( ! is_array( $decoded['@graph'] ) ) {
                    return array(
                        'valid' => false,
                        'error' => __( '@graph must be an array.', 'wp-ai-seo-schema-generator' ),
                        'type'  => '',
                    );
                }
            }
        } elseif ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
            // Array of schemas - each should have @context
            foreach ( $decoded as $item ) {
                if ( isset( $item['@context'] ) ) {
                    $has_context = true;
                    if ( isset( $item['@type'] ) && empty( $schema_type ) ) {
                        $schema_type = $item['@type'];
                    }
                }
            }
        }

        if ( ! $has_context ) {
            return array(
                'valid' => false,
                'error' => __( 'Schema must include @context.', 'wp-ai-seo-schema-generator' ),
                'type'  => '',
            );
        }

        return array(
            'valid' => true,
            'error' => '',
            'type'  => $schema_type,
        );
    }

    /**
     * Validate JSON string without full processing
     *
     * @param string $json JSON string to validate.
     * @return bool True if valid JSON.
     */
    public function is_valid_json( string $json ): bool {
        if ( empty( $json ) ) {
            return false;
        }

        json_decode( $json );
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Pretty print JSON for display
     *
     * @param string $json JSON string.
     * @return string Pretty printed JSON.
     */
    public function pretty_print( string $json ): string {
        $decoded = json_decode( $json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return $json;
        }

        return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Get the primary schema type from JSON-LD
     *
     * @param string $schema JSON-LD string.
     * @return string Schema type or empty string.
     */
    public function get_schema_type( string $schema ): string {
        $decoded = json_decode( $schema, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return '';
        }

        if ( isset( $decoded['@type'] ) ) {
            return is_array( $decoded['@type'] ) ? $decoded['@type'][0] : $decoded['@type'];
        }

        if ( isset( $decoded['@graph'][0]['@type'] ) ) {
            $type = $decoded['@graph'][0]['@type'];
            return is_array( $type ) ? $type[0] : $type;
        }

        return '';
    }
}
