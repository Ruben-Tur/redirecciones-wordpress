<?php
/**
 * Sugerencias de destino para un 404.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Busca una entrada publicada con un slug parecido al último tramo de la
 * URL rota, como hace WordPress con redirect_guess_404_permalink(), para
 * que en la mayoría de los casos baste con pulsar «Redirigir 301».
 */
final class Suggester {

	/**
	 * Sugerencias ya calculadas en esta petición.
	 *
	 * @var array<string, array{url: string, title: string}|null>
	 */
	private array $cache = array();

	/**
	 * Devuelve la ruta y el título de la entrada sugerida, o null.
	 *
	 * @param string $path Ruta normalizada del 404.
	 * @return array{url: string, title: string}|null
	 */
	public function suggest( string $path ): ?array {
		if ( array_key_exists( $path, $this->cache ) ) {
			return $this->cache[ $path ];
		}

		$slug = sanitize_title( pathinfo( basename( $path ), PATHINFO_FILENAME ) );
		$post = null;

		if ( strlen( $slug ) >= 3 ) {
			// De más a menos exacto. WordPress ya prueba el prefijo por su
			// cuenta (redirect_guess_404_permalink() redirige sin dejar 404),
			// así que lo que llega al registro suele necesitar los dos últimos.
			$base     = (string) preg_replace( '/(-\d+)+$/', '', $slug );
			$attempts = array(
				array( $slug, 'exact' ),
				array( $slug, 'prefix' ),
				// «oferta-verano-2024» → «oferta-verano…», por si hay una edición más nueva.
				array( $base, $base !== $slug && strlen( $base ) >= 3 ? 'prefix' : '' ),
				// «silla-roble» → «silla-de-roble»: las mismas palabras en el mismo orden.
				array( $base, str_contains( $base, '-' ) ? 'words' : '' ),
			);

			foreach ( $attempts as [ $candidate, $mode ] ) {
				$post = '' === $mode ? null : $this->find_post( $candidate, $mode );
				if ( null !== $post ) {
					break;
				}
			}
		}

		$suggestion = null;
		if ( null !== $post ) {
			$url = wp_make_link_relative( (string) get_permalink( $post ) );
			if ( '' !== $url && Url::normalize_path( $url, Url::home_path() ) !== $path ) {
				$suggestion = array(
					'url'   => $url,
					'title' => get_the_title( $post ),
				);
			}
		}

		$this->cache[ $path ] = $suggestion;
		return $suggestion;
	}

	/**
	 * Busca una entrada pública por slug.
	 *
	 * Solo se usa al pintar el listado de 404 del escritorio (como mucho
	 * cuatro consultas por fila y con caché por petición), nunca en el front-end.
	 *
	 * @param string $slug Slug.
	 * @param string $mode exact (`slug`), prefix (`slug%`) o words (`%palabra%palabra%`).
	 */
	private function find_post( string $slug, string $mode ): ?\WP_Post {
		global $wpdb;

		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		if ( array() === $types ) {
			return null;
		}

		$type_placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$name_clause       = 'exact' === $mode ? 'post_name = %s' : 'post_name LIKE %s';
		$name_value        = match ( $mode ) {
			'exact'  => $slug,
			'prefix' => $wpdb->esc_like( $slug ) . '%',
			default  => '%' . implode( '%', array_map( array( $wpdb, 'esc_like' ), explode( '-', $slug ) ) ) . '%',
		};

		// Consulta directa y acotada: WP_Query no permite buscar slugs con LIKE.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE $name_clause AND post_status = 'publish' AND post_type IN ($type_placeholders) ORDER BY post_date DESC LIMIT 1",
				$name_value,
				...$types
			)
		);
		// phpcs:enable

		return $id ? get_post( (int) $id ) : null;
	}
}
