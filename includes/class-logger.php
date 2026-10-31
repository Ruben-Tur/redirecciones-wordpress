<?php
/**
 * Registro de errores 404.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Anota cada 404 real del front-end.
 *
 * Coste por petición: una sola consulta UPDATE si la URL ya estaba
 * registrada. Las URL nuevas pasan además por un límite por minuto, para que
 * un bot que pide miles de rutas aleatorias no llene la base de datos.
 */
final class Logger {

	/**
	 * Transitorio con el contador de URL nuevas del minuto en curso.
	 */
	private const RATE_TRANSIENT = 'redirecciones_404_rate';

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $log Repositorio del registro.
	 */
	public function __construct( private readonly Log_Repository $log ) {}

	/**
	 * Engancha el registro.
	 */
	public function register(): void {
		// Prioridad alta: así ya se han ejecutado redirect_canonical() y otros
		// plugins que redirigen en template_redirect, y solo queda el 404 real.
		add_action( 'template_redirect', array( $this, 'maybe_log' ), 999 );
	}

	/**
	 * Registra la petición actual si es un 404 que interesa.
	 */
	public function maybe_log(): void {
		if ( ! is_404() || ! Installer::is_installed() || ! Settings::get( 'log_enabled' ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}

		// La URI no pasa por sanitize_text_field() porque eliminaría los %xx;
		// normalize_path() la decodifica y quita caracteres de control.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = Url::normalize_path( (string) $uri, Url::home_path() );

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ignore     = Url::is_noise( $path ) || Url::is_noise_user_agent( $user_agent ) || Url::is_system_path( $path, rest_get_url_prefix() );

		/**
		 * Permite decidir si un 404 se registra.
		 *
		 * @param bool   $ignore True para no registrarlo.
		 * @param string $path   Ruta normalizada.
		 */
		if ( apply_filters( 'redirecciones_404_ignore', $ignore, $path ) ) {
			return;
		}

		// Solo dominio y ruta de la procedencia: nunca la consulta, ni IP, ni user agent.
		$referrer = Url::sanitize_referrer( (string) wp_get_raw_referer() );
		$now      = current_time( 'mysql', true );

		if ( $this->log->touch( $path, $referrer, $now ) ) {
			return;
		}

		if ( ! $this->take_rate_slot() ) {
			return;
		}

		if ( $this->log->insert( $path, $referrer, $now ) ) {
			$this->log->enforce_cap( (int) Settings::get( 'max_entries' ) );
		} else {
			$this->log->touch( $path, $referrer, $now );
		}
	}

	/**
	 * Reserva un hueco en el límite de URL nuevas por minuto.
	 *
	 * @return bool False si ya se ha llegado al límite este minuto.
	 */
	private function take_rate_slot(): bool {
		$minute = (int) floor( time() / MINUTE_IN_SECONDS );
		$state  = get_transient( self::RATE_TRANSIENT );

		if ( ! is_array( $state ) || ( $state['minute'] ?? 0 ) !== $minute ) {
			$state = array(
				'minute' => $minute,
				'count'  => 0,
			);
		}

		if ( $state['count'] >= (int) Settings::get( 'max_new_per_min' ) ) {
			return false;
		}

		++$state['count'];
		set_transient( self::RATE_TRANSIENT, $state, 2 * MINUTE_IN_SECONDS );

		return true;
	}
}
