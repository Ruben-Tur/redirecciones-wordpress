<?php
/**
 * Listado de redirecciones.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404\Admin;

use RubenTur\Redirecciones404\Redirect_Repository;
use RubenTur\Redirecciones404\Rule_Matcher;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table de las reglas con búsqueda, orden y borrado masivo.
 */
final class Redirects_List_Table extends \WP_List_Table {

	public const PLURAL = 'r404_redirects';

	/**
	 * Constructor.
	 *
	 * @param Redirect_Repository $redirects Repositorio de redirecciones.
	 */
	public function __construct( private readonly Redirect_Repository $redirects ) {
		parent::__construct(
			array(
				'singular' => 'r404_redirect',
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
			'cb'       => '<input type="checkbox">',
			'source'   => __( 'Origen', 'redirecciones-404' ),
			'target'   => __( 'Destino', 'redirecciones-404' ),
			'type'     => __( 'Tipo', 'redirecciones-404' ),
			'hits'     => __( 'Visitas', 'redirecciones-404' ),
			'last_hit' => __( 'Última visita', 'redirecciones-404' ),
			'created'  => __( 'Creada', 'redirecciones-404' ),
		);
	}

	/**
	 * Columnas ordenables.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'source'   => array( 'source', false ),
			'target'   => array( 'target', false ),
			'type'     => array( 'type', false ),
			'hits'     => array( 'hits', true ),
			'last_hit' => array( 'last_hit', true ),
			'created'  => array( 'created', true ),
		);
	}

	/**
	 * Columna principal.
	 */
	protected function get_default_primary_column_name(): string {
		return 'source';
	}

	/**
	 * Acciones masivas.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Eliminar', 'redirecciones-404' ) );
	}

	/**
	 * Carga la página actual.
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( Admin_Page::PER_PAGE_OPTION, 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo lectura: búsqueda, orden y paginación.
		$result = $this->redirects->paginate(
			array(
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable

		$this->items = $result['items'];
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
			esc_html_e( 'Ninguna redirección coincide con la búsqueda.', 'redirecciones-404' );
			return;
		}
		esc_html_e( 'Todavía no hay redirecciones. Crea la primera con el formulario de arriba o desde la pestaña «Errores 404».', 'redirecciones-404' );
	}

	/**
	 * Casilla de selección.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="r404-rule-%1$d">%2$s</label><input type="checkbox" id="r404-rule-%1$d" name="ids[]" value="%1$d">',
			(int) $item['id'],
			/* translators: %s: URL de origen. */
			esc_html( sprintf( __( 'Seleccionar %s', 'redirecciones-404' ), $item['source'] ) )
		);
	}

	/**
	 * Origen con acciones de fila.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_source( array $item ): string {
		$id       = (int) $item['id'];
		$source   = (string) $item['source'];
		$wildcard = (bool) $item['is_wildcard'];
		$test     = $wildcard ? Rule_Matcher::sample_path( $source ) : $source;

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Admin_Page::url( 'redirecciones', array( 'edit' => $id ) ) . '#r404-form-title' ),
				esc_html__( 'Editar', 'redirecciones-404' )
			),
			'view'   => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
				esc_url( home_url( $test ) ),
				esc_html__( 'Probar', 'redirecciones-404' ),
				esc_html__( '(se abre en una pestaña nueva)', 'redirecciones-404' )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" data-r404-confirm="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=redirecciones_404_delete&id=' . $id ), 'redirecciones_404_delete_' . $id ) ),
				esc_attr__( '¿Eliminar esta redirección? La URL antigua volverá a dar error 404.', 'redirecciones-404' ),
				esc_html__( 'Eliminar', 'redirecciones-404' )
			),
		);

		$badge = $wildcard ? ' <span class="r404-badge r404-badge--muted">' . esc_html__( 'comodín', 'redirecciones-404' ) . '</span>' : '';

		return sprintf( '<code class="r404-url">%s</code>%s', esc_html( $source ), $badge ) . $this->row_actions( $actions );
	}

	/**
	 * Destino.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_target( array $item ): string {
		$target   = (string) $item['target'];
		$external = ! str_starts_with( $target, '/' );

		return sprintf(
			'<span class="r404-arrow" aria-hidden="true">→</span> <code class="r404-url">%s</code>%s',
			esc_html( $target ),
			$external ? ' <span class="r404-badge r404-badge--muted">' . esc_html__( 'externa', 'redirecciones-404' ) . '</span>' : ''
		);
	}

	/**
	 * Tipo como etiqueta.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_type( array $item ): string {
		$type = (int) $item['type'];

		return sprintf(
			'<span class="r404-badge%s" title="%s">%d</span>',
			301 === $type ? '' : ' r404-badge--temp',
			301 === $type ? esc_attr__( 'Permanente', 'redirecciones-404' ) : esc_attr__( 'Temporal', 'redirecciones-404' ),
			$type
		);
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
	 * Última vez que se usó.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_last_hit( array $item ): string {
		if ( empty( $item['last_hit'] ) ) {
			return '<span class="r404-muted">' . esc_html__( 'Nunca', 'redirecciones-404' ) . '</span>';
		}

		$time = strtotime( $item['last_hit'] . ' UTC' );

		return sprintf(
			'<time datetime="%1$s" title="%2$s" class="r404-date">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' H:i', $time ) ),
			/* translators: %s: tiempo transcurrido. */
			esc_html( sprintf( __( 'hace %s', 'redirecciones-404' ), human_time_diff( $time ) ) )
		);
	}

	/**
	 * Fecha de creación.
	 *
	 * @param array<string, mixed> $item Fila.
	 */
	protected function column_created( array $item ): string {
		$time = strtotime( $item['created'] . ' UTC' );

		return sprintf(
			'<time datetime="%1$s" class="r404-date">%2$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_html( wp_date( 'j M Y', $time ) )
		);
	}
}
