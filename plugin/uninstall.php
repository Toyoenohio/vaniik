<?php
/**
 * Limpieza al eliminar el plugin.
 *
 * @package WP_WebP_Worker
 */

// Prevenir acceso directo.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Opciones del plugin.
delete_option( 'wpwebp_settings' );
delete_option( 'wpwebp_bulk_offset' );
delete_option( 'wpwebp_bulk_total' );
delete_option( 'wpwebp_db_version' );

// Cron de conversión en lote.
wp_clear_scheduled_hook( 'wpwebp_bulk_convert' );

// Tabla de registro de conversiones.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpwebp_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- limpieza de tabla propia en uninstall.

// Reglas .htaccess propias del plugin (si las escribimos).
if ( function_exists( 'insert_with_markers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	$uploads      = wp_upload_dir();
	$htaccess     = trailingslashit( $uploads['basedir'] ) . '.htaccess';
	insert_with_markers( $htaccess, 'WPWebpWorker', array() );
}
