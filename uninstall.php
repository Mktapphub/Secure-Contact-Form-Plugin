<?php
/**
 * Uninstall handler.
 *
 * WordPress only executes this file when the plugin is deleted from
 * the Plugins screen (never on simple deactivation), and only when
 * accessed via the WP_UNINSTALL_PLUGIN constant check below -- this
 * guards against the file being called directly.
 *
 * Data removal is opt-in via the `scf_settings['delete_data_on_uninstall']`
 * flag so that uninstalling the plugin never silently destroys a site
 * owner's collected leads without explicit consent.
 *
 * @package SecureContactForm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'scf_settings', array() );

$should_delete_data = ! empty( $settings['delete_data_on_uninstall'] );

if ( $should_delete_data ) {
	global $wpdb;

	$table = $wpdb->prefix . 'scf_submissions';

	// Table name is not user input (hardcoded plugin constant), so
	// direct interpolation here is safe and there is no parameterized
	// equivalent for DROP TABLE statements.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	delete_option( 'scf_settings' );
	delete_option( 'scf_db_version' );
}

// Always clean up transient rate-limit records regardless of the
// data-retention preference above -- these are ephemeral abuse
// counters, not user-submitted content, so there's no reason to keep
// them after the plugin is removed.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_scf_rate_' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
