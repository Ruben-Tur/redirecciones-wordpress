<?php
/**
 * Validación y guardado de redirecciones.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Punto único por el que pasan todas las altas y ediciones: formulario,
 * botón «Redirigir 301» del registro e importación CSV. Así las reglas de
 * validación (destinos seguros, duplicados, bucles) son siempre las mismas.
 */
final class Redirect_Service {

	/**
	 * Tipos de redirección admitidos.
	 */
	public const TYPES = array( 301, 302 );

	/**
	 * Constructor.
	 *
	 * @param Redirect_Repository $redirects Repositorio de redirecciones.
	 * @param Log_Repository      $log       Repositorio del registro 404.
	 * @param string              $home_url  URL de la portada.
	 */
	public function __construct(
		private readonly Redirect_Repository $redirects,
		private readonly Log_Repository $log,
		private readonly string $home_url
	) {}

	/**
	 * Valida los datos de una redirección sin guardarla.
	 *
	 * @param array<string, mixed> $input Datos sin validar (`source`, `target`, `type`).
	 * @param int                  $id    Identificador si se está editando.
	 * @return array{source: string, target: string, type: int, is_wildcard: bool, warnings: list<string>}|WP_Error
	 */
	public function validate( array $input, int $id = 0 ): array|WP_Error {
		$raw_source = trim( (string) ( $input['source'] ?? '' ) );
		$raw_target = trim( (string) ( $input['target'] ?? '' ) );
		$type       = (int) ( $input['type'] ?? 301 );

		if ( '' === $raw_source ) {
			return new WP_Error( 'r404_source_empty', __( 'Escribe la URL de origen.', 'redirecciones-404' ) );
		}

		if ( mb_strlen( $raw_source ) > Url::MAX_LENGTH ) {
			return new WP_Error( 'r404_source_long', __( 'La URL de origen es demasiado larga.', 'redirecciones-404' ) );
		}

		$source      = Url::normalize_path( $raw_source, (string) wp_parse_url( $this->home_url, PHP_URL_PATH ) );
		$is_wildcard = str_contains( $source, '*' );

		if ( '/' === $source ) {
			return new WP_Error( 'r404_source_home', __( 'La portada no se puede redirigir desde aquí.', 'redirecciones-404' ) );
		}

		if ( $is_wildcard && '' === trim( str_replace( '*', '', $source ), '/' ) ) {
			return new WP_Error( 'r404_source_broad', __( 'Esa regla redirigiría toda la web. Añade una parte fija, por ejemplo /blog/*.', 'redirecciones-404' ) );
		}

		if ( Url::is_system_path( $source, rest_get_url_prefix() ) ) {
			return new WP_Error( 'r404_source_system', __( 'Las rutas del escritorio, del acceso y de la API REST no se pueden redirigir.', 'redirecciones-404' ) );
		}

		if ( '' === $raw_target ) {
			return new WP_Error( 'r404_target_empty', __( 'Escribe la URL de destino.', 'redirecciones-404' ) );
		}

		$target = Url::sanitize_target( $raw_target );
		if ( '' === $target ) {
			return new WP_Error( 'r404_target_invalid', __( 'El destino debe empezar por / (página de esta web) o por https:// (otra web).', 'redirecciones-404' ) );
		}

		preg_match_all( '/\$(\d)/', $target, $placeholders );
		$max_placeholder = array() === $placeholders[1] ? 0 : max( array_map( 'intval', $placeholders[1] ) );
		if ( $max_placeholder > substr_count( $source, '*' ) ) {
			return new WP_Error(
				'r404_target_placeholder',
				/* translators: %s: marcador como $1. */
				sprintf( __( 'El destino usa %s, pero el origen no tiene tantos comodines (*).', 'redirecciones-404' ), '$' . $max_placeholder )
			);
		}

		// En un destino externo los comodines solo pueden ir en la ruta o la
		// consulta: en el dominio permitirían enviar al visitante a cualquier web.
		if ( ! str_starts_with( $target, '/' ) ) {
			$after_scheme = substr( $target, (int) strpos( $target, '://' ) + 3 );
			$authority    = substr( $after_scheme, 0, strcspn( $after_scheme, '/?#' ) );
			if ( preg_match( '/\$\d/', $authority ) ) {
				return new WP_Error( 'r404_target_host_placeholder', __( 'En un destino externo los comodines ($1, $2…) solo pueden ir después del dominio, por ejemplo https://otra-web.com/$1.', 'redirecciones-404' ) );
			}
		}

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'r404_type', __( 'El tipo debe ser 301 (permanente) o 302 (temporal).', 'redirecciones-404' ) );
		}

		$existing = $this->redirects->find_by_source( $source );
		if ( null !== $existing && (int) $existing['id'] !== $id ) {
			return new WP_Error(
				'r404_duplicate',
				/* translators: %s: URL de origen. */
				sprintf( __( 'Ya hay una redirección para %s.', 'redirecciones-404' ), $source )
			);
		}

		$rule = array(
			'id'          => $id,
			'source'      => $source,
			'target'      => $target,
			'type'        => $type,
			'is_wildcard' => $is_wildcard,
		);

		$chain = $this->check_chain( $rule );
		if ( $chain instanceof WP_Error ) {
			return $chain;
		}

		// El origen se compara sin consulta: `/pagina?id=5` se guarda como
		// `/pagina` y redirige esa ruta con cualquier consulta. Mejor avisar.
		$raw_query = Url::query( $raw_source );
		if ( '' !== $raw_query ) {
			$chain[] = sprintf(
				/* translators: 1: consulta descartada, 2: origen guardado. */
				__( 'Los parámetros del origen (?%1$s) se ignoran: la regla se aplica a %2$s con cualquier consulta.', 'redirecciones-404' ),
				$raw_query,
				$source
			);
		}

		$rule['warnings'] = $chain;
		unset( $rule['id'] );

		return $rule;
	}

	/**
	 * Valida y guarda (crea o actualiza) una redirección.
	 *
	 * @param array<string, mixed> $input Datos sin validar.
	 * @param int                  $id    Identificador si se está editando.
	 * @return array{id: int, source: string, target: string, type: int, is_wildcard: bool, warnings: list<string>, cleared: int, updated: bool}|WP_Error
	 */
	public function save( array $input, int $id = 0 ): array|WP_Error {
		$data = $this->validate( $input, $id );
		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$is_update = $id > 0;
		$row       = array(
			'source'      => $data['source'],
			'target'      => $data['target'],
			'type'        => $data['type'],
			'is_wildcard' => $data['is_wildcard'],
		);

		if ( $is_update ) {
			if ( ! $this->redirects->update( $id, $row ) ) {
				return new WP_Error( 'r404_db', __( 'No se ha podido guardar la redirección.', 'redirecciones-404' ) );
			}
		} else {
			$id = $this->redirects->create( $row );
			if ( 0 === $id ) {
				return new WP_Error( 'r404_db', __( 'No se ha podido guardar la redirección.', 'redirecciones-404' ) );
			}
		}

		// Los 404 que la nueva regla ya resuelve dejan de ser pendientes.
		$cleared = $data['is_wildcard'] ? $this->log->delete_matching_wildcard( $data['source'] ) : $this->log->delete_url( $data['source'] );

		return $data + array(
			'id'      => $id,
			'cleared' => $cleared,
			'updated' => $is_update,
		);
	}

	/**
	 * Comprueba bucles (error) y cadenas de redirecciones (aviso).
	 *
	 * Una cadena A → B → C funciona, pero cada salto resta velocidad y los
	 * buscadores pueden dejar de seguirla: conviene apuntar directamente a C.
	 *
	 * @param array{id: int, source: string, target: string, type: int, is_wildcard: bool} $rule Regla candidata.
	 * @return list<string>|WP_Error Avisos o error si hay bucle.
	 */
	private function check_chain( array $rule ): array|WP_Error {
		$rules = array_filter(
			$this->redirects->get_rules(),
			static fn( array $existing ): bool => (int) $existing['id'] !== $rule['id']
		);

		$rules[] = $rule;
		$matcher = new Rule_Matcher( $rules, $this->home_url );
		$start   = $rule['is_wildcard'] ? Rule_Matcher::sample_path( $rule['source'] ) : $rule['source'];
		$result  = $matcher->follow( $start );

		if ( $result['loop'] || $result['too_long'] ) {
			return new WP_Error(
				'r404_loop',
				sprintf(
					/* translators: %s: cadena de URL separadas por flechas. */
					__( 'Esta redirección crearía un bucle: %s', 'redirecciones-404' ),
					implode( ' → ', array_slice( $result['hops'], 0, 6 ) ) . ( count( $result['hops'] ) > 6 ? ' → …' : '' )
				)
			);
		}

		$warnings = array();

		if ( count( $result['hops'] ) > 2 ) {
			$warnings[] = sprintf(
				/* translators: %s: cadena de URL separadas por flechas. */
				__( 'El destino es a su vez el origen de otra redirección, así que se forma una cadena: %s. Para el SEO es mejor apuntar directamente al destino final.', 'redirecciones-404' ),
				implode( ' → ', $result['hops'] )
			);
		}

		// También avisa si otras reglas ya apuntaban a este origen.
		if ( ! $rule['is_wildcard'] ) {
			$needle = basename( $rule['source'] );

			foreach ( $rules as $other ) {
				// Filtro barato antes de analizar la URL: importa miles de filas.
				if ( (int) $other['id'] === $rule['id'] || ! empty( $other['is_wildcard'] ) || false === stripos( rawurldecode( (string) $other['target'] ), $needle ) ) {
					continue;
				}

				if ( Url::internal_path( (string) $other['target'], $this->home_url ) === $rule['source'] ) {
					$warnings[] = sprintf(
						/* translators: 1: origen de la otra regla, 2: origen de esta regla. */
						__( 'La redirección de %1$s apunta a %2$s, que ahora también redirige: revísala para evitar una cadena.', 'redirecciones-404' ),
						(string) $other['source'],
						$rule['source']
					);
				}
			}
		}

		return $warnings;
	}
}
