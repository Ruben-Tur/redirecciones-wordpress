<?php
/**
 * Utilidades de URL: normalización, validación de destinos y filtros de ruido.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Funciones puras (o casi) sobre URL. Se mantienen sin estado para poder
 * probarlas de forma aislada.
 */
final class Url {

	/**
	 * Longitud máxima que se guarda de una URL (coincide con las columnas).
	 */
	public const MAX_LENGTH = 2048;

	/**
	 * Extensiones de recursos estáticos que no merece la pena registrar:
	 * suelen ser temas o plugins antiguos pidiendo archivos que ya no existen.
	 * Los PDF y documentos sí se registran porque son contenido.
	 */
	private const IGNORED_EXTENSIONS = array( 'css', 'js', 'mjs', 'map', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'ico', 'scss', 'less' );

	/**
	 * Rutas típicas de escáneres automáticos. Registrarlas solo llenaría la tabla.
	 */
	private const NOISE_PATTERNS = array(
		'#^/\.(env|git|svn|hg|ds_store|aws|ssh|vscode|idea)(/|$)#',
		'#/(wp-config|configuration|config|settings|database|db|backup|dump)\.(php|bak|old|sql|txt|zip|tar|gz|swp|save|orig|inc)(~)?$#',
		'#\.(sql|bak|old|swp|tar|gz|tgz|rar|7z|log|ini|sh|asp|aspx|jsp|cgi)$#',
		'#^/(phpmyadmin|pma|myadmin|mysql|adminer|cgi-bin|vendor|node_modules|actuator|autodiscover|owa|ecp|boaform|hudson|solr|telescope|_profiler|_ignition)(/|$)#',
		'#^/(wp-admin|wp-includes)/#',
		'#^/wp-content/.+\.php$#',
		'#^/(xmlrpc|wp-login|wp-signup|wp-trackback|eval-stdin|shell|cmd|info|phpinfo|test|setup|install)\.php$#',
		'#^/\.well-known/#',
		'#^/(favicon\.ico|apple-touch-icon[^/]*\.png|browserconfig\.xml|crossdomain\.xml|sitemap\.xml\.gz)$#',
	);

	/**
	 * Fragmentos de user agent de herramientas de escaneo conocidas. Los
	 * buscadores (Googlebot, Bingbot…) no están aquí a propósito: sus 404
	 * son justo los que más interesa arreglar.
	 */
	private const NOISE_USER_AGENTS = array( 'zgrab', 'masscan', 'nikto', 'sqlmap', 'wpscan', 'nmap', 'nuclei', 'python-requests', 'go-http-client', 'libwww-perl', 'censysinspect', 'httpx - open-source' );

	/**
	 * Normaliza una URL o ruta para compararla y guardarla siempre igual.
	 *
	 * - Acepta URL completas (se queda con la ruta) o rutas con consulta.
	 * - Descarta la consulta y el fragmento: no aportan al registro y pueden
	 *   contener datos personales (correos, tokens…).
	 * - Decodifica `%xx`, quita caracteres de control y barras repetidas.
	 * - Quita la ruta base de la instalación (webs en subcarpeta).
	 * - Minúsculas y sin barra final, salvo la portada `/`.
	 *
	 * @param string $url       URL o ruta tal como llega.
	 * @param string $base_path Ruta de la instalación (`/` o `/blog`).
	 */
	public static function normalize_path( string $url, string $base_path = '' ): string {
		$url = trim( $url );

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		} else {
			$path = substr( $url, 0, strcspn( $url, '?#' ) );
		}

