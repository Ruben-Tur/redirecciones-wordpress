<?php
/**
 * Pruebas del plugin, pensadas para ejecutarse con WordPress Playground.
 *
 * Carga WordPress, activa el plugin en una instalación desechable (SQLite)
 * y comprueba la lógica principal: normalización de URL, comodines,
 * bucles, CSV, registro de 404, redirecciones, limpieza y desinstalación.
 *
 * BORRA LOS DATOS DEL PLUGIN: por eso exige la constante R404_TESTS, que
 * solo se define al lanzarlo con Playground (ver README).
 *
 * Uso (desde la carpeta del plugin, en PowerShell):
 *   wp-playground-cli.cmd php --define-bool R404_TESTS true --mount-dir . /wordpress/wp-content/plugins/redirecciones-404 -- /wordpress/wp-content/plugins/redirecciones-404/tests/run-tests.php
 *
 * @package RubenTur\Redirecciones404
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions -- Script de consola.

if ( 'cli' !== PHP_SAPI || ! defined( 'R404_TESTS' ) || true !== R404_TESTS ) {
	fwrite( STDERR, "Estas pruebas borran datos. Lánzalas con Playground y --define-bool R404_TESTS true.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';

require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use RubenTur\Redirecciones404\Cleanup;
use RubenTur\Redirecciones404\Csv;
use RubenTur\Redirecciones404\Installer;
use RubenTur\Redirecciones404\Log_Repository;
use RubenTur\Redirecciones404\Logger;
use RubenTur\Redirecciones404\Redirect_Repository;
use RubenTur\Redirecciones404\Redirect_Service;
use RubenTur\Redirecciones404\Redirector;
use RubenTur\Redirecciones404\Rule_Matcher;
use RubenTur\Redirecciones404\Settings;
use RubenTur\Redirecciones404\Url;

/**
 * Mini marco de pruebas: secciones, comprobaciones y resumen.
 */
final class R404_Test {

	public static int $passed = 0;

	/**
	 * Fallos anotados.
	 *
	 * @var list<string>
	 */
	public static array $failures = array();

	public static function section( string $title ): void {
		echo "\n== {$title}\n";
	}

	public static function same( mixed $expected, mixed $actual, string $label ): void {
		if ( $expected === $actual ) {
			++self::$passed;
			echo "  ok     {$label}\n";
			return;
		}

		self::$failures[] = $label;
		echo "  FALLO  {$label}\n         esperado: " . var_export( $expected, true ) . "\n         obtenido: " . var_export( $actual, true ) . "\n";
	}

	public static function true( bool $condition, string $label ): void {
		self::same( true, $condition, $label );
	}
}

$t = R404_Test::class;

// Instalación limpia ---------------------------------------------------------

$plugin = 'redirecciones-404/redirecciones-404.php';
$result = activate_plugin( $plugin );
$t::section( 'Activación' );
$t::true( null === $result, 'activate_plugin() sin errores' );

global $wpdb;
Installer::uninstall();
Installer::activate();

$tables = static fn(): array => array(
	(string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Log_Repository::table() ) ),
	(string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Redirect_Repository::table() ) ),
);
$t::same( array( Log_Repository::table(), Redirect_Repository::table() ), $tables(), 'dbDelta crea las dos tablas' );
$t::same( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ), 'guarda la versión del esquema' );
$t::true( false !== wp_next_scheduled( Cleanup::HOOK ), 'programa la limpieza diaria en WP-Cron' );
$t::same( 'daily', wp_get_schedule( Cleanup::HOOK ), 'la limpieza es diaria' );
Cleanup::unschedule();
Installer::maybe_upgrade();
$t::true( false !== wp_next_scheduled( Cleanup::HOOK ), 'maybe_upgrade() recupera la limpieza si el evento se ha perdido' );

// Normalización de URL --------------------------------------------------------

$t::section( 'Url::normalize_path' );
$cases = array(
	array( '/Blog/Mi-Articulo/', '', '/blog/mi-articulo' ),
	array( '/contacto-old?utm_source=x&email=ana@example.com#form', '', '/contacto-old' ),
	array( 'https://miweb.es/producto/silla-roble/', '', '/producto/silla-roble' ),
	array( '//doble//barra///', '', '/doble/barra' ),
	array( '/servicios/dise%C3%B1o', '', '/servicios/diseño' ),
	array( '/invalido/%FF%FE', '', '/invalido/%ff%fe' ),
	array( "/nulo%00/x", '', '/nulo/x' ),
	array( '/sub/blog/entrada', '/sub', '/blog/entrada' ),
	array( '/subcarpeta/blog', '/sub', '/subcarpeta/blog' ),
	array( '/sub', '/sub', '/' ),
	array( '', '', '/' ),
	array( '?solo=consulta', '', '/' ),
	array( '/blog/*', '', '/blog/*' ),
);
foreach ( $cases as [ $input, $base, $expected ] ) {
	$t::same( $expected, Url::normalize_path( $input, $base ), sprintf( '%s (base «%s») → %s', $input, $base, $expected ) );
}
$t::same( 2048, mb_strlen( Url::normalize_path( '/' . str_repeat( 'a', 5000 ) ) ), 'recorta a 2048 caracteres' );
$t::same( 'a=1&b=2', Url::query( '/x?a=1&b=2#frag' ), 'Url::query extrae la consulta sin fragmento' );
$t::same( '', Url::query( '/x#a?b' ), 'Url::query ignora ? dentro del fragmento' );

