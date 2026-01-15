<?php
/**
 * Admin settings page class
 *
 * @package AI_JSONLD_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the admin settings page
 */
class AI_JSONLD_Admin {

    /**
     * Option name
     */
    const OPTION_NAME = 'ai_jsonld_settings';

    /**
     * Option group
     */
    const OPTION_GROUP = 'ai_jsonld_settings_group';

    /**
     * Settings page slug
     */
    const PAGE_SLUG = 'ai-jsonld-generator';

    /**
     * Encryption handler
     *
     * @var AI_JSONLD_Encryption
     */
    private $encryption;

    /**
     * Provider registry
     *
     * @var AI_JSONLD_Provider_Registry
     */
    private $provider_registry;

    /**
     * Constructor
     *
     * @param AI_JSONLD_Encryption        $encryption        Encryption handler.
     * @param AI_JSONLD_Provider_Registry $provider_registry Provider registry.
     */
    public function __construct( AI_JSONLD_Encryption $encryption, AI_JSONLD_Provider_Registry $provider_registry ) {
        $this->encryption        = $encryption;
        $this->provider_registry = $provider_registry;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_ai_jsonld_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    /**
     * Add menu page
     */
    public function add_menu_page() {
        add_options_page(
            __( 'AI JSON-LD Generator', 'ai-jsonld-generator' ),
            __( 'AI JSON-LD', 'ai-jsonld-generator' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => AI_JSONLD_Generator::get_instance()->get_default_settings(),
            )
        );

        // Provider section
        add_settings_section(
            'ai_jsonld_provider_section',
            __( 'LLM Provider Settings', 'ai-jsonld-generator' ),
            array( $this, 'render_provider_section' ),
            self::PAGE_SLUG
        );

        $this->add_provider_fields();

        // Generation section
        add_settings_section(
            'ai_jsonld_generation_section',
            __( 'Generation Settings', 'ai-jsonld-generator' ),
            array( $this, 'render_generation_section' ),
            self::PAGE_SLUG
        );

        $this->add_generation_fields();

        // Output section
        add_settings_section(
            'ai_jsonld_output_section',
            __( 'Output Settings', 'ai-jsonld-generator' ),
            array( $this, 'render_output_section' ),
            self::PAGE_SLUG
        );

        $this->add_output_fields();

        // Advanced section
        add_settings_section(
            'ai_jsonld_advanced_section',
            __( 'Advanced Settings', 'ai-jsonld-generator' ),
            array( $this, 'render_advanced_section' ),
            self::PAGE_SLUG
        );

        $this->add_advanced_fields();
    }

    /**
     * Add provider settings fields
     */
    private function add_provider_fields() {
        add_settings_field(
            'provider',
            __( 'Provider', 'ai-jsonld-generator' ),
            array( $this, 'render_provider_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_provider_section'
        );

        add_settings_field(
            'deepseek_api_key',
            __( 'DeepSeek API Key', 'ai-jsonld-generator' ),
            array( $this, 'render_api_key_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_provider_section'
        );

        add_settings_field(
            'deepseek_model',
            __( 'Model', 'ai-jsonld-generator' ),
            array( $this, 'render_model_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_provider_section'
        );
    }

    /**
     * Add generation settings fields
     */
    private function add_generation_fields() {
        add_settings_field(
            'temperature',
            __( 'Temperature', 'ai-jsonld-generator' ),
            array( $this, 'render_temperature_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_generation_section'
        );

        add_settings_field(
            'max_tokens',
            __( 'Max Tokens', 'ai-jsonld-generator' ),
            array( $this, 'render_max_tokens_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_generation_section'
        );

        add_settings_field(
            'max_content_chars',
            __( 'Max Content Characters', 'ai-jsonld-generator' ),
            array( $this, 'render_max_content_chars_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_generation_section'
        );
    }

    /**
     * Add output settings fields
     */
    private function add_output_fields() {
        add_settings_field(
            'output_location',
            __( 'Output Location', 'ai-jsonld-generator' ),
            array( $this, 'render_output_location_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_output_section'
        );

        add_settings_field(
            'enabled_post_types',
            __( 'Enabled Post Types', 'ai-jsonld-generator' ),
            array( $this, 'render_post_types_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_output_section'
        );

        add_settings_field(
            'skip_if_schema_exists',
            __( 'SEO Plugin Compatibility', 'ai-jsonld-generator' ),
            array( $this, 'render_skip_schema_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_output_section'
        );
    }

    /**
     * Add advanced settings fields
     */
    private function add_advanced_fields() {
        add_settings_field(
            'auto_regenerate_on_update',
            __( 'Auto Regenerate', 'ai-jsonld-generator' ),
            array( $this, 'render_auto_regenerate_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_advanced_section'
        );

        add_settings_field(
            'delete_data_on_uninstall',
            __( 'Data Cleanup', 'ai-jsonld-generator' ),
            array( $this, 'render_delete_data_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_advanced_section'
        );

        add_settings_field(
            'debug_logging',
            __( 'Debug Logging', 'ai-jsonld-generator' ),
            array( $this, 'render_debug_field' ),
            self::PAGE_SLUG,
            'ai_jsonld_advanced_section'
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Raw settings input.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( $input ) {
        $current  = AI_JSONLD_Generator::get_settings();
        $sanitized = array();

        // Provider
        $sanitized['provider'] = sanitize_text_field( $input['provider'] ?? 'deepseek' );

        // API Key - only update if a new value is provided
        if ( ! empty( $input['deepseek_api_key'] ) ) {
            $sanitized['deepseek_api_key'] = $this->encryption->encrypt( sanitize_text_field( $input['deepseek_api_key'] ) );
        } else {
            $sanitized['deepseek_api_key'] = $current['deepseek_api_key'] ?? '';
        }

        // Model
        $sanitized['deepseek_model'] = sanitize_text_field( $input['deepseek_model'] ?? 'deepseek-chat' );

        // Generation settings
        $sanitized['temperature'] = max( 0, min( 1, floatval( $input['temperature'] ?? 0.2 ) ) );
        $sanitized['max_tokens']  = absint( $input['max_tokens'] ?? 1200 );
        $sanitized['max_content_chars'] = absint( $input['max_content_chars'] ?? 8000 );

        // Output settings
        $sanitized['output_location'] = in_array( $input['output_location'] ?? 'head', array( 'head', 'after_content' ), true )
            ? $input['output_location']
            : 'head';

        // Post types
        $sanitized['enabled_post_types'] = array();
        if ( ! empty( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
            foreach ( $input['enabled_post_types'] as $post_type ) {
                $sanitized['enabled_post_types'][] = sanitize_key( $post_type );
            }
        }
        if ( empty( $sanitized['enabled_post_types'] ) ) {
            $sanitized['enabled_post_types'] = array( 'page' );
        }

        // Boolean settings
        $sanitized['skip_if_schema_exists']     = ! empty( $input['skip_if_schema_exists'] );
        $sanitized['auto_regenerate_on_update'] = ! empty( $input['auto_regenerate_on_update'] );
        $sanitized['delete_data_on_uninstall']  = ! empty( $input['delete_data_on_uninstall'] );
        $sanitized['debug_logging']             = ! empty( $input['debug_logging'] );

        // Settings version (preserve or use current)
        $sanitized['settings_version'] = $current['settings_version'] ?? '1.0';

        return $sanitized;
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'ai-jsonld-admin',
            AI_JSONLD_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AI_JSONLD_VERSION
        );

        wp_enqueue_script(
            'ai-jsonld-admin',
            AI_JSONLD_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            AI_JSONLD_VERSION,
            true
        );

        wp_localize_script(
            'ai-jsonld-admin',
            'aiJsonldAdmin',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ai_jsonld_test_connection' ),
                'i18n'     => array(
                    'testing'     => __( 'Testing...', 'ai-jsonld-generator' ),
                    'test'        => __( 'Test Connection', 'ai-jsonld-generator' ),
                    'success'     => __( 'Connection successful!', 'ai-jsonld-generator' ),
                    'error'       => __( 'Connection failed', 'ai-jsonld-generator' ),
                ),
            )
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = AI_JSONLD_Generator::get_settings();

        // Show OpenSSL notice if needed
        $openssl_notice = $this->encryption->get_openssl_notice();
        if ( $openssl_notice ) {
            echo $openssl_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        // Show conflict notice
        $conflict_detector = ai_jsonld_generator()->get_component( 'conflict_detector' );
        if ( $conflict_detector ) {
            $conflict_notice = $conflict_detector->get_admin_notice( $settings );
            if ( $conflict_notice ) {
                echo $conflict_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
        ?>
        <div class="wrap ai-jsonld-settings">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render provider section
     */
    public function render_provider_section() {
        echo '<p>' . esc_html__( 'Configure your LLM provider for generating JSON-LD schemas.', 'ai-jsonld-generator' ) . '</p>';
    }

    /**
     * Render generation section
     */
    public function render_generation_section() {
        echo '<p>' . esc_html__( 'Adjust generation parameters for the AI model.', 'ai-jsonld-generator' ) . '</p>';
    }

    /**
     * Render output section
     */
    public function render_output_section() {
        echo '<p>' . esc_html__( 'Configure how and where the schema is output on your site.', 'ai-jsonld-generator' ) . '</p>';
    }

    /**
     * Render advanced section
     */
    public function render_advanced_section() {
        echo '<p>' . esc_html__( 'Advanced settings for automation and debugging.', 'ai-jsonld-generator' ) . '</p>';
    }

    /**
     * Render provider field
     */
    public function render_provider_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        $options  = $this->provider_registry->get_options();
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[provider]" id="ai_jsonld_provider">
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['provider'], $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Select the LLM provider to use for generating schemas.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $settings  = AI_JSONLD_Generator::get_settings();
        $has_key   = ! empty( $settings['deepseek_api_key'] );
        $masked    = $has_key ? $this->encryption->mask_key( $this->encryption->decrypt( $settings['deepseek_api_key'] ) ) : '';
        ?>
        <div class="ai-jsonld-api-key-wrapper">
            <input
                type="password"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[deepseek_api_key]"
                id="ai_jsonld_api_key"
                class="regular-text"
                placeholder="<?php echo $has_key ? esc_attr( $masked ) : esc_attr__( 'Enter your API key', 'ai-jsonld-generator' ); ?>"
                autocomplete="new-password"
            />
            <button type="button" id="ai_jsonld_test_connection" class="button">
                <?php esc_html_e( 'Test Connection', 'ai-jsonld-generator' ); ?>
            </button>
            <span id="ai_jsonld_connection_status"></span>
        </div>
        <?php if ( $has_key ) : ?>
            <p class="description">
                <?php esc_html_e( 'Current key:', 'ai-jsonld-generator' ); ?> <code><?php echo esc_html( $masked ); ?></code>
                <?php esc_html_e( 'Leave blank to keep the current key.', 'ai-jsonld-generator' ); ?>
            </p>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'Enter your DeepSeek API key.', 'ai-jsonld-generator' ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render model field
     */
    public function render_model_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[deepseek_model]"
            id="ai_jsonld_model"
            value="<?php echo esc_attr( $settings['deepseek_model'] ); ?>"
            class="regular-text"
        />
        <p class="description"><?php esc_html_e( 'The model to use (default: deepseek-chat).', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render temperature field
     */
    public function render_temperature_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[temperature]"
            id="ai_jsonld_temperature"
            value="<?php echo esc_attr( $settings['temperature'] ); ?>"
            min="0"
            max="1"
            step="0.1"
            class="small-text"
        />
        <p class="description"><?php esc_html_e( 'Controls randomness (0-1). Lower values are more deterministic.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render max tokens field
     */
    public function render_max_tokens_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_tokens]"
            id="ai_jsonld_max_tokens"
            value="<?php echo esc_attr( $settings['max_tokens'] ); ?>"
            min="100"
            max="4000"
            class="small-text"
        />
        <p class="description"><?php esc_html_e( 'Maximum tokens in the response (default: 1200).', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render max content chars field
     */
    public function render_max_content_chars_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_content_chars]"
            id="ai_jsonld_max_content_chars"
            value="<?php echo esc_attr( $settings['max_content_chars'] ); ?>"
            min="1000"
            max="32000"
            class="small-text"
        />
        <p class="description"><?php esc_html_e( 'Maximum content characters to send to the API (default: 8000).', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render output location field
     */
    public function render_output_location_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <fieldset>
            <label>
                <input
                    type="radio"
                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[output_location]"
                    value="head"
                    <?php checked( $settings['output_location'], 'head' ); ?>
                />
                <?php esc_html_e( 'In <head> (Recommended)', 'ai-jsonld-generator' ); ?>
            </label>
            <br>
            <label>
                <input
                    type="radio"
                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[output_location]"
                    value="after_content"
                    <?php checked( $settings['output_location'], 'after_content' ); ?>
                />
                <?php esc_html_e( 'After content', 'ai-jsonld-generator' ); ?>
            </label>
        </fieldset>
        <p class="description"><?php esc_html_e( 'Where to output the JSON-LD script tag.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render post types field
     */
    public function render_post_types_field() {
        $settings   = AI_JSONLD_Generator::get_settings();
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        ?>
        <fieldset>
            <?php foreach ( $post_types as $post_type ) : ?>
                <label>
                    <input
                        type="checkbox"
                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled_post_types][]"
                        value="<?php echo esc_attr( $post_type->name ); ?>"
                        <?php checked( in_array( $post_type->name, $settings['enabled_post_types'], true ) ); ?>
                    />
                    <?php echo esc_html( $post_type->labels->singular_name ); ?>
                </label>
                <br>
            <?php endforeach; ?>
        </fieldset>
        <p class="description"><?php esc_html_e( 'Select which post types should have the JSON-LD generator available.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render skip schema field
     */
    public function render_skip_schema_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[skip_if_schema_exists]"
                value="1"
                <?php checked( $settings['skip_if_schema_exists'] ); ?>
            />
            <?php esc_html_e( 'Skip output if SEO plugin schema is detected', 'ai-jsonld-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Prevents duplicate schema when using Yoast SEO, RankMath, or similar plugins.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render auto regenerate field
     */
    public function render_auto_regenerate_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_regenerate_on_update]"
                value="1"
                <?php checked( $settings['auto_regenerate_on_update'] ); ?>
            />
            <?php esc_html_e( 'Automatically regenerate schema when content is updated', 'ai-jsonld-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Schema will be regenerated asynchronously when post content changes.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render delete data field
     */
    public function render_delete_data_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_data_on_uninstall]"
                value="1"
                <?php checked( $settings['delete_data_on_uninstall'] ); ?>
            />
            <?php esc_html_e( 'Delete all generated schemas on plugin uninstall', 'ai-jsonld-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Warning: This will permanently delete all generated JSON-LD data.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * Render debug field
     */
    public function render_debug_field() {
        $settings = AI_JSONLD_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_logging]"
                value="1"
                <?php checked( $settings['debug_logging'] ); ?>
            />
            <?php esc_html_e( 'Enable debug logging', 'ai-jsonld-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Logs debug information to the WordPress debug log. Never logs API keys or content.', 'ai-jsonld-generator' ); ?></p>
        <?php
    }

    /**
     * AJAX handler for test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'ai_jsonld_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-jsonld-generator' ) ) );
        }

        $settings = AI_JSONLD_Generator::get_settings();

        // Check if a new API key was provided
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $new_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( ! empty( $new_key ) ) {
            $settings['deepseek_api_key'] = $this->encryption->encrypt( $new_key );
        }

        if ( empty( $settings['deepseek_api_key'] ) ) {
            wp_send_json_error( array( 'message' => __( 'API key is required.', 'ai-jsonld-generator' ) ) );
        }

        $provider = $this->provider_registry->get_active( $settings );

        if ( ! $provider ) {
            wp_send_json_error( array( 'message' => __( 'Provider not found.', 'ai-jsonld-generator' ) ) );
        }

        $result = $provider->test_connection( $settings );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
    }
}