		$decoded = rawurldecode( $path );
		// Si al decodificar sale UTF-8 inválido, mejor conservar la forma codificada.
		$path = mb_check_encoding( $decoded, 'UTF-8' ) ? $decoded : $path;
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $path );
		$path = str_replace( '\\', '/', $path );
		$path = (string) preg_replace( '#/{2,}#', '/', '/' . $path );

		$base_path = rtrim( $base_path, '/' );
		if ( '' !== $base_path ) {
			$base_length = strlen( $base_path );
			$next_char   = substr( $path, $base_length, 1 );
			if ( 0 === strncasecmp( $path, $base_path, $base_length ) && ( '' === $next_char || '/' === $next_char ) ) {
				$path = '/' . ltrim( substr( $path, $base_length ), '/' );
			}
		}

		$path = mb_strtolower( $path, 'UTF-8' );

		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return mb_substr( '' === $path ? '/' : $path, 0, self::MAX_LENGTH, 'UTF-8' );
	}

	/**
	 * Devuelve la consulta (sin `?`) de una URL o ruta, o una cadena vacía.
	 *
	 * @param string $url URL o ruta.
	 */
	public static function query( string $url ): string {
		$url = substr( $url, 0, strcspn( $url, '#' ) );
		$pos = strpos( $url, '?' );

		return false === $pos ? '' : substr( $url, $pos + 1 );
	}

	/**
	 * Indica si una ruta normalizada es ruido que no se debe registrar.
	 *
	 * @param string $path Ruta normalizada.
	 */
	public static function is_noise( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' !== $extension && in_array( $extension, self::IGNORED_EXTENSIONS, true ) ) {
			return true;
		}

		foreach ( self::NOISE_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Indica si el user agent es de un escáner conocido.
	 *
	 * @param string $user_agent Cabecera User-Agent.
	 */
	public static function is_noise_user_agent( string $user_agent ): bool {
		$user_agent = strtolower( $user_agent );

		foreach ( self::NOISE_USER_AGENTS as $needle ) {
			if ( str_contains( $user_agent, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rutas de WordPress que nunca se redirigen ni se aceptan como origen:
	 * escritorio, acceso, API REST, cron y XML-RPC.
	 *
	 * @param string $path        Ruta normalizada.
	 * @param string $rest_prefix Prefijo de la API REST (por defecto `wp-json`).
	 */
	public static function is_system_path( string $path, string $rest_prefix = 'wp-json' ): bool {
		$rest_prefix = trim( strtolower( $rest_prefix ), '/' );

		return 1 === preg_match( '#^/(wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-signup\.php|wp-activate\.php|wp-comments-post\.php|' . preg_quote( $rest_prefix, '#' ) . ')(/|$)#', $path );
	}

	/**
	 * Limpia y valida un destino escrito por el administrador.
	 *
	 * Solo se admiten rutas internas (`/algo`) o URL absolutas `http(s)://`.
	 * Cualquier otro esquema (`javascript:`, `data:`…) o URL relativa al
	 * protocolo (`//otro-dominio`) se rechaza devolviendo una cadena vacía.
	 *
	 * @param string $target Destino sin validar.
	 */
	public static function sanitize_target( string $target ): string {
		$target = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $target ) );

		if ( '' === $target || strlen( $target ) > self::MAX_LENGTH || preg_match( '/\s/', $target ) ) {
			return '';
		}

		if ( str_starts_with( $target, '/' ) ) {
			if ( str_starts_with( $target, '//' ) || str_contains( $target, '\\' ) ) {
				return '';
			}
			$clean = esc_url_raw( $target );
			return str_starts_with( $clean, '/' ) ? $clean : '';
		}

		if ( ! preg_match( '#^https?://#i', $target ) ) {
			return '';
		}

		$host = wp_parse_url( $target, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host || str_contains( $target, '\\' ) ) {
			return '';
		}

		return esc_url_raw( $target, array( 'http', 'https' ) );
	}

	/**
	 * Si el destino es de esta web, devuelve su ruta normalizada; si es
	 * externo, null.
	 *
	 * @param string $target   Destino ya validado (ruta o URL absoluta).
	 * @param string $home_url URL de la portada.
	 */
	public static function internal_path( string $target, string $home_url ): ?string {
		$home_path = (string) wp_parse_url( $home_url, PHP_URL_PATH );

		if ( str_starts_with( $target, '/' ) ) {
			return self::normalize_path( $target );
		}

		$host      = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( $home_url, PHP_URL_HOST ) );

		if ( '' === $host || $host !== $home_host ) {
			return null;
		}

		return self::normalize_path( $target, $home_path );
	}

	/**
	 * Construye la URL final a la que se envía al visitante.
	 *
	 * @param string $target   Destino de la regla (ya con `$1` sustituidos).
	 * @param string $home_url URL de la portada (sin barra final).
	 * @param string $query    Consulta original a conservar, sin `?`.
	 */
	public static function to_location( string $target, string $home_url, string $query = '' ): string {
		if ( str_starts_with( $target, '/' ) ) {
			// Una captura que empiece por barra no debe convertirse en `//otro-dominio`.
			$location = untrailingslashit( $home_url ) . '/' . ltrim( $target, '/' );
		} else {
			$location = $target;
		}

		if ( '' !== $query && ! str_contains( $location, '?' ) ) {
			$location .= '?' . $query;
		}

		return $location;
	}

	/**
	 * Reduce la procedencia a esquema, dominio y ruta. Sin consulta ni
	 * fragmento, que es donde suelen ir correos, tokens o identificadores.
	 *
	 * @param string $referrer Cabecera Referer.
	 */
	public static function sanitize_referrer( string $referrer ): string {
		$parts = wp_parse_url( trim( $referrer ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		$clean = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$clean .= ':' . (int) $parts['port'];
		}
		$clean .= $parts['path'] ?? '/';

		return mb_substr( esc_url_raw( $clean, array( 'http', 'https' ) ), 0, self::MAX_LENGTH, 'UTF-8' );
	}

	/**
	 * Ruta de la instalación según la URL de la portada (`''` en la raíz).
	 */
	public static function home_path(): string {
		return untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	}
}
