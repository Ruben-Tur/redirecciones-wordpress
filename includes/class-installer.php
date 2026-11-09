<?php
/**
 * Activación, actualización del esquema y desinstalación.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404;

defined( 'ABSPATH' ) || exit;

/**
 * Crea y actualiza las tablas con dbDelta() y las elimina al desinstalar.
 */
final class Installer {

	/**
	 * Versión del esquema. Si cambia, maybe_upgrade() vuelve a pasar dbDelta().
	 */
	public const DB_VERSION = '1.0.0';

	public const DB_VERSION_OPTION = 'redirecciones_404_db_version';

	/**
	 * Al activar: tablas, ajustes por defecto y tarea diaria.
	 */
	public static function activate(): void {
		self::install();
		Cleanup::schedule();
	}

	/**
	 * Al desactivar solo se quita la tarea programada: los datos se
	 * conservan hasta que se borra el plugin (uninstall.php).
	 */
	public static function deactivate(): void {
		Cleanup::unschedule();
		delete_option( Redirect_Repository::CACHE_OPTION );
	}

	/**
	 * Indica si las tablas existen en este sitio.
	 */
	public static function is_installed(): bool {
		return false !== get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Actualiza el esquema si la versión guardada no coincide (por ejemplo,
	 * al actualizar el plugin sin desactivarlo, o en un sitio nuevo de una red).
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}

		// Barato (la lista de WP-Cron ya está en memoria) y recupera la
		// limpieza si el evento se ha perdido; sin ella el registro solo se
		// recortaría al llegar URL nuevas.
		Cleanup::schedule();
	}

	/**
	 * Tablas propias de un sitio de la red, para que WordPress las borre
	 * junto con las suyas al eliminar ese sitio (filtro `wpmu_drop_tables`).
	 *
	 * @param string[] $tables  Tablas que WordPress va a borrar.
	 * @param int      $site_id Sitio que se elimina.
	 * @return string[]
	 */
	public static function drop_site_tables( array $tables, int $site_id ): array {
		global $wpdb;

		$prefix   = $wpdb->get_blog_prefix( $site_id );
		$tables[] = $prefix . 'r404_log';
		$tables[] = $prefix . 'r404_redirects';

		return $tables;
	}

	/**
	 * Crea o actualiza las tablas.
	 *
	 * dbDelta() es quisquilloso con el formato: un campo por línea, dos
	 * espacios tras PRIMARY KEY y KEY en lugar de INDEX.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$log_table       = Log_Repository::table();
		$redirects_table = Redirect_Repository::table();

		dbDelta(
			"CREATE TABLE {$log_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(2048) NOT NULL,
  url_hash char(32) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  referrer varchar(2048) NOT NULL DEFAULT '',
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY last_seen (last_seen),
  KEY hits (hits)
) {$charset_collate};
CREATE TABLE {$redirects_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(2048) NOT NULL,
  source_hash char(32) NOT NULL,
  target varchar(2048) NOT NULL,
  type smallint(3) unsigned NOT NULL DEFAULT 301,
  is_wildcard tinyint(1) unsigned NOT NULL DEFAULT 0,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL DEFAULT NULL,
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_hash (source_hash)
) {$charset_collate};"
		);

		add_option( Settings::OPTION, Settings::defaults() );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		delete_option( Redirect_Repository::CACHE_OPTION );
	}

	/**
	 * Borra todo rastro del plugin en el sitio actual.
	 */
	public static function uninstall(): void {
		global $wpdb;

		Cleanup::unschedule();

		// Una tabla por sentencia: SQLite no admite DROP TABLE con varias tablas.
		foreach ( array( Log_Repository::table(), Redirect_Repository::table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Desinstalación: hay que eliminar las tablas propias.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( Settings::OPTION );
		delete_option( self::DB_VERSION_OPTION );
		delete_option( Redirect_Repository::CACHE_OPTION );
		delete_transient( 'redirecciones_404_rate' );
		delete_metadata( 'user', 0, 'redirecciones_404_per_page', '', true );

		// Avisos pendientes del panel (uno por usuario). Sin caché de objetos
		// persistente viven en wp_options hasta que WordPress los purga.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Desinstalación: no hay API para borrar transitorios por prefijo.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( '_transient_redirecciones_404_notice_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_redirecciones_404_notice_' ) . '%'
			)
		);
	}
}