$t::section( 'Ruido y rutas de sistema' );
foreach ( array( '/wp-content/themes/viejo/style.css', '/.env', '/.git/config', '/wp-config.php.bak', '/phpmyadmin/index.php', '/wp-content/plugins/x/shell.php', '/favicon.ico', '/backup.sql', '/.well-known/security.txt' ) as $noise ) {
	$t::true( Url::is_noise( $noise ), "ruido: {$noise}" );
}
foreach ( array( '/blog/oferta-verano-2024', '/wp-content/catalogo.pdf', '/producto/silla-roble', '/tienda/' ) as $real ) {
	$t::true( ! Url::is_noise( $real ), "no es ruido: {$real}" );
}
$t::true( Url::is_noise_user_agent( 'Mozilla/5.0 zgrab/0.x' ), 'user agent de escáner' );
$t::true( ! Url::is_noise_user_agent( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ), 'Googlebot sí se registra' );
foreach ( array( '/wp-admin', '/wp-admin/options.php', '/wp-login.php', '/wp-json/wp/v2/posts', '/wp-cron.php', '/xmlrpc.php' ) as $system ) {
	$t::true( Url::is_system_path( $system ), "ruta de sistema: {$system}" );
}
$t::true( ! Url::is_system_path( '/wp-jsonp-antiguo' ), 'no confunde /wp-jsonp-antiguo con la API REST' );
$t::true( Url::is_system_path( '/api/x', 'api' ), 'respeta un prefijo REST personalizado' );

$t::section( 'Url::sanitize_target (destinos seguros)' );
$targets = array(
	'/noticias/'                          => '/noticias/',
	'/noticias/$1'                        => '/noticias/$1',
	'https://tienda.example.com/silla'    => 'https://tienda.example.com/silla',
	'  /con-espacios-alrededor  '         => '/con-espacios-alrededor',
	'javascript:alert(1)'                 => '',
	'JaVaScRiPt:alert(1)'                 => '',
	'data:text/html;base64,PHNjcmlwdD4='  => '',
	'//evil.example.com/'                 => '',
	'/\\evil.example.com'                 => '',
	'ftp://example.com/archivo'           => '',
	'pagina-sin-barra'                    => '',
	'/con espacio'                        => '',
	"/salto\r\nSet-Cookie:x"              => '/saltoSet-Cookie:x',
	'https://'                            => '',
);
foreach ( $targets as $input => $expected ) {
	$t::same( $expected, Url::sanitize_target( (string) $input ), 'destino ' . addcslashes( (string) $input, "\r\n" ) );
}

$t::section( 'Privacidad de la procedencia' );
$t::same( 'https://www.google.com/', Url::sanitize_referrer( 'https://www.google.com/' ), 'conserva dominio' );
$t::same( 'https://foro.example.com/hilo/123', Url::sanitize_referrer( 'https://Foro.Example.com/hilo/123?email=ana@example.com&token=abc#c' ), 'quita consulta y fragmento (correos, tokens)' );
$t::same( '', Url::sanitize_referrer( 'javascript:alert(1)' ), 'descarta esquemas no http' );
$t::same( '', Url::sanitize_referrer( 'no es una url' ), 'descarta basura' );

$t::section( 'Destinos internos y externos' );
$home = 'https://miweb.es';
$t::same( '/nueva', Url::internal_path( '/Nueva/', $home ), 'ruta relativa → interna' );
$t::same( '/nueva', Url::internal_path( 'https://MIWEB.es/nueva', $home ), 'mismo dominio → interna' );
$t::same( null, Url::internal_path( 'https://otra.es/nueva', $home ), 'otro dominio → externa' );
$t::same( '/nueva', Url::internal_path( 'https://miweb.es/sub/nueva', 'https://miweb.es/sub' ), 'quita la subcarpeta de la instalación' );
$t::same( 'https://miweb.es/nueva?utm=x', Url::to_location( '/nueva', $home, 'utm=x' ), 'conserva la consulta' );
$t::same( 'https://miweb.es/nueva?a=1', Url::to_location( '/nueva?a=1', $home, 'utm=x' ), 'no pisa la consulta del destino' );
$t::same( 'https://miweb.es/evil.com', Url::to_location( '//evil.com', $home ), 'una captura con // no sale del dominio' );
$t::same( 'https://miweb.es/nueva?utm=x#seccion', Url::to_location( '/nueva#seccion', $home, 'utm=x' ), 'la consulta va antes del fragmento' );

// Comodines -----------------------------------------------------------------

