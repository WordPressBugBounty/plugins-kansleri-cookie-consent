<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Kansleri_Cookie_Consent
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
  exit;
}

/**
 * Remove the plugin's options, cron event and consent log table for the current site.
 */
function kncc_uninstall_site() {
  global $wpdb;

  delete_option('kncc_settings');
  delete_option('kncc_cookies');
  delete_option('kncc_consent_stats');
  delete_option('kncc_incomplete_cookies');
  delete_option('kncc_show_activation_notice');
  delete_option('kncc_defaults_localized');
  delete_option('kncc_cron_error');
  delete_option('kncc_blocked_scripts');
  delete_option('kncc_custom_blocked_scripts');
  delete_option('kncc_blocked_url_patterns');
  delete_option('kncc_blocked_categories_seen');
  delete_option('kncc_floating_btn_migrated');
  delete_option('kncc_block_html_scripts_migrated');
  delete_option('kncc_guard_cleanup');
  delete_option('kncc_guard_removed_cookies');
  delete_option('kncc_guard_kept_cookies');
  delete_option('kncc_db_version');

  delete_transient('kncc_frontend_html');

  // Remove per-language text and cookie options.
  $kncc_prefixes = array( 'kncc_texts_', 'kncc_cookies_', 'kncc_cookie_descriptions_' );
  foreach ( $kncc_prefixes as $kncc_prefix ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup on uninstall.
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( $kncc_prefix ) . '%'
      )
    );
  }

  wp_clear_scheduled_hook('kncc_daily_cookie_check');

  $kncc_table = $wpdb->prefix . 'kncc_consent_log';
  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, cannot be parameterized.
  $wpdb->query("DROP TABLE IF EXISTS {$kncc_table}");
}

// On multisite every site has its own options, log table and cron event.
if ( is_multisite() ) {
  foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $kncc_site_id ) {
    switch_to_blog( $kncc_site_id );
    kncc_uninstall_site();
    restore_current_blog();
  }
} else {
  kncc_uninstall_site();
}
