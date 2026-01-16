<?php
/**
 * Plugin Name: WP AI SEO Schema Generator
 * Plugin URI: https://debumedia.com/wp-ai-seo-schema-generator
 * Description: Automatically generates schema.org JSON-LD structured data for WordPress pages using AI (DeepSeek, OpenAI).
 * Version: 1.6.3
 * Author: Debu Media
 * Author URI: https://debumedia.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-seo-schema-generator
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WP_AI_SCHEMA_VERSION', '1.6.3' );
define( 'WP_AI_SCHEMA_PLUGIN_FILE', __FILE__ );
define( 'WP_AI_SCHEMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_SCHEMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_AI_SCHEMA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP_AI_SCHEMA_TEXT_DOMAIN', 'wp-ai-seo-schema-generator' );

/**
 * Main plugin class
 *
 * =============================================================================
 * GENERATION LIMITS - Each model defines its own limits
 * =============================================================================
 *
 * Each provider defines a MODELS constant array where each model specifies:
 *   - max_tokens: How many output tokens to request
 *   - max_content_chars: Maximum page content characters to send
 *
 * To change limits, edit the MODELS array in the provider class:
 *
 * - DeepSeek: providers/class-deepseek-provider.php
 *   Look for: const MODELS = array(...)
 *
 * - OpenAI: providers/class-openai-provider.php
 *   Look for: const MODELS = array(...)
 *
 * Example model config:
 *   'model-name' => array(
 *       'name'              => 'Display Name',
 *       'context_window'    => 128000,   // Total context (input + output)
 *       'max_output'        => 8192,     // Model's maximum output tokens
 *       'max_tokens'        => 8000,     // Our requested output (edit this)
 *       'max_content_chars' => 50000,    // Content limit (edit this)
 *   ),
 */
final class WP_AI_Schema_Generator {