$t::section( 'Rule_Matcher' );
$rules   = array(
	array( 'id' => 1, 'source' => '/contacto-old', 'target' => '/contacto/', 'type' => 301, 'is_wildcard' => false ),
	array( 'id' => 2, 'source' => '/blog/*', 'target' => '/noticias/$1', 'type' => 301, 'is_wildcard' => true ),
	array( 'id' => 3, 'source' => '/blog/2019/*', 'target' => '/archivo/2019/$1', 'type' => 301, 'is_wildcard' => true ),
	array( 'id' => 4, 'source' => '/tienda/*/talla-*', 'target' => '/productos/$1?talla=$2', 'type' => 302, 'is_wildcard' => true ),
	array( 'id' => 5, 'source' => '/blog/especial', 'target' => '/especial/', 'type' => 301, 'is_wildcard' => false ),
);
$matcher = new Rule_Matcher( $rules, $home );
$match   = static fn( string $path ): ?string => $matcher->match( $path )['target'] ?? null;

$t::same( '/contacto/', $match( '/contacto-old' ), 'regla exacta' );
$t::same( '/noticias/mi-articulo', $match( '/blog/mi-articulo' ), '/blog/* → /noticias/$1' );
$t::same( '/noticias/2024/05/entrada', $match( '/blog/2024/05/entrada' ), 'el comodín captura también barras' );
$t::same( '/noticias/', $match( '/blog' ), '/blog/* cubre también /blog' );
$t::same( '/archivo/2019/vieja', $match( '/blog/2019/vieja' ), 'gana la regla con comodín más específica' );
$t::same( '/especial/', $match( '/blog/especial' ), 'la regla exacta gana al comodín' );
$t::same( '/productos/camiseta?talla=xl', $match( '/tienda/camiseta/talla-xl' ), 'varios comodines: $1 y $2' );
$t::same( null, $match( '/blogger' ), 'no confunde /blogger con /blog/*' );
$t::same( null, $match( '/otra' ), 'sin coincidencia → null' );
$t::same( '#^/a\.b\?(.*)$#u', Rule_Matcher::wildcard_regex( '/a.b?*' ), 'escapa los caracteres especiales del origen' );
$t::same( '/x//y/', Rule_Matcher::apply_captures( '/x/$1/$2/$3', array( '', '', 'y' ) ), 'marcadores sin captura quedan vacíos' );
$t::same( '/n/a%20b%3Fc%23d/e', Rule_Matcher::apply_captures( '/n/$1', array( '', 'a b?c#d/e' ) ), 'las capturas se recodifican (sin perder espacios ni crear ? o #) y conservan las barras' );
$t::same( '/n/dise%C3%B1o', Rule_Matcher::apply_captures( '/n/$1', array( '', 'diseño' ) ), 'captura con caracteres no ASCII' );

$t::section( 'Bucles y cadenas' );
$loop = new Rule_Matcher(
	array(
		array( 'id' => 1, 'source' => '/a', 'target' => '/b', 'type' => 301, 'is_wildcard' => false ),
		array( 'id' => 2, 'source' => '/b', 'target' => 'https://miweb.es/a/', 'type' => 301, 'is_wildcard' => false ),
	),
	$home
);
$t::true( $loop->follow( '/a' )['loop'], 'detecta /a → /b → /a (aunque el destino sea absoluto y con barra)' );
$grow = new Rule_Matcher( array( array( 'id' => 1, 'source' => '/blog/*', 'target' => '/blog/noticias/$1', 'type' => 301, 'is_wildcard' => true ) ), $home );
$t::true( $grow->follow( Rule_Matcher::sample_path( '/blog/*' ) )['too_long'], 'detecta un comodín que se reescribe a sí mismo sin fin' );
$chain = new Rule_Matcher(
	array(
		array( 'id' => 1, 'source' => '/a', 'target' => '/b', 'type' => 301, 'is_wildcard' => false ),
		array( 'id' => 2, 'source' => '/b', 'target' => 'https://otra.es/', 'type' => 301, 'is_wildcard' => false ),
	),
	$home
);
$followed = $chain->follow( '/a' );
$t::same( array( '/a', '/b', 'https://otra.es/' ), $followed['hops'], 'sigue la cadena hasta un destino externo' );
$t::true( ! $followed['loop'] && ! $followed['too_long'], 'una cadena no es un bucle' );

// CSV ---------------------------------------------------------------------------

$t::section( 'Csv::parse' );
$parsed = Csv::parse( "origen,destino,tipo\n/tienda-antigua,/tienda/,301\n/blog/*,/noticias/\$1,\n\n/temporal,/rebajas/,302\n" );
$t::same( 3, count( $parsed['rows'] ), 'cabecera en español, coma, línea vacía ignorada' );
$t::same( array( 'line' => 3, 'source' => '/blog/*', 'target' => '/noticias/$1', 'type' => 301 ), $parsed['rows'][1], 'tipo vacío → 301 y número de línea correcto' );
$t::same( 302, $parsed['rows'][2]['type'], 'respeta el 302' );

