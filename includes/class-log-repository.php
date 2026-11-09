<?php
/**
 * Acceso a la tabla del registro de errores 404.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

// Tablas propias: WordPress no ofrece una API de alto nivel para ellas y los
// datos cambian en cada visita, así que no tiene sentido cachearlos.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Consultas sobre `{prefijo}r404_log`. Todas pasan por `$wpdb->prepare()`.
 */
final class Log_Repository {

	/**
	 * Columnas por las que se puede ordenar el listado.
	 */
	private const SORTABLE = array( 'url', 'hits', 'first_seen', 'last_seen' );

	/**
	 * Nombre completo de la tabla.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'r404_log';
	}

	/**
	 * Suma una visita a una URL existente.
	 *
	 * @param string $url      Ruta normalizada.
	 * @param string $referrer Procedencia ya reducida (puede ir vacía).
	 * @param string $now      Fecha UTC en formato MySQL.
	 * @return bool Si existía la URL.
	 */
	public function touch( string $url, string $referrer, string $now ): bool {
		global $wpdb;

		// Solo se sustituye la procedencia si la nueva visita trae una.
		if ( '' === $referrer ) {
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s WHERE url_hash = %s', self::table(), $now, md5( $url ) ) );
		} else {
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s, referrer = %s WHERE url_hash = %s', self::table(), $now, $referrer, md5( $url ) ) );
		}

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Añade una URL nueva al registro.
	 *
	 * @param string $url      Ruta normalizada.
	 * @param string $referrer Procedencia ya reducida.
	 * @param string $now      Fecha UTC en formato MySQL.
	 * @return bool Si se ha insertado (false si otra petición se adelantó).
	 */
	public function insert( string $url, string $referrer, string $now ): bool {
		global $wpdb;

		// Dos visitas simultáneas a la misma URL nueva chocan con la clave
		// única: no es un error real, así que no se muestra.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'url'        => $url,
				'url_hash'   => md5( $url ),
				'hits'       => 1,
				'referrer'   => $referrer,
				'first_seen' => $now,
				'last_seen'  => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		return false !== $inserted;
	}

	/**
	 * Registra una visita a una URL inexistente (la crea o suma una visita).
	 *
	 * @param string $url      Ruta normalizada.
	 * @param string $referrer Procedencia ya reducida.
	 * @return bool True si la URL es nueva en el registro.
	 */
	public function record( string $url, string $referrer = '' ): bool {
		$now = current_time( 'mysql', true );

		if ( $this->touch( $url, $referrer, $now ) ) {
			return false;
		}

		if ( $this->insert( $url, $referrer, $now ) ) {
			return true;
		}

		$this->touch( $url, $referrer, $now );
		return false;
	}

	/**
	 * Recorta la tabla a un máximo de filas borrando las menos recientes.
	 *
	 * Se hace en dos pasos (buscar identificadores y borrarlos) porque
	 * `DELETE … ORDER BY … LIMIT` es propio de MySQL.
	 *
	 * @param int $max Máximo de filas.
	 * @return int Filas borradas.
	 */
	public function enforce_cap( int $max ): int {
		global $wpdb;

		$excess = $this->count() - $max;
		if ( $excess <= 0 ) {
			return 0;
		}

		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY last_seen ASC, hits ASC, id ASC LIMIT %d', self::table(), $excess ) );

		return $this->delete( array_map( 'intval', $ids ) );
	}

	/**
	 * Borra las entradas cuya última visita es anterior a N días.
	 *
	 * @param int $days Días de antigüedad.
	 * @return int Filas borradas.
	 */
	public function delete_older_than( int $days ): int {
		global $wpdb;

		$limit = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', self::table(), $limit ) );
	}

	/**
	 * Borra entradas por identificador.
	 *
	 * @param int[] $ids Identificadores.
	 * @return int Filas borradas.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Solo marcadores %d generados arriba.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ($placeholders)", self::table(), ...$ids ) );
	}

	/**
	 * Borra la entrada de una URL concreta.
	 *
	 * @param string $url Ruta normalizada.
	 */
	public function delete_url( string $url ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE url_hash = %s', self::table(), md5( $url ) ) );
	}

	/**
	 * Borra las entradas que ya cubre una regla con comodín.
	 *
	 * @param string $source Origen con `*`.
	 * @return int Filas borradas.
	 */
	public function delete_matching_wildcard( string $source ): int {
		global $wpdb;

		$like    = $wpdb->esc_like( rtrim( (string) strstr( $source, '*', true ), '/' ) ) . '%';
		$regex   = Rule_Matcher::wildcard_regex( $source );
		$ids     = array();
		$last_id = 0;

		// Por bloques: el registro admite hasta 50 000 URL y un tope fijo
		// dejaría pendientes 404 que la regla ya resuelve.
		do {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, url FROM %i WHERE id > %d AND url LIKE %s ORDER BY id ASC LIMIT 1000', self::table(), $last_id, $like ),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				if ( preg_match( $regex, (string) $row['url'] ) ) {
					$ids[] = $last_id;
				}
			}
		} while ( 1000 === count( $rows ) );

		$deleted = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$deleted += $this->delete( $chunk );
		}

		return $deleted;
	}

	/**
	 * Vacía el registro.
	 */
	public function delete_all(): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/**
	 * Busca una entrada por identificador.
	 *
	 * @param int $id Identificador.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Número de URL registradas.
	 */
	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Totales para la cabecera: URL distintas y visitas perdidas.
	 *
	 * @return array{urls: int, hits: int}
	 */
	public function stats(): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS urls, COALESCE(SUM(hits), 0) AS hits FROM %i', self::table() ), ARRAY_A );

		return array(
			'urls' => (int) ( $row['urls'] ?? 0 ),
			'hits' => (int) ( $row['hits'] ?? 0 ),
		);
	}

	/**
	 * Página del listado con búsqueda y orden.
	 *
	 * @param array{search?: string, orderby?: string, order?: string, per_page?: int, page?: int} $args Opciones.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function paginate( array $args ): array {
		global $wpdb;

		$orderby  = in_array( $args['orderby'] ?? '', self::SORTABLE, true ) ? $args['orderby'] : 'hits';
		$order    = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$search   = trim( (string) ( $args['search'] ?? '' ) );
		$like     = '%' . $wpdb->esc_like( mb_strtolower( $search ) ) . '%';

		// $order solo puede ser ASC o DESC (lista cerrada arriba).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE url LIKE %s ORDER BY %i $order, id DESC LIMIT %d OFFSET %d", self::table(), $like, $orderby, $per_page, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE url LIKE %s', self::table(), $like ) );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}
}
