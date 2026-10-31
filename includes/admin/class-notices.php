<?php
/**
 * Avisos de una sola vez tras redirigir (patrón POST/redirect/GET).
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda los avisos en un transitorio por usuario y los pinta con el
 * marcado estándar de WordPress (`.notice`).
 */
final class Notices {

	/**
	 * Clave del transitorio para el usuario actual.
	 */
	private static function key(): string {
		return 'redirecciones_404_notice_' . get_current_user_id();
	}

	/**
	 * Añade un aviso para la siguiente carga de la página.
	 *
	 * @param string       $type    success, error, warning o info.
	 * @param string       $message Texto principal (sin HTML).
	 * @param list<string> $details Líneas de detalle (sin HTML).
	 * @param array        $old     Valores del formulario para volver a rellenarlo.
	 */
	public static function add( string $type, string $message, array $details = array(), array $old = array() ): void {
		$notices = get_transient( self::key() );
		$notices = is_array( $notices ) ? $notices : array( 'items' => array() );

		$notices['items'][] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => $message,
			'details' => array_slice( $details, 0, 50 ),
		);

		if ( array() !== $old ) {
			$notices['old'] = $old;
		}

		set_transient( self::key(), $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Recupera y borra los avisos pendientes.
	 *
	 * @return array{items: list<array{type: string, message: string, details: list<string>}>, old: array}
	 */
	public static function pull(): array {
		$notices = get_transient( self::key() );
		delete_transient( self::key() );

		return array(
			'items' => is_array( $notices ) && isset( $notices['items'] ) ? $notices['items'] : array(),
			'old'   => is_array( $notices ) && isset( $notices['old'] ) ? $notices['old'] : array(),
		);
	}

	/**
	 * Pinta los avisos.
	 *
	 * @param list<array{type: string, message: string, details: list<string>}> $items Avisos.
	 */
	public static function render( array $items ): void {
		foreach ( $items as $notice ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );

			if ( array() !== $notice['details'] ) {
				echo '<ul class="r404-notice-list">';
				foreach ( $notice['details'] as $detail ) {
					echo '<li>' . esc_html( $detail ) . '</li>';
				}
				echo '</ul>';
			}

			echo '</div>';
		}
	}
}
