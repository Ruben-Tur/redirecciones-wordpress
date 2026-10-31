<?php
/**
 * Plugin Name:       Redirecciones 404
 * Plugin URI:        https://github.com/Ruben-Tur/redirecciones-wordpress
 * Description:       Registra los errores 404 de la web y permite convertirlos en redirecciones 301 con un clic. Admite comodines, importación y exportación en CSV y limpieza automática del registro.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Rubén Tur
 * Author URI:        https://github.com/Ruben-Tur
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redirecciones-404
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;
const TEXT_DOMAIN = 'redirecciones-404';

require_once __DIR__ . '/includes/class-autoloader.php';

Autoloader::register( __NAMESPACE__, __DIR__ . '/includes' );

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

Plugin::boot();