$parsed = Csv::parse( "\xEF\xBB\xBFdestination;source\r\n/nuevo;/viejo\r\n" );
$t::same( array( '/viejo', '/nuevo' ), array( $parsed['rows'][0]['source'], $parsed['rows'][0]['target'] ), 'BOM, punto y coma, CRLF y columnas en otro orden' );

$parsed = Csv::parse( "/sin-cabecera,/destino\n\"/con,coma\",\"/destino \"\"citado\"\"\"\n" );
$t::same( 2, count( $parsed['rows'] ), 'sin cabecera y con campos entre comillas' );
$t::same( '/con,coma', $parsed['rows'][1]['source'], 'coma dentro de comillas' );

$parsed = Csv::parse( "origen,destino,tipo\n/a\n/b,/c,307\n,/d\n/ok,/bien\n" );
$t::same( array( 2, 3, 4 ), array_keys( $parsed['errors'] ), 'anota errores por línea (falta destino, tipo 307, falta origen)' );
$t::same( 1, count( $parsed['rows'] ), 'las filas válidas siguen adelante' );

$parsed = Csv::parse( "origen,destino\n/dise\xF1o,/dise\xF1o-web\n" );
$t::same( '/diseño', $parsed['rows'][0]['source'], 'convierte Windows-1252 (Excel) a UTF-8' );

$t::same( 1, count( Csv::parse( '' )['errors'] ), 'archivo vacío → error' );
$big = "origen,destino\n" . str_repeat( "/a,/b\n", Csv::MAX_ROWS + 3 );
$t::same( Csv::MAX_ROWS, count( Csv::parse( $big )['rows'] ), 'limita el número de filas por importación' );

$t::section( 'Protección frente a inyección de fórmulas' );
$t::same( "'=HYPERLINK(\"x\")", Csv::escape_cell( '=HYPERLINK("x")' ), '= se neutraliza' );
$t::same( "'+34", Csv::escape_cell( '+34' ), '+ se neutraliza' );
$t::same( "'-1", Csv::escape_cell( '-1' ), '- se neutraliza' );
$t::same( "'@SUM(A1)", Csv::escape_cell( '@SUM(A1)' ), '@ se neutraliza' );
$t::same( '/normal', Csv::escape_cell( '/normal' ), 'las rutas no cambian' );
$t::same( '=evil', Csv::parse( "origen,destino\n'=evil,/x\n" )['rows'][0]['source'], 'al reimportar se quita el apóstrofo' );
$t::same( array( '/a', '/b', '301', '0', '7' ), Csv::export_row( array( 'source' => '/a', 'target' => '/b', 'type' => 301, 'is_wildcard' => 0, 'hits' => 7 ) ), 'fila de exportación' );

// Servicio de redirecciones (con base de datos) ---------------------------------

$t::section( 'Redirect_Service' );
$log       = new Log_Repository();
$redirects = new Redirect_Repository();
$service   = new Redirect_Service( $redirects, $log, home_url() );

$log->record( '/contacto-old', 'https://www.google.com/' );
$log->record( '/blog/oferta-verano-2024' );
$log->record( '/blog/otra-entrada' );

$saved = $service->save( array( 'source' => '/Contacto-Old/', 'target' => '/contacto/', 'type' => 301 ) );
$t::true( is_array( $saved ), 'crea una redirección válida' );
$t::same( '/contacto-old', $saved['source'] ?? null, 'normaliza el origen' );
$t::same( 1, $saved['cleared'] ?? null, 'quita su 404 del registro' );
$t::same( 1, count( $redirects->get_rules() ), 'la caché de reglas se regenera' );

$t::same( 'r404_duplicate', $service->save( array( 'source' => '/contacto-old', 'target' => '/otra/' ) )->get_error_code(), 'rechaza orígenes duplicados' );
$t::same( 'r404_target_invalid', $service->save( array( 'source' => '/x', 'target' => 'javascript:alert(1)' ) )->get_error_code(), 'rechaza javascript:' );
$t::same( 'r404_source_system', $service->save( array( 'source' => '/wp-login.php', 'target' => '/x' ) )->get_error_code(), 'no deja redirigir wp-login.php' );
$t::same( 'r404_source_system', $service->save( array( 'source' => '/wp-json/*', 'target' => '/x' ) )->get_error_code(), 'no deja redirigir la API REST' );
$t::same( 'r404_source_home', $service->save( array( 'source' => '/', 'target' => '/x' ) )->get_error_code(), 'no deja redirigir la portada' );
$t::same( 'r404_source_broad', $service->save( array( 'source' => '/*', 'target' => '/x' ) )->get_error_code(), 'rechaza /* (toda la web)' );
$t::same( 'r404_target_placeholder', $service->save( array( 'source' => '/a/*', 'target' => '/b/$2' ) )->get_error_code(), 'rechaza $2 con un solo comodín' );
$t::same( 'r404_type', $service->save( array( 'source' => '/a', 'target' => '/b', 'type' => 307 ) )->get_error_code(), 'solo 301 o 302' );
$t::same( 'r404_loop', $service->save( array( 'source' => '/contacto', 'target' => '/contacto-old' ) )->get_error_code(), 'rechaza un bucle con una regla existente' );
$t::same( 'r404_loop', $service->save( array( 'source' => '/misma', 'target' => '/misma/' ) )->get_error_code(), 'rechaza redirigir una URL a sí misma' );
$t::same( 'r404_loop', $service->save( array( 'source' => '/blog/*', 'target' => '/blog/noticias/$1' ) )->get_error_code(), 'rechaza un comodín que se persigue a sí mismo' );

