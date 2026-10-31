<?php
/**
 * Limpieza diaria del registro con WP-Cron.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Borra cada día los 404 sin visitas en los últimos N días (ajuste).
 */
final class Cleanup {

	public const HOOK = 'redirecciones_404_cleanup';

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $log Repositorio del registro.
	 */
	public function __construct( private readonly Log_Repository $log ) {}

	/**
	 * Engancha la tarea programada.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Ejecuta la limpieza.
	 *
	 * @return int Entradas borradas.
	 */
	public function run(): int {
		if ( ! Installer::is_installed() ) {
			return 0;
		}

		$deleted  = $this->log->delete_older_than( (int) Settings::get( 'retention_days' ) );
		$deleted += $this->log->enforce_cap( (int) Settings::get( 'max_entries' ) );

		return $deleted;
	}

	/**
	 * Programa el evento diario si no lo está.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Quita el evento programado.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
