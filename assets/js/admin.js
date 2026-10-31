/**
 * Redirecciones 404: mejoras del panel.
 *
 * Todo funciona sin JavaScript; esto solo añade confirmaciones antes de
 * borrar y evita envíos dobles del botón «Redirigir 301».
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = window.wp.i18n;
	const root = document.querySelector( '.r404' );
	if ( ! root ) {
		return;
	}

	// Confirmación en enlaces y formularios con data-r404-confirm.
	root.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( 'a[data-r404-confirm]' );
		if ( link && ! window.confirm( link.dataset.r404Confirm ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'submit', ( event ) => {
		const form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) || ! root.contains( form ) ) {
			return;
		}

		if ( form.dataset.r404Confirm && ! window.confirm( form.dataset.r404Confirm ) ) {
			event.preventDefault();
			return;
		}

		// Acción masiva «Eliminar» con filas marcadas.
		const bulk = form.querySelector( 'select[name="action"], select[name="action2"]' );
		if ( bulk && form.querySelector( 'input[name="ids[]"]:checked' ) ) {
			const submitter = event.submitter;
			const select = submitter && submitter.id === 'doaction2' ? form.querySelector( 'select[name="action2"]' ) : form.querySelector( 'select[name="action"]' );
			if ( select && select.value === 'delete' ) {
				const count = form.querySelectorAll( 'input[name="ids[]"]:checked' ).length;
				/* translators: %d: número de elementos marcados. */
				const message = sprintf( _n( '¿Eliminar %d elemento? No se puede deshacer.', '¿Eliminar %d elementos? No se puede deshacer.', count, 'redirecciones-404' ), count );
				if ( ! window.confirm( message ) ) {
					event.preventDefault();
					return;
				}
			}
		}

		// Evita el doble clic en «Redirigir 301» mientras se envía.
		if ( form.classList.contains( 'r404-row-form' ) ) {
			const button = root.querySelector( `button[form="${ form.id }"]` );
			if ( button ) {
				button.disabled = true;
				button.textContent = __( 'Creando…', 'redirecciones-404' );
			}
		}
	} );
}() );
