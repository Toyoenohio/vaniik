<?php
/**
 * Conversión: un attachment, el lote por cron y las reglas .htaccess.
 *
 * @package WP_WebP_Worker
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWebp_Converter {

	const MIME_TYPES = array( 'image/jpeg', 'image/png' );
	const BATCH_SIZE = 20;
	const CRON_HOOK  = 'wpwebp_bulk_convert';

	/**
	 * Convierte un único attachment a WebP (junto al original, como archivo.jpg.webp).
	 *
	 * @param int $attachment_id ID del attachment.
	 * @return string 'ok' (convertido o ya existía) | 'skip' (no aplica) | 'error' (falló).
	 */
	public static function convert_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return 'skip';
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::MIME_TYPES, true ) ) {
			return 'skip';
		}

		$settings = WPWebp_Settings::get();
		if ( empty( $settings['endpoint'] ) ) {
			return 'skip';
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return 'skip';
		}

		$webp_path = $file . '.webp';

		// Si ya existe, no volvemos a llamar al Worker.
		if ( file_exists( $webp_path ) ) {
			return 'ok';
		}

		$body = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- archivo binario local, lectura directa intencional.
		if ( false === $body ) {
			return 'error';
		}

		$headers = array( 'Content-Type' => $mime );
		if ( ! empty( $settings['token'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $settings['token'];
		}

		$response = wp_remote_post(
			add_query_arg( array( 'quality' => absint( $settings['quality'] ) ), $settings['endpoint'] ),
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'error';
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'error';
		}

		$webp = wp_remote_retrieve_body( $response );
		if ( empty( $webp ) ) {
			return 'error';
		}

		$written = file_put_contents( $webp_path, $webp ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- archivo binario local, escritura directa intencional.

		if ( false === $written ) {
			return 'error';
		}

		WPWebp_Stats::log( $attachment_id, $file, $webp_path );

		return 'ok';
	}

	/**
	 * Cuenta los attachments convertibles (JPEG/PNG).
	 *
	 * @return int
	 */
	public static function count_attachments() {
		$count = wp_count_attachments();

		$total = 0;
		foreach ( self::MIME_TYPES as $mime ) {
			if ( isset( $count->{$mime} ) ) {
				$total += (int) $count->{$mime};
			}
		}

		return $total;
	}

	/**
	 * Inicia el lote: guarda el total, resetea contadores y programa la primera pasada.
	 */
	public static function start_bulk() {
		delete_option( 'wpwebp_bulk_offset' );
		update_option( 'wpwebp_bulk_total', self::count_attachments() );
		update_option( 'wpwebp_bulk_ok', 0 );
		update_option( 'wpwebp_bulk_failed', 0 );
		update_option( 'wpwebp_bulk_skipped', 0 );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Procesa un lote de BATCH_SIZE attachments y se reprograma si quedan.
	 * Acumula los contadores ok/failed/skipped entre lotes.
	 */
	public static function run_batch() {
		$offset  = (int) get_option( 'wpwebp_bulk_offset', 0 );
		$ok      = (int) get_option( 'wpwebp_bulk_ok', 0 );
		$failed  = (int) get_option( 'wpwebp_bulk_failed', 0 );
		$skipped = (int) get_option( 'wpwebp_bulk_skipped', 0 );

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => self::MIME_TYPES,
				'post_status'    => 'inherit',
				'posts_per_page' => self::BATCH_SIZE,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $ids ) ) {
			delete_option( 'wpwebp_bulk_offset' );
			delete_option( 'wpwebp_bulk_total' );
			return;
		}

		foreach ( $ids as $id ) {
			$status = self::convert_attachment( $id );

			if ( 'ok' === $status ) {
				$ok++;
			} elseif ( 'error' === $status ) {
				$failed++;
			} else {
				$skipped++;
			}
		}

		update_option( 'wpwebp_bulk_offset', $offset + count( $ids ) );
		update_option( 'wpwebp_bulk_ok', $ok );
		update_option( 'wpwebp_bulk_failed', $failed );
		update_option( 'wpwebp_bulk_skipped', $skipped );

		// Reprogramar el siguiente lote.
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
	}

	/**
	 * Estado actual del lote (offset/total/contadores) para la UI.
	 *
	 * @return array
	 */
	public static function bulk_status() {
		return array(
			'offset'  => (int) get_option( 'wpwebp_bulk_offset', 0 ),
			'total'   => (int) get_option( 'wpwebp_bulk_total', 0 ),
			'ok'      => (int) get_option( 'wpwebp_bulk_ok', 0 ),
			'failed'  => (int) get_option( 'wpwebp_bulk_failed', 0 ),
			'skipped' => (int) get_option( 'wpwebp_bulk_skipped', 0 ),
			'done'    => ! wp_next_scheduled( self::CRON_HOOK ),
		);
	}

	/**
	 * Detecta si el servidor web es Apache.
	 *
	 * @return bool
	 */
	public static function is_apache() {
		global $is_apache;
		if ( isset( $is_apache ) ) {
			return (bool) $is_apache;
		}

		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 'apache' );
		}

		return false;
	}

	/**
	 * Escribe (o regenera) las reglas .htaccess en la carpeta de uploads.
	 *
	 * @return bool True si se escribió; false si no es Apache o falló.
	 */
	public static function ensure_htaccess() {
		if ( ! self::is_apache() ) {
			return false;
		}

		$uploads  = wp_upload_dir();
		$htaccess = trailingslashit( $uploads['basedir'] ) . '.htaccess';

		if ( ! is_writable( trailingslashit( $uploads['basedir'] ) ) && ! file_exists( $htaccess ) ) {
			return false;
		}

		$rules = array(
			'# WP WebP Worker - servir .webp si existe y el navegador lo acepta',
			'<IfModule mod_rewrite.c>',
			'  RewriteEngine On',
			'  RewriteCond %{HTTP_ACCEPT} image/webp',
			'  RewriteCond %{REQUEST_FILENAME} (.*)\.(jpe?g|png)$',
			'  RewriteCond %1\.%2\.webp -f',
			'  RewriteRule (.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,E=accept:1,L]',
			'</IfModule>',
			'AddType image/webp .webp',
		);

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		return insert_with_markers( $htaccess, 'WPWebpWorker', $rules );
	}
}
