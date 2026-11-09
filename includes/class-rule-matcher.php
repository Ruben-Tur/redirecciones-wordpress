<?php
/**
 * Resolución de reglas de redirección: exactas y con comodines.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Busca la regla que corresponde a una ruta.
 *
 * Las reglas exactas se buscan en un índice (coste constante); las de
 * comodín se prueban de la más específica a la más general, así
 * `/blog/2024/*` gana a `/blog/*` aunque se haya creado después.
 */
final class Rule_Matcher {

	/**
	 * Máximo de saltos que se siguen al buscar bucles y cadenas.
	 */
	public const MAX_HOPS = 10;

	/**
	 * Reglas exactas indexadas por origen.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $exact = array();

	/**
	 * Reglas con comodín, ya ordenadas y con su expresión regular.
	 *
	 * @var list<array{regex: string, rule: array<string, mixed>}>
	 */
	private array $wildcards = array();

	/**
	 * Crea el buscador a partir de las reglas guardadas.
	 *
	 * @param array<int, array<string, mixed>> $rules    Reglas con `source`, `target`, `type` e `is_wildcard`.
	 * @param string                           $home_url URL de la portada, para saber qué destinos son internos.
	 */
	public function __construct( array $rules, private readonly string $home_url ) {
		foreach ( $rules as $rule ) {
			$source = (string) $rule['source'];

			if ( ! empty( $rule['is_wildcard'] ) ) {
				$this->wildcards[] = array(
					'regex' => self::wildcard_regex( $source ),
					'rule'  => $rule,
				);
			} else {
				$this->exact[ $source ] = $rule;
			}
		}

		usort(
			$this->wildcards,
			static function ( array $a, array $b ): int {
				// Más texto fijo = regla más específica.
				$fixed = strlen( str_replace( '*', '', (string) $b['rule']['source'] ) ) <=> strlen( str_replace( '*', '', (string) $a['rule']['source'] ) );
				return 0 !== $fixed ? $fixed : strcmp( (string) $a['rule']['source'], (string) $b['rule']['source'] );
			}
		);
	}

	/**
	 * Convierte un origen con `*` en una expresión regular.
	 *
	 * Cada `*` captura cualquier cosa (también barras) y se puede usar en el
	 * destino como `$1`, `$2`… Un `/*` final es opcional, para que
	 * `/blog/*` cubra también `/blog`.
	 *
	 * @param string $source Origen normalizado.
	 */
	public static function wildcard_regex( string $source ): string {
		$optional_tail = str_ends_with( $source, '/*' ) && strlen( $source ) > 2;
		$body          = $optional_tail ? substr( $source, 0, -2 ) : $source;
		$pieces        = array_map( static fn( string $piece ): string => preg_quote( $piece, '#' ), explode( '*', $body ) );
		$regex         = implode( '(.*)', $pieces );

		if ( $optional_tail ) {
			$regex .= '(?:/(.*))?';
		}

		return '#^' . $regex . '$#u';
	}

	/**
	 * Sustituye `$1`…`$9` del destino por los fragmentos capturados.
	 *
	 * Las capturas salen de la ruta ya decodificada, así que se vuelven a
	 * codificar (salvo `/` y `%`): un `%20`, `%3F` o `%23` de la URL pedida no
	 * debe convertirse en un espacio perdido ni en una consulta o un fragmento
	 * nuevos en el destino.
	 *
	 * @param string            $target   Destino de la regla.
	 * @param array<int,string> $captures Capturas de preg_match (índice 0 = coincidencia completa).
	 */
	public static function apply_captures( string $target, array $captures ): string {
		return (string) preg_replace_callback(
			'/\$(\d)/',
			static fn( array $m ): string => str_replace( array( '%2F', '%25' ), array( '/', '%' ), rawurlencode( (string) ( $captures[ (int) $m[1] ] ?? '' ) ) ),
			$target
		);
	}

	/**
	 * Busca la regla para una ruta normalizada.
	 *
	 * @param string $path Ruta normalizada.
	 * @return array{rule: array<string, mixed>, target: string}|null
	 */
	public function match( string $path ): ?array {
		if ( isset( $this->exact[ $path ] ) ) {
			return array(
				'rule'   => $this->exact[ $path ],
				'target' => (string) $this->exact[ $path ]['target'],
			);
		}

		foreach ( $this->wildcards as $wildcard ) {
			if ( preg_match( $wildcard['regex'], $path, $captures ) ) {
				return array(
					'rule'   => $wildcard['rule'],
					'target' => self::apply_captures( (string) $wildcard['rule']['target'], $captures ),
				);
			}
		}

		return null;
	}

	/**
	 * Sigue las redirecciones desde una ruta y devuelve los saltos.
	 *
	 * @param string $start Ruta normalizada de partida.
	 * @return array{hops: list<string>, loop: bool, too_long: bool}
	 */
	public function follow( string $start ): array {
		$hops    = array( $start );
		$current = $start;

		for ( $i = 0; $i < self::MAX_HOPS; $i++ ) {
			$match = $this->match( $current );
			if ( null === $match ) {
				break;
			}

			$next = Url::internal_path( $match['target'], $this->home_url );
			if ( null === $next ) {
				$hops[] = $match['target'];
				break;
			}

			if ( in_array( $next, $hops, true ) ) {
				$hops[] = $next;
				return array(
					'hops'     => $hops,
					'loop'     => true,
					'too_long' => false,
				);
			}

			$hops[]  = $next;
			$current = $next;
		}

		return array(
			'hops'     => $hops,
			'loop'     => false,
			'too_long' => count( $hops ) > self::MAX_HOPS,
		);
	}

	/**
	 * Ruta de ejemplo que encaja con un origen (los `*` se sustituyen por
	 * un texto fijo), útil para comprobar bucles en reglas con comodín.
	 *
	 * @param string $source Origen normalizado.
	 */
	public static function sample_path( string $source ): string {
		return str_replace( '*', 'ejemplo', $source );
	}
}
