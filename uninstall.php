<?php
/**
 * WP AI SEO Schema Generator Uninstall
 *
 * This file runs when the plugin is uninstalled (deleted) from WordPress.
 * It cleans up all plugin data based on user settings.
 *
 * @package WP_AI_Schema_Generator
 */

// Exit if not called by WordPress uninstall process
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up plugin data for a single site
 */
function wp_ai_schema_cleanup_site() {
    global $wpdb;

    // Get settings to check if we should delete post meta
    $settings    = get_option( 'wp_ai_schema_settings', array() );
    $delete_data = isset( $settings['delete_data_on_uninstall'] ) ? $settings['delete_data_on_uninstall'] : false;

    // Always delete plugin options
    delete_option( 'wp_ai_schema_settings' );

    // Delete transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_wp_ai_schema_%'
         OR option_name LIKE '_transient_timeout_wp_ai_schema_%'"
    );

    // Conditionally delete post meta
    if ( $delete_data ) {
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key IN (
                 '_wp_ai_schema_data',
                 '_wp_ai_schema_last_generated',
                 '_wp_ai_schema_status',
                 '_wp_ai_schema_error',
                 '_wp_ai_schema_hash',
                 '_wp_ai_schema_type_hint',
                 '_wp_ai_schema_detected_type'
             )"
        );
    }

    // Clear scheduled events
    wp_clear_scheduled_hook( 'wp_ai_schema_regenerate' );
}

/**
 * Main uninstall routine
 */
function wp_ai_schema_uninstall() {
    // Check if multisite
    if ( is_multisite() ) {
        // Get all sites
        $sites = get_sites( array( 'fields' => 'ids' ) );

        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            wp_ai_schema_cleanup_site();
            restore_current_blog();
        }
    } else {
        // Single site
        wp_ai_schema_cleanup_site();
    }
}

// Run uninstall
wp_ai_schema_uninstall();
