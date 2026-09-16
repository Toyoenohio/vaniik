<?php
/**
 * Plugin Name:       WP WebP Worker
 * Plugin URI:        https://github.com/toyoenohio/wp-webp-worker
 * Description:       Convierte imágenes a WebP mediante un Worker de Cloudflare (al subir y en lote) y las sirve en cualquier servidor (Apache, Nginx, LiteSpeed). Herramienta para operar librerías grandes de clientes.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Toyoenohio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-webp-worker
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPWEBP_VERSION', '1.0.1' );
define( 'WPWEBP_FILE', __FILE__ );
define( 'WPWEBP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPWEBP_URL', plugin_dir_url( __FILE__ ) );

require_once WPWEBP_DIR . 'includes/class-wpwebp-settings.php';
require_once WPWEBP_DIR . 'includes/class-wpwebp-converter.php';
require_once WPWEBP_DIR . 'includes/class-wpwebp-stats.php';
require_once WPWEBP_DIR . 'includes/class-wpwebp-updater.php';
require_once WPWEBP_DIR . 'includes/class-wpwebp-serve.php';
require_once WPWEBP_DIR . 'includes/class-wpwebp-plugin.php';

// Hooks de ciclo de vida registrados en el archivo principal (no dentro de una clase).
register_activation_hook( __FILE__, array( 'WPWebp_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPWebp_Plugin', 'deactivate' ) );

WPWebp_Plugin::init();
