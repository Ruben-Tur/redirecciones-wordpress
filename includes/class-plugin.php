<?php
/**
 * Arranque del plugin.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Crea los servicios y engancha cada parte en su momento: el redirector y
 * el registro en todas las peticiones; el panel, solo en el escritorio.
 */
final class Plugin {

	/**
	 * Evita arrancar dos veces si el archivo se incluye de nuevo.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registra los ganchos.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$log       = new Log_Repository();
		$redirects = new Redirect_Repository();

		( new Redirector( $redirects ) )->register();
		( new Logger( $log ) )->register();
		( new Cleanup( $log ) )->register();

		// Multisitio: al eliminar un sitio de la red, sus tablas se van con él.
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_site_tables' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );

			// home_url() se pide tarde para respetar filtros de idioma o dominio.
			add_action(
				'init',
				static function () use ( $log, $redirects ): void {
					$service = new Redirect_Service( $redirects, $log, home_url() );
					( new Admin\Admin_Page( $log, $redirects ) )->register();
					( new Admin\Actions( $log, $redirects, $service ) )->register();
				}
			);
		}
	}
}
