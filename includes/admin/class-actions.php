<?php
/**
 * Acciones del panel (admin-post.php).
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404\Admin;

use RubenTur\Redirecciones404\Csv;
use RubenTur\Redirecciones404\Log_Repository;
use RubenTur\Redirecciones404\Redirect_Repository;
use RubenTur\Redirecciones404\Redirect_Service;
use RubenTur\Redirecciones404\Url;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada acción comprueba permisos y nonce antes de tocar nada, y termina
 * redirigiendo a la página con un aviso (POST/redirect/GET).
 */
final class Actions {

	/**
	 * Constructor.
	 *
	 * @param Log_Repository      $log       Registro de 404.
	 * @param Redirect_Repository $redirects Redirecciones.
	 * @param Redirect_Service    $service   Validación y guardado.
	 */
	public function __construct(
		private readonly Log_Repository $log,
		private readonly Redirect_Repository $redirects,
		private readonly Redirect_Service $service
	) {}

	/**
	 * Engancha las acciones.
	 */
	public function register(): void {
		add_action( 'admin_post_redirecciones_404_redirect_log', array( $this, 'redirect_log' ) );
		add_action( 'admin_post_redirecciones_404_save', array( $this, 'save' ) );
		add_action( 'admin_post_redirecciones_404_delete', array( $this, 'delete' ) );
		add_action( 'admin_post_redirecciones_404_delete_log', array( $this, 'delete_log' ) );
		add_action( 'admin_post_redirecciones_404_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_post_redirecciones_404_export', array( $this, 'export' ) );
		add_action( 'admin_post_redirecciones_404_import', array( $this, 'import' ) );
	}

