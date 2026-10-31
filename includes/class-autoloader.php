<?php
/**
 * Autocarga de clases sin Composer.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Traduce nombres de clase con espacio de nombres a archivos con la
 * nomenclatura de WordPress: `Admin\Log_List_Table` se carga desde
 * `includes/admin/class-log-list-table.php`.
 */
final class Autoloader {

	/**
	 * Registra el autoloader para un espacio de nombres y una carpeta base.
	 *
	 * @param string $namespace_prefix Espacio de nombres raíz, sin barra final.
	 * @param string $base_dir         Carpeta donde viven las clases.
	 */
	public static function register( string $namespace_prefix, string $base_dir ): void {
		$prefix   = $namespace_prefix . '\\';
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}

				$file = $base_dir . self::file_for( substr( $class_name, strlen( $prefix ) ) );

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Devuelve la ruta relativa del archivo de una clase.
	 *
	 * @param string $relative_class Clase sin el espacio de nombres raíz (p. ej. `Admin\Actions`).
	 */
	public static function file_for( string $relative_class ): string {
		$parts = explode( '\\', $relative_class );
		$class = array_pop( $parts );
		$dirs  = array_map( static fn( string $dir ): string => strtolower( str_replace( '_', '-', $dir ) ) . '/', $parts );

		return implode( '', $dirs ) . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	}
}
