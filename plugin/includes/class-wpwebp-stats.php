<?php
/**
 * Registro de conversiones (tabla propia) + pantalla de estadísticas.
 *
 * @package WP_WebP_Worker
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWebp_Stats {

	const TABLE    = 'wpwebp_log';
	const PER_PAGE = 20;

	/**
	 * Nombre completo de la tabla (con prefijo de WordPress).
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Crea la tabla con dbDelta (segura ante CREATE/ALTER).
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table            = self::table_name();
		$charset_collate  = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			file_name varchar(255) NOT NULL DEFAULT '',
			original_size bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			webp_size bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			saved_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			converted_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY converted_at (converted_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Registra (o actualiza) el estado de un attachment convertido.
	 * Upsert: una fila por imagen; al reconvertir se actualiza.
	 *
	 * @param int    $attachment_id ID del attachment.
	 * @param string $file          Ruta del original.
	 * @param string $webp_path     Ruta del .webp generado.
	 */
	public static function log( $attachment_id, $file, $webp_path ) {
		global $wpdb;

		if ( ! file_exists( $file ) || ! file_exists( $webp_path ) ) {
			return;
		}

		$original_size = (int) filesize( $file );
		$webp_size     = (int) filesize( $webp_path );

		if ( ! $original_size || ! $webp_size ) {
			return;
		}

		$saved = max( 0, $original_size - $webp_size );

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tabla propia del plugin.
			self::table_name(),
			array(
				'attachment_id' => absint( $attachment_id ),
				'file_name'     => basename( $file ),
				'original_size' => $original_size,
				'webp_size'     => $webp_size,
				'saved_bytes'   => $saved,
				'converted_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Resumen agregado (totales).
	 *
	 * @return object|null
	 */
	public static function get_summary() {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- agregado sobre tabla propia.
			"SELECT COUNT(*) AS total,
			        COALESCE(SUM(original_size),0) AS original,
			        COALESCE(SUM(webp_size),0) AS webp,
			        COALESCE(SUM(saved_bytes),0) AS saved
			 FROM {$table}"
		);
	}

	/**
	 * Total de filas (para paginación).
	 *
	 * @return int
	 */
	public static function get_total_entries() {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tabla propia.
			'SELECT COUNT(*) FROM ' . self::table_name()
		);
	}

	/**
	 * Entradas de una página, más recientes primero.
	 *
	 * @param int $page     Número de página (1-based).
	 * @param int $per_page Entradas por página.
	 * @return array
	 */
	public static function get_entries( $page, $per_page ) {
		global $wpdb;

		$table  = self::table_name();
		$offset = ( $page - 1 ) * $per_page;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tabla propia.
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Formatea bytes a unidad legible.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = (int) $bytes;

		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 2 ) . ' MB';
		}

		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}

		return $bytes . ' B';
	}

	/**
	 * Renderiza la pantalla de estadísticas.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'wp-webp-worker' ) );
		}

		$summary       = self::get_summary();
		$total_entries = self::get_total_entries();
		$page          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification -- solo lectura de página, sin acción de estado.
		$entries       = self::get_entries( $page, self::PER_PAGE );
		$total_pages   = max( 1, (int) ceil( $total_entries / self::PER_PAGE ) );

		$saved_pct = $summary && $summary->original > 0
			? round( ( $summary->saved / $summary->original ) * 100, 1 )
			: 0;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="wpwebp-summary">
				<div class="wpwebp-summary-card">
					<span class="wpwebp-summary-label"><?php esc_html_e( 'Imágenes convertidas', 'wp-webp-worker' ); ?></span>
					<span class="wpwebp-summary-value"><?php echo esc_html( $summary ? (int) $summary->total : 0 ); ?></span>
				</div>
				<div class="wpwebp-summary-card">
					<span class="wpwebp-summary-label"><?php esc_html_e( 'Peso original', 'wp-webp-worker' ); ?></span>
					<span class="wpwebp-summary-value"><?php echo esc_html( self::format_bytes( $summary ? $summary->original : 0 ) ); ?></span>
				</div>
				<div class="wpwebp-summary-card">
					<span class="wpwebp-summary-label"><?php esc_html_e( 'Peso WebP', 'wp-webp-worker' ); ?></span>
					<span class="wpwebp-summary-value"><?php echo esc_html( self::format_bytes( $summary ? $summary->webp : 0 ) ); ?></span>
				</div>
				<div class="wpwebp-summary-card">
					<span class="wpwebp-summary-label"><?php esc_html_e( 'Ahorro', 'wp-webp-worker' ); ?></span>
					<span class="wpwebp-summary-value wpwebp-summary-saved">
						<?php echo esc_html( self::format_bytes( $summary ? $summary->saved : 0 ) ); ?>
						(<?php echo esc_html( $saved_pct ); ?>%)
					</span>
				</div>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'Aún no hay conversiones registradas. Convierte la librería o sube una imagen.', 'wp-webp-worker' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Imagen', 'wp-webp-worker' ); ?></th>
							<th><?php esc_html_e( 'Original', 'wp-webp-worker' ); ?></th>
							<th><?php esc_html_e( 'WebP', 'wp-webp-worker' ); ?></th>
							<th><?php esc_html_e( 'Ahorro', 'wp-webp-worker' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'wp-webp-worker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php
							$row_pct = $entry->original_size > 0
								? round( ( $entry->saved_bytes / $entry->original_size ) * 100, 1 )
								: 0;
							?>
							<tr>
								<td>
									<?php echo wp_kses_post( wp_get_attachment_image( $entry->attachment_id, array( 40, 40 ), false, array( 'style' => 'vertical-align:middle;margin-right:8px;' ) ) ); ?>
									<strong><?php echo esc_html( $entry->file_name ); ?></strong>
								</td>
								<td><?php echo esc_html( self::format_bytes( $entry->original_size ) ); ?></td>
								<td><?php echo esc_html( self::format_bytes( $entry->webp_size ) ); ?></td>
								<td>
									<?php echo esc_html( self::format_bytes( $entry->saved_bytes ) ); ?>
									(<?php echo esc_html( $row_pct ); ?>%)
								</td>
								<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $entry->converted_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'current'   => $page,
										'total'     => $total_pages,
										'prev_text' => __( '‹ Anterior', 'wp-webp-worker' ),
										'next_text' => __( 'Siguiente ›', 'wp-webp-worker' ),
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
