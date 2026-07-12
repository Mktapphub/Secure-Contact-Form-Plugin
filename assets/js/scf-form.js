/**
 * Secure Contact Form Pro — front-end submission handler.
 *
 * Uses the native Fetch API (no jQuery dependency) to submit the form
 * asynchronously to admin-ajax.php, avoiding a full page reload while
 * remaining fully progressive: without JS, the form still POSTs
 * normally... (a server-side fallback handler can be added if needed;
 * omitted here since admin-ajax.php requires the JS-driven action param).
 *
 * `scfData` (ajaxUrl, nonce, i18n) is injected via wp_localize_script().
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'scf-contact-form' );

		if ( ! form || typeof scfData === 'undefined' ) {
			return;
		}

		var wrapper = form.closest( '.scf-form-wrapper' ) || form.parentElement;
		var messageBox = wrapper ? wrapper.querySelector( '.scf-form-message' ) : null;

		// Defensive fallback: if a theme, page builder, or wpautop-style
		// content filter has stripped or relocated the message element,
		// create one and insert it before the form so status updates
		// are never silently lost.
		if ( ! messageBox && wrapper ) {
			messageBox = document.createElement( 'div' );
			messageBox.className = 'scf-form-message';
			messageBox.setAttribute( 'role', 'status' );
			messageBox.setAttribute( 'aria-live', 'polite' );
			messageBox.hidden = true;
			wrapper.insertBefore( messageBox, form );
		}
		var submitBtn = form.querySelector( '.scf-submit-btn' );
		var btnText = form.querySelector( '.scf-btn-text' );
		var spinner = form.querySelector( '.scf-spinner' );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			handleSubmit();
		} );

		/**
		 * Orchestrate the submission lifecycle: clear previous state,
		 * lock the UI, send the request, then render the result.
		 */
		function handleSubmit() {
			clearErrors();
			hideMessage();
			setLoading( true );

			var formData = new FormData( form );
			formData.append( 'action', 'scf_submit_form' ); // Required by admin-ajax.php routing.

			fetch( scfData.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin', // Sends WP auth cookies for logged-in users; harmless for guests.
				body: formData,
			} )
				.then( function ( response ) {
					return response.json().then( function ( json ) {
						return { ok: response.ok, status: response.status, json: json };
					} );
				} )
				.then( function ( result ) {
					if ( result.json && result.json.success ) {
						onSuccess( result.json.data );
					} else {
						onError( result.json ? result.json.data : null );
					}
				} )
				.catch( function () {
					showMessage( scfData.i18n.genericError, 'error' );
				} )
				.finally( function () {
					setLoading( false );
				} );
		}

		/**
		 * Handle a successful submission: reset the form and show a
		 * success message inside the aria-live region so assistive
		 * technology announces it automatically.
		 *
		 * @param {Object} data Response payload, expects { message }.
		 */
		function onSuccess( data ) {
			form.reset();
			var msg = ( data && data.message ) ? data.message : scfData.i18n.genericError;
			showMessage( msg, 'success' );
		}

		/**
		 * Handle a failed submission: surface field-level validation
		 * errors inline and a top-level summary message.
		 *
		 * @param {Object} data Response payload, expects { message, errors? }.
		 */
		function onError( data ) {
			var msg = ( data && data.message ) ? data.message : scfData.i18n.genericError;
			showMessage( msg, 'error' );

			if ( data && data.errors ) {
				Object.keys( data.errors ).forEach( function ( fieldName ) {
					showFieldError( fieldName, data.errors[ fieldName ] );
				} );

				// Move focus to the first invalid field for keyboard/screen-reader users.
				var firstField = form.querySelector( '[name="' + Object.keys( data.errors )[ 0 ] + '"]' );
				if ( firstField ) {
					firstField.focus();
				}
			}
		}

		/**
		 * Display an inline error beneath a specific field and mark
		 * its row invalid for styling + aria purposes.
		 *
		 * @param {string} fieldName
		 * @param {string} message
		 */
		function showFieldError( fieldName, message ) {
			var errorEl = form.querySelector( '.scf-field-error[data-field="' + fieldName + '"]' );
			var input = form.querySelector( '[name="' + fieldName + '"]' );

			if ( errorEl ) {
				errorEl.textContent = message;
			}
			if ( input ) {
				input.closest( '.scf-form-row' ).classList.add( 'scf-invalid' );
				input.setAttribute( 'aria-invalid', 'true' );
			}
		}

		/**
		 * Clear all inline field errors and invalid styling.
		 */
		function clearErrors() {
			form.querySelectorAll( '.scf-field-error' ).forEach( function ( el ) {
				el.textContent = '';
			} );
			form.querySelectorAll( '.scf-form-row.scf-invalid' ).forEach( function ( row ) {
				row.classList.remove( 'scf-invalid' );
			} );
			form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
				el.removeAttribute( 'aria-invalid' );
			} );
		}

		/**
		 * Show the top-level status message.
		 *
		 * @param {string} text
		 * @param {'success'|'error'} type
		 */
		function showMessage( text, type ) {
			if ( ! messageBox ) {
				return;
			}
			messageBox.textContent = text;
			messageBox.hidden = false;
			messageBox.classList.remove( 'scf-success', 'scf-error' );
			messageBox.classList.add( type === 'success' ? 'scf-success' : 'scf-error' );
			messageBox.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}

		function hideMessage() {
			if ( messageBox ) {
				messageBox.hidden = true;
			}
		}

		/**
		 * Toggle the submit button's loading state (disabled + spinner).
		 *
		 * @param {boolean} isLoading
		 */
		function setLoading( isLoading ) {
			submitBtn.disabled = isLoading;
			spinner.hidden = ! isLoading;
			btnText.textContent = isLoading ? scfData.i18n.sending : scfData.i18n.submit;
		}
	} );
} )();
