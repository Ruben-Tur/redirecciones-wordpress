# Redirecciones 404

Plugin de WordPress que registra los errores 404 de una web y permite convertirlos en redirecciones 301 con un clic desde el escritorio, sin ralentizar la web ni guardar datos personales.

Está pensado para quien cambia de tema, borra productos o reorganiza una web y no quiere perder las visitas (ni el posicionamiento) que siguen llegando a las URL antiguas.

## Características

- **Registro de errores 404** con número de visitas, primera y última visita y la última procedencia. Solo se anotan los 404 reales: se ignoran el escritorio, la API REST, el cron, los recursos estáticos (CSS, JS, fuentes) y el ruido de escáneres (`/.env`, `/wp-config.php.bak`, `sqlmap`…). Googlebot y Bingbot sí se registran, porque sus 404 son los que más interesa arreglar.
- **Redirigir 301 con un clic**: cada fila tiene su campo de destino y su botón. Si hay una entrada publicada con un nombre parecido (`/producto/silla-roble` → «Silla de roble macizo»), el destino ya viene sugerido.
- **Redirecciones resueltas antes de cargar el tema**, en `plugins_loaded`. Las reglas se leen de una opción autocargada que se regenera al cambiar cualquier regla, así que una visita normal no cuesta ninguna consulta extra.
- **Comodines** para mover secciones enteras: `/blog/*` → `/noticias/$1`. Gana siempre la regla exacta y, después, la más específica.
- **Protección contra bucles y cadenas**: no deja guardar una regla que vuelva sobre sí misma (`/a` → `/b` → `/a`) y avisa cuando el destino es a su vez el origen de otra redirección, porque las cadenas restan velocidad y posicionamiento.
- **301 o 302** por regla, con el contador de visitas y la fecha de la última.
- **Importación y exportación en CSV** (coma o punto y coma, con o sin cabecera, UTF-8 o Windows-1252 de Excel), con errores explicados por línea.
- **Limpieza automática** diaria con WP-Cron de los 404 sin visitas en los últimos N días, más un tope de filas y un límite de URL nuevas por minuto para que un bot no pueda llenar la base de datos.
- **Interfaz nativa de WordPress** (`WP_List_Table`, avisos, ayuda contextual, opciones de pantalla) en Herramientas → Redirecciones, con tres pestañas: Errores 404, Redirecciones y Ajustes.
- **Desinstalación limpia**: al borrar el plugin se eliminan sus tablas, opciones y tareas programadas, también en multisitio.

## Tecnologías

| Parte | Tecnología |
|---|---|
| Lenguaje | PHP 8.1+ con espacio de nombres y autocarga propia (sin Composer) |
| Plataforma | WordPress 6.4+ (Settings API, `WP_List_Table`, WP-Cron, `admin-post.php`) |
| Base de datos | Dos tablas propias creadas con `dbDelta()`; SQL compatible con MySQL/MariaDB y con SQLite |
| Panel | HTML del escritorio de WordPress, CSS propio mínimo y JavaScript sin dependencias (con `wp.i18n`) |
| Estilo de código | WordPress Coding Standards (`phpcs.xml.dist`) |
| Pruebas | Script PHP con WordPress Playground y pruebas de navegador con Edge headless |

## Requisitos

- WordPress 6.4 o superior (usa el marcador `%i` de `$wpdb->prepare()`).
- PHP 8.1 o superior.
- MySQL 5.7+/MariaDB 10.3+ o la integración SQLite de WordPress.

## Instalación

1. Copia la carpeta del plugin en `wp-content/plugins/redirecciones-404/` (el nombre de la carpeta debe ser ese).
2. Actívalo en **Plugins**. Al activarlo se crean las tablas `{prefijo}r404_log` y `{prefijo}r404_redirects` y se programa la limpieza diaria.
3. Abre **Herramientas → Redirecciones**.

Para probarlo sin instalar nada más que WordPress Playground:

```powershell
cd redirecciones-wordpress
..\_herramientas\wp-playground\node_modules\.bin\wp-playground-cli.cmd server --port 8881 --php 8.1 `
  --mount-dir . /wordpress/wp-content/plugins/redirecciones-404 `
  --blueprint playground\blueprint.json --login
```

El blueprint pone WordPress en español, activa el plugin, configura los enlaces permanentes y crea unas cuantas páginas y entradas de ejemplo de una tienda de muebles.

## Uso

**Errores 404.** La lista se ordena por visitas. Escribe el destino (o acepta la sugerencia) y pulsa **Redirigir 301**: la URL antigua empieza a redirigir al momento y sale del registro. Puedes buscar, ordenar por URL, visitas o fecha, borrar entradas sueltas o en bloque y vaciar el registro.

**Redirecciones.** Formulario para añadir o editar reglas y listado con su tipo, visitas y última visita.

| Origen | Destino | Resultado |
|---|---|---|
| `/contacto-old` | `/contacto/` | Redirección exacta |
| `/blog/*` | `/noticias/$1` | `/blog/mi-entrada` → `/noticias/mi-entrada` (y `/blog` → `/noticias/`) |
| `/tienda/*/talla-*` | `/productos/$1?talla=$2` | Varios comodines |
| `/catalogo` | `https://tienda.example.com/` | Destino externo, permitido de forma explícita para esa regla |

El origen siempre es una ruta de esta web; se normaliza (minúsculas, sin barra final ni parámetros) para que `/Contacto-Old/` y `/contacto-old` sean la misma. El destino tiene que empezar por `/` o por `http(s)://`: se rechazan `javascript:`, `data:`, `//otro-dominio` y similares. No se pueden redirigir la portada, el escritorio, `wp-login.php` ni la API REST.

