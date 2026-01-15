<?php
/**
 * AI JSON-LD Generator Uninstall
 *
 * This file runs when the plugin is uninstalled (deleted) from WordPress.
 * It cleans up all plugin data based on user settings.
 *
 * @package AI_JSONLD_Generator
 */

// Exit if not called by WordPress uninstall process
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up plugin data for a single site
 */
function ai_jsonld_cleanup_site() {
    global $wpdb;

    // Get settings to check if we should delete post meta
    $settings    = get_option( 'ai_jsonld_settings', array() );
    $delete_data = isset( $settings['delete_data_on_uninstall'] ) ? $settings['delete_data_on_uninstall'] : false;

    // Always delete plugin options
    delete_option( 'ai_jsonld_settings' );

    // Delete transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_ai_jsonld_%'
         OR option_name LIKE '_transient_timeout_ai_jsonld_%'"
    );

    // Conditionally delete post meta
    if ( $delete_data ) {
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key IN (
                 '_ai_jsonld_schema',
                 '_ai_jsonld_schema_last_generated',
                 '_ai_jsonld_schema_status',
                 '_ai_jsonld_schema_error',
                 '_ai_jsonld_schema_hash',
                 '_ai_jsonld_type_hint',
                 '_ai_jsonld_detected_type'
             )"
        );
    }

    // Clear scheduled events
    wp_clear_scheduled_hook( 'ai_jsonld_regenerate' );
}

/**
 * Main uninstall routine
 */
function ai_jsonld_uninstall() {
    // Check if multisite
    if ( is_multisite() ) {
        // Get all sites
        $sites = get_sites( array( 'fields' => 'ids' ) );

        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            ai_jsonld_cleanup_site();
            restore_current_blog();
        }
    } else {
        // Single site
        ai_jsonld_cleanup_site();
    }
}

// Run uninstall
ai_jsonld_uninstall();
