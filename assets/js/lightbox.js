/**
 * Image Kit — Lightbox.
 *
 * Singleton modal that opens at native size for any thumbnail clicked
 * inside the Image Kit admin page. Supports:
 *
 *   - Single-image zoom (default mode)
 *   - openCompare(oldUrl, newUrl) — side-by-side before/after
 *   - openCollection(items, currentIndex) — arrow-key navigation across thumbs
 *
 * Auto-wires a delegated click handler on `#image-kit-app` that picks up any
 * `img.ik-thumb` or `img.ik-iu-thumbnail` and opens single-image mode. Thumbs
 * with a `data-lightbox-src` attribute use that URL for the lightbox instead
 * of the (smaller) `src` displayed in the table.
 *
 * Backdrop click, ESC, and the × button all close the lightbox. The DOM node
 * is created once on first use and reused for the lifetime of the page.
 */
(function () {
	'use strict';

	let rootEl       = null;
	let imageEl      = null;
	let spinnerEl    = null;
	let compareEl    = null;
	let compareBefore = null;
	let compareAfter  = null;
	let closeBtnEl   = null;
	let captionEl    = null;
	let collection   = null; // [{ url, label? }] when in collection mode
	let currentIndex = 0;
	let isOpen       = false;

	function escHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	function buildRoot() {
		if (rootEl) return;

		rootEl = document.createElement('div');
		rootEl.className = 'ik-lightbox';
		rootEl.style.display = 'none';
		rootEl.setAttribute('role', 'dialog');
		rootEl.setAttribute('aria-modal', 'true');
		rootEl.innerHTML =
			'<div class="ik-lightbox-backdrop"></div>' +
			'<button type="button" class="ik-lightbox-close" aria-label="Close">&times;</button>' +
			'<div class="ik-lightbox-content">' +
				'<div class="ik-lightbox-single">' +
					'<div class="ik-lightbox-spinner" aria-hidden="true"></div>' +
					'<img class="ik-lightbox-img" alt="">' +
				'</div>' +
				'<div class="ik-lightbox-compare" style="display:none;">' +
					'<div class="ik-lightbox-compare-side">' +
						'<div class="ik-lightbox-compare-label">Before</div>' +
						'<div class="ik-lightbox-compare-img-wrap">' +
							'<div class="ik-lightbox-spinner ik-lightbox-spinner-before" aria-hidden="true"></div>' +
							'<img class="ik-lightbox-img-before" alt="">' +
						'</div>' +
					'</div>' +
					'<div class="ik-lightbox-compare-side">' +
						'<div class="ik-lightbox-compare-label">After</div>' +
						'<div class="ik-lightbox-compare-img-wrap">' +
							'<div class="ik-lightbox-spinner ik-lightbox-spinner-after" aria-hidden="true"></div>' +
							'<img class="ik-lightbox-img-after" alt="">' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>' +
			'<div class="ik-lightbox-caption"></div>';

		document.body.appendChild(rootEl);

		imageEl              = rootEl.querySelector('.ik-lightbox-img');
		spinnerEl            = rootEl.querySelector('.ik-lightbox-single .ik-lightbox-spinner');
		compareEl            = rootEl.querySelector('.ik-lightbox-compare');
		compareBefore        = rootEl.querySelector('.ik-lightbox-img-before');
		compareAfter         = rootEl.querySelector('.ik-lightbox-img-after');
		const beforeSpinner  = rootEl.querySelector('.ik-lightbox-spinner-before');
		const afterSpinner   = rootEl.querySelector('.ik-lightbox-spinner-after');
		closeBtnEl           = rootEl.querySelector('.ik-lightbox-close');
		captionEl            = rootEl.querySelector('.ik-lightbox-caption');

		rootEl.querySelector('.ik-lightbox-backdrop').addEventListener('click', close);
		closeBtnEl.addEventListener('click', close);

		// Loading indicator wiring for single-image mode.
		imageEl.addEventListener('load',  hideSpinner);
		imageEl.addEventListener('error', hideSpinner);

		// Loading indicator wiring for compare mode (each side independent).
		function hide(el) { if (el) el.style.display = 'none'; }
		compareBefore.addEventListener('load',  function () { hide(beforeSpinner); });
		compareBefore.addEventListener('error', function () { hide(beforeSpinner); });
		compareAfter .addEventListener('load',  function () { hide(afterSpinner); });
		compareAfter .addEventListener('error', function () { hide(afterSpinner); });

		// Expose the compare spinners on rootEl so showCompare can reveal them.
		rootEl._ikCompareSpinners = { before: beforeSpinner, after: afterSpinner };
	}

	function showSpinner() { if (spinnerEl) spinnerEl.style.display = ''; }
	function hideSpinner() { if (spinnerEl) spinnerEl.style.display = 'none'; }

	function close() {
		if (!isOpen) return;
		rootEl.style.display = 'none';
		if (imageEl) imageEl.src = '';
		if (compareBefore) compareBefore.src = '';
		if (compareAfter) compareAfter.src = '';
		if (captionEl) captionEl.textContent = '';
		collection = null;
		currentIndex = 0;
		isOpen = false;
		document.body.classList.remove('ik-lightbox-open');
	}

	function showSingle() {
		buildRoot();
		rootEl.querySelector('.ik-lightbox-single').style.display = '';
		compareEl.style.display = 'none';
		rootEl.style.display = '';
		isOpen = true;
		document.body.classList.add('ik-lightbox-open');
	}

	function showCompare() {
		buildRoot();
		rootEl.querySelector('.ik-lightbox-single').style.display = 'none';
		compareEl.style.display = '';
		rootEl.style.display = '';
		isOpen = true;
		document.body.classList.add('ik-lightbox-open');
	}

	function open(url, opts) {
		opts = opts || {};
		showSingle();
		showSpinner();
		imageEl.src = url || '';
		captionEl.textContent = opts.caption || '';
	}

	function openCompare(beforeUrl, afterUrl, opts) {
		opts = opts || {};
		showCompare();
		const labels = opts.labels || { before: 'Before', after: 'After' };
		const labelEls = rootEl.querySelectorAll('.ik-lightbox-compare-label');
		if (labelEls[0]) labelEls[0].textContent = labels.before || 'Before';
		if (labelEls[1]) labelEls[1].textContent = labels.after || 'After';
		const spinners = rootEl._ikCompareSpinners || {};
		if (spinners.before) spinners.before.style.display = '';
		if (spinners.after)  spinners.after.style.display  = '';
		compareBefore.src = beforeUrl || '';
		compareAfter.src  = afterUrl  || '';
		captionEl.textContent = opts.caption || '';
	}

	function openCollection(items, startIndex) {
		if (!Array.isArray(items) || !items.length) return;
		collection = items.slice();
		currentIndex = Math.max(0, Math.min(startIndex || 0, items.length - 1));
		renderCurrent();
	}

	function renderCurrent() {
		if (!collection || !collection.length) return;
		const item = collection[currentIndex];
		open(item.url, { caption: item.label || '' });
		updateCounter();
	}

	function updateCounter() {
		if (!captionEl) return;
		if (collection && collection.length > 1) {
			const base = collection[currentIndex].label || '';
			captionEl.textContent = (base ? base + ' · ' : '') +
				(currentIndex + 1) + ' / ' + collection.length + ' (← → to navigate)';
		}
	}

	function nextInCollection(delta) {
		if (!collection || collection.length <= 1) return;
		currentIndex = (currentIndex + delta + collection.length) % collection.length;
		renderCurrent();
	}

	// ── Global keyboard handling ───────────────────────────────────────
	document.addEventListener('keydown', function (e) {
		if (!isOpen) return;
		if (e.key === 'Escape') { close(); return; }
		if (e.key === 'ArrowRight') { nextInCollection( 1); }
		else if (e.key === 'ArrowLeft')  { nextInCollection(-1); }
	});

	// ── Auto-wire: delegated click on Image Kit thumbs ─────────────────
	function bindAutoWire() {
		const app = document.getElementById('image-kit-app');
		if (!app) return;

		app.addEventListener('click', function (e) {
			const target = e.target;
			if (!(target instanceof HTMLImageElement)) return;
			if (!target.matches('img.ik-thumb, img.ik-iu-thumbnail')) return;
			// Allow modules to intercept (e.g. before/after compare mode) by
			// calling stopPropagation() on a handler bound earlier in the
			// bubble chain.
			if (e.defaultPrevented) return;

			const url = target.dataset.lightboxSrc || target.src;
			if (!url) return;

			// Build a collection from sibling thumbs in the same tbody so
			// arrow keys cycle within the visible results page.
			const tbody = target.closest('tbody');
			if (tbody) {
				const siblings = Array.from(tbody.querySelectorAll('img.ik-thumb, img.ik-iu-thumbnail'));
				const items = siblings.map(function (img) {
					return {
						url: img.dataset.lightboxSrc || img.src,
						label: img.getAttribute('alt') || '',
					};
				});
				const idx = siblings.indexOf(target);
				openCollection(items, idx >= 0 ? idx : 0);
			} else {
				open(url);
			}

			e.preventDefault();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindAutoWire);
	} else {
		bindAutoWire();
	}

	window.imageKitLightbox = {
		open: open,
		openCompare: openCompare,
		openCollection: openCollection,
		close: close,
	};
})();
