<?php
/**
 * Ajustes del plugin guardados en una sola opción.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Lee, valida y guarda los ajustes.
 */
final class Settings {

	public const OPTION = 'redirecciones_404_settings';

	/**
	 * Límites de cada ajuste numérico: [mínimo, máximo].
	 */
	public const LIMITS = array(
		'retention_days'  => array( 1, 365 ),
		'max_entries'     => array( 100, 50000 ),
		'max_new_per_min' => array( 5, 1000 ),
	);

	/**
	 * Valores por defecto.
	 *
	 * @return array{log_enabled: bool, retention_days: int, max_entries: int, max_new_per_min: int, keep_query: bool}
	 */
	public static function defaults(): array {
		return array(
			'log_enabled'     => true,
			'retention_days'  => 30,
			'max_entries'     => 5000,
			'max_new_per_min' => 60,
			'keep_query'      => true,
		);
	}

	/**
	 * Ajustes actuales completados con los valores por defecto.
	 *
	 * @return array{log_enabled: bool, retention_days: int, max_entries: int, max_new_per_min: int, keep_query: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Devuelve un ajuste concreto.
	 *
	 * @param string $key Nombre del ajuste.
	 */
	public static function get( string $key ): int|bool {
		return self::all()[ $key ] ?? self::defaults()[ $key ];
	}

	/**
	 * Limpia lo que llega del formulario de ajustes. Los números fuera de
	 * rango se ajustan al límite más cercano.
	 *
	 * @param mixed $input Valores sin validar.
	 * @return array{log_enabled: bool, retention_days: int, max_entries: int, max_new_per_min: int, keep_query: bool}
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array(
			'log_enabled' => ! empty( $input['log_enabled'] ),
			'keep_query'  => ! empty( $input['keep_query'] ),
		);

		// Al guardar el formulario, una casilla sin marcar no llega: se respeta como «no».
		if ( ! isset( $input['_form'] ) ) {
			$clean['log_enabled'] = array_key_exists( 'log_enabled', $input ) ? (bool) $input['log_enabled'] : $defaults['log_enabled'];
			$clean['keep_query']  = array_key_exists( 'keep_query', $input ) ? (bool) $input['keep_query'] : $defaults['keep_query'];
		}

		foreach ( self::LIMITS as $key => [ $min, $max ] ) {
			$value         = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : $defaults[ $key ];
			$clean[ $key ] = max( $min, min( $max, $value ) );
		}

		return array(
			'log_enabled'     => $clean['log_enabled'],
			'retention_days'  => $clean['retention_days'],
			'max_entries'     => $clean['max_entries'],
			'max_new_per_min' => $clean['max_new_per_min'],
			'keep_query'      => $clean['keep_query'],
		);
	}
}
