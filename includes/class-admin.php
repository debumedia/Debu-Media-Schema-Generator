<?php
/**
 * Admin settings page class
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the admin settings page
 */
class WP_AI_Schema_Admin {

    /**
     * Option name
     */
    const OPTION_NAME = 'wp_ai_schema_settings';

    /**
     * Option group
     */
    const OPTION_GROUP = 'wp_ai_schema_settings_group';

    /**
     * Settings page slug
     */
    const PAGE_SLUG = 'wp-ai-seo-schema-generator';

    /**
     * Encryption handler
     *
     * @var WP_AI_Schema_Encryption
     */
    private $encryption;

    /**
     * Provider registry
     *
     * @var WP_AI_Schema_Provider_Registry
     */
    private $provider_registry;

    /**
     * Constructor
     *
     * @param WP_AI_Schema_Encryption        $encryption        Encryption handler.
     * @param WP_AI_Schema_Provider_Registry $provider_registry Provider registry.
     */
    public function __construct( WP_AI_Schema_Encryption $encryption, WP_AI_Schema_Provider_Registry $provider_registry ) {
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
        add_action( 'wp_ajax_wp_ai_schema_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    /**
     * Add menu page
     */
    public function add_menu_page() {
        add_options_page(
            __( 'AI JSON-LD Generator', 'wp-ai-seo-schema-generator' ),
            __( 'AI JSON-LD', 'wp-ai-seo-schema-generator' ),
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
                'default'           => WP_AI_Schema_Generator::get_instance()->get_default_settings(),
            )
        );

        // Provider section
        add_settings_section(
            'wp_ai_schema_provider_section',
            __( 'LLM Provider Settings', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_provider_section' ),
            self::PAGE_SLUG
        );

        $this->add_provider_fields();

        // Business Details section
        add_settings_section(
            'wp_ai_schema_business_section',
            __( 'Business Details', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_section' ),
            self::PAGE_SLUG
        );

        $this->add_business_fields();

        // Generation section
        add_settings_section(
            'wp_ai_schema_generation_section',
            __( 'Generation Settings', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_generation_section' ),
            self::PAGE_SLUG
        );

        $this->add_generation_fields();

        // Output section
        add_settings_section(
            'wp_ai_schema_output_section',
            __( 'Output Settings', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_output_section' ),
            self::PAGE_SLUG
        );

        $this->add_output_fields();

        // Advanced section
        add_settings_section(
            'wp_ai_schema_advanced_section',
            __( 'Advanced Settings', 'wp-ai-seo-schema-generator' ),
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
            __( 'Provider', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_provider_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_provider_section'
        );

        add_settings_field(
            'deepseek_api_key',
            __( 'DeepSeek API Key', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_api_key_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_provider_section'
        );

        add_settings_field(
            'deepseek_model',
            __( 'Model', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_model_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_provider_section'
        );
    }

    /**
     * Add generation settings fields
     */
    private function add_generation_fields() {
        add_settings_field(
            'temperature',
            __( 'Temperature', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_temperature_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_generation_section'
        );

        add_settings_field(
            'max_tokens',
            __( 'Max Tokens', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_max_tokens_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_generation_section'
        );

        add_settings_field(
            'max_content_chars',
            __( 'Max Content Characters', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_max_content_chars_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_generation_section'
        );
    }

    /**
     * Add output settings fields
     */
    private function add_output_fields() {
        add_settings_field(
            'output_location',
            __( 'Output Location', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_output_location_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_output_section'
        );

        add_settings_field(
            'enabled_post_types',
            __( 'Enabled Post Types', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_post_types_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_output_section'
        );

        add_settings_field(
            'skip_if_schema_exists',
            __( 'SEO Plugin Compatibility', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_skip_schema_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_output_section'
        );
    }

    /**
     * Add advanced settings fields
     */
    private function add_advanced_fields() {
        add_settings_field(
            'auto_regenerate_on_update',
            __( 'Auto Regenerate', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_auto_regenerate_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_advanced_section'
        );

        add_settings_field(
            'delete_data_on_uninstall',
            __( 'Data Cleanup', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_delete_data_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_advanced_section'
        );

        add_settings_field(
            'debug_logging',
            __( 'Debug Logging', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_debug_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_advanced_section'
        );
    }

    /**
     * Add business settings fields
     */
    private function add_business_fields() {
        add_settings_field(
            'business_name',
            __( 'Business Name', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_name_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );

        add_settings_field(
            'business_description',
            __( 'Business Description', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_description_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );

        add_settings_field(
            'business_logo',
            __( 'Logo URL', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_logo_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );

        add_settings_field(
            'business_email',
            __( 'Email', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_email_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );

        add_settings_field(
            'business_phone',
            __( 'Phone', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_phone_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );

        add_settings_field(
            'business_founding_date',
            __( 'Founding Date', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_founding_date_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );

        add_settings_field(
            'business_social_links',
            __( 'Social Links', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_social_links_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );

        add_settings_field(
            'business_locations',
            __( 'Locations', 'wp-ai-seo-schema-generator' ),
            array( $this, 'render_business_locations_field' ),
            self::PAGE_SLUG,
            'wp_ai_schema_business_section'
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Raw settings input.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( $input ) {
        $current  = WP_AI_Schema_Generator::get_settings();
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

        // Business details
        $sanitized['business_name']         = sanitize_text_field( $input['business_name'] ?? '' );
        $sanitized['business_description']  = sanitize_textarea_field( $input['business_description'] ?? '' );
        $sanitized['business_logo']         = esc_url_raw( $input['business_logo'] ?? '' );
        $sanitized['business_email']        = sanitize_email( $input['business_email'] ?? '' );
        $sanitized['business_phone']        = sanitize_text_field( $input['business_phone'] ?? '' );
        $sanitized['business_founding_date'] = sanitize_text_field( $input['business_founding_date'] ?? '' );

        // Social links
        $sanitized['business_social_links'] = array();
        if ( ! empty( $input['business_social_links'] ) && is_array( $input['business_social_links'] ) ) {
            foreach ( $input['business_social_links'] as $platform => $url ) {
                if ( ! empty( $url ) ) {
                    $sanitized['business_social_links'][ sanitize_key( $platform ) ] = esc_url_raw( $url );
                }
            }
        }

        // Business locations
        $sanitized['business_locations'] = array();
        if ( ! empty( $input['business_locations'] ) && is_array( $input['business_locations'] ) ) {
            foreach ( $input['business_locations'] as $index => $location ) {
                // Skip completely empty locations
                $has_data = ! empty( $location['name'] ) ||
                            ! empty( $location['street'] ) ||
                            ! empty( $location['city'] ) ||
                            ! empty( $location['phone'] ) ||
                            ! empty( $location['email'] );

                if ( ! $has_data ) {
                    continue;
                }

                $sanitized_location = array(
                    'name'        => sanitize_text_field( $location['name'] ?? '' ),
                    'street'      => sanitize_text_field( $location['street'] ?? '' ),
                    'city'        => sanitize_text_field( $location['city'] ?? '' ),
                    'state'       => sanitize_text_field( $location['state'] ?? '' ),
                    'postal_code' => sanitize_text_field( $location['postal_code'] ?? '' ),
                    'country'     => sanitize_text_field( $location['country'] ?? '' ),
                    'phone'       => sanitize_text_field( $location['phone'] ?? '' ),
                    'email'       => sanitize_email( $location['email'] ?? '' ),
                    'hours'       => array(),
                );

                // Sanitize opening hours
                $valid_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
                if ( ! empty( $location['hours'] ) && is_array( $location['hours'] ) ) {
                    foreach ( $valid_days as $day ) {
                        $sanitized_location['hours'][ $day ] = sanitize_text_field( $location['hours'][ $day ] ?? '' );
                    }
                } else {
                    foreach ( $valid_days as $day ) {
                        $sanitized_location['hours'][ $day ] = '';
                    }
                }

                $sanitized['business_locations'][] = $sanitized_location;
            }
        }

        // Settings version - bump when business settings change
        $sanitized['settings_version'] = '1.1';

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
            WP_AI_SCHEMA_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_AI_SCHEMA_VERSION
        );

        wp_enqueue_script(
            'ai-jsonld-admin',
            WP_AI_SCHEMA_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WP_AI_SCHEMA_VERSION,
            true
        );

        wp_localize_script(
            'ai-jsonld-admin',
            'aiJsonldAdmin',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'wp_ai_schema_test_connection' ),
                'i18n'     => array(
                    'testing'     => __( 'Testing...', 'wp-ai-seo-schema-generator' ),
                    'test'        => __( 'Test Connection', 'wp-ai-seo-schema-generator' ),
                    'success'     => __( 'Connection successful!', 'wp-ai-seo-schema-generator' ),
                    'error'       => __( 'Connection failed', 'wp-ai-seo-schema-generator' ),
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

        $settings = WP_AI_Schema_Generator::get_settings();

        // Show OpenSSL notice if needed
        $openssl_notice = $this->encryption->get_openssl_notice();
        if ( $openssl_notice ) {
            echo $openssl_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        // Show conflict notice
        $conflict_detector = wp_ai_schema_generator()->get_component( 'conflict_detector' );
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
        echo '<p>' . esc_html__( 'Configure your LLM provider for generating JSON-LD schemas.', 'wp-ai-seo-schema-generator' ) . '</p>';
    }

    /**
     * Render generation section
     */
    public function render_generation_section() {
        echo '<p>' . esc_html__( 'Adjust generation parameters for the AI model.', 'wp-ai-seo-schema-generator' ) . '</p>';
    }

    /**
     * Render output section
     */
    public function render_output_section() {
        echo '<p>' . esc_html__( 'Configure how and where the schema is output on your site.', 'wp-ai-seo-schema-generator' ) . '</p>';
    }

    /**
     * Render advanced section
     */
    public function render_advanced_section() {
        echo '<p>' . esc_html__( 'Advanced settings for automation and debugging.', 'wp-ai-seo-schema-generator' ) . '</p>';
    }

    /**
     * Render business section
     */
    public function render_business_section() {
        echo '<p>' . esc_html__( 'Provide your business details to ensure accurate schema generation. This information will be used by the AI to generate precise Organization, LocalBusiness, and ContactPoint schemas.', 'wp-ai-seo-schema-generator' ) . '</p>';
    }

    /**
     * Render business name field
     */
    public function render_business_name_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_name]"
            id="wp_ai_schema_business_name"
            value="<?php echo esc_attr( $settings['business_name'] ?? '' ); ?>"
            class="regular-text"
        />
        <p class="description"><?php esc_html_e( 'Your official business or organization name.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render business description field
     */
    public function render_business_description_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <textarea
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_description]"
            id="wp_ai_schema_business_description"
            rows="3"
            class="large-text"
        ><?php echo esc_textarea( $settings['business_description'] ?? '' ); ?></textarea>
        <p class="description"><?php esc_html_e( 'A brief description of your business or organization.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render business logo field
     */
    public function render_business_logo_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="url"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_logo]"
            id="wp_ai_schema_business_logo"
            value="<?php echo esc_url( $settings['business_logo'] ?? '' ); ?>"
            class="regular-text"
            placeholder="https://"
        />
        <p class="description"><?php esc_html_e( 'Full URL to your business logo image.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render business email field
     */
    public function render_business_email_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="email"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_email]"
            id="wp_ai_schema_business_email"
            value="<?php echo esc_attr( $settings['business_email'] ?? '' ); ?>"
            class="regular-text"
        />
        <p class="description"><?php esc_html_e( 'Primary contact email address.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render business phone field
     */
    public function render_business_phone_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="tel"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_phone]"
            id="wp_ai_schema_business_phone"
            value="<?php echo esc_attr( $settings['business_phone'] ?? '' ); ?>"
            class="regular-text"
            placeholder="+1-234-567-8900"
        />
        <p class="description"><?php esc_html_e( 'Primary contact phone number (include country code).', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render business founding date field
     */
    public function render_business_founding_date_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="date"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_founding_date]"
            id="wp_ai_schema_business_founding_date"
            value="<?php echo esc_attr( $settings['business_founding_date'] ?? '' ); ?>"
            class="regular-text"
        />
        <p class="description"><?php esc_html_e( 'When was your business founded?', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render business social links field
     */
    public function render_business_social_links_field() {
        $settings     = WP_AI_Schema_Generator::get_settings();
        $social_links = $settings['business_social_links'] ?? array();
        $platforms    = array(
            'facebook'  => __( 'Facebook', 'wp-ai-seo-schema-generator' ),
            'twitter'   => __( 'Twitter/X', 'wp-ai-seo-schema-generator' ),
            'linkedin'  => __( 'LinkedIn', 'wp-ai-seo-schema-generator' ),
            'instagram' => __( 'Instagram', 'wp-ai-seo-schema-generator' ),
            'youtube'   => __( 'YouTube', 'wp-ai-seo-schema-generator' ),
            'tiktok'    => __( 'TikTok', 'wp-ai-seo-schema-generator' ),
            'pinterest' => __( 'Pinterest', 'wp-ai-seo-schema-generator' ),
            'github'    => __( 'GitHub', 'wp-ai-seo-schema-generator' ),
        );
        ?>
        <div class="ai-jsonld-social-links">
            <?php foreach ( $platforms as $platform => $label ) : ?>
                <div class="ai-jsonld-social-link-row">
                    <label for="wp_ai_schema_social_<?php echo esc_attr( $platform ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <input
                        type="url"
                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_social_links][<?php echo esc_attr( $platform ); ?>]"
                        id="wp_ai_schema_social_<?php echo esc_attr( $platform ); ?>"
                        value="<?php echo esc_url( $social_links[ $platform ] ?? '' ); ?>"
                        class="regular-text"
                        placeholder="https://"
                    />
                </div>
            <?php endforeach; ?>
        </div>
        <p class="description"><?php esc_html_e( 'Add your social media profile URLs.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render business locations field
     */
    public function render_business_locations_field() {
        $settings  = WP_AI_Schema_Generator::get_settings();
        $locations = $settings['business_locations'] ?? array();

        // Ensure at least one empty location for the template
        if ( empty( $locations ) ) {
            $locations = array( $this->get_empty_location() );
        }

        $days = array(
            'monday'    => __( 'Monday', 'wp-ai-seo-schema-generator' ),
            'tuesday'   => __( 'Tuesday', 'wp-ai-seo-schema-generator' ),
            'wednesday' => __( 'Wednesday', 'wp-ai-seo-schema-generator' ),
            'thursday'  => __( 'Thursday', 'wp-ai-seo-schema-generator' ),
            'friday'    => __( 'Friday', 'wp-ai-seo-schema-generator' ),
            'saturday'  => __( 'Saturday', 'wp-ai-seo-schema-generator' ),
            'sunday'    => __( 'Sunday', 'wp-ai-seo-schema-generator' ),
        );
        ?>
        <div class="ai-jsonld-locations-wrapper">
            <div class="ai-jsonld-locations" id="ai-jsonld-locations">
                <?php foreach ( $locations as $index => $location ) : ?>
                    <div class="ai-jsonld-location" data-index="<?php echo esc_attr( $index ); ?>">
                        <div class="ai-jsonld-location-header">
                            <h4><?php esc_html_e( 'Location', 'wp-ai-seo-schema-generator' ); ?> <span class="location-number"><?php echo esc_html( $index + 1 ); ?></span></h4>
                            <button type="button" class="button ai-jsonld-remove-location" <?php echo count( $locations ) <= 1 ? 'style="display:none;"' : ''; ?>>
                                <?php esc_html_e( 'Remove', 'wp-ai-seo-schema-generator' ); ?>
                            </button>
                        </div>

                        <div class="ai-jsonld-location-fields">
                            <div class="ai-jsonld-field-row">
                                <label><?php esc_html_e( 'Location Name', 'wp-ai-seo-schema-generator' ); ?></label>
                                <input
                                    type="text"
                                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][name]"
                                    value="<?php echo esc_attr( $location['name'] ?? '' ); ?>"
                                    class="regular-text"
                                    placeholder="<?php esc_attr_e( 'e.g., Main Office, Downtown Branch', 'wp-ai-seo-schema-generator' ); ?>"
                                />
                            </div>

                            <div class="ai-jsonld-field-row">
                                <label><?php esc_html_e( 'Street Address', 'wp-ai-seo-schema-generator' ); ?></label>
                                <input
                                    type="text"
                                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][street]"
                                    value="<?php echo esc_attr( $location['street'] ?? '' ); ?>"
                                    class="regular-text"
                                />
                            </div>

                            <div class="ai-jsonld-field-row ai-jsonld-field-row-half">
                                <div>
                                    <label><?php esc_html_e( 'City', 'wp-ai-seo-schema-generator' ); ?></label>
                                    <input
                                        type="text"
                                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][city]"
                                        value="<?php echo esc_attr( $location['city'] ?? '' ); ?>"
                                        class="regular-text"
                                    />
                                </div>
                                <div>
                                    <label><?php esc_html_e( 'State/Province', 'wp-ai-seo-schema-generator' ); ?></label>
                                    <input
                                        type="text"
                                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][state]"
                                        value="<?php echo esc_attr( $location['state'] ?? '' ); ?>"
                                        class="regular-text"
                                    />
                                </div>
                            </div>

                            <div class="ai-jsonld-field-row ai-jsonld-field-row-half">
                                <div>
                                    <label><?php esc_html_e( 'Postal Code', 'wp-ai-seo-schema-generator' ); ?></label>
                                    <input
                                        type="text"
                                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][postal_code]"
                                        value="<?php echo esc_attr( $location['postal_code'] ?? '' ); ?>"
                                        class="regular-text"
                                    />
                                </div>
                                <div>
                                    <label><?php esc_html_e( 'Country', 'wp-ai-seo-schema-generator' ); ?></label>
                                    <input
                                        type="text"
                                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][country]"
                                        value="<?php echo esc_attr( $location['country'] ?? '' ); ?>"
                                        class="regular-text"
                                    />
                                </div>
                            </div>

                            <div class="ai-jsonld-field-row ai-jsonld-field-row-half">
                                <div>
                                    <label><?php esc_html_e( 'Phone', 'wp-ai-seo-schema-generator' ); ?></label>
                                    <input
                                        type="tel"
                                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][phone]"
                                        value="<?php echo esc_attr( $location['phone'] ?? '' ); ?>"
                                        class="regular-text"
                                    />
                                </div>
                                <div>
                                    <label><?php esc_html_e( 'Email', 'wp-ai-seo-schema-generator' ); ?></label>
                                    <input
                                        type="email"
                                        name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][email]"
                                        value="<?php echo esc_attr( $location['email'] ?? '' ); ?>"
                                        class="regular-text"
                                    />
                                </div>
                            </div>

                            <div class="ai-jsonld-field-row">
                                <label><?php esc_html_e( 'Opening Hours', 'wp-ai-seo-schema-generator' ); ?></label>
                                <div class="ai-jsonld-opening-hours">
                                    <?php foreach ( $days as $day_key => $day_label ) : ?>
                                        <div class="ai-jsonld-hours-row">
                                            <span class="day-label"><?php echo esc_html( $day_label ); ?></span>
                                            <input
                                                type="text"
                                                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[business_locations][<?php echo esc_attr( $index ); ?>][hours][<?php echo esc_attr( $day_key ); ?>]"
                                                value="<?php echo esc_attr( $location['hours'][ $day_key ] ?? '' ); ?>"
                                                class="small-text"
                                                placeholder="<?php esc_attr_e( '09:00-17:00 or Closed', 'wp-ai-seo-schema-generator' ); ?>"
                                            />
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button button-secondary" id="ai-jsonld-add-location">
                <?php esc_html_e( '+ Add Location', 'wp-ai-seo-schema-generator' ); ?>
            </button>
        </div>
        <p class="description"><?php esc_html_e( 'Add one or more business locations with address and opening hours.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Get empty location template
     *
     * @return array Empty location structure.
     */
    private function get_empty_location(): array {
        return array(
            'name'        => '',
            'street'      => '',
            'city'        => '',
            'state'       => '',
            'postal_code' => '',
            'country'     => '',
            'phone'       => '',
            'email'       => '',
            'hours'       => array(
                'monday'    => '',
                'tuesday'   => '',
                'wednesday' => '',
                'thursday'  => '',
                'friday'    => '',
                'saturday'  => '',
                'sunday'    => '',
            ),
        );
    }

    /**
     * Render provider field
     */
    public function render_provider_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        $options  = $this->provider_registry->get_options();
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[provider]" id="wp_ai_schema_provider">
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['provider'], $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Select the LLM provider to use for generating schemas.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $settings  = WP_AI_Schema_Generator::get_settings();
        $has_key   = ! empty( $settings['deepseek_api_key'] );
        $masked    = $has_key ? $this->encryption->mask_key( $this->encryption->decrypt( $settings['deepseek_api_key'] ) ) : '';
        ?>
        <div class="ai-jsonld-api-key-wrapper">
            <input
                type="password"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[deepseek_api_key]"
                id="wp_ai_schema_api_key"
                class="regular-text"
                placeholder="<?php echo $has_key ? esc_attr( $masked ) : esc_attr__( 'Enter your API key', 'wp-ai-seo-schema-generator' ); ?>"
                autocomplete="new-password"
            />
            <button type="button" id="wp_ai_schema_test_connection" class="button">
                <?php esc_html_e( 'Test Connection', 'wp-ai-seo-schema-generator' ); ?>
            </button>
            <span id="wp_ai_schema_connection_status"></span>
        </div>
        <?php if ( $has_key ) : ?>
            <p class="description">
                <?php esc_html_e( 'Current key:', 'wp-ai-seo-schema-generator' ); ?> <code><?php echo esc_html( $masked ); ?></code>
                <?php esc_html_e( 'Leave blank to keep the current key.', 'wp-ai-seo-schema-generator' ); ?>
            </p>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'Enter your DeepSeek API key.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render model field
     */
    public function render_model_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[deepseek_model]"
            id="wp_ai_schema_model"
            value="<?php echo esc_attr( $settings['deepseek_model'] ); ?>"
            class="regular-text"
        />
        <p class="description"><?php esc_html_e( 'The model to use (default: deepseek-chat).', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render temperature field
     */
    public function render_temperature_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[temperature]"
            id="wp_ai_schema_temperature"
            value="<?php echo esc_attr( $settings['temperature'] ); ?>"
            min="0"
            max="1"
            step="0.1"
            class="small-text"
        />
        <p class="description"><?php esc_html_e( 'Controls randomness (0-1). Lower values are more deterministic.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render max tokens field
     */
    public function render_max_tokens_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_tokens]"
            id="wp_ai_schema_max_tokens"
            value="<?php echo esc_attr( $settings['max_tokens'] ); ?>"
            min="100"
            max="4000"
            class="small-text"
        />
        <p class="description"><?php esc_html_e( 'Maximum tokens in the response (default: 1200).', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render max content chars field
     */
    public function render_max_content_chars_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_content_chars]"
            id="wp_ai_schema_max_content_chars"
            value="<?php echo esc_attr( $settings['max_content_chars'] ); ?>"
            min="1000"
            max="32000"
            class="small-text"
        />
        <p class="description"><?php esc_html_e( 'Maximum content characters to send to the API (default: 8000).', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render output location field
     */
    public function render_output_location_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <fieldset>
            <label>
                <input
                    type="radio"
                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[output_location]"
                    value="head"
                    <?php checked( $settings['output_location'], 'head' ); ?>
                />
                <?php esc_html_e( 'In <head> (Recommended)', 'wp-ai-seo-schema-generator' ); ?>
            </label>
            <br>
            <label>
                <input
                    type="radio"
                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[output_location]"
                    value="after_content"
                    <?php checked( $settings['output_location'], 'after_content' ); ?>
                />
                <?php esc_html_e( 'After content', 'wp-ai-seo-schema-generator' ); ?>
            </label>
        </fieldset>
        <p class="description"><?php esc_html_e( 'Where to output the JSON-LD script tag.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render post types field
     */
    public function render_post_types_field() {
        $settings   = WP_AI_Schema_Generator::get_settings();
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
        <p class="description"><?php esc_html_e( 'Select which post types should have the JSON-LD generator available.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render skip schema field
     */
    public function render_skip_schema_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[skip_if_schema_exists]"
                value="1"
                <?php checked( $settings['skip_if_schema_exists'] ); ?>
            />
            <?php esc_html_e( 'Skip output if SEO plugin schema is detected', 'wp-ai-seo-schema-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Prevents duplicate schema when using Yoast SEO, RankMath, or similar plugins.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render auto regenerate field
     */
    public function render_auto_regenerate_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_regenerate_on_update]"
                value="1"
                <?php checked( $settings['auto_regenerate_on_update'] ); ?>
            />
            <?php esc_html_e( 'Automatically regenerate schema when content is updated', 'wp-ai-seo-schema-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Schema will be regenerated asynchronously when post content changes.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render delete data field
     */
    public function render_delete_data_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_data_on_uninstall]"
                value="1"
                <?php checked( $settings['delete_data_on_uninstall'] ); ?>
            />
            <?php esc_html_e( 'Delete all generated schemas on plugin uninstall', 'wp-ai-seo-schema-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Warning: This will permanently delete all generated JSON-LD data.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * Render debug field
     */
    public function render_debug_field() {
        $settings = WP_AI_Schema_Generator::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_logging]"
                value="1"
                <?php checked( $settings['debug_logging'] ); ?>
            />
            <?php esc_html_e( 'Enable debug logging', 'wp-ai-seo-schema-generator' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Logs debug information to the WordPress debug log. Never logs API keys or content.', 'wp-ai-seo-schema-generator' ); ?></p>
        <?php
    }

    /**
     * AJAX handler for test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'wp_ai_schema_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-seo-schema-generator' ) ) );
        }

        $settings = WP_AI_Schema_Generator::get_settings();

        // Check if a new API key was provided
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $new_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( ! empty( $new_key ) ) {
            $settings['deepseek_api_key'] = $this->encryption->encrypt( $new_key );
        }

        if ( empty( $settings['deepseek_api_key'] ) ) {
            wp_send_json_error( array( 'message' => __( 'API key is required.', 'wp-ai-seo-schema-generator' ) ) );
        }

        $provider = $this->provider_registry->get_active( $settings );

        if ( ! $provider ) {
            wp_send_json_error( array( 'message' => __( 'Provider not found.', 'wp-ai-seo-schema-generator' ) ) );
        }

        $result = $provider->test_connection( $settings );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
    }
}
