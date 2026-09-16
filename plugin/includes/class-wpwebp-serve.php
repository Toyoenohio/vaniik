<?php
/**
 * Servido server-agnóstico: reescribe la URL de las imágenes a .webp en el HTML
 * cuando el navegador acepta WebP y el .webp existe. Funciona en Apache, Nginx
 * y LiteSpeed sin tocar la config del servidor (a diferencia del .htaccess).
 *
 * @package WP_WebP_Worker
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWebp_Serve {

	/**
	 * Registra los filtros de servido.
	 */
	public static function init() {
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 5 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ) );
	}

	/**
	 * ¿El navegador acepta WebP? (cacheado por request).
	 *
	 * @return bool
	 */
	public static function accepts_webp() {
		static $accepts = null;

		if ( null === $accepts ) {
			$accepts = false;
			if ( isset( $_SERVER['HTTP_ACCEPT'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 'image/webp' ) ) {
				$accepts = true;
			}
		}

		return $accepts;
	}

	/**
	 * Devuelve la URL .webp si existe en disco; si no, la URL original.
	 *
	 * @param string $url URL de la imagen (.jpg/.png).
	 * @return string URL .webp o la original.
	 */
	public static function webp_url( $url ) {
		if ( ! preg_match( '#\.(jpe?g|png)$#i', $url ) ) {
			return $url;
		}

		static $uploads = null;
		if ( null === $uploads ) {
			$uploads = wp_get_upload_dir();
		}

		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return $url;
		}

		// Solo reescribimos imágenes dentro de la carpeta de uploads.
		if ( 0 !== strpos( $url, $uploads['baseurl'] ) ) {
			return $url;
		}

		$rel  = substr( $url, strlen( $uploads['baseurl'] ) );
		$path = $uploads['basedir'] . $rel;

		if ( file_exists( $path . '.webp' ) ) {
			return $url . '.webp';
		}

		return $url;
	}

	/**
	 * Filtra el `src` de una imagen de attachment.
	 *
	 * @param array|false $image         [url, width, height, is_intermediate].
	 * @param int         $attachment_id ID del attachment.
	 * @param string|int[] $size         Tamaño solicitado.
	 * @param bool        $icon          Si es un icono.
	 * @return array|false
	 */
	public static function filter_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! self::accepts_webp() || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}

		$image[0] = self::webp_url( $image[0] );

		return $image;
	}

	/**
	 * Filtra cada candidato del srcset.
	 *
	 * @param array  $sources        Candidatos por ancho.
	 * @param int[]  $size_array     [width, height].
	 * @param string $image_src      URL del src.
	 * @param array  $image_meta     Metadatos de la imagen.
	 * @param int    $attachment_id  ID del attachment.
	 * @return array
	 */
	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! self::accepts_webp() || ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $src ) {
			if ( ! empty( $src['url'] ) ) {
				$sources[ $width ]['url'] = self::webp_url( $src['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Reescribe los `src="...jpg|png"` en el contenido (imágenes hardcodeadas).
	 * Solo toca atributos src; el srcset lo cubre el filtro dedicado.
	 *
	 * @param string $content Contenido HTML.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( ! self::accepts_webp() ) {
			return $content;
		}

		return preg_replace_callback(
			'#(src=[\'"])([^\'"]+\.(?:jpe?g|png))([\'"])#i',
			function ( $m ) {
				return $m[1] . self::webp_url( $m[2] ) . $m[3];
			},
			(string) $content
		);
	}
}