**CSV.** Exporta con el botón **Exportar CSV**. Para importar, el formato es:

```csv
origen,destino,tipo
/tienda-antigua,/tienda/,301
/blog/*,/noticias/$1,301
/rebajas-2025,/rebajas/,302
```

El tipo es opcional (301 por defecto). Si un origen ya existe, se actualiza. Límite: 1 MB y 5000 filas por archivo.

**Ajustes.** Activar o pausar el registro, días que se conservan los 404 sin visitas (30 por defecto), máximo de URL registradas (5000), URL nuevas por minuto (60) y si se conserva la consulta original al redirigir (`?utm_source=…`).

### Qué datos guarda y dónde

| Dato | Dónde | Por qué |
|---|---|---|
| Ruta de la URL que dio 404, **sin parámetros** | `{prefijo}r404_log.url` | Los parámetros pueden llevar correos o tokens |
| Visitas, primera y última visita | `{prefijo}r404_log` | Priorizar qué arreglar |
| Esquema, dominio y ruta de la última procedencia, **sin consulta ni fragmento** | `{prefijo}r404_log.referrer` | Saber qué enlace roto hay que corregir |
| Reglas y sus visitas | `{prefijo}r404_redirects` | Funcionamiento del plugin |
| Ajustes, versión del esquema y caché de reglas | Opciones `redirecciones_404_*` | Funcionamiento del plugin |

No guarda direcciones IP, user agents, cookies ni identificadores de usuario, y no hace peticiones a servicios externos.

### Seguridad

Cada acción del panel comprueba `current_user_can( 'manage_options' )` y un nonce propio (por fila en los botones de redirigir y borrar). Toda la entrada se valida en el servidor y toda la salida se escapa con `esc_html()`, `esc_attr()` o `esc_url()`. Todas las consultas pasan por `$wpdb->prepare()` (los nombres de tabla y columna con `%i`; el orden, con lista cerrada). Las redirecciones internas usan `wp_safe_redirect()` y las externas añaden solo el dominio de esa regla a `allowed_redirect_hosts`. La exportación sale con `Content-Disposition: attachment`, `nosniff` y sin caché, y neutraliza celdas que una hoja de cálculo interpretaría como fórmula (`= + - @`).

## Estructura

```
redirecciones-404.php          Cabecera del plugin y arranque
uninstall.php                  Borra tablas, opciones y cron (también en multisitio)
includes/
  class-autoloader.php         Autocarga: Admin\Log_List_Table → admin/class-log-list-table.php
  class-plugin.php             Crea los servicios y engancha cada parte
  class-installer.php          dbDelta(), activación, desactivación y desinstalación
  class-url.php                Normalización, destinos seguros, ruido, privacidad de la procedencia
  class-rule-matcher.php       Reglas exactas y con comodín, bucles y cadenas
  class-redirect-service.php   Validación única para formulario, botón 404 e importación
  class-redirector.php         Resuelve y sirve las redirecciones en plugins_loaded
  class-logger.php             Registra los 404 en template_redirect con límite por minuto
  class-cleanup.php            Limpieza diaria con WP-Cron
  class-csv.php                Lectura y escritura de CSV
  class-suggester.php          Sugerencias de destino por slug
  class-settings.php           Ajustes y sus límites
  class-log-repository.php     Consultas de la tabla de 404
  class-redirect-repository.php Consultas de reglas y caché
  admin/                       Página, listados, acciones (admin-post.php) y avisos
assets/css/admin.css           Toques de marca sobre la interfaz nativa
assets/js/admin.js             Confirmaciones y envío único del botón
playground/blueprint.json      Sitio de prueba en español con contenido de ejemplo
tests/run-tests.php            Pruebas con WordPress Playground
readme.txt                     Ficha en formato WordPress.org
```

## Pruebas

`tests/run-tests.php` carga WordPress en Playground (con SQLite), activa el plugin y hace 169 comprobaciones: normalización de URL, ruido, destinos peligrosos, privacidad de la procedencia, sugerencias de destino, comodines, bucles y cadenas, CSV (separadores, codificaciones, errores por línea, límite de filas, fórmulas), validaciones del servicio, resolución de redirecciones, registro de 404 con límite por minuto y tope de filas, limpieza de WP-Cron, ajustes, CSV de ida y vuelta, desactivación y `uninstall.php`.

Como borra los datos del plugin, solo se ejecuta si se define `R404_TESTS`:

```powershell
cd redirecciones-wordpress
..\_herramientas\wp-playground\node_modules\.bin\wp-playground-cli.cmd php --php 8.1 --define-bool R404_TESTS true `
  --mount-dir . /wordpress/wp-content/plugins/redirecciones-404 `
  -- /wordpress/wp-content/plugins/redirecciones-404/tests/run-tests.php
```

Termina con `169 comprobaciones: 169 correctas, 0 fallidas` y código de salida 0 (1 si algo falla).

Además, el plugin se ha probado de extremo a extremo en un navegador real (Edge headless contra Playground): 404 generados desde fuera, redirección con un clic, códigos 301/302 y cabecera `Location` comprobados con `fetch(..., { redirect: 'manual' })`, comodines, bucles rechazados, CSV exportado, borrado e importado de nuevo, limpieza disparada por `wp-cron.php`, ajustes, móvil y tableta, desactivación y borrado del plugin.

---

Hecho por [Rubén Tur](https://github.com/Ruben-Tur). Licencia GPL-2.0-or-later.