$wild = $service->save( array( 'source' => '/blog/*', 'target' => '/noticias/$1', 'type' => 301 ) );
$t::true( is_array( $wild ) && $wild['is_wildcard'], 'crea la regla con comodín' );
$t::same( 2, $wild['cleared'] ?? null, 'quita del registro los 404 que cubre el comodín' );

$chained = $service->save( array( 'source' => '/promo', 'target' => '/contacto-old' ) );
$t::true( is_array( $chained ) && 1 === count( $chained['warnings'] ), 'avisa de cadena si el destino es otro origen' );
$reverse = $service->save( array( 'source' => '/destino-encadenado', 'target' => '/final' ) );
$pointer = $service->save( array( 'source' => '/apunta', 'target' => '/destino-encadenado' ) );
$t::true( is_array( $pointer ) && count( $pointer['warnings'] ) > 0, 'avisa de cadena hacia un origen existente' );
$t::true( is_array( $reverse ) && array() === $reverse['warnings'], 'sin aviso cuando no hay cadena' );

$edit = $service->save( array( 'source' => '/promo', 'target' => '/contacto/', 'type' => 302 ), (int) $chained['id'] );
$t::true( is_array( $edit ) && $edit['updated'] && array() === $edit['warnings'], 'editar una regla corrige la cadena' );
$t::same( 302, (int) $redirects->find( (int) $chained['id'] )['type'], 'la edición guarda el tipo' );

$external = $service->save( array( 'source' => '/tienda-vieja', 'target' => 'https://tienda.example.com/' ) );
$t::true( is_array( $external ), 'admite destinos externos escritos por el administrador' );

$t::same( 'r404_target_host_placeholder', $service->save( array( 'source' => '/salir/*', 'target' => 'https://$1/' ) )->get_error_code(), 'rechaza un comodín en el dominio externo (https://$1/)' );
$t::same( 'r404_target_host_placeholder', $service->save( array( 'source' => '/salir/*', 'target' => 'https://tienda.example.com$1' ) )->get_error_code(), 'rechaza un comodín pegado al dominio (https://web.es$1)' );
$docs = $service->save( array( 'source' => '/docs/*', 'target' => 'https://docs.example.com/$1' ) );
$t::true( is_array( $docs ), 'admite comodines en la ruta de un destino externo' );

$with_query = $service->save( array( 'source' => '/ficha?id=5', 'target' => '/fichas/' ) );
$t::true( is_array( $with_query ) && '/ficha' === $with_query['source'] && 1 === count( $with_query['warnings'] ), 'avisa de que se ignoran los parámetros del origen' );

// Redirector -------------------------------------------------------------------

$t::section( 'Redirector::resolve' );
$redirector = new Redirector( $redirects );
$home       = home_url();
$resolved   = $redirector->resolve( '/contacto-old?utm_source=boletin', $home, true );
$t::same( $home . '/contacto/?utm_source=boletin', $resolved['url'] ?? null, 'exacta y conserva la consulta' );
$t::same( 301, $resolved['type'] ?? null, 'tipo 301' );
$t::same( $home . '/contacto/', $redirector->resolve( '/contacto-old?utm_source=boletin', $home, false )['url'] ?? null, 'sin conservar consulta si el ajuste está desactivado' );
$t::same( $home . '/noticias/mi-articulo', $redirector->resolve( '/blog/Mi-Articulo/', $home, true )['url'] ?? null, 'comodín /blog/* → /noticias/$1' );
$t::same( 'https://tienda.example.com/', $redirector->resolve( '/tienda-vieja', $home, true )['url'] ?? null, 'destino externo' );
$t::true( $redirector->resolve( '/tienda-vieja', $home, true )['external'] ?? false, 'marca el destino externo' );
$t::same( null, $redirector->resolve( '/no-existe-regla', $home, true ), 'sin regla → null' );
$t::same( null, $redirector->resolve( '/wp-admin/', $home, true ), 'nunca resuelve el escritorio' );
$t::same( 'https://docs.example.com/guia/inicio', $redirector->resolve( '/docs/guia/inicio', $home, false )['url'] ?? null, 'comodín hacia un destino externo' );

// Reglas antiguas o metidas a mano en la tabla, sin pasar por la validación.
$legacy_host = $redirects->create( array( 'source' => '/fuera/*', 'target' => 'https://$1/', 'type' => 301, 'is_wildcard' => true ) );
$legacy_glue = $redirects->create( array( 'source' => '/pegado/*', 'target' => 'https://tienda.example.com$1', 'type' => 301, 'is_wildcard' => true ) );
$t::same( null, $redirector->resolve( '/fuera/evil.example', $home, true ), 'un comodín nunca decide el dominio externo (redirección abierta)' );
$t::same( null, $redirector->resolve( '/pegado/@evil.example', $home, true ), 'una captura con @ no cambia el dominio externo' );
$redirects->delete( array( $legacy_host, $legacy_glue ) );

