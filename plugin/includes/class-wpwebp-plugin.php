<?php
/**
 * Clase principal: cablea hooks, menú admin, AJAX y cron.
 *
 * @package WP_WebP_Worker
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWebp_Plugin {

	/**
	 * Inicializa el plugin.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( 'WPWebp_Settings', 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Conversión automática al subir una imagen.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 10, 2 );

		// Cron del lote.
		add_action( WPWebp_Converter::CRON_HOOK, array( 'WPWebp_Converter', 'run_batch' ) );

		// AJAX (solo admin).
		add_action( 'wp_ajax_wpwebp_bulk_start', array( __CLASS__, 'ajax_bulk_start' ) );
		add_action( 'wp_ajax_wpwebp_bulk_status', array( __CLASS__, 'ajax_bulk_status' ) );
		add_action( 'wp_ajax_wpwebp_bulk_htaccess', array( __CLASS__, 'ajax_bulk_htaccess' ) );
	}

	/**
	 * Activación: escribe .htaccess y no programa nada más (el lote es bajo demanda).
	 */
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! get_option( 'wpwebp_settings' ) ) {
			add_option( 'wpwebp_settings', WPWebp_Settings::get_defaults() );
		}

		WPWebp_Stats::create_table();
		WPWebp_Converter::ensure_htaccess();
	}

	/**
	 * Desactivación: limpia el cron y los marcadores de lote.
	 */
	public static function deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		wp_clear_scheduled_hook( WPWebp_Converter::CRON_HOOK );
		delete_option( 'wpwebp_bulk_offset' );
		delete_option( 'wpwebp_bulk_total' );
		delete_option( 'wpwebp_bulk_ok' );
		delete_option( 'wpwebp_bulk_failed' );
		delete_option( 'wpwebp_bulk_skipped' );
	}

	/**
	 * Menú de administración.
	 */
	public static function add_menu() {
		add_menu_page(
			__( 'WP WebP Worker', 'wp-webp-worker' ),
			__( 'WebP Worker', 'wp-webp-worker' ),
			'manage_options',
			WPWebp_Settings::PAGE_SLUG,
			array( 'WPWebp_Settings', 'render_page' ),
			'dashicons-format-image',
			80
		);

		add_submenu_page(
			WPWebp_Settings::PAGE_SLUG,
			__( 'Estadísticas', 'wp-webp-worker' ),
			__( 'Estadísticas', 'wp-webp-worker' ),
			'manage_options',
			'wpwebp-stats',
			array( 'WPWebp_Stats', 'render_page' )
		);
	}

	/**
	 * Carga assets solo en nuestra página de ajustes.
	 *
	 * @param string $hook Hook de la página admin actual.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_' . WPWebp_Settings::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wpwebp-admin',
			WPWEBP_URL . 'assets/js/admin.js',
			array(),
			WPWEBP_VERSION,
			true
		);

		wp_localize_script(
			'wpwebp-admin',
			'wpwebpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpwebp_bulk' ),
			)
		);

		wp_enqueue_style(
			'wpwebp-admin',
			WPWEBP_URL . 'assets/css/admin.css',
			array(),
			WPWEBP_VERSION
		);
	}

	/**
	 * Conversión automática al subir (si está activada).
	 *
	 * @param array $metadata      Metadatos generados.
	 * @param int   $attachment_id ID del attachment.
	 * @return array
	 */
	public static function on_upload( $metadata, $attachment_id ) {
		$settings = WPWebp_Settings::get();
		if ( ! empty( $settings['on_upload'] ) ) {
			WPWebp_Converter::convert_attachment( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * AJAX: inicia el lote.
	 */
	public static function ajax_bulk_start() {
		check_ajax_referer( 'wpwebp_bulk', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'wp-webp-worker' ) ), 403 );
		}

		WPWebp_Converter::start_bulk();

		wp_send_json_success( WPWebp_Converter::bulk_status() );
	}

	/**
	 * AJAX: estado del lote.
	 */
	public static function ajax_bulk_status() {
		check_ajax_referer( 'wpwebp_bulk', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'wp-webp-worker' ) ), 403 );
		}

		wp_send_json_success( WPWebp_Converter::bulk_status() );
	}

	/**
	 * AJAX: regenera las reglas .htaccess.
	 */
	public static function ajax_bulk_htaccess() {
		check_ajax_referer( 'wpwebp_bulk', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'wp-webp-worker' ) ), 403 );
		}

		$ok = WPWebp_Converter::ensure_htaccess();

		if ( $ok ) {
			wp_send_json_success( array( 'message' => __( 'Reglas .htaccess escritas en la carpeta de uploads.', 'wp-webp-worker' ) ) );
		}

		wp_send_json_error(
			array(
				'message' => WPWebp_Converter::is_apache()
					? __( 'No se pudieron escribir las reglas .htaccess.', 'wp-webp-worker' )
					: __( 'Tu servidor no es Apache. Configura la redirección WebP manualmente (Nginx/LiteSpeed).', 'wp-webp-worker' ),
			),
			500
		);
	}
}
