<?php
/**
 * Página del plugin en Herramientas → Redirecciones.
 *
 * @package RubenTur\Redirecciones404
 */

namespace RubenTur\Redirecciones404\Admin;

use RubenTur\Redirecciones404\Csv;
use RubenTur\Redirecciones404\Log_Repository;
use RubenTur\Redirecciones404\Redirect_Repository;
use RubenTur\Redirecciones404\Settings;
use RubenTur\Redirecciones404\Suggester;
use const RubenTur\Redirecciones404\PLUGIN_FILE;
use const RubenTur\Redirecciones404\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Menú, pestañas, ajustes, recursos y acciones masivas de los listados.
 */
final class Admin_Page {

	public const SLUG = 'redirecciones-404';

	public const CAPABILITY = 'manage_options';

	public const PER_PAGE_OPTION = 'redirecciones_404_per_page';

	private const SETTINGS_GROUP = 'redirecciones_404';

	/**
	 * Sufijo del gancho de la página (tools_page_redirecciones-404).
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Listado de la pestaña actual, preparado en load-{$hook}.
	 *
	 * @var Log_List_Table|Redirects_List_Table|null
	 */
	private Log_List_Table|Redirects_List_Table|null $table = null;

	/**
	 * Constructor.
	 *
	 * @param Log_Repository      $log       Registro de 404.
	 * @param Redirect_Repository $redirects Redirecciones.
	 */
	public function __construct(
		private readonly Log_Repository $log,
		private readonly Redirect_Repository $redirects
	) {}

