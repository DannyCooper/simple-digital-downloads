<?php
/**
 * Uninstall handler.
 *
 * Only removes options, transients, and cron hooks.
 * Orders, Downloads, and uploaded files are never deleted automatically.
 * Data removal is a manual action via the settings page.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	'sdd_stripe_secret_key',
	'sdd_stripe_webhook_secret',
	'sdd_stripe_webhook_endpoint_id',
	'sdd_upload_dir_key',
	'sdd_currency',
	'sdd_token_expiry_hours',
	'sdd_max_downloads',
	'sdd_delivery_page_id',
	'sdd_file_protection_ok',
	'sdd_file_protection_checked',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'sdd_protection_self_check' );
wp_clear_scheduled_hook( 'sdd_reconcile_sessions' );
