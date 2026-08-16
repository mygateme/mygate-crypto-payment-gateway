<?php
/**
 * MyGate intentionally retains merchant settings on uninstall so an accidental
 * removal does not destroy the connection configuration.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'mgcpg_poll_payment' );