	/**
	 * Engancha la página.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( $this, 'save_per_page' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * URL de una pestaña de la página.
	 *
	 * @param string               $tab  Pestaña: 404, redirecciones o ajustes.
	 * @param array<string, mixed> $args Parámetros extra.
	 */
	public static function url( string $tab = '404', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Campo oculto con el nonce, sin `id`.
	 *
	 * La pestaña tiene varios formularios (y el listado ya pinta el suyo):
	 * wp_nonce_field() repetiría `id="_wpnonce"` en cada uno. Al volver,
	 * wp_get_referer() usa la cabecera Referer.
	 *
	 * @param string $action Acción del nonce.
	 */
	public static function nonce_field( string $action ): void {
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( $action ) ) );
	}

	/**
	 * Añade la entrada en Herramientas.
	 */
	public function add_menu(): void {
		$hook = add_management_page(
			__( 'Redirecciones y errores 404', 'redirecciones-404' ),
			__( 'Redirecciones', 'redirecciones-404' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			$this->hook = $hook;
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * Enlace «Abrir» en la lista de plugins.
	 *
	 * @param array<string, string> $links Enlaces existentes.
	 * @return array<string, string>
	 */
	public function action_links( array $links ): array {
		$open = sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Abrir', 'redirecciones-404' ) );
		return array( 'open' => $open ) + $links;
	}

	/**
	 * Guarda las filas por página de las opciones de pantalla.
	 *
	 * @param mixed  $screen_option Valor por defecto (false).
	 * @param string $option        Nombre de la opción.
	 * @param mixed  $value         Valor enviado.
	 * @return int
	 */
	public function save_per_page( mixed $screen_option, string $option, mixed $value ): int {
		return max( 1, min( 200, (int) $value ) );
	}

	/**
	 * Pestaña actual (lista cerrada).
	 */
	public function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '404'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo navegación.
		return in_array( $tab, array( '404', 'redirecciones', 'ajustes' ), true ) ? $tab : '404';
	}

	/**
	 * Antes de pintar: acciones masivas, opciones de pantalla y ayuda.
	 */
	public function on_load(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para gestionar las redirecciones.', 'redirecciones-404' ), 403 );
		}

		$tab = $this->current_tab();

		if ( 'ajustes' !== $tab ) {
			add_screen_option(
				'per_page',
				array(
					'label'   => __( 'Filas por página', 'redirecciones-404' ),
					'default' => 20,
					'option'  => self::PER_PAGE_OPTION,
				)
			);

			$this->table = '404' === $tab
				? new Log_List_Table( $this->log, new Suggester() )
				: new Redirects_List_Table( $this->redirects );

			$this->process_bulk_action( $tab );
		}

		// Igual que en edit.php: tras buscar u ordenar, fuera los campos ocultos del formulario.
		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
			exit;
		}

		$this->table?->prepare_items();
		$this->add_help_tabs();
	}

	/**
	 * Borrado masivo desde los listados (formulario GET con nonce, como en
	 * las pantallas de entradas de WordPress).
	 *
	 * @param string $tab Pestaña actual.
	 */
	private function process_bulk_action( string $tab ): void {
		if ( 'delete' !== $this->table?->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . ( '404' === $tab ? Log_List_Table::PLURAL : Redirects_List_Table::PLURAL ) );

		$ids     = isset( $_GET['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['ids'] ) ) : array();
		$deleted = '404' === $tab ? $this->log->delete( $ids ) : $this->redirects->delete( $ids );

		Notices::add(
			$deleted > 0 ? 'success' : 'info',
			'404' === $tab
				/* translators: %d: número de entradas. */
				? sprintf( _n( '%d error 404 eliminado del registro.', '%d errores 404 eliminados del registro.', $deleted, 'redirecciones-404' ), $deleted )
				/* translators: %d: número de redirecciones. */
				: sprintf( _n( '%d redirección eliminada.', '%d redirecciones eliminadas.', $deleted, 'redirecciones-404' ), $deleted )
		);

		$back = remove_query_arg( array( 'action', 'action2', 'ids', '_wpnonce', '_wp_http_referer' ), wp_get_referer() ? wp_get_referer() : self::url( $tab ) );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Pestañas de ayuda de la pantalla.
	 */
	private function add_help_tabs(): void {
		$screen = get_current_screen();
		if ( null === $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'r404-uso',
				'title'   => __( 'Cómo funciona', 'redirecciones-404' ),
				'content' => '<p>' . esc_html__( 'Cada visita a una página que no existe se anota en «Errores 404» con su número de visitas y la última procedencia. Escribe el destino y pulsa «Redirigir 301»: la URL antigua empezará a llevar a la nueva y saldrá del registro.', 'redirecciones-404' ) . '</p>'
					. '<p>' . esc_html__( 'Usa 301 cuando el cambio es definitivo (los buscadores trasladan el posicionamiento a la nueva URL) y 302 solo para cambios temporales.', 'redirecciones-404' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'r404-comodines',
				'title'   => __( 'Comodines', 'redirecciones-404' ),
				'content' => '<p>' . esc_html__( 'Un * en el origen captura cualquier texto, y en el destino se recupera con $1, $2…', 'redirecciones-404' ) . '</p>'
					. '<p><code>/blog/*</code> → <code>/noticias/$1</code></p>'
					. '<p>' . esc_html__( 'Así /blog/mi-articulo lleva a /noticias/mi-articulo. Si dos reglas coinciden, gana la exacta y después la más específica.', 'redirecciones-404' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'r404-csv',
				'title'   => __( 'CSV', 'redirecciones-404' ),
				'content' => '<p>' . esc_html__( 'Columnas: origen, destino y tipo (301 o 302, opcional). Se admite separador coma o punto y coma, con o sin cabecera.', 'redirecciones-404' ) . '</p>'
					. '<pre>origen,destino,tipo' . "\n" . '/tienda-antigua,/tienda/,301' . "\n" . '/blog/*,/noticias/$1,301</pre>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Hecho por', 'redirecciones-404' ) . '</strong></p><p><a href="https://github.com/Ruben-Tur" rel="noopener">Rubén Tur</a></p>'
		);
	}

	/**
	 * Hoja de estilos y script, solo en esta página.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook ) {
			return;
		}

		$base = plugin_dir_url( PLUGIN_FILE );
		wp_enqueue_style( 'redirecciones-404-admin', $base . 'assets/css/admin.css', array(), VERSION );
		wp_enqueue_script(
			'redirecciones-404-admin',
			$base . 'assets/js/admin.js',
			array( 'wp-i18n' ),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( 'redirecciones-404-admin', 'redirecciones-404' );
	}

	/**
	 * Registra los ajustes con la Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'r404_log',
			__( 'Registro de errores 404', 'redirecciones-404' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Solo se guarda la ruta (sin parámetros), el número de visitas, las fechas y el dominio y la ruta de la última procedencia. Nunca direcciones IP ni datos del navegador.', 'redirecciones-404' ) . '</p>';
			},
			self::SLUG
		);

		$this->add_checkbox_field( 'log_enabled', 'r404_log', __( 'Registrar errores 404', 'redirecciones-404' ), __( 'Anotar las visitas a páginas que no existen.', 'redirecciones-404' ) );
		$this->add_number_field( 'retention_days', 'r404_log', __( 'Conservar durante', 'redirecciones-404' ), __( 'días sin visitas. La limpieza se hace una vez al día.', 'redirecciones-404' ) );
		$this->add_number_field( 'max_entries', 'r404_log', __( 'Máximo de URL', 'redirecciones-404' ), __( 'Al superarlo se borran las menos recientes.', 'redirecciones-404' ) );
		$this->add_number_field( 'max_new_per_min', 'r404_log', __( 'URL nuevas por minuto', 'redirecciones-404' ), __( 'Límite contra bots que piden rutas al azar. Las URL ya registradas siguen sumando visitas.', 'redirecciones-404' ) );

		add_settings_section( 'r404_redirects', __( 'Redirecciones', 'redirecciones-404' ), '__return_false', self::SLUG );

		$this->add_checkbox_field( 'keep_query', 'r404_redirects', __( 'Conservar parámetros', 'redirecciones-404' ), __( 'Pasar la consulta original al destino (por ejemplo, ?utm_source=boletin), salvo que el destino ya tenga la suya.', 'redirecciones-404' ) );
	}

	/**
	 * Campo de casilla.
	 *
	 * @param string $key         Clave del ajuste.
	 * @param string $section     Sección.
	 * @param string $title       Etiqueta.
	 * @param string $description Texto junto a la casilla.
	 */
	private function add_checkbox_field( string $key, string $section, string $title, string $description ): void {
		add_settings_field(
			'r404_' . $key,
			$title,
			static function () use ( $key, $description ): void {
				printf(
					'<label for="r404_%1$s"><input type="checkbox" id="r404_%1$s" name="%2$s[%1$s]" value="1" %3$s> %4$s</label>',
					esc_attr( $key ),
					esc_attr( Settings::OPTION ),
					checked( (bool) Settings::get( $key ), true, false ),
					esc_html( $description )
				);
			},
			self::SLUG,
			$section
		);
	}

	/**
	 * Campo numérico con sus límites.
	 *
	 * @param string $key         Clave del ajuste.
	 * @param string $section     Sección.
	 * @param string $title       Etiqueta.
	 * @param string $description Texto de ayuda.
	 */
	private function add_number_field( string $key, string $section, string $title, string $description ): void {
		add_settings_field(
			'r404_' . $key,
			$title,
			static function () use ( $key, $description ): void {
				[ $min, $max ] = Settings::LIMITS[ $key ];
				printf(
					'<input type="number" class="small-text r404-mono" id="r404_%1$s" name="%2$s[%1$s]" value="%3$d" min="%4$d" max="%5$d" step="1" required aria-describedby="r404_%1$s_help"> <span id="r404_%1$s_help" class="description">%6$s</span>',
					esc_attr( $key ),
					esc_attr( Settings::OPTION ),
					(int) Settings::get( $key ),
					(int) $min,
					(int) $max,
					esc_html( $description )
				);
			},
			self::SLUG,
			$section,
			array( 'label_for' => 'r404_' . $key )
		);
	}

	/**
	 * Pinta la página.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para gestionar las redirecciones.', 'redirecciones-404' ), 403 );
		}

		$tab     = $this->current_tab();
		$stats   = $this->log->stats();
		$total   = $this->redirects->count();
		$notices = Notices::pull();
		?>
		<div class="wrap r404">
			<div class="r404-header">
				<h1 class="r404-title">
					<span class="r404-wordmark" aria-hidden="true">redirecciones</span>
					<span class="screen-reader-text"><?php esc_html_e( 'Redirecciones y errores 404', 'redirecciones-404' ); ?></span>
				</h1>
				<p class="r404-summary">
					<?php
					printf(
						/* translators: 1: visitas perdidas, 2: número de URL. */
						esc_html__( '%1$s en %2$s URL que no existen', 'redirecciones-404' ),
						'<span class="r404-pill">' . esc_html(
							/* translators: %s: número de visitas. */
							sprintf( _n( '%s visita perdida', '%s visitas perdidas', $stats['hits'], 'redirecciones-404' ), number_format_i18n( $stats['hits'] ) )
						) . '</span>',
						'<strong class="r404-num">' . esc_html( number_format_i18n( $stats['urls'] ) ) . '</strong>'
					);
					?>
				</p>
			</div>
			<hr class="wp-header-end">

			<?php
			if ( 'ajustes' === $tab ) {
				settings_errors();
			}
			Notices::render( $notices['items'] );
			$this->render_tabs( $tab, $stats['urls'], $total );

			match ( $tab ) {
				'redirecciones' => $this->render_redirects_tab( $notices['old'] ),
				'ajustes'       => $this->render_settings_tab(),
				default         => $this->render_log_tab( $stats['urls'] ),
			};
			?>
		</div>
		<?php
	}

	/**
	 * Navegación por pestañas.
	 *
	 * @param string $current   Pestaña actual.
	 * @param int    $errors    Número de 404 registrados.
	 * @param int    $redirects Número de redirecciones.
	 */
	private function render_tabs( string $current, int $errors, int $redirects ): void {
		$tabs = array(
			'404'           => array( __( 'Errores 404', 'redirecciones-404' ), $errors ),
			'redirecciones' => array( __( 'Redirecciones', 'redirecciones-404' ), $redirects ),
			'ajustes'       => array( __( 'Ajustes', 'redirecciones-404' ), null ),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix r404-tabs" aria-label="' . esc_attr__( 'Secciones', 'redirecciones-404' ) . '">';
		foreach ( $tabs as $key => [ $label, $count ] ) {
			$active = $key === $current;
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s%5$s</a>',
				esc_url( self::url( $key ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $label ),
				null === $count ? '' : ' <span class="r404-count">' . esc_html( number_format_i18n( $count ) ) . '</span>'
			);
		}
		echo '</nav>';
	}

	/**
	 * Pestaña «Errores 404».
	 *
	 * @param int $count Número de URL registradas.
	 */
	private function render_log_tab( int $count ): void {
		?>
		<p class="r404-intro">
			<?php esc_html_e( 'Cada vez que alguien llega a una página que no existe, aparece aquí. Escribe a dónde debe ir y pulsa «Redirigir 301»: cuando hay una entrada con un nombre parecido, el destino ya viene sugerido.', 'redirecciones-404' ); ?>
		</p>

		<form method="get" class="r404-list-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="404">
			<?php
			$this->table->search_box( __( 'Buscar URL', 'redirecciones-404' ), 'r404-log' );
			$this->table->display();
			?>
		</form>
		<?php
		$this->table->render_row_forms();

		if ( $count > 0 ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="r404-clear-log" data-r404-confirm="<?php esc_attr_e( '¿Vaciar todo el registro de errores 404? No se puede deshacer.', 'redirecciones-404' ); ?>">
				<input type="hidden" name="action" value="redirecciones_404_clear_log">
				<?php self::nonce_field( 'redirecciones_404_clear_log' ); ?>
			</form>
			<?php
		}
	}

	/**
	 * Pestaña «Redirecciones»: formulario, listado e importación/exportación.
	 *
	 * @param array<string, mixed> $old Valores enviados si hubo un error.
	 */
	private function render_redirects_tab( array $old ): void {
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo carga el formulario.
		$editing = $edit_id > 0 ? $this->redirects->find( $edit_id ) : null;
		$values  = array(
			'source' => (string) ( $old['source'] ?? $editing['source'] ?? '' ),
			'target' => (string) ( $old['target'] ?? $editing['target'] ?? '' ),
			'type'   => (int) ( $old['type'] ?? $editing['type'] ?? 301 ),
		);
		$id      = null !== $editing ? (int) $editing['id'] : 0;
		?>
		<section class="r404-card" aria-labelledby="r404-form-title">
			<h2 id="r404-form-title" class="r404-card__title">
				<?php echo $id ? esc_html__( 'Editar redirección', 'redirecciones-404' ) : esc_html__( 'Añadir redirección', 'redirecciones-404' ); ?>
			</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="r404-rule-form">
				<input type="hidden" name="action" value="redirecciones_404_save">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php self::nonce_field( 'redirecciones_404_save' ); ?>

				<div class="r404-field r404-field--source">
					<label for="r404-source"><?php esc_html_e( 'Origen', 'redirecciones-404' ); ?></label>
					<input type="text" id="r404-source" name="source" class="code" value="<?php echo esc_attr( $values['source'] ); ?>" placeholder="/pagina-antigua" required maxlength="2048" spellcheck="false" autocomplete="off" aria-describedby="r404-source-help">
					<p id="r404-source-help" class="description"><?php esc_html_e( 'Ruta de esta web. Un * captura el resto, para mover secciones enteras.', 'redirecciones-404' ); ?></p>
				</div>

				<div class="r404-field r404-field--target">
					<label for="r404-target"><?php esc_html_e( 'Destino', 'redirecciones-404' ); ?></label>
					<input type="text" id="r404-target" name="target" class="code" value="<?php echo esc_attr( $values['target'] ); ?>" placeholder="/pagina-nueva/" required maxlength="2048" spellcheck="false" autocomplete="off" aria-describedby="r404-target-help">
					<p id="r404-target-help" class="description">
						<?php
						printf(
							/* translators: 1: ejemplo de origen, 2: ejemplo de destino. */
							esc_html__( 'Ruta de esta web o URL https:// de otra. Con comodines: %1$s → %2$s', 'redirecciones-404' ),
							'<code>/blog/*</code>',
							'<code>/noticias/$1</code>'
						);
						?>
					</p>
				</div>

				<div class="r404-field r404-field--type">
					<label for="r404-type"><?php esc_html_e( 'Tipo', 'redirecciones-404' ); ?></label>
					<select id="r404-type" name="type">
						<option value="301" <?php selected( $values['type'], 301 ); ?>><?php esc_html_e( '301 · Permanente', 'redirecciones-404' ); ?></option>
						<option value="302" <?php selected( $values['type'], 302 ); ?>><?php esc_html_e( '302 · Temporal', 'redirecciones-404' ); ?></option>
					</select>
				</div>

				<div class="r404-field r404-field--submit">
					<?php
					submit_button( $id ? __( 'Guardar cambios', 'redirecciones-404' ) : __( 'Añadir redirección', 'redirecciones-404' ), 'primary', 'submit', false );
					if ( $id ) {
						printf( ' <a class="button" href="%s">%s</a>', esc_url( self::url( 'redirecciones' ) ), esc_html__( 'Cancelar', 'redirecciones-404' ) );
					}
					?>
				</div>
			</form>
		</section>

		<form method="get" class="r404-list-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="redirecciones">
			<?php
			$this->table->search_box( __( 'Buscar redirecciones', 'redirecciones-404' ), 'r404-redirects' );
			$this->table->display();
			?>
		</form>

		<section class="r404-card r404-csv" aria-labelledby="r404-csv-title">
			<h2 id="r404-csv-title" class="r404-card__title"><?php esc_html_e( 'Importar y exportar', 'redirecciones-404' ); ?></h2>
			<div class="r404-csv__grid">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="r404-csv__export">
					<input type="hidden" name="action" value="redirecciones_404_export">
					<?php self::nonce_field( 'redirecciones_404_export' ); ?>
					<p><?php esc_html_e( 'Descarga todas las redirecciones en un CSV que se abre con cualquier hoja de cálculo.', 'redirecciones-404' ); ?></p>
					<?php submit_button( __( 'Exportar CSV', 'redirecciones-404' ), 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="r404-csv__import">
					<input type="hidden" name="action" value="redirecciones_404_import">
					<?php self::nonce_field( 'redirecciones_404_import' ); ?>
					<label for="r404-csv-file"><?php esc_html_e( 'Archivo CSV', 'redirecciones-404' ); ?></label>
					<input type="file" id="r404-csv-file" name="r404_csv" accept=".csv,text/csv" required aria-describedby="r404-csv-help">
					<p id="r404-csv-help" class="description">
						<?php
						printf(
							/* translators: 1: columnas, 2: tamaño máximo, 3: número máximo de filas. */
							esc_html__( 'Columnas %1$s. Máximo %2$s y %3$s filas. Si un origen ya existe, se actualiza.', 'redirecciones-404' ),
							'<code>origen,destino,tipo</code>',
							esc_html( size_format( Csv::MAX_BYTES ) ),
							esc_html( number_format_i18n( Csv::MAX_ROWS ) )
						);
						?>
					</p>
					<?php submit_button( __( 'Importar CSV', 'redirecciones-404' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Pestaña «Ajustes».
	 */
	private function render_settings_tab(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="r404-settings">
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_form]" value="1">
			<?php
			settings_fields( self::SETTINGS_GROUP );
			do_settings_sections( self::SLUG );
			submit_button( __( 'Guardar ajustes', 'redirecciones-404' ) );
			?>
		</form>
		<?php
	}
}
