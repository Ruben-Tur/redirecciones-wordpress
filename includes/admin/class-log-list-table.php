<?php
/**
 * Listado de errores 404.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404\Admin;

use RubenTur\Redirecciones404\Log_Repository;
use RubenTur\Redirecciones404\Suggester;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table con búsqueda, orden, borrado masivo y, en cada fila, el
 * formulario de «Redirigir 301».
 *
 * Los formularios de cada fila no pueden ir dentro del formulario del
 * listado (HTML no admite formularios anidados), así que se pintan después
 * y los campos de la fila se asocian con el atributo `form`.
 */
final class Log_List_Table extends \WP_List_Table {

	public const PLURAL = 'r404_errors';

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $log       Registro de 404.
	 * @param Suggester      $suggester Sugerencias de destino.
	 */
	public function __construct(
		private readonly Log_Repository $log,
		private readonly Suggester $suggester
	) {
		parent::__construct(
			array(
				'singular' => 'r404_error',
				'plural'   => self::PLURAL,
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columnas.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'        => '<input type="checkbox">',
			'url'       => __( 'URL', 'redirecciones-404' ),
			'hits'      => __( 'Visitas', 'redirecciones-404' ),
			'referrer'  => __( 'Última procedencia', 'redirecciones-404' ),
			'last_seen' => __( 'Última visita', 'redirecciones-404' ),
			'redirect'  => __( 'Redirigir a', 'redirecciones-404' ),
		);
	}

	/**
	 * Columnas ordenables (true = descendente al primer clic).
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'url'       => array( 'url', false ),
			'hits'      => array( 'hits', true ),
			'last_seen' => array( 'last_seen', true ),
		);
	}

	/**
	 * Columna principal (la que se ve en móvil).
	 */
	protected function get_default_primary_column_name(): string {
		return 'url';
	}

	/**
	 * Acciones masivas.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Eliminar del registro', 'redirecciones-404' ) );
	}

	/**
	 * Carga la página actual.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo lectura: búsqueda, orden y paginación.
		$result = $this->log->paginate(
			array(
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'hits',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'per_page' => $this->get_items_per_page( Admin_Page::PER_PAGE_OPTION, 20 ),
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable

		$this->items = $result['items'];
		$per_page    = $this->get_items_per_page( Admin_Page::PER_PAGE_OPTION, 20 );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * Mensaje sin resultados.
	 */
	public function no_items(): void {
		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			esc_html_e( 'Ninguna URL coincide con la búsqueda.', 'redirecciones-404' );
			return;
		}
		esc_html_e( 'No hay errores 404 registrados. Cuando alguien visite una página que no existe, aparecerá aquí.', 'redirecciones-404' );
	}

