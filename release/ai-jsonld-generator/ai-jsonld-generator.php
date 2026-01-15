<?php
/**
 * Plugin Name: AI JSON-LD Generator
 * Plugin URI: https://example.com/ai-jsonld-generator
 * Description: Automatically generates schema.org JSON-LD structured data for WordPress pages using AI (DeepSeek).
 * Version: 1.0.0
 * Author: Debu Media
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-jsonld-generator
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'AI_JSONLD_VERSION', '1.0.0' );
define( 'AI_JSONLD_PLUGIN_FILE', __FILE__ );
define( 'AI_JSONLD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_JSONLD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_JSONLD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'AI_JSONLD_TEXT_DOMAIN', 'ai-jsonld-generator' );

/**
 * Main plugin class
 */
final class AI_JSONLD_Generator {

    /**
     * Plugin instance
     *
     * @var AI_JSONLD_Generator
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    private $encryption;
    private $provider_registry;
    private $content_processor;
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
     * @return AI_JSONLD_Generator
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
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-encryption.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-content-processor.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-schema-validator.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-prompt-builder.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-conflict-detector.php';

        // Provider system
        require_once AI_JSONLD_PLUGIN_DIR . 'providers/interface-provider.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'providers/class-abstract-provider.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'providers/class-provider-registry.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'providers/class-deepseek-provider.php';

        // Admin and frontend
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-admin.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-metabox.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once AI_JSONLD_PLUGIN_DIR . 'includes/class-schema-output.php';
    }

    /**
     * Initialize components
     */
    private function init_components() {
        // Core utilities (no dependencies)
        $this->encryption        = new AI_JSONLD_Encryption();
        $this->content_processor = new AI_JSONLD_Content_Processor();
        $this->schema_validator  = new AI_JSONLD_Schema_Validator();
        $this->conflict_detector = new AI_JSONLD_Conflict_Detector();

        // Provider system
        $this->provider_registry = new AI_JSONLD_Provider_Registry();
        $this->provider_registry->register( new AI_JSONLD_DeepSeek_Provider( $this->encryption ) );

        // Prompt builder (depends on content processor)
        $this->prompt_builder = new AI_JSONLD_Prompt_Builder( $this->content_processor );

        // Admin components
        $this->admin   = new AI_JSONLD_Admin( $this->encryption, $this->provider_registry );
        $this->metabox = new AI_JSONLD_Metabox( $this->content_processor );

        // AJAX handler (orchestrates everything)
        $this->ajax = new AI_JSONLD_Ajax(
            $this->content_processor,
            $this->prompt_builder,
            $this->provider_registry,
            $this->schema_validator,
            $this->encryption
        );

        // Frontend output
        $this->schema_output = new AI_JSONLD_Schema_Output( $this->conflict_detector );
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Load text domain
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Activation and deactivation
        register_activation_hook( AI_JSONLD_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( AI_JSONLD_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Schedule async regeneration
        add_action( 'ai_jsonld_regenerate', array( $this, 'handle_async_regenerate' ) );
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            AI_JSONLD_TEXT_DOMAIN,
            false,
            dirname( AI_JSONLD_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options if not exists
        if ( false === get_option( 'ai_jsonld_settings' ) ) {
            add_option( 'ai_jsonld_settings', $this->get_default_settings() );
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
             WHERE option_name LIKE '_transient_ai_jsonld_%'
             OR option_name LIKE '_transient_timeout_ai_jsonld_%'"
        );

        // Clear scheduled events
        wp_clear_scheduled_hook( 'ai_jsonld_regenerate' );
    }

    /**
     * Get default settings
     *
     * @return array
     */
    public function get_default_settings() {
        return array(
            'provider'                   => 'deepseek',
            'deepseek_api_key'           => '',
            'deepseek_model'             => 'deepseek-chat',
            'temperature'                => 0.2,
            'max_tokens'                 => 1200,
            'max_content_chars'          => 8000,
            'output_location'            => 'head',
            'enabled_post_types'         => array( 'page' ),
            'auto_regenerate_on_update'  => false,
            'skip_if_schema_exists'      => false,
            'delete_data_on_uninstall'   => false,
            'debug_logging'              => false,
            'settings_version'           => '1.0',
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
            'provider'                   => 'deepseek',
            'deepseek_api_key'           => '',
            'deepseek_model'             => 'deepseek-chat',
            'temperature'                => 0.2,
            'max_tokens'                 => 1200,
            'max_content_chars'          => 8000,
            'output_location'            => 'head',
            'enabled_post_types'         => array( 'page' ),
            'auto_regenerate_on_update'  => false,
            'skip_if_schema_exists'      => false,
            'delete_data_on_uninstall'   => false,
            'debug_logging'              => false,
            'settings_version'           => '1.0',
        );

        $settings = get_option( 'ai_jsonld_settings', array() );

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

        $prefix = '[AI JSON-LD]';

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
 * @return AI_JSONLD_Generator
 */
function ai_jsonld_generator() {
    return AI_JSONLD_Generator::get_instance();
}

// Initialize on plugins_loaded to ensure all dependencies are available
add_action( 'plugins_loaded', 'ai_jsonld_generator' );
