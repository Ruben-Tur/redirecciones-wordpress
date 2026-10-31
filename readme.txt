=== Redirecciones 404 ===
Contributors: rubentur
Tags: redirecciones, 404, 301, seo, redirect
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Registra los errores 404 de tu web y conviértelos en redirecciones 301 con un clic. Comodines, CSV y limpieza automática.

== Description ==

Al cambiar de tema, borrar productos o reorganizar una web, muchas URL antiguas dejan de existir y las visitas que llegan a ellas se pierden. Redirecciones 404 anota cada error 404 con su número de visitas y su procedencia, y desde Herramientas → Redirecciones puedes redirigirlo a la página correcta con un clic.

* **Redirigir 301 con un clic** desde la lista de errores, con destino sugerido cuando hay una entrada con un nombre parecido.
* **Rápido**: las redirecciones se resuelven en `plugins_loaded`, antes de cargar el tema, a partir de una caché autocargada.
* **Comodines** para mover secciones enteras: `/blog/*` → `/noticias/$1`.
* **Sin bucles**: rechaza reglas que vuelven sobre sí mismas y avisa de las cadenas de redirecciones.
* **301 o 302** por regla, con contador de visitas.
* **Importación y exportación en CSV**, compatible con Excel.
* **Limpieza automática** diaria de los 404 antiguos, tope de filas y límite de URL nuevas por minuto contra bots.
* **Privacidad**: guarda solo la ruta (sin parámetros) y el dominio y la ruta de la procedencia. Nunca IP ni user agent.
* **Seguridad**: permisos y nonce en cada acción, consultas preparadas, salida escapada y destinos validados.

Hecho por [Rubén Tur](https://github.com/Ruben-Tur).

== Installation ==

1. Sube la carpeta `redirecciones-404` a `/wp-content/plugins/`.
2. Activa el plugin en **Plugins**.
3. Ve a **Herramientas → Redirecciones**.

== Frequently Asked Questions ==

= ¿Ralentiza la web? =

No. Las reglas se guardan en una opción autocargada, que WordPress ya lee al arrancar, y se resuelven antes de cargar el tema. Registrar un 404 que ya existe cuesta una sola consulta UPDATE.

= ¿Qué formato tiene el CSV? =

`origen,destino,tipo`, con cabecera opcional y separador coma o punto y coma. El tipo es 301 (por defecto) o 302. Máximo 1 MB y 5000 filas por archivo.

= ¿Puedo redirigir a otra web? =

Sí. Escribe la URL completa con `https://`. Solo se permite el dominio que hayas escrito en esa regla.

= ¿Qué pasa si desactivo el plugin? =

Las redirecciones dejan de funcionar, pero se conservan. Al borrar el plugin se eliminan sus tablas y opciones.

== Screenshots ==

1. Errores 404 ordenados por visitas, con el botón «Redirigir 301» en cada fila.
2. Redirecciones con comodines, tipo y visitas.
3. Ajustes de registro y limpieza.

== Changelog ==

= 1.0.0 =
* Primera versión.
