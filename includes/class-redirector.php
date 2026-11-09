<?php
/**
 * Servidor de redirecciones.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve las redirecciones en `plugins_loaded`, antes de que WordPress
 * analice la consulta, cargue el tema o pinte nada. Las reglas salen de
 * una opción autocargada, así que una visita sin redirección no cuesta
 * ninguna consulta adicional.
 */
final class Redirector {

	/**
	 * Valor de la cabecera X-Redirect-By.
	 */
	private const REDIRECT_BY = 'Redirecciones 404';

	/**
	 * Archivos de WordPress cuyas peticiones nunca se redirigen.
	 */
	private const SYSTEM_SCRIPTS = array( 'wp-login.php', 'wp-signup.php', 'wp-activate.php', 'wp-comments-post.php', 'wp-trackback.php', 'xmlrpc.php', 'wp-cron.php' );

	/**
	 * Constructor.
	 *
	 * @param Redirect_Repository $redirects Repositorio de redirecciones.
	 */
	public function __construct( private readonly Redirect_Repository $redirects ) {}

	/**
	 * Engancha la resolución.
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirige la petición actual si alguna regla coincide.
	 */
	public function maybe_redirect(): void {
		$location = $this->resolve_current_request();

		if ( null === $location ) {
			return;
		}

		$allow_host = null;
		if ( $location['external'] ) {
			// Permiso explícito solo para el dominio de esta regla (resolve() ya
			// ha comprobado que coincide con el de la URL final).
			$host       = (string) wp_parse_url( $location['url'], PHP_URL_HOST );
			$allow_host = static fn( array $hosts ): array => array_merge( $hosts, array( $host ) );
			add_filter( 'allowed_redirect_hosts', $allow_host );
		}

		// La visita solo se cuenta si la redirección se sirve de verdad.
		if ( '' !== wp_validate_redirect( $location['url'], '' ) ) {
			$this->redirects->increment_hits( $location['id'] );

			if ( wp_safe_redirect( $location['url'], $location['type'], self::REDIRECT_BY ) ) {
				exit;
			}
		}

		// Si no se ha redirigido, el dominio externo no queda permitido para
		// otras redirecciones de esta petición.
		if ( null !== $allow_host ) {
			remove_filter( 'allowed_redirect_hosts', $allow_host );
		}
	}

	/**
	 * Calcula a dónde habría que redirigir la petición actual.
	 *
	 * @return array{id: int, url: string, type: int, external: bool}|null
	 */
	public function resolve_current_request(): ?array {
		global $pagenow;

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! Installer::is_installed() ) {
			return null;
		}

		// Con WordPress en su propia carpeta (p. ej. /wp/wp-login.php) estas
		// rutas no empiezan como espera Url::is_system_path(): se descartan
		// por el archivo que atiende la petición.
		if ( in_array( $pagenow ?? '', self::SYSTEM_SCRIPTS, true ) ) {
			return null;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// Solo GET y HEAD: redirigir un POST perdería los datos del formulario.
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo se comprueba si existe.
			return null;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Se normaliza abajo.

		return $this->resolve( $uri, home_url(), (bool) Settings::get( 'keep_query' ) );
	}

	/**
	 * Calcula la redirección de una URI concreta.
	 *
	 * @param string $uri        URI de la petición (ruta y consulta).
	 * @param string $home_url   URL de la portada.
	 * @param bool   $keep_query Si se conserva la consulta original.
	 * @return array{id: int, url: string, type: int, external: bool}|null
	 */
	public function resolve( string $uri, string $home_url, bool $keep_query ): ?array {
		$home_path = untrailingslashit( (string) wp_parse_url( $home_url, PHP_URL_PATH ) );
		$path      = Url::normalize_path( $uri, $home_path );

		if ( Url::is_system_path( $path, rest_get_url_prefix() ) ) {
			return null;
		}

		$rules = $this->redirects->get_rules();
		if ( array() === $rules ) {
			return null;
		}

		$match = ( new Rule_Matcher( $rules, $home_url ) )->match( $path );
		if ( null === $match ) {
			return null;
		}

		$internal = Url::internal_path( $match['target'], $home_url );

		// Última defensa contra bucles: nunca se redirige una URL a sí misma.
		if ( $internal === $path ) {
			return null;
		}

		// Un destino externo solo puede llevar al dominio escrito en la regla:
		// un comodín ($1) nunca debe poder cambiarlo (`https://web.es$1` con
		// `@otro.com` saldría hacia otro.com).
		if ( null === $internal && ! self::same_host( $match['target'], (string) $match['rule']['target'] ) ) {
			return null;
		}

		return array(
			'id'       => (int) $match['rule']['id'],
			'url'      => Url::to_location( $match['target'], $home_url, $keep_query ? Url::query( $uri ) : '' ),
			'type'     => (int) $match['rule']['type'],
			'external' => null === $internal,
		);
	}

	/**
	 * Indica si el destino final conserva el dominio del destino de la regla.
	 *
	 * @param string $resolved Destino con los comodines ya sustituidos.
	 * @param string $original Destino tal como está guardado en la regla.
	 */
	private static function same_host( string $resolved, string $original ): bool {
		$host = strtolower( (string) wp_parse_url( $original, PHP_URL_HOST ) );

		return '' !== $host && ! str_contains( $host, '$' ) && strtolower( (string) wp_parse_url( $resolved, PHP_URL_HOST ) ) === $host;
	}
}
