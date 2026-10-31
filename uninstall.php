<?php
/**
 * Desinstalación: elimina tablas, opciones y tareas programadas.
 *
 * WordPress solo carga este archivo al borrar el plugin desde el
 * escritorio. Desactivarlo no borra nada.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

Autoloader::register( __NAMESPACE__, __DIR__ . '/includes' );

if ( is_multisite() ) {
	// En una red, cada sitio tiene sus propias tablas y opciones.
	$redirecciones_404_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $redirecciones_404_sites as $redirecciones_404_site_id ) {
		switch_to_blog( (int) $redirecciones_404_site_id );
		Installer::uninstall();
		restore_current_blog();
	}
} else {
	Installer::uninstall();
}
