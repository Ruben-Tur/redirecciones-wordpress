<?php
/**
 * Lectura y escritura de redirecciones en CSV.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Formato: `origen,destino,tipo` con cabecera opcional. Al importar se
 * acepta coma, punto y coma (lo que usa Excel en español) o tabulador, y
 * cabeceras en español o en inglés en cualquier orden.
 */
final class Csv {

	/**
	 * Tamaño máximo del archivo que se acepta (1 MB ≈ 15 000 reglas).
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Máximo de filas por importación.
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Cabecera de exportación.
	 */
	public const HEADER = array( 'origen', 'destino', 'tipo', 'comodin', 'visitas' );

	/**
	 * Nombres de columna reconocidos al importar.
	 */
	private const ALIASES = array(
		'source' => array( 'origen', 'source', 'from', 'desde', 'url' ),
		'target' => array( 'destino', 'target', 'to', 'hasta', 'destination', 'redirect' ),
		'type'   => array( 'tipo', 'type', 'code', 'codigo', 'código', 'status' ),
	);

	/**
	 * Analiza el contenido de un CSV.
	 *
	 * No valida las URL (de eso se encarga Redirect_Service al guardar):
	 * aquí solo se comprueba la estructura y se anotan los errores por línea.
	 *
	 * @param string $contents Contenido del archivo.
	 * @return array{rows: list<array{line: int, source: string, target: string, type: int}>, errors: array<int, string>}
	 */
	public static function parse( string $contents ): array {
		$rows   = array();
		$errors = array();

		$contents = self::to_utf8( $contents );
		if ( '' === trim( $contents ) ) {
			return array(
				'rows'   => array(),
				'errors' => array( 0 => __( 'El archivo está vacío.', 'redirecciones-404' ) ),
			);
		}

		$delimiter = self::detect_delimiter( $contents );
		$stream    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Flujo en memoria, no toca disco.
		fwrite( $stream, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );

		$columns = array(
			'source' => 0,
			'target' => 1,
			'type'   => 2,
		);
		$line    = 0;

		while ( false !== ( $cells = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;

			if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
				continue;
			}

			$cells = array_map( static fn( $cell ): string => self::unescape_cell( trim( (string) $cell ) ), $cells );

			if ( 1 === $line && self::is_header( $cells ) ) {
				$columns = self::map_header( $cells );
				continue;
			}

			if ( count( $rows ) >= self::MAX_ROWS ) {
				/* translators: %d: número máximo de filas. */
				$errors[ $line ] = sprintf( __( 'Se ha alcanzado el máximo de %d filas por importación; el resto se ignora.', 'redirecciones-404' ), self::MAX_ROWS );
				break;
			}

			$source = $cells[ $columns['source'] ] ?? '';
			$target = $cells[ $columns['target'] ] ?? '';
			$type   = $cells[ $columns['type'] ] ?? '';

			if ( '' === $source || '' === $target ) {
				$errors[ $line ] = __( 'Faltan el origen o el destino.', 'redirecciones-404' );
				continue;
			}

			if ( '' !== $type && ! in_array( $type, array( '301', '302' ), true ) ) {
				/* translators: %s: valor de la columna tipo. */
				$errors[ $line ] = sprintf( __( 'Tipo «%s» no válido: usa 301 o 302.', 'redirecciones-404' ), $type );
				continue;
			}

			$rows[] = array(
				'line'   => $line,
				'source' => $source,
				'target' => $target,
				'type'   => '' === $type ? 301 : (int) $type,
			);
		}

		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * Fila de exportación de una redirección, protegida frente a inyección
	 * de fórmulas al abrir el archivo en una hoja de cálculo.
	 *
	 * @param array<string, mixed> $redirect Fila de la tabla.
	 * @return list<string>
	 */
	public static function export_row( array $redirect ): array {
		return array_map(
			array( self::class, 'escape_cell' ),
			array(
				(string) $redirect['source'],
				(string) $redirect['target'],
				(string) $redirect['type'],
				empty( $redirect['is_wildcard'] ) ? '0' : '1',
				(string) (int) $redirect['hits'],
			)
		);
	}

	/**
	 * Antepone un apóstrofo a las celdas que una hoja de cálculo
	 * interpretaría como fórmula (`= + - @`, tabulador o retorno).
	 *
	 * @param string $value Valor de la celda.
	 */
	public static function escape_cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Deshace escape_cell() al importar un archivo exportado por el plugin.
	 *
	 * @param string $value Valor de la celda.
	 */
	private static function unescape_cell( string $value ): string {
		return preg_match( "/^'[=+\-@\t\r]/", $value ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Quita el BOM y convierte de Windows-1252 si el archivo no es UTF-8
	 * (lo habitual al guardar desde Excel).
	 *
	 * @param string $contents Contenido original.
	 */
	private static function to_utf8( string $contents ): string {
		if ( str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
			$contents = substr( $contents, 3 );
		}

		if ( ! mb_check_encoding( $contents, 'UTF-8' ) ) {
			$contents = (string) mb_convert_encoding( $contents, 'UTF-8', 'Windows-1252' );
		}

		return str_replace( array( "\r\n", "\r" ), "\n", $contents );
	}

	/**
	 * Elige el separador más frecuente en la primera línea.
	 *
	 * @param string $contents Contenido en UTF-8.
	 */
	private static function detect_delimiter( string $contents ): string {
		$first = strtok( $contents, "\n" );
		$best  = ',';
		$most  = 0;

		foreach ( array( ',', ';', "\t" ) as $candidate ) {
			$count = substr_count( (string) $first, $candidate );
			if ( $count > $most ) {
				$best = $candidate;
				$most = $count;
			}
		}

		return $best;
	}

	/**
	 * Indica si una fila es la cabecera.
	 *
	 * @param list<string> $cells Celdas de la fila.
	 */
	private static function is_header( array $cells ): bool {
		$first = mb_strtolower( $cells[0] ?? '', 'UTF-8' );

		foreach ( self::ALIASES as $aliases ) {
			if ( in_array( $first, $aliases, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Posición de cada columna según la cabecera.
	 *
	 * @param list<string> $cells Celdas de la cabecera.
	 * @return array{source: int, target: int, type: int}
	 */
	private static function map_header( array $cells ): array {
		$columns = array(
			'source' => 0,
			'target' => 1,
			'type'   => 2,
		);

		foreach ( $cells as $index => $cell ) {
			$name = mb_strtolower( $cell, 'UTF-8' );
			foreach ( self::ALIASES as $key => $aliases ) {
				if ( in_array( $name, $aliases, true ) ) {
					$columns[ $key ] = $index;
				}
			}
		}

		return $columns;
	}
}