    /**
     * Plugin instance
     *
     * @var WP_AI_Schema_Generator
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    private $encryption;
    private $provider_registry;
    private $content_processor;
    private $content_analyzer;
    private $schema_validator;
    private $prompt_builder;
    private $conflict_detector;
    private $admin;
    private $metabox;
    private $ajax;
    private $schema_output;

    /**
     * Get plugin instance
     *
     * @return WP_AI_Schema_Generator
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core utilities
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-encryption.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-content-processor.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-content-analyzer.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-schema-validator.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-schema-reference.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-prompt-builder.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-conflict-detector.php';

        // Provider system
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'providers/interface-provider.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'providers/class-abstract-provider.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'providers/class-provider-registry.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'providers/class-deepseek-provider.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'providers/class-openai-provider.php';

        // Admin and frontend
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-admin.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-metabox.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once WP_AI_SCHEMA_PLUGIN_DIR . 'includes/class-schema-output.php';
    }

    /**
     * Initialize components
     */
    private function init_components() {
        // Core utilities (no dependencies)
        $this->encryption        = new WP_AI_Schema_Encryption();
        $this->content_processor = new WP_AI_Schema_Content_Processor();
        $this->schema_validator  = new WP_AI_Schema_Validator();
        $this->conflict_detector = new WP_AI_Schema_Conflict_Detector();

        // Provider system
        $this->provider_registry = new WP_AI_Schema_Provider_Registry();
        $this->provider_registry->register( new WP_AI_Schema_DeepSeek_Provider( $this->encryption ) );
        $this->provider_registry->register( new WP_AI_Schema_OpenAI_Provider( $this->encryption ) );

        // Prompt builder (depends on content processor)
        $this->prompt_builder = new WP_AI_Schema_Prompt_Builder( $this->content_processor );

        // Admin components
        $this->admin   = new WP_AI_Schema_Admin( $this->encryption, $this->provider_registry );
        $this->metabox = new WP_AI_Schema_Metabox( $this->content_processor );

        // Content analyzer for two-pass generation
        $this->content_analyzer = new WP_AI_Schema_Content_Analyzer(
            $this->provider_registry,
            $this->content_processor
        );

        // AJAX handler (orchestrates everything)
        $this->ajax = new WP_AI_Schema_Ajax(
            $this->content_processor,
            $this->prompt_builder,
            $this->provider_registry,
            $this->schema_validator,
            $this->encryption
        );

        // Wire content analyzer into AJAX handler for two-pass support
        $this->ajax->set_content_analyzer( $this->content_analyzer );

        // Frontend output
        $this->schema_output = new WP_AI_Schema_Output( $this->conflict_detector );
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Load text domain
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Activation and deactivation
        register_activation_hook( WP_AI_SCHEMA_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WP_AI_SCHEMA_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Schedule async regeneration
        add_action( 'wp_ai_schema_regenerate', array( $this, 'handle_async_regenerate' ) );
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            WP_AI_SCHEMA_TEXT_DOMAIN,
            false,
            dirname( WP_AI_SCHEMA_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options if not exists
        if ( false === get_option( 'wp_ai_schema_settings' ) ) {
            add_option( 'wp_ai_schema_settings', $this->get_default_settings() );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        global $wpdb;

        // Clear transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_wp_ai_schema_%'
             OR option_name LIKE '_transient_timeout_wp_ai_schema_%'"
        );

        // Clear scheduled events
        wp_clear_scheduled_hook( 'wp_ai_schema_regenerate' );
    }

    /**
     * Get default settings
     *
     * @return array
     */
    public function get_default_settings() {
        return array(
            // Provider settings
            'provider'                   => 'deepseek',
            'deepseek_api_key'           => '',
            'deepseek_model'             => 'deepseek-chat',
            'openai_api_key'             => '',
            'openai_model'               => 'gpt-5-nano',

            // Generation settings (max_tokens and max_content_chars are constants - see top of file)
            'temperature'                => 0.2,

            // Output settings
            'output_location'            => 'head',
            'enabled_post_types'         => array( 'page' ),

            // Business details
            'business_name'              => '',
            'business_description'       => '',
            'business_logo'              => '',
            'business_email'             => '',
            'business_phone'             => '',
            'business_founding_date'     => '',
            'business_social_links'      => array(),
            'business_locations'         => array(),

            // Behavior settings
            'auto_regenerate_on_update'  => false,
            'skip_if_schema_exists'      => false,
            'delete_data_on_uninstall'   => false,
            'debug_logging'              => false,

            // Internal
            'settings_version'           => '1.1',
        );
    }

    /**
     * Handle async regeneration
     *
     * @param int $post_id Post ID to regenerate schema for.
     */
    public function handle_async_regenerate( $post_id ) {
        if ( ! $post_id ) {
            return;
        }

        $this->ajax->generate_schema( $post_id, true );
    }

    /**
     * Get component instance
     *
     * @param string $component Component name.
     * @return object|null
     */
    public function get_component( $component ) {
        if ( isset( $this->$component ) ) {
            return $this->$component;
        }
        return null;
    }

    /**
     * Get plugin settings
     *
     * @return array
     */
    public static function get_settings() {
        $defaults = array(
            // Provider settings
            'provider'                   => 'deepseek',
            'deepseek_api_key'           => '',
            'deepseek_model'             => 'deepseek-chat',
            'openai_api_key'             => '',
            'openai_model'               => 'gpt-5-nano',

            // Generation settings (max_tokens and max_content_chars are constants - see top of file)
            'temperature'                => 0.2,

            // Output settings
            'output_location'            => 'head',
            'enabled_post_types'         => array( 'page' ),

            // Business details
            'business_name'              => '',
            'business_description'       => '',
            'business_logo'              => '',
            'business_email'             => '',
            'business_phone'             => '',
            'business_founding_date'     => '',
            'business_social_links'      => array(),
            'business_locations'         => array(),

            // Behavior settings
            'auto_regenerate_on_update'  => false,
            'skip_if_schema_exists'      => false,
            'delete_data_on_uninstall'   => false,
            'debug_logging'              => false,

            // Internal
            'settings_version'           => '1.1',
        );

        $settings = get_option( 'wp_ai_schema_settings', array() );

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Debug log helper
     *
     * @param string $message Message to log.
     * @param string $level   Log level (info, warning, error).
     */
    public static function log( $message, $level = 'info' ) {
        $settings = self::get_settings();

        if ( empty( $settings['debug_logging'] ) ) {
            return;
        }

        $prefix = '[WP AI Schema]';

        switch ( $level ) {
            case 'error':
                $prefix .= ' ERROR:';
                break;
            case 'warning':
                $prefix .= ' WARNING:';
                break;
            default:
                $prefix .= ' INFO:';
        }

        error_log( $prefix . ' ' . $message );
    }
}

/**
 * Initialize the plugin
 *
 * @return WP_AI_Schema_Generator
 */
function wp_ai_schema_generator() {
    return WP_AI_Schema_Generator::get_instance();
}

// Initialize on plugins_loaded to ensure all dependencies are available
add_action( 'plugins_loaded', 'wp_ai_schema_generator' );
