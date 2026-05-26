/**
 * Image Kit — Shared admin JS utilities.
 *
 * Provides tab switching, AJAX helper, progress bar management,
 * CSV export, and other shared UI utilities.
 *
 * Vanilla JS, no jQuery.
 */
(function () {
	'use strict';

	const { ajaxUrl, nonce } = imageKit;

	// ── AJAX helper ──

	/**
	 * POST to WordPress AJAX endpoint.
	 *
	 * @param {string} action  AJAX action name.
	 * @param {Object} [data]  Additional key-value pairs.
	 * @returns {Promise<Object>} Parsed JSON response.
	 */
	function post(action, data) {
		const body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce);
		if (data) {
			for (const [k, v] of Object.entries(data)) {
				if (Array.isArray(v)) {
					v.forEach(val => body.append(k + '[]', val));
				} else {
					body.append(k, v);
				}
			}
		}
		return fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		}).then(r => r.json());
	}

	// ── HTML escaping ──

	function escHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	function escAttr(str) {
		return escHtml(str).replace(/"/g, '&quot;');
	}

	// ── Logging ──

	function appendLog(container, type, message) {
		const div = document.createElement('div');
		div.className = 'ik-log-entry ik-log-' + type;
		div.textContent = message;
		container.appendChild(div);
		container.scrollTop = container.scrollHeight;
	}

	// ── Formatting ──

	function formatBytes(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

	function formatNumber(n) {
		return Number(n).toLocaleString();
	}

	// ── CSV export ──

	function csvField(val) {
		var s = String(val);
		if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1 || s.indexOf('\n') !== -1) {
			return '"' + s.replace(/"/g, '""') + '"';
		}
		return s;
	}

	/**
	 * Generate and download a CSV file from data.
	 *
	 * @param {string}   filename  Download filename.
	 * @param {string[]} columns   Column headers.
	 * @param {Array[]}  rows      Array of arrays (each row's values).
	 */
	function exportCSV(filename, columns, rows) {
		let csv = columns.map(csvField).join(',') + '\n';
		rows.forEach(function (row) {
			csv += row.map(csvField).join(',') + '\n';
		});
		const blob = new Blob([csv], { type: 'text/csv' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		a.click();
		URL.revokeObjectURL(url);
	}

	// ── Wizard step management ──

	/**
	 * Show a wizard step and update step indicators.
	 *
	 * @param {string} stepsSelector  CSS selector for the step indicator container.
	 * @param {Object} panelMap       { 1: Element, 2: Element, 3: Element }
	 * @param {number} n              Step number to show.
	 */
	function showStep(stepsSelector, panelMap, n) {
		Object.values(panelMap).forEach(p => {
			if (p) p.style.display = 'none';
		});
		if (panelMap[n]) {
			panelMap[n].style.display = '';
		}
		document.querySelectorAll(stepsSelector + ' .ik-step').forEach(s => {
			const sn = parseInt(s.dataset.step, 10);
			s.classList.remove('active', 'completed');
			if (sn === n) {
				s.classList.add('active');
			} else if (sn < n) {
				s.classList.add('completed');
			}
		});
	}

	// ── Progress bar ──

	/**
	 * Update a progress bar.
	 *
	 * @param {Element} container  Element containing .ik-progress-fill and .ik-progress-text.
	 * @param {number}  current    Current count.
	 * @param {number}  total      Total count.
	 * @param {string}  [text]     Optional text override.
	 */
	function updateProgress(container, current, total, text) {
		const fill = container.querySelector('.ik-progress-fill');
		const textEl = container.querySelector('.ik-progress-text');
		const pct = total > 0 ? Math.round((current / total) * 100) : 100;
		if (fill) fill.style.width = pct + '%';
		if (textEl && text !== undefined) textEl.textContent = text;
	}

	// ── Select all / deselect all ──

	/**
	 * Wire up select-all checkbox behavior.
	 *
	 * @param {Element}  selectAllCheckbox  The "select all" checkbox.
	 * @param {string}   itemSelector       CSS selector for individual checkboxes.
	 * @param {Function} [onChange]          Called after selection changes with (checkedCount, totalCount).
	 */
	function wireSelectAll(selectAllCheckbox, itemSelector, onChange) {
		function update() {
			const items = document.querySelectorAll(itemSelector);
			const checked = document.querySelectorAll(itemSelector + ':checked');
			selectAllCheckbox.checked = (checked.length === items.length);
			selectAllCheckbox.indeterminate = (checked.length > 0 && checked.length < items.length);
			if (onChange) {
				onChange(checked.length, items.length);
			}
		}

		selectAllCheckbox.addEventListener('change', function () {
			const isChecked = this.checked;
			document.querySelectorAll(itemSelector).forEach(cb => {
				cb.checked = isChecked;
			});
			update();
		});

		// Delegate change events on individual checkboxes.
		document.addEventListener('change', function (e) {
			if (e.target.matches(itemSelector)) {
				update();
			}
		});

		return { update };
	}

	// ── Tab switching ──

	document.addEventListener('DOMContentLoaded', function () {
		const tabButtons = document.querySelectorAll('.ik-tab');
		const tabPanels = document.querySelectorAll('.ik-tab-content');

		function activateTab(slug) {
			tabButtons.forEach(b => {
				const isActive = b.dataset.tab === slug;
				b.classList.toggle('active', isActive);
				b.setAttribute('aria-selected', isActive ? 'true' : 'false');
			});
			tabPanels.forEach(p => {
				p.style.display = (p.id === 'ik-tab-' + slug) ? '' : 'none';
			});
		}

		tabButtons.forEach(btn => {
			btn.addEventListener('click', function () {
				const slug = this.dataset.tab;
				activateTab(slug);
				history.replaceState(null, '', '#' + slug);
			});
		});

		// Restore tab from URL hash.
		const hash = window.location.hash.replace('#', '');
		if (hash && document.getElementById('ik-tab-' + hash)) {
			activateTab(hash);
		}
	});

	// ── Expose utilities globally for module scripts ──

	window.imageKitUtils = {
		post,
		escHtml,
		escAttr,
		appendLog,
		formatBytes,
		formatNumber,
		csvField,
		exportCSV,
		showStep,
		updateProgress,
		wireSelectAll,
	};
})();
