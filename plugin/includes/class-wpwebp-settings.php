<?php
/**
 * Settings API: endpoint del Worker, token, calidad y conversión al subir.
 *
 * @package WP_WebP_Worker
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWebp_Settings {

	const OPTION_NAME  = 'wpwebp_settings';
	const OPTION_GROUP = 'wpwebp_options_group';
	const PAGE_SLUG    = 'wpwebp-settings';

	/**
	 * Valores por defecto.
	 */
	public static function get_defaults() {
		return array(
			'endpoint'  => '',
			'token'     => '',
			'quality'   => 80,
			'on_upload' => 1,
		);
	}

	/**
	 * Obtiene los ajustes (o un solo valor) mezclados con los defaults.
	 *
	 * @param string|null $key Clave concreta o null para todo el array.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$defaults = self::get_defaults();
		$settings = wp_parse_args( get_option( self::OPTION_NAME, array() ), $defaults );

		if ( null === $key ) {
			return $settings;
		}

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $defaults[ $key ];
	}

	/**
	 * Registra settings, secciones y campos. Hook: admin_init.
	 */
	public static function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'wpwebp_general',
			__( 'Worker de Cloudflare', 'wp-webp-worker' ),
			array( __CLASS__, 'render_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpwebp_endpoint',
			__( 'Endpoint del Worker', 'wp-webp-worker' ),
			array( __CLASS__, 'render_field_endpoint' ),
			self::PAGE_SLUG,
			'wpwebp_general'
		);

		add_settings_field(
			'wpwebp_token',
			__( 'Token (AUTH_TOKEN)', 'wp-webp-worker' ),
			array( __CLASS__, 'render_field_token' ),
			self::PAGE_SLUG,
			'wpwebp_general'
		);

		add_settings_field(
			'wpwebp_quality',
			__( 'Calidad WebP', 'wp-webp-worker' ),
			array( __CLASS__, 'render_field_quality' ),
			self::PAGE_SLUG,
			'wpwebp_general'
		);

		add_settings_field(
			'wpwebp_on_upload',
			__( 'Convertir al subir', 'wp-webp-worker' ),
			array( __CLASS__, 'render_field_on_upload' ),
			self::PAGE_SLUG,
			'wpwebp_general'
		);
	}

	/**
	 * Sanitiza todos los campos al guardar.
	 *
	 * @param array $input Entrada cruda del formulario.
	 * @return array Ajustes saneados.
	 */
	public static function sanitize( $input ) {
		$defaults = self::get_defaults();
		$input    = is_array( $input ) ? $input : array();

		$sanitized                 = $defaults;
		$sanitized['endpoint']     = isset( $input['endpoint'] ) ? esc_url_raw( trim( $input['endpoint'] ) ) : '';
		$sanitized['token']        = isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '';

		if ( isset( $input['quality'] ) ) {
			$quality               = absint( $input['quality'] );
			$sanitized['quality']  = ( $quality >= 1 && $quality <= 100 ) ? $quality : 80;
		}

		$sanitized['on_upload'] = empty( $input['on_upload'] ) ? 0 : 1;

		return $sanitized;
	}

	/**
	 * Descripción de la sección.
	 */
	public static function render_section() {
		echo '<p>' . esc_html__( 'Pega aquí la URL del Worker y el token. Sin endpoint configurado, el plugin no convierte nada.', 'wp-webp-worker' ) . '</p>';
	}

	/**
	 * Campo: endpoint.
	 */
	public static function render_field_endpoint() {
		$value = self::get( 'endpoint' );
		printf(
			'<input type="url" id="wpwebp_endpoint" name="%1$s[endpoint]" value="%2$s" class="regular-text" placeholder="https://wp-webp-worker.tucuenta.workers.dev">',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'La URL base del Worker (sin query string).', 'wp-webp-worker' ) . '</p>';
	}

	/**
	 * Campo: token.
	 */
	public static function render_field_token() {
		$value = self::get( 'token' );
		printf(
			'<input type="password" id="wpwebp_token" name="%1$s[token]" value="%2$s" class="regular-text" autocomplete="off">',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'El AUTH_TOKEN del Worker. Déjalo vacío si el Worker no tiene token.', 'wp-webp-worker' ) . '</p>';
	}

	/**
	 * Campo: calidad.
	 */
	public static function render_field_quality() {
		$value = self::get( 'quality' );
		printf(
			'<input type="number" id="wpwebp_quality" name="%1$s[quality]" value="%2$d" min="1" max="100" class="small-text">',
			esc_attr( self::OPTION_NAME ),
			absint( $value )
		);
		echo '<p class="description">' . esc_html__( 'Calidad de compresión (1-100). Default: 80.', 'wp-webp-worker' ) . '</p>';
	}

	/**
	 * Campo: convertir al subir.
	 */
	public static function render_field_on_upload() {
		$value = self::get( 'on_upload' );
		printf(
			'<label><input type="checkbox" id="wpwebp_on_upload" name="%1$s[on_upload]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $value, 1, false ),
			esc_html__( 'Convertir automáticamente cada imagen nueva al subirla.', 'wp-webp-worker' )
		);
	}

	/**
	 * Renderiza la página de ajustes completa.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'wp-webp-worker' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( 'wpwebp_messages' ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Conversión en lote', 'wp-webp-worker' ); ?></h2>
			<p><?php esc_html_e( 'Convierte toda la librería de medios existente a WebP. Se procesa en segundo plano, en lotes, para no agotar el servidor.', 'wp-webp-worker' ); ?></p>
			<p>
				<button type="button" id="wpwebp-bulk-start" class="button button-primary">
					<?php esc_html_e( 'Convertir toda la librería', 'wp-webp-worker' ); ?>
				</button>
				<button type="button" id="wpwebp-bulk-htaccess" class="button">
					<?php esc_html_e( 'Regenerar reglas .htaccess', 'wp-webp-worker' ); ?>
				</button>
			</p>
			<div id="wpwebp-bulk-progress" class="wpwebp-progress" style="display:none;">
				<div id="wpwebp-bulk-bar">
					<div id="wpwebp-bulk-fill"></div>
				</div>
				<p id="wpwebp-bulk-status"></p>
			</div>
		</div>
		<?php
	}
}
