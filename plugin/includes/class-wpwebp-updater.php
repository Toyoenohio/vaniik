<?php
/**
 * Auto-update: consulta los releases de GitHub e inyecta "actualización disponible".
 *
 * @package WP_WebP_Worker
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWebp_Updater {

	const REPO      = 'Toyoenohio/vaniik';
	const CACHE_KEY = 'wpwebp_latest_release';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Basename del plugin (carpeta/archivo-principal).
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Constructor: registra los filtros del update checker.
	 *
	 * @param string $file Ruta del archivo principal del plugin.
	 */
	public function __construct( $file ) {
		$this->basename = plugin_basename( $file );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
	}

	/**
	 * Obtiene el último release de GitHub (cacheado 12h).
	 *
	 * @return array|null Release decodificado, o null si no se pudo obtener.
	 */
	private function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wp-webp-worker',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return null;
		}

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Extrae la URL de descarga del release (asset .zip, o zipball como fallback).
	 *
	 * @param array $release Release de GitHub.
	 * @return string|null URL de descarga.
	 */
	private function get_download_url( $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['browser_download_url'], $asset['name'] ) && false !== stripos( $asset['name'], '.zip' ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		return isset( $release['zipball_url'] ) ? $release['zipball_url'] : null;
	}

	/**
	 * Inyecta la actualización en el transient cuando hay una versión más nueva.
	 *
	 * @param object $transient Transient de actualizaciones de plugins.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$new_version = ltrim( (string) $release['tag_name'], 'v' );
		if ( ! version_compare( WPWEBP_VERSION, $new_version, '<' ) ) {
			return $transient;
		}

		$download_url = $this->get_download_url( $release );
		if ( ! $download_url ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'slug'         => dirname( $this->basename ),
			'plugin'       => $this->basename,
			'new_version'  => $new_version,
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $download_url,
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);

		return $transient;
	}

	/**
	 * Proporciona la info del plugin para el popup "Ver detalles".
	 *
	 * @param false|object $result Resultado actual.
	 * @param string       $action Acción de la API.
	 * @param object       $args   Argumentos de la petición.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = isset( $args->slug ) ? (string) $args->slug : '';
		if ( dirname( $this->basename ) !== $slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$download_url = $this->get_download_url( $release );

		return (object) array(
			'name'          => 'WP WebP Worker',
			'slug'          => $slug,
			'version'       => ltrim( (string) $release['tag_name'], 'v' ),
			'author'        => 'Toyoenohio',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $download_url,
			'sections'      => array(
				'description' => isset( $release['body'] ) ? wp_kses_post( $release['body'] ) : '',
			),
		);
	}
}
