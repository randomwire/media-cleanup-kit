/**
 * Media Cleanup Kit — Confirmation modal.
 *
 * Promise-based replacement for window.confirm() and the legacy two-step
 * "armed button" gate. Modern Chromium/WebKit can silently suppress
 * window.confirm() for users who dismissed an earlier dialog on the same
 * origin — that returns false and the click bails out without a prompt
 * ever showing. This modal is fully in-page, so the browser can't gate it.
 *
 * Usage:
 *   window.imageKitModal.confirm({
 *       title:        'Delete files?',
 *       message:      'Delete 12 file group(s)? This cannot be undone.',
 *       confirmLabel: 'Delete',
 *       cancelLabel:  'Cancel',
 *       danger:       true,
 *   }).then(function (ok) { if (ok) { ... } });
 */
(function () {
	'use strict';

	let rootEl       = null;
	let dialogEl     = null;
	let titleEl      = null;
	let messageEl    = null;
	let confirmBtn   = null;
	let cancelBtn    = null;
	let closeBtn     = null;
	let backdropEl   = null;

	let isOpen           = false;
	let activeResolver   = null;   // resolves the in-flight promise
	let previousFocusEl  = null;
	let keydownHandler   = null;

	function buildRoot() {
		if ( rootEl ) return;

		rootEl = document.createElement( 'div' );
		rootEl.className = 'ik-modal';
		rootEl.style.display = 'none';
		rootEl.setAttribute( 'role', 'alertdialog' );
		rootEl.setAttribute( 'aria-modal', 'true' );
		rootEl.setAttribute( 'aria-labelledby', 'ik-modal-title' );
		rootEl.setAttribute( 'aria-describedby', 'ik-modal-message' );

		rootEl.innerHTML =
			'<div class="ik-modal-backdrop"></div>' +
			'<div class="ik-modal-dialog">' +
				'<button type="button" class="ik-modal-close" aria-label="Close">&times;</button>' +
				'<h2 class="ik-modal-title" id="ik-modal-title"></h2>' +
				'<p class="ik-modal-message" id="ik-modal-message"></p>' +
				'<div class="ik-modal-actions">' +
					'<button type="button" class="button ik-modal-cancel"></button>' +
					'<button type="button" class="button button-primary ik-modal-confirm"></button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( rootEl );

		dialogEl   = rootEl.querySelector( '.ik-modal-dialog' );
		titleEl    = rootEl.querySelector( '.ik-modal-title' );
		messageEl  = rootEl.querySelector( '.ik-modal-message' );
		confirmBtn = rootEl.querySelector( '.ik-modal-confirm' );
		cancelBtn  = rootEl.querySelector( '.ik-modal-cancel' );
		closeBtn   = rootEl.querySelector( '.ik-modal-close' );
		backdropEl = rootEl.querySelector( '.ik-modal-backdrop' );

		confirmBtn.addEventListener( 'click', function () { settle( true );  } );
		cancelBtn .addEventListener( 'click', function () { settle( false ); } );
		closeBtn  .addEventListener( 'click', function () { settle( false ); } );
		backdropEl.addEventListener( 'click', function () { settle( false ); } );
	}

	function settle( ok ) {
		if ( ! isOpen ) return;
		const resolver = activeResolver;
		activeResolver = null;
		isOpen = false;

		rootEl.style.display = 'none';
		document.body.classList.remove( 'ik-modal-open' );

		if ( keydownHandler ) {
			document.removeEventListener( 'keydown', keydownHandler, true );
			keydownHandler = null;
		}

		// Restore focus to whatever opened the modal.
		if ( previousFocusEl && typeof previousFocusEl.focus === 'function' ) {
			try { previousFocusEl.focus(); } catch ( e ) {}
		}
		previousFocusEl = null;

		if ( resolver ) resolver( ok );
	}

	function confirm( opts ) {
		opts = opts || {};

		// Reject stacked calls — return the in-flight promise so the
		// second caller resolves alongside the open dialog.
		if ( isOpen ) {
			return Promise.resolve( false );
		}

		buildRoot();

		titleEl.textContent    = opts.title        || 'Confirm';
		messageEl.textContent  = opts.message      || '';
		confirmBtn.textContent = opts.confirmLabel || 'Confirm';
		cancelBtn.textContent  = opts.cancelLabel  || 'Cancel';

		confirmBtn.classList.toggle( 'is-danger', !! opts.danger );

		previousFocusEl = document.activeElement;

		rootEl.style.display = '';
		document.body.classList.add( 'ik-modal-open' );
		isOpen = true;

		// Minimal focus trap: cycle between cancel and confirm.
		keydownHandler = function ( e ) {
			if ( ! isOpen ) return;
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				settle( false );
				return;
			}
			if ( e.key === 'Tab' ) {
				const focusables = [ cancelBtn, confirmBtn ];
				const idx = focusables.indexOf( document.activeElement );
				if ( idx === -1 ) {
					e.preventDefault();
					confirmBtn.focus();
					return;
				}
				e.preventDefault();
				const dir = e.shiftKey ? -1 : 1;
				const next = focusables[ ( idx + dir + focusables.length ) % focusables.length ];
				next.focus();
			}
		};
		document.addEventListener( 'keydown', keydownHandler, true );

		// Focus the confirm button on open. setTimeout sidesteps the
		// click event that opened the dialog stealing focus back.
		setTimeout( function () { confirmBtn.focus(); }, 0 );

		return new Promise( function ( resolve ) { activeResolver = resolve; } );
	}

	window.imageKitModal = { confirm: confirm };
})();
