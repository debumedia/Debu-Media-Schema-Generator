<?php
/**
 * Metabox class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the edit screen metabox for JSON-LD generation
 */
class WP_AI_Schema_Metabox {

    /**
     * Content processor
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

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Add meta box to enabled post types
     */
    public function add_meta_box() {
        $settings    = WP_AI_Schema_Generator::get_settings();
        $post_types  = $settings['enabled_post_types'] ?? array( 'page' );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'wp_ai_schema_generator',
                __( 'AI JSON-LD Generator', 'wp-ai-seo-schema-generator' ),
                array( $this, 'render_metabox' ),
                $post_type,
                'normal',
                'default'
            );
        }
    }

    /**
     * Render the metabox content
     *
     * @param WP_Post $post Current post object.
     */
    public function render_metabox( $post ) {
        $settings     = WP_AI_Schema_Generator::get_settings();
        $cache_status = $this->content_processor->get_cache_status( $post->ID, $settings );
        $schema       = get_post_meta( $post->ID, '_wp_ai_schema_schema', true );
        $type_hint    = get_post_meta( $post->ID, '_wp_ai_schema_type_hint', true ) ?: 'auto';
        $is_empty     = $this->content_processor->is_content_empty( $post->ID );
        $content_info = $this->content_processor->get_content_info( $post->ID );

        // Nonce for security
        wp_nonce_field( 'wp_ai_schema_metabox', 'wp_ai_schema_metabox_nonce' );
        ?>
        <div class="ai-jsonld-metabox">
            <?php if ( $content_info['builder'] ) : ?>
                <div class="ai-jsonld-notice ai-jsonld-notice-info">
                    <?php
                    printf(
                        /* translators: 1: page builder name, 2: content characters */
                        esc_html__( 'Page Builder Detected: %1$s. Content extracted: %2$s characters.', 'wp-ai-seo-schema-generator' ),
                        '<strong>' . esc_html( $content_info['builder_label'] ) . '</strong>',
                        number_format( max( $content_info['standard_length'], $content_info['rendered_length'], $content_info['builder_length'] ) )
                    );
                    ?>
                </div>
            <?php endif; ?>

            <?php if ( $is_empty ) : ?>
                <div class="ai-jsonld-notice ai-jsonld-notice-warning">
                    <?php esc_html_e( 'This page has insufficient content to generate meaningful schema.', 'wp-ai-seo-schema-generator' ); ?>
                    <?php if ( $content_info['can_fetch_frontend'] ) : ?>
                        <br><small><?php esc_html_e( 'Try checking "Fetch from frontend" below if you\'re using a page builder.', 'wp-ai-seo-schema-generator' ); ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="ai-jsonld-controls">
                <div class="ai-jsonld-type-selector">
                    <label for="wp_ai_schema_type_hint">
                        <?php esc_html_e( 'Schema type hint:', 'wp-ai-seo-schema-generator' ); ?>
                    </label>
                    <select name="wp_ai_schema_type_hint" id="wp_ai_schema_type_hint">
                        <?php foreach ( WP_AI_Schema_Prompt_Builder::get_schema_type_options() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type_hint, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ai-jsonld-options">
                    <label>
                        <input type="checkbox" id="wp_ai_schema_force_regenerate" />
                        <?php esc_html_e( 'Force regenerate (ignore cache)', 'wp-ai-seo-schema-generator' ); ?>
                    </label>
                    <?php if ( 'publish' === $post->post_status ) : ?>
                        <label title="<?php esc_attr_e( 'Fetches the live page to extract content. Useful for page builders like Bricks, Elementor, etc.', 'wp-ai-seo-schema-generator' ); ?>">
                            <input type="checkbox" id="wp_ai_schema_fetch_frontend" />
                            <?php esc_html_e( 'Fetch from frontend', 'wp-ai-seo-schema-generator' ); ?>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="ai-jsonld-actions">
                    <button type="button" id="wp_ai_schema_generate" class="button button-primary" <?php disabled( $is_empty ); ?>>
                        <?php esc_html_e( 'Generate JSON-LD', 'wp-ai-seo-schema-generator' ); ?>
                    </button>
                    <span class="ai-jsonld-spinner spinner"></span>
                </div>
            </div>

            <div class="ai-jsonld-status">
                <?php $this->render_status( $cache_status ); ?>
            </div>

            <div class="ai-jsonld-preview">
                <label for="wp_ai_schema_schema_preview">
                    <?php esc_html_e( 'Generated Schema:', 'wp-ai-seo-schema-generator' ); ?>
                </label>
                <div class="ai-jsonld-preview-actions">
                    <button type="button" id="wp_ai_schema_copy" class="button button-small" <?php disabled( empty( $schema ) ); ?>>
                        <?php esc_html_e( 'Copy', 'wp-ai-seo-schema-generator' ); ?>
                    </button>
                    <button type="button" id="wp_ai_schema_validate" class="button button-small" <?php disabled( empty( $schema ) ); ?>>
                        <?php esc_html_e( 'Validate', 'wp-ai-seo-schema-generator' ); ?>
                    </button>
                </div>
                <textarea
                    id="wp_ai_schema_schema_preview"
                    class="large-text code"
                    rows="10"
                    readonly
                ><?php echo esc_textarea( $schema ? $this->pretty_print_json( $schema ) : '' ); ?></textarea>
            </div>

            <div id="wp_ai_schema_message" class="ai-jsonld-message hidden"></div>

            <!-- Diagnostic Panel -->
            <div class="ai-jsonld-diagnostic-section">
                <div class="ai-jsonld-diagnostic-header">
                    <h4><?php esc_html_e( 'Frontend Output Status', 'wp-ai-seo-schema-generator' ); ?></h4>
                    <div class="ai-jsonld-diagnostic-actions">
                        <button type="button" id="wp_ai_schema_run_diagnostics" class="button button-small">
                            <?php esc_html_e( 'Run Diagnostics', 'wp-ai-seo-schema-generator' ); ?>
                        </button>
                        <button type="button" id="wp_ai_schema_verify_frontend" class="button button-small" <?php disabled( empty( $schema ) ); ?>>
                            <?php esc_html_e( 'Verify Frontend', 'wp-ai-seo-schema-generator' ); ?>
                        </button>
                        <span class="ai-jsonld-diagnostic-spinner spinner"></span>
                    </div>
                </div>
                <div id="wp_ai_schema_diagnostic_panel" class="ai-jsonld-diagnostic-panel">
                    <p class="ai-jsonld-diagnostic-hint">
                        <?php esc_html_e( 'Click "Run Diagnostics" to check if schema will appear on the frontend.', 'wp-ai-seo-schema-generator' ); ?>
                    </p>
                </div>
                <div id="wp_ai_schema_verify_result" class="ai-jsonld-verify-result hidden"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render cache status
     *
     * @param array $cache_status Cache status array.
     */
    private function render_status( $cache_status ) {
        if ( ! $cache_status['has_schema'] ) {
            echo '<span class="ai-jsonld-status-label ai-jsonld-status-none">';
            esc_html_e( 'No schema generated yet', 'wp-ai-seo-schema-generator' );
            echo '</span>';
            return;
        }

        if ( $cache_status['is_current'] ) {
            echo '<span class="ai-jsonld-status-label ai-jsonld-status-current">';
            esc_html_e( 'Schema is current', 'wp-ai-seo-schema-generator' );
            echo '</span>';
        } else {
            echo '<span class="ai-jsonld-status-label ai-jsonld-status-outdated">';
            esc_html_e( 'Content has changed since last generation', 'wp-ai-seo-schema-generator' );
            echo '</span>';
        }

        if ( $cache_status['generated_at'] ) {
            echo '<span class="ai-jsonld-generated-time">';
            printf(
                /* translators: %s: formatted date and time */
                esc_html__( 'Last generated: %s', 'wp-ai-seo-schema-generator' ),
                esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cache_status['generated_at'] ) )
            );
            echo '</span>';
        }

        if ( 'error' === $cache_status['status'] && $cache_status['error'] ) {
            echo '<div class="ai-jsonld-error">';
            echo '<strong>' . esc_html__( 'Last error:', 'wp-ai-seo-schema-generator' ) . '</strong> ';
            echo esc_html( $cache_status['error'] );
            echo '</div>';
        }
    }

    /**
     * Save meta box data
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function save_meta( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['wp_ai_schema_metabox_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_ai_schema_metabox_nonce'] ) ), 'wp_ai_schema_metabox' ) ) {
            return;
        }

        // Check autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Check if this post type is enabled
        $settings   = WP_AI_Schema_Generator::get_settings();
        $post_types = $settings['enabled_post_types'] ?? array( 'page' );

        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        // Save type hint
        if ( isset( $_POST['wp_ai_schema_type_hint'] ) ) {
            $type_hint = WP_AI_Schema_Prompt_Builder::validate_type_hint(
                sanitize_text_field( wp_unslash( $_POST['wp_ai_schema_type_hint'] ) )
            );
            update_post_meta( $post_id, '_wp_ai_schema_type_hint', $type_hint );
        }

        // Check if we should auto-regenerate
        if ( ! empty( $settings['auto_regenerate_on_update'] ) ) {
            if ( $this->content_processor->should_regenerate( $post_id, $settings, false ) ) {
                wp_schedule_single_event( time(), 'wp_ai_schema_regenerate', array( $post_id ) );
            }
        }
    }

    /**
     * Enqueue metabox assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        global $post;

        if ( ! $post ) {
            return;
        }

        $settings   = WP_AI_Schema_Generator::get_settings();
        $post_types = $settings['enabled_post_types'] ?? array( 'page' );

        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        wp_enqueue_style(
            'ai-jsonld-metabox',
            WP_AI_SCHEMA_PLUGIN_URL . 'assets/css/metabox.css',
            array(),
            WP_AI_SCHEMA_VERSION
        );

        wp_enqueue_script(
            'ai-jsonld-metabox',
            WP_AI_SCHEMA_PLUGIN_URL . 'assets/js/metabox.js',
            array( 'jquery' ),
            WP_AI_SCHEMA_VERSION,
            true
        );

        wp_localize_script(
            'ai-jsonld-metabox',
            'wpAiSchemaMetabox',
            array(
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'wp_ai_schema_generate_' . $post->ID ),
                'post_id'    => $post->ID,
                'post_url'   => get_permalink( $post->ID ),
                'post_status' => $post->post_status,
                'i18n'       => array(
                    'generating'           => __( 'Generating...', 'wp-ai-seo-schema-generator' ),
                    'generate'             => __( 'Generate JSON-LD', 'wp-ai-seo-schema-generator' ),
                    'preparing'            => __( 'Preparing content...', 'wp-ai-seo-schema-generator' ),
                    'sending'              => __( 'Sending to AI...', 'wp-ai-seo-schema-generator' ),
                    'waiting'              => __( 'Waiting for response...', 'wp-ai-seo-schema-generator' ),
                    'processing'           => __( 'Processing schema...', 'wp-ai-seo-schema-generator' ),
                    'success'              => __( 'Schema generated successfully!', 'wp-ai-seo-schema-generator' ),
                    'error'                => __( 'Error generating schema', 'wp-ai-seo-schema-generator' ),
                    'timeout'              => __( 'Request timed out. The AI may be busy - please try again.', 'wp-ai-seo-schema-generator' ),
                    'copied'               => __( 'Copied to clipboard!', 'wp-ai-seo-schema-generator' ),
                    'copy_failed'          => __( 'Failed to copy', 'wp-ai-seo-schema-generator' ),
                    'valid_json'           => __( 'Valid JSON', 'wp-ai-seo-schema-generator' ),
                    'invalid_json'         => __( 'Invalid JSON', 'wp-ai-seo-schema-generator' ),
                    'cooldown'             => __( 'Please wait %d seconds...', 'wp-ai-seo-schema-generator' ),
                    'rate_limited'         => __( 'Rate limited. Please try again later.', 'wp-ai-seo-schema-generator' ),
                    'schema_current'       => __( 'Schema is current', 'wp-ai-seo-schema-generator' ),
                    'schema_outdated'      => __( 'Content has changed since last generation', 'wp-ai-seo-schema-generator' ),
                    // Diagnostic strings
                    'running_diagnostics'  => __( 'Running diagnostics...', 'wp-ai-seo-schema-generator' ),
                    'run_diagnostics'      => __( 'Run Diagnostics', 'wp-ai-seo-schema-generator' ),
                    'verifying_frontend'   => __( 'Verifying frontend...', 'wp-ai-seo-schema-generator' ),
                    'verify_frontend'      => __( 'Verify Frontend', 'wp-ai-seo-schema-generator' ),
                    'diagnostic_error'     => __( 'Error running diagnostics', 'wp-ai-seo-schema-generator' ),
                    'verify_error'         => __( 'Error verifying frontend', 'wp-ai-seo-schema-generator' ),
                    'schema_verified'      => __( 'Schema verified on frontend!', 'wp-ai-seo-schema-generator' ),
                    'schema_not_found'     => __( 'Schema not found on frontend', 'wp-ai-seo-schema-generator' ),
                    'schema_mismatch'      => __( 'Schema found but does not match', 'wp-ai-seo-schema-generator' ),
                    'checking_via_js'      => __( 'Checking via browser...', 'wp-ai-seo-schema-generator' ),
                    'js_verify_success'    => __( 'Schema found via browser check!', 'wp-ai-seo-schema-generator' ),
                    'js_verify_not_found'  => __( 'Schema not found via browser check', 'wp-ai-seo-schema-generator' ),
                    'preview_only'         => __( 'Post is not published. Use preview to verify.', 'wp-ai-seo-schema-generator' ),
                ),
            )
        );
    }

    /**
     * Pretty print JSON for display
     *
     * @param string $json JSON string.
     * @return string Pretty printed JSON.
     */
    private function pretty_print_json( $json ) {
        $decoded = json_decode( $json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return $json;
        }

        return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }
}