// Petición completa: con el archivo de acceso de WordPress no se redirige
// (WordPress instalado en su propia carpeta: /wp/wp-login.php).
$wp_in_dir = $service->save( array( 'source' => '/wp/*', 'target' => '/otra/$1' ) );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/wp/wp-login.php';
$t::true( null !== $redirector->resolve_current_request(), 'resolve_current_request() resuelve la ruta en una petición normal' );
$saved_pagenow = $GLOBALS['pagenow'] ?? null;
$GLOBALS['pagenow'] = 'wp-login.php';
$t::same( null, $redirector->resolve_current_request(), 'nunca redirige wp-login.php aunque una regla coincida' );
$GLOBALS['pagenow'] = $saved_pagenow;
$redirects->delete( array( (int) $wp_in_dir['id'] ) );

// maybe_redirect() de principio a fin: wp_redirect se corta con una excepción.
$stop_redirect = static function ( string $location ): string {
	throw new RuntimeException( $location );
};
add_filter( 'wp_redirect', $stop_redirect );
$hits_before            = (int) $redirects->find( (int) $docs['id'] )['hits'];
$_SERVER['REQUEST_URI'] = '/docs/guia';
try {
	$redirector->maybe_redirect();
	$sent = null;
} catch ( RuntimeException $e ) {
	$sent = $e->getMessage();
}
$t::same( 'https://docs.example.com/guia', $sent, 'maybe_redirect() envía al dominio externo de la regla' );
$t::same( $hits_before + 1, (int) $redirects->find( (int) $docs['id'] )['hits'], 'cuenta la visita al servir la redirección' );
remove_filter( 'wp_redirect', $stop_redirect );
remove_all_filters( 'allowed_redirect_hosts' );

// Si otro plugin anula la redirección, el dominio externo no queda permitido.
add_filter( 'wp_redirect', '__return_false' );
$redirector->maybe_redirect();
remove_filter( 'wp_redirect', '__return_false' );
$t::true( ! in_array( 'docs.example.com', apply_filters( 'allowed_redirect_hosts', array(), '' ), true ), 'sin redirección, el dominio externo no queda permitido en la petición' );
$_SERVER['REQUEST_URI'] = '/';

// Hits de la redirección.
$redirects->increment_hits( (int) $saved['id'] );
$redirects->increment_hits( (int) $saved['id'] );
$row = $redirects->find( (int) $saved['id'] );
$t::same( 2, (int) $row['hits'], 'cuenta las visitas de cada redirección' );
$t::true( ! empty( $row['last_hit'] ), 'guarda la fecha de la última visita' );

// Registro de 404 ------------------------------------------------------------------

