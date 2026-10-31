<?php
/**
 * Acceso a la tabla de redirecciones y a su caché.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

// Tablas propias: sin API de alto nivel en WordPress. La caché de reglas
// se gestiona aparte con una opción (ver get_rules()).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Consultas sobre `{prefijo}r404_redirects`.
 */
final class Redirect_Repository {

	/**
	 * Opción con el conjunto de reglas listo para usar en cada petición.
	 */
	public const CACHE_OPTION = 'redirecciones_404_rules';

	/**
	 * Columnas por las que se puede ordenar el listado.
	 */
	private const SORTABLE = array( 'source', 'target', 'type', 'hits', 'last_hit', 'created' );

	/**
	 * Copia en memoria de las reglas durante la petición (evita releerlas
	 * en cada fila al importar un CSV grande).
	 *
	 * @var array<int, array{id: int, source: string, target: string, type: int, is_wildcard: bool}>|null
	 */
	private ?array $memo = null;

	/**
	 * Nombre completo de la tabla.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'r404_redirects';
	}

	/**
	 * Reglas activas para resolver redirecciones.
	 *
	 * Se leen de una opción autocargada, de modo que en el caso habitual no
	 * cuestan ninguna consulta extra: WordPress ya trae las opciones
	 * autocargadas en una sola consulta al arrancar. Se regenera cuando falta.
	 *
	 * @return array<int, array{id: int, source: string, target: string, type: int, is_wildcard: bool}>
	 */
	public function get_rules(): array {
		if ( null !== $this->memo ) {
			return $this->memo;
		}

		$cached = get_option( self::CACHE_OPTION );

		if ( is_array( $cached ) ) {
			$this->memo = $cached;
			return $cached;
		}

		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, target, type, is_wildcard FROM %i ORDER BY id ASC', self::table() ), ARRAY_A );
		$rules = array();

		foreach ( (array) $rows as $row ) {
			$rules[] = array(
				'id'          => (int) $row['id'],
				'source'      => (string) $row['source'],
				'target'      => (string) $row['target'],
				'type'        => (int) $row['type'],
				'is_wildcard' => (bool) $row['is_wildcard'],
			);
		}

		update_option( self::CACHE_OPTION, $rules, true );
		$this->memo = $rules;

		return $rules;
	}

	/**
	 * Invalida la caché de reglas. Se llama tras cualquier cambio.
	 */
	public function flush_cache(): void {
		delete_option( self::CACHE_OPTION );
		$this->memo = null;
	}

	/**
	 * Mantiene al día la copia en memoria tras crear o editar una regla, sin
	 * volver a leer la tabla. La opción se invalida igualmente.
	 *
	 * @param int                                                                  $id   Identificador.
	 * @param array{source: string, target: string, type: int, is_wildcard: bool} $data Datos guardados.
	 */
	private function remember( int $id, array $data ): void {
		delete_option( self::CACHE_OPTION );

		if ( null === $this->memo ) {
			return;
		}

		$rule = array(
			'id'          => $id,
			'source'      => $data['source'],
			'target'      => $data['target'],
			'type'        => (int) $data['type'],
			'is_wildcard' => (bool) $data['is_wildcard'],
		);

		foreach ( $this->memo as $index => $existing ) {
			if ( $existing['id'] === $id ) {
				$this->memo[ $index ] = $rule;
				return;
			}
		}

		$this->memo[] = $rule;
	}

	/**
	 * Crea una redirección.
	 *
	 * @param array{source: string, target: string, type: int, is_wildcard: bool} $data Datos ya validados.
	 * @return int Identificador nuevo (0 si falla).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			self::table(),
			array(
				'source'      => $data['source'],
				'source_hash' => md5( $data['source'] ),
				'target'      => $data['target'],
				'type'        => $data['type'],
				'is_wildcard' => $data['is_wildcard'] ? 1 : 0,
				'hits'        => 0,
				'created'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( false === $ok ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		$this->remember( $id, $data );

		return $id;
	}

	/**
	 * Actualiza una redirección.
	 *
	 * @param int                                                                  $id   Identificador.
	 * @param array{source: string, target: string, type: int, is_wildcard: bool} $data Datos ya validados.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$ok = $wpdb->update(
			self::table(),
			array(
				'source'      => $data['source'],
				'source_hash' => md5( $data['source'] ),
				'target'      => $data['target'],
				'type'        => $data['type'],
				'is_wildcard' => $data['is_wildcard'] ? 1 : 0,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		if ( false !== $ok ) {
			$this->remember( $id, $data );
		}

		return false !== $ok;
	}

	/**
	 * Borra redirecciones por identificador.
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
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ($placeholders)", self::table(), ...$ids ) );

		$this->flush_cache();

		return $deleted;
	}

	/**
	 * Suma una visita a la redirección. Se llama en cada redirección servida.
	 *
	 * @param int $id Identificador.
	 */
	public function increment_hits( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d', self::table(), current_time( 'mysql', true ), $id ) );
	}

	/**
	 * Busca una redirección por identificador.
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
	 * Busca una redirección por su origen normalizado.
	 *
	 * @param string $source Origen.
	 * @return array<string, mixed>|null
	 */
	public function find_by_source( string $source ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_hash = %s', self::table(), md5( $source ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Número total de redirecciones.
	 */
	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Todas las redirecciones para exportar, en bloques para no cargar
	 * miles de filas de golpe en memoria.
	 *
	 * @param int $batch Tamaño de bloque.
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function each( int $batch = 500 ): \Generator {
		global $wpdb;
		$last_id = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', self::table(), $last_id, $batch ), ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$last_id = (int) $row['id'];
				yield $row;
			}
		} while ( is_array( $rows ) && count( $rows ) === $batch );
	}

	/**
	 * Página del listado con búsqueda (en origen y destino) y orden.
	 *
	 * @param array{search?: string, orderby?: string, order?: string, per_page?: int, page?: int} $args Opciones.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function paginate( array $args ): array {
		global $wpdb;

		$orderby  = in_array( $args['orderby'] ?? '', self::SORTABLE, true ) ? $args['orderby'] : 'created';
		$order    = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$like     = '%' . $wpdb->esc_like( trim( (string) ( $args['search'] ?? '' ) ) ) . '%';

		// $order solo puede ser ASC o DESC (lista cerrada arriba).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE source LIKE %s OR target LIKE %s ORDER BY %i $order, id DESC LIMIT %d OFFSET %d", self::table(), $like, $like, $orderby, $per_page, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source LIKE %s OR target LIKE %s', self::table(), $like, $like ) );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}
}