	/**
	 * Botón «Vaciar registro» junto a las acciones masivas.
	 *
	 * @param string $which top o bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || ! $this->has_items() ) {
			return;
		}

		printf(
			'<div class="alignleft actions"><button type="submit" form="r404-clear-log" class="button r404-danger">%s</button></div>',
			esc_html__( 'Vaciar registro', 'redirecciones-404' )
		);
	}

	/**
	 * Casilla de selección.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="r404-log-%1$d">%2$s</label><input type="checkbox" id="r404-log-%1$d" name="ids[]" value="%1$d">',
			(int) $item['id'],
			/* translators: %s: URL. */
			esc_html( sprintf( __( 'Seleccionar %s', 'redirecciones-404' ), $item['url'] ) )
		);
	}

	/**
	 * URL con acciones de fila.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_url( array $item ): string {
		$id      = (int) $item['id'];
		$actions = array(
			'view'   => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
				esc_url( home_url( (string) $item['url'] ) ),
				esc_html__( 'Abrir', 'redirecciones-404' ),
				esc_html__( '(se abre en una pestaña nueva)', 'redirecciones-404' )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" data-r404-confirm="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=redirecciones_404_delete_log&id=' . $id ), 'redirecciones_404_delete_log_' . $id ) ),
				esc_attr__( '¿Eliminar esta URL del registro?', 'redirecciones-404' ),
				esc_html__( 'Eliminar', 'redirecciones-404' )
			),
		);

		return sprintf( '<code class="r404-url">%s</code>', esc_html( (string) $item['url'] ) ) . $this->row_actions( $actions );
	}

	/**
	 * Visitas.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_hits( array $item ): string {
		return sprintf( '<span class="r404-hits">%s</span>', esc_html( number_format_i18n( (int) $item['hits'] ) ) );
	}

	/**
	 * Última procedencia (dominio y ruta, sin consulta).
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_referrer( array $item ): string {
		$referrer = (string) $item['referrer'];

		if ( '' === $referrer ) {
			return '<span class="r404-muted" title="' . esc_attr__( 'Visita directa, marcador o procedencia oculta por el navegador', 'redirecciones-404' ) . '">' . esc_html__( 'Directa', 'redirecciones-404' ) . '</span>';
		}

		$host = (string) wp_parse_url( $referrer, PHP_URL_HOST );
		$path = (string) wp_parse_url( $referrer, PHP_URL_PATH );

		return sprintf(
			'<a href="%1$s" class="r404-referrer" target="_blank" rel="noopener noreferrer nofollow" title="%2$s"><span class="r404-referrer__host">%3$s</span><span class="r404-referrer__path">%4$s</span></a>',
			esc_url( $referrer ),
			esc_attr( $referrer ),
			esc_html( $host ),
			esc_html( '/' === $path ? '' : $path )
		);
	}

	/**
	 * Última visita relativa y fecha de la primera.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_last_seen( array $item ): string {
		$last  = strtotime( $item['last_seen'] . ' UTC' );
		$first = strtotime( $item['first_seen'] . ' UTC' );

		return sprintf(
			'<time datetime="%1$s" title="%2$s" class="r404-date">%3$s</time><br><span class="r404-muted r404-date">%4$s</span>',
			esc_attr( gmdate( 'c', $last ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' H:i', $last ) ),
			/* translators: %s: tiempo transcurrido, p. ej. «2 horas». */
			esc_html( sprintf( __( 'hace %s', 'redirecciones-404' ), human_time_diff( $last ) ) ),
			/* translators: %s: fecha de la primera visita. */
			esc_html( sprintf( __( 'desde el %s', 'redirecciones-404' ), wp_date( 'j M Y', $first ) ) )
		);
	}

	/**
	 * Campo de destino y botón «Redirigir 301».
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_redirect( array $item ): string {
		$id         = (int) $item['id'];
		$suggestion = $this->suggester->suggest( (string) $item['url'] );
		$form_id    = 'r404-redirect-' . $id;

		$html = sprintf(
			'<div class="r404-inline"><label class="screen-reader-text" for="r404-target-%1$d">%2$s</label><input type="text" id="r404-target-%1$d" name="target" form="%3$s" class="code r404-inline__input" value="%4$s" placeholder="/nueva-url/" required maxlength="2048" spellcheck="false" autocomplete="off"%5$s><button type="submit" form="%3$s" class="button button-primary r404-inline__button">%6$s</button></div>',
			$id,
			/* translators: %s: URL rota. */
			esc_html( sprintf( __( 'Destino de la redirección para %s', 'redirecciones-404' ), $item['url'] ) ),
			esc_attr( $form_id ),
			esc_attr( $suggestion['url'] ?? '' ),
			null !== $suggestion ? ' aria-describedby="r404-suggestion-' . $id . '"' : '',
			esc_html__( 'Redirigir 301', 'redirecciones-404' )
		);

		if ( null !== $suggestion ) {
			$html .= sprintf(
				'<p class="r404-suggestion" id="r404-suggestion-%1$d">%2$s <span class="r404-suggestion__title">%3$s</span></p>',
				$id,
				esc_html__( 'Sugerencia:', 'redirecciones-404' ),
				esc_html( $suggestion['title'] )
			);
		}

		return $html;
	}

	/**
	 * Formularios de «Redirigir 301», fuera del formulario del listado.
	 */
	public function render_row_forms(): void {
		$action  = admin_url( 'admin-post.php' );
		$referer = isset( $_SERVER['REQUEST_URI'] ) ? remove_query_arg( '_wp_http_referer', wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Se escapa al pintarlo, como en wp_referer_field().

		// Campos ocultos escritos a mano: wp_nonce_field() repetiría el id «_wpnonce» en cada fila.
		foreach ( $this->items as $item ) {
			$id = (int) $item['id'];
			printf(
				'<form method="post" action="%1$s" id="r404-redirect-%2$d" class="r404-row-form"><input type="hidden" name="action" value="redirecciones_404_redirect_log"><input type="hidden" name="log_id" value="%2$d"><input type="hidden" name="_wpnonce" value="%3$s"><input type="hidden" name="_wp_http_referer" value="%4$s"></form>',
				esc_url( $action ),
				$id,
				esc_attr( wp_create_nonce( 'redirecciones_404_redirect_log_' . $id ) ),
				esc_attr( $referer )
			);
		}
	}
}