$t::section( 'Registro de 404' );
$log->delete_all();
$t::true( $log->record( '/producto/silla-roble', 'https://www.google.com/' ), 'primera visita crea la fila' );
$t::true( ! $log->record( '/producto/silla-roble' ), 'segunda visita suma' );
$log->record( '/producto/silla-roble', 'https://www.bing.com/' );
$entry = $log->find( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE url = %s', Log_Repository::table(), '/producto/silla-roble' ) ) );
$t::same( 3, (int) $entry['hits'], 'tres visitas' );
$t::same( 'https://www.bing.com/', $entry['referrer'], 'guarda la última procedencia' );
$log->record( '/producto/silla-roble' );
$t::same( 'https://www.bing.com/', $log->find( (int) $entry['id'] )['referrer'], 'una visita sin procedencia no borra la anterior' );
$t::same( array( 'id', 'url', 'url_hash', 'hits', 'referrer', 'first_seen', 'last_seen' ), array_keys( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', Log_Repository::table() ), ARRAY_A ) ), 'no hay columnas de IP ni de user agent' );

// Logger completo: simula peticiones 404 reales.
$logger  = new Logger( $log );
$request = static function ( string $uri, string $ua = 'Mozilla/5.0', string $referrer = '' ) use ( $logger ): void {
	global $wp_query;
	$_SERVER['REQUEST_URI']     = $uri;
	$_SERVER['REQUEST_METHOD']  = 'GET';
	$_SERVER['HTTP_USER_AGENT'] = $ua;
	if ( '' === $referrer ) {
		unset( $_SERVER['HTTP_REFERER'] );
	} else {
		$_SERVER['HTTP_REFERER'] = $referrer;
	}
	$wp_query->init();
	$wp_query->set_404();
	$logger->maybe_log();
};

$log->delete_all();
delete_transient( 'redirecciones_404_rate' );
$request( '/catalogo-2023.pdf?email=ana@example.com', 'Mozilla/5.0', 'https://boletin.example.com/abrir?token=secreto' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i', Log_Repository::table() ), ARRAY_A );
$t::same( '/catalogo-2023.pdf', $row['url'] ?? null, 'registra la ruta sin la consulta' );
$t::same( 'https://boletin.example.com/abrir', $row['referrer'] ?? null, 'la procedencia se guarda sin consulta' );
$request( '/.env' );
$request( '/wp-content/themes/viejo/style.css' );
$request( '/pagina-x', 'sqlmap/1.7' );
$t::same( 1, $log->count(), 'ignora ruido y escáneres' );

update_option( Settings::OPTION, array_merge( Settings::all(), array( 'max_new_per_min' => 5 ) ) );
delete_transient( 'redirecciones_404_rate' );
$log->delete_all();
for ( $i = 1; $i <= 12; $i++ ) {
	$request( '/aleatoria-' . wp_generate_password( 8, false ) );
}
$t::same( 5, $log->count(), 'límite de URL nuevas por minuto (bot con rutas aleatorias)' );
$first = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM %i ORDER BY id ASC LIMIT 1', Log_Repository::table() ) );
$request( $first );
$t::same( 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE url = %s', Log_Repository::table(), $first ) ), 'las URL ya registradas siguen sumando aunque se alcance el límite' );

update_option( Settings::OPTION, array_merge( Settings::all(), array( 'log_enabled' => false ) ) );
$log->delete_all();
$request( '/con-registro-apagado' );
$t::same( 0, $log->count(), 'no registra si el ajuste está desactivado' );
update_option( Settings::OPTION, Settings::defaults() );

$t::section( 'Tope de filas' );
$log->delete_all();
for ( $i = 1; $i <= 8; $i++ ) {
	$log->insert( "/tope-{$i}", '', gmdate( 'Y-m-d H:i:s', time() - ( 10 - $i ) * HOUR_IN_SECONDS ) );
}
$t::same( 3, $log->enforce_cap( 5 ), 'borra las 3 más antiguas al superar el máximo' );
$t::same( array( '/tope-4', '/tope-5', '/tope-6', '/tope-7', '/tope-8' ), $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM %i ORDER BY url ASC', Log_Repository::table() ) ), 'quedan las más recientes' );

$t::section( 'Comodín sobre un registro grande' );
$log->delete_all();
$now = current_time( 'mysql', true );
for ( $i = 1; $i <= 1100; $i++ ) {
	$log->insert( "/lote/{$i}", '', $now );
}
$log->insert( '/lotes-no', '', $now );
$t::same( 1100, $log->delete_matching_wildcard( '/lote/*' ), 'borra todos los 404 que cubre el comodín, también más allá del primer bloque' );
$t::same( 1, $log->count(), 'conserva los que no encajan' );

$t::section( 'Limpieza programada (WP-Cron)' );
$log->delete_all();
$log->insert( '/vieja', '', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
$log->insert( '/reciente', '', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) );
do_action( Cleanup::HOOK );
$t::same( array( '/reciente' ), $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM %i', Log_Repository::table() ) ), 'do_action del evento borra lo de más de 30 días' );
update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'retention_days' => 1 ) ) );
do_action( Cleanup::HOOK );
$t::same( 0, $log->count(), 'respeta los días configurados' );
update_option( Settings::OPTION, Settings::defaults() );

$t::section( 'Ajustes' );
$clean = Settings::sanitize( array( '_form' => '1', 'retention_days' => '9999', 'max_entries' => '-5', 'max_new_per_min' => 'abc' ) );
$t::same( 365, $clean['retention_days'], 'días fuera de rango → máximo' );
$t::same( 100, $clean['max_entries'], 'máximo de URL fuera de rango → mínimo' );
$t::same( 60, $clean['max_new_per_min'], 'valor no numérico → por defecto' );
$t::same( false, $clean['log_enabled'], 'casilla sin marcar en el formulario → desactivada' );

// Exportación e importación de ida y vuelta --------------------------------------

$t::section( 'CSV de ida y vuelta' );
$lines = array( Csv::HEADER );
foreach ( $redirects->each( 2 ) as $redirect ) {
	$lines[] = Csv::export_row( $redirect );
}
$stream = fopen( 'php://temp', 'r+' );
foreach ( $lines as $line ) {
	fputcsv( $stream, $line, ',', '"', '' );
}
rewind( $stream );
$csv = stream_get_contents( $stream );
fclose( $stream );
$before = $redirects->count();
$t::true( $before >= 5, "exporta {$before} reglas (lee en bloques)" );

$all_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', Redirect_Repository::table() ) ) );
$redirects->delete( $all_ids );
$t::same( 0, $redirects->count(), 'borra todas las reglas' );
$imported = 0;
foreach ( Csv::parse( $csv )['rows'] as $row ) {
	$imported += is_array( $service->save( $row ) ) ? 1 : 0;
}
$t::same( $before, $imported, 'reimporta todas sin errores' );
$t::same( $home . '/noticias/x', $redirector->resolve( '/blog/x', $home, true )['url'] ?? null, 'las reglas reimportadas funcionan' );

// Sugerencias de destino ---------------------------------------------------------

$t::section( 'Suggester' );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$demo = array(
	'silla-de-roble'     => 'Silla de roble macizo',
	'oferta-verano-2026' => 'Oferta de verano 2026',
	'contacto'           => 'Contacto',
);
foreach ( $demo as $slug => $title ) {
	wp_insert_post(
		array(
			'post_type'   => 'contacto' === $slug ? 'page' : 'post',
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_status' => 'publish',
		)
	);
}
$suggester = new RubenTur\Redirecciones404\Suggester();
$t::same( null, $suggester->suggest( '/contacto-old' ), 'no inventa: /contacto-old no tiene todas las palabras de «contacto»' );
$t::same( '/silla-de-roble/', $suggester->suggest( '/producto/silla-roble' )['url'] ?? null, 'mismas palabras en orden: silla-roble → silla-de-roble' );
$t::same( '/oferta-verano-2026/', $suggester->suggest( '/blog/oferta-verano-2024' )['url'] ?? null, 'edición más nueva: oferta-verano-2024 → oferta-verano-2026' );
$t::same( 'Silla de roble macizo', $suggester->suggest( '/producto/silla-roble' )['title'] ?? null, 'devuelve el título para mostrarlo' );
$t::same( null, $suggester->suggest( '/servicios/reformas' ), 'sin parecido → sin sugerencia' );
$t::same( null, $suggester->suggest( '/silla-de-roble' ), 'no sugiere la propia URL' );
$in_subdir = static fn( $value ): string => untrailingslashit( (string) $value ) . '/tienda';
add_filter( 'option_home', $in_subdir );
$t::same( '/silla-de-roble/', ( new RubenTur\Redirecciones404\Suggester() )->suggest( '/producto/silla-roble' )['url'] ?? null, 'web en subcarpeta: la sugerencia no repite la subcarpeta' );
remove_filter( 'option_home', $in_subdir );

// Botón «Redirigir 301» -------------------------------------------------------

$t::section( 'Acción «Redirigir 301»' );
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admins[0] );
$actions        = new RubenTur\Redirecciones404\Admin\Actions( $log, $redirects, $service );
$click_redirect = static function ( int $log_id, string $target ) use ( $actions, $stop_redirect ): void {
	$_POST    = array(
		'log_id' => (string) $log_id,
		'target' => $target,
	);
	$_REQUEST = $_POST + array( '_wpnonce' => wp_create_nonce( 'redirecciones_404_redirect_log_' . $log_id ) );
	add_filter( 'wp_redirect', $stop_redirect );
	try {
		$actions->redirect_log();
	} catch ( RuntimeException $e ) {
		unset( $e ); // Fin de la acción: vuelve al panel.
	}
	remove_filter( 'wp_redirect', $stop_redirect );
};
$log->delete_all();
$log->insert( '/a*', '', current_time( 'mysql', true ) );
$log->insert( '/catalogo-viejo', '', current_time( 'mysql', true ) );
$star_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE url = %s', Log_Repository::table(), '/a*' ) );
$ok_id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE url = %s', Log_Repository::table(), '/catalogo-viejo' ) );
$click_redirect( $star_id, '/destino/' );
$t::true( null === $redirects->find_by_source( '/a*' ) && null !== $log->find( $star_id ), 'un 404 con * no se convierte en comodín con un clic' );
$click_redirect( $ok_id, '/catalogo/' );
$t::true( null !== $redirects->find_by_source( '/catalogo-viejo' ) && null === $log->find( $ok_id ), 'un 404 normal se redirige y sale del registro' );
$_POST    = array();
$_REQUEST = array();
RubenTur\Redirecciones404\Admin\Notices::pull();

// Desactivación y desinstalación ------------------------------------------------

$t::section( 'Desactivación y desinstalación' );
$t::true( in_array( Log_Repository::table(), apply_filters( 'wpmu_drop_tables', array(), get_current_blog_id() ), true ), 'multisitio: las tablas se borran al eliminar el sitio' );
set_transient( 'redirecciones_404_notice_999', array( 'items' => array() ), MINUTE_IN_SECONDS );
deactivate_plugins( $plugin );
$t::same( false, wp_next_scheduled( Cleanup::HOOK ), 'desactivar quita la tarea programada' );
$t::true( $redirects->count() > 0, 'desactivar conserva los datos' );

define( 'WP_UNINSTALL_PLUGIN', $plugin );
require WP_PLUGIN_DIR . '/redirecciones-404/uninstall.php';
$t::same( array( '', '' ), $tables(), 'uninstall.php elimina las tablas' );
$t::same( array( false, false, false ), array( get_option( Settings::OPTION ), get_option( Installer::DB_VERSION_OPTION ), get_option( Redirect_Repository::CACHE_OPTION ) ), 'uninstall.php elimina las opciones' );
$t::same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, '%redirecciones_404_notice_%' ) ), 'uninstall.php elimina los avisos pendientes del panel' );

// Resumen ----------------------------------------------------------------------

$failed = count( R404_Test::$failures );
echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d comprobaciones: %d correctas, %d fallidas\n", R404_Test::$passed + $failed, R404_Test::$passed, $failed );
exit( $failed > 0 ? 1 : 0 );
