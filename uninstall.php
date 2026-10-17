<?php
/**
 * Uninstall handler. Runs only when the administrator enabled
 * "Delete all Ai Connect data on uninstall" in Settings.
 *
 * Drops both the current (ai_connect_*) and the pre-1.1.0 (sitewright_*)
 * table families, deletes both option families, cached token payloads,
 * transients, and sandbox files.
 *
 * @package AiConnect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ai_connect_settings = get_option( 'ai_connect_settings', null );
if ( null === $ai_connect_settings ) {
	// Fall back to the pre-1.1.0 option key when the new one was never written.
	$ai_connect_settings = get_option( 'sitewright_settings', null );
}
if ( ! is_array( $ai_connect_settings ) || empty( $ai_connect_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$ai_connect_tables = array(
	$wpdb->prefix . 'ai_connect_tokens',
	$wpdb->prefix . 'ai_connect_log',
	$wpdb->prefix . 'ai_connect_jobs',
	$wpdb->prefix . 'sitewright_tokens',
	$wpdb->prefix . 'sitewright_log',
);

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Fixed table names built from $wpdb->prefix.
foreach ( $ai_connect_tables as $ai_connect_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$ai_connect_table}" );
}
// phpcs:enable

delete_option( 'ai_connect_settings' );
delete_option( 'ai_connect_design' );
delete_option( 'ai_connect_db_version' );
delete_option( 'sitewright_settings' );
delete_option( 'sitewright_design' );
delete_option( 'sitewright_db_version' );
delete_transient( 'ai_connect_wpcli_status' );
delete_transient( 'sitewright_wpcli_status' );
delete_transient( 'ai_connect_last_token' );

// Cached new-token payloads, the prompt-fill token and auth-failure counters
// (both brandings).
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ai\_connect\_new\_token\_%'"
	. " OR option_name LIKE '\_transient\_timeout\_ai\_connect\_new\_token\_%'"
	. " OR option_name LIKE '\_transient\_ai\_connect\_auth\_fail\_%'"
	. " OR option_name LIKE '\_transient\_timeout\_ai\_connect\_auth\_fail\_%'"
	. " OR option_name LIKE '\_transient\_ai\_connect\_last\_token%'"
	. " OR option_name LIKE '\_transient\_timeout\_ai\_connect\_last\_token%'"
	. " OR option_name LIKE '\_transient\_sitewright\_new\_token\_%'"
	. " OR option_name LIKE '\_transient\_timeout\_sitewright\_new\_token\_%'"
);
// phpcs:enable

// Remove sandbox files (only files directly inside; the loader never creates subdirectories).
$ai_connect_sandbox = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) . '/sitewright-sandbox';
if ( is_dir( $ai_connect_sandbox ) ) {
	$ai_connect_entries = scandir( $ai_connect_sandbox );
	if ( is_array( $ai_connect_entries ) ) {
		foreach ( $ai_connect_entries as $ai_connect_entry ) {
			if ( '.' === $ai_connect_entry || '..' === $ai_connect_entry ) {
				continue;
			}
			$ai_connect_path = $ai_connect_sandbox . '/' . $ai_connect_entry;
			if ( is_file( $ai_connect_path ) ) {
				@unlink( $ai_connect_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}
	@rmdir( $ai_connect_sandbox ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
