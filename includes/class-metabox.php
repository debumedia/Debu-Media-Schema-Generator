<?php
/**
 * Metabox class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the edit screen metabox for JSON-LD generation
 */
class AI_JSONLD_Metabox {

    /**
     * Content processor
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
        $settings    = AI_JSONLD_Generator::get_settings();
        $post_types  = $settings['enabled_post_types'] ?? array( 'page' );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'ai_jsonld_generator',
                __( 'AI JSON-LD Generator', 'ai-jsonld-generator' ),
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
        $settings     = AI_JSONLD_Generator::get_settings();
        $cache_status = $this->content_processor->get_cache_status( $post->ID, $settings );
        $schema       = get_post_meta( $post->ID, '_ai_jsonld_schema', true );
        $type_hint    = get_post_meta( $post->ID, '_ai_jsonld_type_hint', true ) ?: 'auto';
        $is_empty     = $this->content_processor->is_content_empty( $post->ID );

        // Nonce for security
        wp_nonce_field( 'ai_jsonld_metabox', 'ai_jsonld_metabox_nonce' );
        ?>
        <div class="ai-jsonld-metabox">
            <?php if ( $is_empty ) : ?>
                <div class="ai-jsonld-notice ai-jsonld-notice-warning">
                    <?php esc_html_e( 'This page has insufficient content to generate meaningful schema.', 'ai-jsonld-generator' ); ?>
                </div>
            <?php endif; ?>

            <div class="ai-jsonld-controls">
                <div class="ai-jsonld-type-selector">
                    <label for="ai_jsonld_type_hint">
                        <?php esc_html_e( 'Schema type hint:', 'ai-jsonld-generator' ); ?>
                    </label>
                    <select name="ai_jsonld_type_hint" id="ai_jsonld_type_hint">
                        <?php foreach ( AI_JSONLD_Prompt_Builder::get_schema_type_options() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type_hint, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ai-jsonld-options">
                    <label>
                        <input type="checkbox" id="ai_jsonld_force_regenerate" />
                        <?php esc_html_e( 'Force regenerate (ignore cache)', 'ai-jsonld-generator' ); ?>
                    </label>
                </div>

                <div class="ai-jsonld-actions">
                    <button type="button" id="ai_jsonld_generate" class="button button-primary" <?php disabled( $is_empty ); ?>>
                        <?php esc_html_e( 'Generate JSON-LD', 'ai-jsonld-generator' ); ?>
                    </button>
                    <span class="ai-jsonld-spinner spinner"></span>
                </div>
            </div>

            <div class="ai-jsonld-status">
                <?php $this->render_status( $cache_status ); ?>
            </div>

            <div class="ai-jsonld-preview">
                <label for="ai_jsonld_schema_preview">
                    <?php esc_html_e( 'Generated Schema:', 'ai-jsonld-generator' ); ?>
                </label>
                <div class="ai-jsonld-preview-actions">
                    <button type="button" id="ai_jsonld_copy" class="button button-small" <?php disabled( empty( $schema ) ); ?>>
                        <?php esc_html_e( 'Copy', 'ai-jsonld-generator' ); ?>
                    </button>
                    <button type="button" id="ai_jsonld_validate" class="button button-small" <?php disabled( empty( $schema ) ); ?>>
                        <?php esc_html_e( 'Validate', 'ai-jsonld-generator' ); ?>
                    </button>
                </div>
                <textarea
                    id="ai_jsonld_schema_preview"
                    class="large-text code"
                    rows="10"
                    readonly
                ><?php echo esc_textarea( $schema ? $this->pretty_print_json( $schema ) : '' ); ?></textarea>
            </div>

            <div id="ai_jsonld_message" class="ai-jsonld-message hidden"></div>
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
            esc_html_e( 'No schema generated yet', 'ai-jsonld-generator' );
            echo '</span>';
            return;
        }

        if ( $cache_status['is_current'] ) {
            echo '<span class="ai-jsonld-status-label ai-jsonld-status-current">';
            esc_html_e( 'Schema is current', 'ai-jsonld-generator' );
            echo '</span>';
        } else {
            echo '<span class="ai-jsonld-status-label ai-jsonld-status-outdated">';
            esc_html_e( 'Content has changed since last generation', 'ai-jsonld-generator' );
            echo '</span>';
        }

        if ( $cache_status['generated_at'] ) {
            echo '<span class="ai-jsonld-generated-time">';
            printf(
                /* translators: %s: formatted date and time */
                esc_html__( 'Last generated: %s', 'ai-jsonld-generator' ),
                esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cache_status['generated_at'] ) )
            );
            echo '</span>';
        }

        if ( 'error' === $cache_status['status'] && $cache_status['error'] ) {
            echo '<div class="ai-jsonld-error">';
            echo '<strong>' . esc_html__( 'Last error:', 'ai-jsonld-generator' ) . '</strong> ';
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
        if ( ! isset( $_POST['ai_jsonld_metabox_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ai_jsonld_metabox_nonce'] ) ), 'ai_jsonld_metabox' ) ) {
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
        $settings   = AI_JSONLD_Generator::get_settings();
        $post_types = $settings['enabled_post_types'] ?? array( 'page' );

        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        // Save type hint
        if ( isset( $_POST['ai_jsonld_type_hint'] ) ) {
            $type_hint = AI_JSONLD_Prompt_Builder::validate_type_hint(
                sanitize_text_field( wp_unslash( $_POST['ai_jsonld_type_hint'] ) )
            );
            update_post_meta( $post_id, '_ai_jsonld_type_hint', $type_hint );
        }

        // Check if we should auto-regenerate
        if ( ! empty( $settings['auto_regenerate_on_update'] ) ) {
            if ( $this->content_processor->should_regenerate( $post_id, $settings, false ) ) {
                wp_schedule_single_event( time(), 'ai_jsonld_regenerate', array( $post_id ) );
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

        $settings   = AI_JSONLD_Generator::get_settings();
        $post_types = $settings['enabled_post_types'] ?? array( 'page' );

        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        wp_enqueue_style(
            'ai-jsonld-metabox',
            AI_JSONLD_PLUGIN_URL . 'assets/css/metabox.css',
            array(),
            AI_JSONLD_VERSION
        );

        wp_enqueue_script(
            'ai-jsonld-metabox',
            AI_JSONLD_PLUGIN_URL . 'assets/js/metabox.js',
            array( 'jquery' ),
            AI_JSONLD_VERSION,
            true
        );

        wp_localize_script(
            'ai-jsonld-metabox',
            'aiJsonldMetabox',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ai_jsonld_generate_' . $post->ID ),
                'post_id'  => $post->ID,
                'i18n'     => array(
                    'generating'      => __( 'Generating...', 'ai-jsonld-generator' ),
                    'generate'        => __( 'Generate JSON-LD', 'ai-jsonld-generator' ),
                    'success'         => __( 'Schema generated successfully!', 'ai-jsonld-generator' ),
                    'error'           => __( 'Error generating schema', 'ai-jsonld-generator' ),
                    'copied'          => __( 'Copied to clipboard!', 'ai-jsonld-generator' ),
                    'copy_failed'     => __( 'Failed to copy', 'ai-jsonld-generator' ),
                    'valid_json'      => __( 'Valid JSON', 'ai-jsonld-generator' ),
                    'invalid_json'    => __( 'Invalid JSON', 'ai-jsonld-generator' ),
                    'cooldown'        => __( 'Please wait %d seconds...', 'ai-jsonld-generator' ),
                    'rate_limited'    => __( 'Rate limited. Please try again later.', 'ai-jsonld-generator' ),
                    'schema_current'  => __( 'Schema is current', 'ai-jsonld-generator' ),
                    'schema_outdated' => __( 'Content has changed since last generation', 'ai-jsonld-generator' ),
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