	/**
	 * Comprueba permisos y nonce. Corta la petición si algo falla.
	 *
	 * @param string $nonce_action Acción del nonce.
	 */
	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( Admin_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para gestionar las redirecciones.', 'redirecciones-404' ), 403 );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Vuelve a la página del plugin.
	 *
	 * @param string               $tab      Pestaña de destino.
	 * @param array<string, mixed> $args     Parámetros extra.
	 * @param bool                 $referrer Volver a la URL de origen (conserva búsqueda y página).
	 */
	private function back( string $tab, array $args = array(), bool $referrer = false ): never {
		$fallback = Admin_Page::url( $tab, $args );
		$location = $referrer && wp_get_referer() ? wp_get_referer() : $fallback;

		// Solo se vuelve a la página del plugin, nunca a otra URL que traiga el formulario.
		if ( ! str_contains( (string) $location, 'page=' . Admin_Page::SLUG ) ) {
			$location = $fallback;
		}

		wp_safe_redirect( $location );
		exit;
	}

	/**
	 * Aviso de éxito tras guardar una redirección (y avisos de cadena).
	 *
	 * @param string               $message Mensaje principal.
	 * @param array<string, mixed> $result  Resultado de Redirect_Service::save().
	 */
	private function notify_saved( string $message, array $result ): void {
		$details = array();
		if ( $result['cleared'] > 0 ) {
			/* translators: %d: número de entradas. */
			$details[] = sprintf( _n( 'Se ha quitado %d error 404 del registro que esta regla ya resuelve.', 'Se han quitado %d errores 404 del registro que esta regla ya resuelve.', $result['cleared'], 'redirecciones-404' ), $result['cleared'] );
		}

		Notices::add( 'success', $message, $details );

		foreach ( $result['warnings'] as $warning ) {
			Notices::add( 'warning', $warning );
		}
	}

	/**
	 * Botón «Redirigir 301» de la pestaña de errores.
	 */
	public function redirect_log(): void {
		$id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El id forma parte de la acción del nonce, que se comprueba justo después.
		$this->authorize( 'redirecciones_404_redirect_log_' . $id );

		$entry = $this->log->find( $id );
		if ( null === $entry ) {
			Notices::add( 'error', __( 'Esa URL ya no está en el registro.', 'redirecciones-404' ) );
			$this->back( '404', array(), true );
		}

		// Cualquiera puede pedir `/a%2A` y dejar `/a*` en el registro. En un
		// origen el * es un comodín, así que con un clic se crearía una regla
		// que captura media web (`/about`, `/archivo`…).
		if ( str_contains( (string) $entry['url'], '*' ) ) {
			/* translators: %s: URL. */
			Notices::add( 'error', sprintf( __( 'No se ha creado la redirección de %s: contiene *, que en el origen funciona como comodín. Si es lo que quieres, créala desde la pestaña Redirecciones.', 'redirecciones-404' ), $entry['url'] ) );
			$this->back( '404', array(), true );
		}

		// El destino se valida en Redirect_Service (sanitize_text_field() rompería los %xx).
		$target = isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = $this->service->save(
			array(
				'source' => (string) $entry['url'],
				'target' => $target,
				'type'   => 301,
			)
		);

		if ( $result instanceof WP_Error ) {
			/* translators: 1: URL, 2: motivo. */
			Notices::add( 'error', sprintf( __( 'No se ha creado la redirección de %1$s: %2$s', 'redirecciones-404' ), $entry['url'], $result->get_error_message() ) );
			$this->back( '404', array(), true );
		}

		/* translators: 1: origen, 2: destino. */
		$this->notify_saved( sprintf( __( 'Redirección 301 creada: %1$s → %2$s', 'redirecciones-404' ), $result['source'], $result['target'] ), $result );
		$this->back( '404', array(), true );
	}

	/**
	 * Alta o edición desde el formulario de la pestaña de redirecciones.
	 */
	public function save(): void {
		$this->authorize( 'redirecciones_404_save' );

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$input = array(
			// Origen y destino se normalizan y validan en Redirect_Service.
			'source' => isset( $_POST['source'] ) ? (string) wp_unslash( $_POST['source'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'target' => isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'type'   => isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 301,
		);

		if ( $id > 0 && null === $this->redirects->find( $id ) ) {
			Notices::add( 'error', __( 'Esa redirección ya no existe.', 'redirecciones-404' ) );
			$this->back( 'redirecciones' );
		}

		$result = $this->service->save( $input, $id );

		if ( $result instanceof WP_Error ) {
			$old = array(
				'source' => mb_substr( $input['source'], 0, Url::MAX_LENGTH ),
				'target' => mb_substr( $input['target'], 0, Url::MAX_LENGTH ),
				'type'   => $input['type'],
			);
			Notices::add( 'error', $result->get_error_message(), array(), $old );
			$this->back( 'redirecciones', $id ? array( 'edit' => $id ) : array() );
		}

		$message = $result['updated']
			/* translators: 1: origen, 2: destino. */
			? sprintf( __( 'Redirección actualizada: %1$s → %2$s', 'redirecciones-404' ), $result['source'], $result['target'] )
			/* translators: 1: origen, 2: destino. */
			: sprintf( __( 'Redirección creada: %1$s → %2$s', 'redirecciones-404' ), $result['source'], $result['target'] );

		$this->notify_saved( $message, $result );
		$this->back( 'redirecciones' );
	}

	/**
	 * Borra una redirección (enlace de fila).
	 */
	public function delete(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Se comprueba justo después.
		$this->authorize( 'redirecciones_404_delete_' . $id );

		$deleted = $this->redirects->delete( array( $id ) );
		Notices::add( $deleted ? 'success' : 'error', $deleted ? __( 'Redirección eliminada.', 'redirecciones-404' ) : __( 'Esa redirección ya no existe.', 'redirecciones-404' ) );
		$this->back( 'redirecciones', array(), true );
	}

	/**
	 * Borra una URL del registro (enlace de fila).
	 */
	public function delete_log(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Se comprueba justo después.
		$this->authorize( 'redirecciones_404_delete_log_' . $id );

		$deleted = $this->log->delete( array( $id ) );
		Notices::add( $deleted ? 'success' : 'error', $deleted ? __( 'URL eliminada del registro.', 'redirecciones-404' ) : __( 'Esa URL ya no está en el registro.', 'redirecciones-404' ) );
		$this->back( '404', array(), true );
	}

	/**
	 * Vacía el registro de 404.
	 */
	public function clear_log(): void {
		$this->authorize( 'redirecciones_404_clear_log' );

		$deleted = $this->log->delete_all();
		/* translators: %d: número de entradas. */
		Notices::add( 'success', sprintf( _n( 'Registro vaciado: %d URL eliminada.', 'Registro vaciado: %d URL eliminadas.', $deleted, 'redirecciones-404' ), $deleted ) );
		$this->back( '404' );
	}

	/**
	 * Descarga todas las redirecciones en CSV.
	 */
	public function export(): void {
		$this->authorize( 'redirecciones_404_export' );

		$filename = 'redirecciones-' . wp_date( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Salida directa al navegador.
		$out = fopen( 'php://output', 'w' );
		// BOM para que Excel reconozca el UTF-8 (tildes y eñes).
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, Csv::HEADER, ',', '"', '' );

		foreach ( $this->redirects->each() as $redirect ) {
			fputcsv( $out, Csv::export_row( $redirect ), ',', '"', '' );
		}

		fclose( $out );
		// phpcs:enable
		exit;
	}

	/**
	 * Importa redirecciones desde un CSV subido.
	 */
	public function import(): void {
		$this->authorize( 'redirecciones_404_import' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Se valida campo a campo abajo.
		$file = isset( $_FILES['r404_csv'] ) && is_array( $_FILES['r404_csv'] ) ? $_FILES['r404_csv'] : null;

		$error = $this->validate_upload( $file );
		if ( null !== $error ) {
			Notices::add( 'error', $error );
			$this->back( 'redirecciones' );
		}

		// Archivo temporal de la subida ya comprobado con is_uploaded_file().
		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$parsed   = Csv::parse( (string) $contents );

		$created  = 0;
		$updated  = 0;
		$warnings = 0;
		$errors   = array();

		foreach ( $parsed['errors'] as $line => $message ) {
			/* translators: 1: número de línea, 2: error. */
			$errors[ $line ] = 0 === $line ? $message : sprintf( __( 'Línea %1$d: %2$s', 'redirecciones-404' ), $line, $message );
		}

		$home_path = Url::home_path();

		foreach ( $parsed['rows'] as $row ) {
			$existing = $this->redirects->find_by_source( Url::normalize_path( $row['source'], $home_path ) );
			$id       = null !== $existing ? (int) $existing['id'] : 0;
			$result   = $this->service->save( $row, $id );

			if ( $result instanceof WP_Error ) {
				/* translators: 1: número de línea, 2: error. */
				$errors[ $row['line'] ] = sprintf( __( 'Línea %1$d: %2$s', 'redirecciones-404' ), $row['line'], $result->get_error_message() );
				continue;
			}

			if ( $id > 0 ) {
				++$updated;
			} else {
				++$created;
			}
			$warnings += count( $result['warnings'] );
		}

		ksort( $errors );

		$summary = sprintf(
			/* translators: 1: nuevas, 2: actualizadas, 3: con errores. */
			__( 'Importación terminada: %1$d nuevas, %2$d actualizadas y %3$d con errores.', 'redirecciones-404' ),
			$created,
			$updated,
			count( $errors )
		);

		Notices::add( array() === $errors ? 'success' : 'warning', $summary, array_values( $errors ) );

		if ( $warnings > 0 ) {
			/* translators: %d: número de reglas. */
			Notices::add( 'warning', sprintf( _n( 'Hay %d aviso en las reglas importadas (cadenas de redirecciones o parámetros del origen que se ignoran). Revisa que apunten al destino final.', 'Hay %d avisos en las reglas importadas (cadenas de redirecciones o parámetros del origen que se ignoran). Revisa que apunten al destino final.', $warnings, 'redirecciones-404' ), $warnings ) );
		}

		$this->back( 'redirecciones' );
	}

	/**
	 * Comprueba la subida: sin errores, tamaño, extensión y que sea texto.
	 *
	 * @param array<string, mixed>|null $file Entrada de $_FILES.
	 * @return string|null Mensaje de error o null si es válida.
	 */
	private function validate_upload( ?array $file ): ?string {
		if ( null === $file || ! isset( $file['error'], $file['tmp_name'], $file['size'], $file['name'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return __( 'Elige un archivo CSV para importar.', 'redirecciones-404' );
		}

		if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) $file['size'] > Csv::MAX_BYTES ) {
			/* translators: %s: tamaño máximo. */
			return sprintf( __( 'El archivo es demasiado grande. El máximo es %s.', 'redirecciones-404' ), size_format( Csv::MAX_BYTES ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return __( 'No se ha podido subir el archivo. Inténtalo de nuevo.', 'redirecciones-404' );
		}

		$check = wp_check_filetype(
			sanitize_file_name( (string) $file['name'] ),
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);
		if ( false === $check['ext'] ) {
			return __( 'El archivo debe tener extensión .csv o .txt.', 'redirecciones-404' );
		}

		// Un CSV no lleva bytes nulos: si los hay, es un binario renombrado.
		$head = (string) file_get_contents( (string) $file['tmp_name'], false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( str_contains( $head, "\0" ) ) {
			return __( 'El archivo no parece un CSV de texto.', 'redirecciones-404' );
		}

		return null;
	}
}
