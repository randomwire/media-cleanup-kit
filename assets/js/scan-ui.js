/**
 * Image Kit — Shared scan UI helper.
 *
 * Provides a unified scan / review / inline-apply experience across all
 * Image Kit modules. Each module supplies a config object via
 * `window.imageKitScanUI.init(config)`; the helper renders and drives:
 *
 *   - the scan phase: AJAX batch loop, progress bar, counters, log, cancel
 *   - the review-and-apply phase: filter tabs, search, sortable columns,
 *     WP-style pagination, current-page select-all (with "select all
 *     matching" extension), per-row status badges, per-row Apply button,
 *     expandable detail rows, CSV export, bulk Apply Selected, discard
 *
 * Review and apply live in the same panel; rows update their status
 * inline as applies complete, rather than switching to a separate screen.
 */
(function () {
	'use strict';

	const PAGE_SIZE = 25;

	function isPlainObject(o) {
		return o && typeof o === 'object' && !Array.isArray(o);
	}

	function el(tag, attrs, html) {
		const e = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'class') e.className = attrs[k];
				else if (k === 'style' && isPlainObject(attrs[k])) Object.assign(e.style, attrs[k]);
				else e.setAttribute(k, attrs[k]);
			});
		}
		if (html !== undefined) e.innerHTML = html;
		return e;
	}

	function esc(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	function escAttr(str) {
		return esc(str).replace(/"/g, '&quot;');
	}

	function init(config) {
		const { post, escHtml, appendLog, exportCSV, updateProgress, formatNumber } = window.imageKitUtils;

		// ── Defaults / normalize config ──────────────────────────────────
		const cfg = Object.assign({
			counters: [],
			columns: [],
			filters: [{ key: 'all', label: 'All', predicate() { return true; } }],
			searchableFields: [],
			rowKey(item) { return String(item.id); },
			rowStatus(item) { return 'pending'; },
			rowDetail: null,
			bindRowDetail: null,
			emptyMessage: 'No items found.',
			csvExport: null,
			apply: null,
			perRowApplyLabel: 'Apply',
			perRowAppliedLabel: 'Applied ✓',
			pageSize: PAGE_SIZE,
		}, config);

		const containers = cfg.containers || {};
		const startBtn   = cfg.startButton;

		if (!containers.config || !containers.progress || !containers.results || !startBtn) {
			console.warn('[image-kit] scan-ui.init: missing required containers');
			return null;
		}

		// ── State ────────────────────────────────────────────────────────
		const state = {
			items: [],
			rowState: new Map(),     // rowKey → { status, message?, applied_at? }
			counters: {},            // key → number
			isScanning: false,
			isCancelling: false,
			scanGen: 0,
			currentRunParams: null,  // last resolved initRun output (for onCancel)
			selection: new Set(),    // rowKey set
			selectAllMatching: false,// true once user clicks "select all N matching"
			currentFilter: 'all',
			searchQuery: '',
			sortColumn: null,
			sortAsc: true,
			page: 1,
			// Apply state
			applyQueue: [],          // rowKeys pending apply
			applyInFlight: false,
			applyAbort: false,
			applyTotalForRun: 0,     // for the sticky banner
			applyDoneForRun: 0,
		};

		// ── Build progress panel markup ──────────────────────────────────
		const progressMarkup =
			'<h3 class="ik-scan-progress-title">' + esc(cfg.progressTitle || 'Scanning…') + '</h3>' +
			'<div class="ik-progress ik-scan-progress-bar">' +
				'<div class="ik-progress-bar"><div class="ik-progress-fill"></div></div>' +
				'<span class="ik-progress-text">0%</span>' +
			'</div>' +
			'<div class="ik-scan-counters"></div>' +
			'<div class="ik-log ik-scan-log"></div>' +
			'<p><button type="button" class="button ik-scan-cancel">Cancel</button></p>';
		containers.progress.innerHTML = progressMarkup;
		containers.progress.style.display = 'none';

		const progressBarEl   = containers.progress.querySelector('.ik-scan-progress-bar');
		const progressTextEl  = containers.progress.querySelector('.ik-progress-text');
		const countersEl      = containers.progress.querySelector('.ik-scan-counters');
		const logEl           = containers.progress.querySelector('.ik-scan-log');
		const cancelBtn       = containers.progress.querySelector('.ik-scan-cancel');

		// Render counter cells.
		cfg.counters.forEach(function (c) {
			const cell = el('span', { class: 'ik-scan-counter', 'data-key': c.key });
			cell.innerHTML = '<strong>' + esc(c.label) + ':</strong> <span class="ik-scan-counter-value">0</span>';
			countersEl.appendChild(cell);
		});

		function setCounter(key, value) {
			state.counters[key] = value;
			const cell = countersEl.querySelector('[data-key="' + key + '"] .ik-scan-counter-value');
			if (cell) cell.textContent = formatNumber(value);
		}

		// ── Build results panel markup ───────────────────────────────────
		containers.results.innerHTML =
			'<div class="ik-scan-apply-banner" style="display:none;"></div>' +
			'<p class="ik-scan-summary"></p>' +
			'<div class="ik-scan-results-toolbar"></div>' +
			'<div class="ik-scan-pagination-top"></div>' +
			'<table class="widefat striped ik-scan-table"><thead></thead><tbody></tbody></table>' +
			'<div class="ik-scan-pagination-bottom"></div>' +
			'<details class="ik-scan-apply-log-wrap" style="display:none;margin-top:12px;">' +
				'<summary>Apply log</summary>' +
				'<div class="ik-log ik-scan-apply-log"></div>' +
			'</details>';
		containers.results.style.display = 'none';

		const summaryEl      = containers.results.querySelector('.ik-scan-summary');
		const toolbarEl      = containers.results.querySelector('.ik-scan-results-toolbar');
		const paginationTop  = containers.results.querySelector('.ik-scan-pagination-top');
		const paginationBot  = containers.results.querySelector('.ik-scan-pagination-bottom');
		const tableEl        = containers.results.querySelector('.ik-scan-table');
		const tableHead      = tableEl.querySelector('thead');
		const tableBody      = tableEl.querySelector('tbody');
		const applyBanner    = containers.results.querySelector('.ik-scan-apply-banner');
		const applyLogWrap   = containers.results.querySelector('.ik-scan-apply-log-wrap');
		const applyLogEl     = containers.results.querySelector('.ik-scan-apply-log');

		// ── Helpers: filtering / sorting / paging ────────────────────────
		function getFilteredItems() {
			const filterEntry = cfg.filters.find(function (f) { return f.key === state.currentFilter; }) || cfg.filters[0];
			const predicate = filterEntry ? filterEntry.predicate : function () { return true; };

			let items = state.items.filter(function (item) {
				return predicate(item, state.rowState.get(cfg.rowKey(item)) || {});
			});

			if (state.searchQuery && cfg.searchableFields.length) {
				const q = state.searchQuery.toLowerCase();
				items = items.filter(function (item) {
					return cfg.searchableFields.some(function (k) {
						const v = item[k];
						return v && String(v).toLowerCase().indexOf(q) !== -1;
					});
				});
			}

			if (state.sortColumn) {
				const col = cfg.columns.find(function (c) { return c.key === state.sortColumn; });
				if (col) {
					const sortValue = col.sortValue || function (item) { return item[col.key]; };
					items = items.slice().sort(function (a, b) {
						const va = sortValue(a), vb = sortValue(b);
						if (va == null && vb == null) return 0;
						if (va == null) return state.sortAsc ? -1 : 1;
						if (vb == null) return state.sortAsc ? 1 : -1;
						if (typeof va === 'number' && typeof vb === 'number') {
							return state.sortAsc ? va - vb : vb - va;
						}
						const sa = String(va).toLowerCase(), sb = String(vb).toLowerCase();
						return state.sortAsc ? sa.localeCompare(sb) : sb.localeCompare(sa);
					});
				}
			}

			return items;
		}

		function getFilterCounts() {
			const counts = {};
			cfg.filters.forEach(function (f) {
				counts[f.key] = state.items.filter(function (item) {
					return f.predicate(item, state.rowState.get(cfg.rowKey(item)) || {});
				}).length;
			});
			return counts;
		}

		// ── Status helpers ───────────────────────────────────────────────
		function getRowStatus(item) {
			const key = cfg.rowKey(item);
			const override = state.rowState.get(key);
			if (override && override.status) return override.status;
			return cfg.rowStatus(item);
		}

		function setRowState(item, status, message) {
			const key = cfg.rowKey(item);
			state.rowState.set(key, { status: status, message: message || '' });
		}

		const STATUS_LABELS = {
			pending:  'Pending',
			applying: 'Applying…',
			applied:  'Applied ✓',
			skipped:  'Skipped',
			error:    'Error',
		};

		function renderStatusBadge(item) {
			const status = getRowStatus(item);
			const rs = state.rowState.get(cfg.rowKey(item)) || {};
			const message = rs.message || (item.skip_reason ? String(item.skip_reason).replace(/_/g, ' ') : '');
			let html = '<span class="ik-scan-status ik-scan-status-' + status + '">' + STATUS_LABELS[status] + '</span>';
			if (message && (status === 'skipped' || status === 'error')) {
				html += ' <small>' + esc(message) + '</small>';
			}
			return html;
		}

		// ── Render: toolbar (filter tabs + search + bulk actions) ────────
		function renderToolbar() {
			const counts = getFilterCounts();
			const filters = cfg.filters.map(function (f) {
				const isCurrent = f.key === state.currentFilter;
				return '<li><a href="#" class="ik-scan-filter' + (isCurrent ? ' current' : '') + '" data-filter="' + escAttr(f.key) + '">' +
					esc(f.label) + ' <span class="count">(' + (counts[f.key] || 0) + ')</span></a></li>';
			}).join(' | ');

			const selectedCount = state.selection.size;
			const applyDisabled = !cfg.apply || selectedCount === 0 ? 'disabled' : '';

			let html = '<ul class="subsubsub">' + filters + '</ul>';

			html += '<div class="ik-scan-toolbar-row">';
			if (cfg.searchableFields.length) {
				html += '<p class="search-box">' +
					'<input type="search" class="ik-scan-search" value="' + escAttr(state.searchQuery) + '" placeholder="Search…"> ' +
					'<button type="button" class="button ik-scan-search-btn">Search</button>' +
					'</p>';
			}
			if (cfg.apply) {
				html += '<button type="button" class="button button-primary ik-scan-apply-btn" ' + applyDisabled + '>' +
					esc(cfg.applyButtonLabel || 'Apply Selected') +
					(selectedCount ? ' (' + selectedCount + ')' : '') +
				'</button>';
			}
			if (cfg.csvExport) {
				html += ' <button type="button" class="button ik-scan-export-btn">Export CSV</button>';
			}
			if (cfg.onDiscard) {
				html += ' <button type="button" class="button ik-scan-discard-btn">Discard</button>';
			}
			html += '</div>';

			toolbarEl.innerHTML = html;

			toolbarEl.querySelectorAll('.ik-scan-filter').forEach(function (a) {
				a.addEventListener('click', function (e) {
					e.preventDefault();
					state.currentFilter = a.dataset.filter;
					state.page = 1;
					render();
				});
			});

			const searchInput = toolbarEl.querySelector('.ik-scan-search');
			const searchBtn   = toolbarEl.querySelector('.ik-scan-search-btn');
			if (searchInput && searchBtn) {
				const doSearch = function () {
					state.searchQuery = searchInput.value;
					state.page = 1;
					render();
				};
				searchBtn.addEventListener('click', doSearch);
				searchInput.addEventListener('keydown', function (e) {
					if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
				});
			}

			const applyBtn = toolbarEl.querySelector('.ik-scan-apply-btn');
			if (applyBtn) {
				// Single-row apply fires immediately (the selection IS the
				// deliberate action). Multi-row goes through the shared
				// confirmation modal — avoids window.confirm(), which modern
				// browsers can silently suppress.
				applyBtn.addEventListener('click', function () {
					if (state.selection.size <= 1) {
						startBulkApply();
						return;
					}
					const msg = cfg.apply.confirmMessage
						? cfg.apply.confirmMessage(state.selection.size)
						: 'Apply ' + state.selection.size + ' selected items? This cannot be undone.';
					window.imageKitModal.confirm({
						title:        'Apply selected?',
						message:      msg,
						confirmLabel: cfg.apply.confirmLabel || 'Apply',
						danger:       true,
					}).then(function (ok) { if (ok) startBulkApply(); });
				});
			}

			const exportBtn = toolbarEl.querySelector('.ik-scan-export-btn');
			if (exportBtn) exportBtn.addEventListener('click', doExportCSV);

			const discardBtn = toolbarEl.querySelector('.ik-scan-discard-btn');
			if (discardBtn) {
				discardBtn.addEventListener('click', function () {
					window.imageKitModal.confirm({
						title:        'Discard scan results?',
						message:      'Clear the current scan results from this page. You can re-run the scan at any time.',
						confirmLabel: 'Discard',
					}).then(function (ok) {
						if (!ok) return;
						if (cfg.onDiscard) cfg.onDiscard();
						reset();
					});
				});
			}
		}

		// ── Render: table header (with sort arrows) ──────────────────────
		function renderTableHead() {
			let html = '<tr>';
			html += '<th class="check-column"><input type="checkbox" class="ik-scan-select-all-page"></th>';
			html += '<th class="ik-scan-expand-col"></th>';
			cfg.columns.forEach(function (c) {
				let arrow = '';
				if (c.sortable) {
					if (state.sortColumn === c.key) arrow = state.sortAsc ? ' ▴' : ' ▾';
					else arrow = ' ▾';
				}
				const sortCls = c.sortable ? 'ik-scan-sortable" data-sort="' + escAttr(c.key) : '';
				html += '<th class="' + sortCls + '">' + esc(c.label) + arrow + '</th>';
			});
			html += '<th>Status</th>';
			if (cfg.apply) html += '<th>Action</th>';
			html += '</tr>';
			tableHead.innerHTML = html;

			tableHead.querySelectorAll('.ik-scan-sortable').forEach(function (th) {
				th.style.cursor = 'pointer';
				th.addEventListener('click', function () {
					const key = th.dataset.sort;
					if (state.sortColumn === key) state.sortAsc = !state.sortAsc;
					else { state.sortColumn = key; state.sortAsc = true; }
					render();
				});
			});

			const selectAllPage = tableHead.querySelector('.ik-scan-select-all-page');
			if (selectAllPage) {
				selectAllPage.addEventListener('change', function () {
					const pageItems = getCurrentPageItems();
					pageItems.forEach(function (item) {
						const key = cfg.rowKey(item);
						if (!canSelectRow(item)) return;
						if (selectAllPage.checked) state.selection.add(key);
						else state.selection.delete(key);
					});
					state.selectAllMatching = false;
					render();
				});
				const pageItems = getCurrentPageItems();
				const selectableCount = pageItems.filter(canSelectRow).length;
				const selectedOnPage = pageItems.filter(function (item) {
					return state.selection.has(cfg.rowKey(item));
				}).length;
				selectAllPage.checked = selectableCount > 0 && selectedOnPage === selectableCount;
				selectAllPage.indeterminate = selectedOnPage > 0 && selectedOnPage < selectableCount;
			}
		}

		function canSelectRow(item) {
			const status = getRowStatus(item);
			if (status === 'applied' || status === 'skipped' || status === 'applying') return false;
			if (cfg.apply && cfg.apply.canApply && !cfg.apply.canApply(item)) return false;
			return true;
		}

		// ── Render: table body ───────────────────────────────────────────
		function getCurrentPageItems() {
			const filtered = getFilteredItems();
			const start = (state.page - 1) * cfg.pageSize;
			return filtered.slice(start, start + cfg.pageSize);
		}

		function renderTableBody() {
			const items = getCurrentPageItems();
			// select-col + expand-col + columns + status-col + (action-col if apply).
			const totalCols = 3 + cfg.columns.length + (cfg.apply ? 1 : 0);
			if (items.length === 0) {
				tableBody.innerHTML = '<tr><td colspan="' + totalCols + '"><em>' + esc(cfg.emptyMessage) + '</em></td></tr>';
				return;
			}

			let html = '';
			items.forEach(function (item) {
				const key = cfg.rowKey(item);
				const selectable = canSelectRow(item);
				const checked = state.selection.has(key) ? 'checked' : '';
				const status = getRowStatus(item);

				html += '<tr class="ik-scan-row ik-scan-row-' + status + '" data-row-key="' + escAttr(key) + '">';
				html += '<th class="check-column"><input type="checkbox" class="ik-scan-row-check" ' + checked +
					(selectable ? '' : ' disabled') + '></th>';
				html += '<td class="ik-scan-expand-col">';
				if (cfg.rowDetail) {
					html += '<button type="button" class="button-link ik-scan-expand-toggle">▶</button>';
				}
				html += '</td>';
				cfg.columns.forEach(function (c) {
					html += '<td>' + c.render(item) + '</td>';
				});
				html += '<td class="ik-scan-status-cell">' + renderStatusBadge(item) + '</td>';
				if (cfg.apply) {
					html += '<td class="ik-scan-row-action">';
					if (status === 'pending' && (!cfg.apply.canApply || cfg.apply.canApply(item))) {
						html += '<button type="button" class="button button-small ik-scan-row-apply">' + esc(cfg.perRowApplyLabel) + '</button>';
					} else if (status === 'applying') {
						html += '<span class="description">Applying…</span>';
					}
					html += '</td>';
				}
				html += '</tr>';

				if (cfg.rowDetail) {
					html += '<tr class="ik-scan-detail-row" style="display:none;" data-detail-for="' + escAttr(key) + '">' +
						'<td colspan="' + totalCols + '"></td></tr>';
				}
			});
			tableBody.innerHTML = html;

			// Bind row interactions.
			tableBody.querySelectorAll('.ik-scan-row-check').forEach(function (cb) {
				cb.addEventListener('change', function () {
					const row = cb.closest('.ik-scan-row');
					const key = row.dataset.rowKey;
					if (cb.checked) state.selection.add(key);
					else state.selection.delete(key);
					state.selectAllMatching = false;
					renderToolbar();
					renderTableHead();
				});
			});

			if (cfg.rowDetail) {
				tableBody.querySelectorAll('.ik-scan-expand-toggle').forEach(function (btn) {
					btn.addEventListener('click', function () {
						const row = btn.closest('.ik-scan-row');
						if (!row) return;
						// The detail row is always the immediate next sibling
						// (rendered together). Sibling traversal avoids any
						// quoting/escaping issues from rowKey-as-selector.
						const detailRow = row.nextElementSibling;
						if (!detailRow || !detailRow.classList.contains('ik-scan-detail-row')) return;
						const key = row.dataset.rowKey;
						const item = state.items.find(function (i) { return cfg.rowKey(i) === key; });
						if (!item) return;
						if (detailRow.style.display === 'none') {
							const cell = detailRow.querySelector('td');
							cell.innerHTML = cfg.rowDetail(item);
							if (cfg.bindRowDetail) cfg.bindRowDetail(cell, item);
							detailRow.style.display = '';
							btn.textContent = '▼';
						} else {
							detailRow.style.display = 'none';
							btn.textContent = '▶';
						}
					});
				});
			}

			if (cfg.apply) {
				tableBody.querySelectorAll('.ik-scan-row-apply').forEach(function (btn) {
					btn.addEventListener('click', function () {
						const row = btn.closest('.ik-scan-row');
						const key = row.dataset.rowKey;
						applyOne(key);
					});
				});
			}
		}

		// ── Render: pagination ───────────────────────────────────────────
		function renderPagination() {
			const total = getFilteredItems().length;
			const totalPages = Math.max(1, Math.ceil(total / cfg.pageSize));
			if (state.page > totalPages) state.page = totalPages;

			const html = (totalPages <= 1)
				? '<span class="displaying-num">' + total + ' items</span>'
				: '<div class="tablenav"><div class="tablenav-pages">' +
					'<span class="displaying-num">' + total + ' items</span> ' +
					'<span class="pagination-links">' +
					(state.page <= 1 ? '<span class="button disabled">«</span>' : '<a class="button ik-scan-page" href="#" data-page="1">«</a>') + ' ' +
					(state.page <= 1 ? '<span class="button disabled">‹</span>' : '<a class="button ik-scan-page" href="#" data-page="' + (state.page - 1) + '">‹</a>') +
					' <span class="paging-input">' +
						'<input type="number" class="ik-scan-current-page" value="' + state.page + '" min="1" max="' + totalPages + '" size="2" style="width:50px;"> of <span class="total-pages">' + totalPages + '</span>' +
					'</span> ' +
					(state.page >= totalPages ? '<span class="button disabled">›</span>' : '<a class="button ik-scan-page" href="#" data-page="' + (state.page + 1) + '">›</a>') + ' ' +
					(state.page >= totalPages ? '<span class="button disabled">»</span>' : '<a class="button ik-scan-page" href="#" data-page="' + totalPages + '">»</a>') +
					'</span></div></div>';

			paginationTop.innerHTML = html;
			paginationBot.innerHTML = html;

			containers.results.querySelectorAll('.ik-scan-page').forEach(function (a) {
				a.addEventListener('click', function (e) {
					e.preventDefault();
					state.page = parseInt(a.dataset.page, 10);
					render();
				});
			});
			containers.results.querySelectorAll('.ik-scan-current-page').forEach(function (inp) {
				inp.addEventListener('change', function () {
					const n = parseInt(inp.value, 10);
					if (!isNaN(n) && n >= 1 && n <= totalPages) {
						state.page = n;
						render();
					}
				});
			});
		}

		// ── Render: summary banner ───────────────────────────────────────
		function renderSummary() {
			const total = state.items.length;
			let appliedCount = 0, errorCount = 0, skippedCount = 0;
			state.items.forEach(function (item) {
				const s = getRowStatus(item);
				if (s === 'applied') appliedCount++;
				else if (s === 'error') errorCount++;
				else if (s === 'skipped') skippedCount++;
			});

			let summary = '<strong>' + total + '</strong> item' + (total !== 1 ? 's' : '');
			if (appliedCount) summary += ', <strong>' + appliedCount + '</strong> applied';
			if (errorCount) summary += ', <strong>' + errorCount + '</strong> errors';
			if (skippedCount) summary += ', <strong>' + skippedCount + '</strong> skipped';

			if (cfg.summarySuffix) summary += ' ' + cfg.summarySuffix(state.items);

			summaryEl.innerHTML = summary;
		}

		// ── Render: orchestrator ─────────────────────────────────────────
		function render() {
			// Preserve focus + caret in any inline input (search box / page-input)
			// that the user might be typing into. render() rebuilds the toolbar
			// and pagination DOM wholesale, which would otherwise blow away focus.
			const active = document.activeElement;
			let restoreSelector = null;
			let restoreStart = null;
			let restoreEnd   = null;
			if (active && containers.results.contains(active)) {
				if (active.classList.contains('ik-scan-search')) {
					restoreSelector = '.ik-scan-search';
				} else if (active.classList.contains('ik-scan-current-page')) {
					restoreSelector = '.ik-scan-current-page';
				}
				if (restoreSelector) {
					try {
						restoreStart = active.selectionStart;
						restoreEnd   = active.selectionEnd;
					} catch (e) { /* number inputs don't support selection */ }
				}
			}

			renderSummary();
			renderToolbar();
			renderTableHead();
			renderTableBody();
			renderPagination();

			if (restoreSelector) {
				const fresh = containers.results.querySelector(restoreSelector);
				if (fresh) {
					fresh.focus();
					if (restoreStart !== null) {
						try { fresh.setSelectionRange(restoreStart, restoreEnd); } catch (e) {}
					}
				}
			}
		}

		// ── Scan flow ────────────────────────────────────────────────────
		startBtn.addEventListener('click', function () {
			if (state.isScanning) return;
			let params = {};
			if (cfg.scan.getParams) {
				params = cfg.scan.getParams();
				if (params === false) return;
			}
			startScan(params);
		});

		cancelBtn.addEventListener('click', function () {
			if (state.isScanning) {
				state.isCancelling = true;
				state.scanGen++;
				cancelBtn.textContent = 'Cancelling…';
				cancelBtn.disabled = true;
				// Tell the server to mark the run as cancelled, if the module
				// persists run state (Restore Full Size / Repair Image Blocks).
				if (cfg.scan.onCancel && state.currentRunParams) {
					try { cfg.scan.onCancel(state.currentRunParams); } catch (e) {}
				}
				finishScanOrCancel();
			}
		});

		function finishScanOrCancel() {
			state.isScanning = false;
			containers.progress.style.display = 'none';
			startBtn.disabled = false;
			cancelBtn.textContent = 'Cancel';
			cancelBtn.disabled = false;
		}

		function startScan(extraParams) {
			state.items = [];
			state.rowState.clear();
			state.counters = {};
			state.selection.clear();
			state.selectAllMatching = false;
			state.page = 1;
			state.isCancelling = false;
			state.scanGen++;
			startBtn.disabled = true;

			containers.results.style.display = 'none';
			containers.progress.style.display = '';
			logEl.innerHTML = '';
			cfg.counters.forEach(function (c) { setCounter(c.key, 0); });
			updateProgress(progressBarEl, 0, 1, '0%');

			const gen = state.scanGen;
			state.isScanning = true;

			// Optional initRun step (used by Restore Full Size / Repair Image Blocks).
			const initPromise = cfg.scan.initRun
				? cfg.scan.initRun(extraParams)
				: Promise.resolve(Object.assign({}, extraParams));

			initPromise.then(function (runParams) {
				if (gen !== state.scanGen || state.isCancelling) { finishScanOrCancel(); return; }
				if (!runParams) { finishScanOrCancel(); return; }
				state.currentRunParams = runParams;
				runBatch(0, runParams, gen);
			}).catch(function (err) {
				if (gen !== state.scanGen) return;
				alert('Failed to start scan: ' + err.message);
				finishScanOrCancel();
			});
		}

		function runBatch(offset, runParams, gen) {
			if (gen !== state.scanGen || state.isCancelling) { finishScanOrCancel(); return; }

			const params = Object.assign({ offset: offset }, runParams);
			post(cfg.scan.action, params).then(function (resp) {
				if (gen !== state.scanGen || state.isCancelling) { finishScanOrCancel(); return; }
				if (!resp.success) {
					alert('Scan failed: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown'));
					finishScanOrCancel();
					return;
				}
				const d = resp.data || {};

				if (Array.isArray(d.items)) {
					d.items.forEach(function (item) { state.items.push(item); });
				}
				if (isPlainObject(d.progress)) {
					Object.keys(d.progress).forEach(function (k) { setCounter(k, d.progress[k]); });
				}
				// Reserved client-side counter: `_items` reports the cumulative
				// count of rows the user can see, derived from state.items.length.
				// Modules opt in by including { key: '_items', label: '…' } in
				// their counters config. No-op for modules that don't.
				setCounter('_items', state.items.length);
				if (Array.isArray(d.log_lines)) {
					d.log_lines.forEach(function (line) {
						const text = line.title + (line.detail ? ' — ' + line.detail : '');
						appendLog(logEl, line.type || 'info', text);
					});
				}

				const newOffset = d.offset != null ? d.offset : (offset + (d.items ? d.items.length : 0));
				// Total may come back per-batch (`d.total`) or from initRun (`runParams.total_posts` / `runParams.total`).
				const total = (d.total != null) ? d.total
					: (runParams && runParams.total_posts != null) ? runParams.total_posts
					: (runParams && runParams.total != null) ? runParams.total
					: Math.max(newOffset, 1);
				updateProgress(progressBarEl, newOffset, total,
					formatNumber(newOffset) + ' / ' + formatNumber(total));

				if (d.done) {
					if (cfg.scan.afterDone) {
						// Module loads items in a separate request after the batch loop.
						cfg.scan.afterDone(runParams).then(function (items) {
							if (gen !== state.scanGen) return;
							finishScanOrCancel();
							state.items = Array.isArray(items) ? items.slice() : [];
							if (cfg.scan.onItemsLoaded) cfg.scan.onItemsLoaded(state.items);
							showResults();
						}).catch(function (err) {
							if (gen !== state.scanGen) return;
							alert('Failed to load results: ' + err.message);
							finishScanOrCancel();
						});
					} else {
						finishScanOrCancel();
						if (cfg.scan.onItemsLoaded) cfg.scan.onItemsLoaded(state.items);
						showResults();
					}
				} else {
					runBatch(newOffset, runParams, gen);
				}
			}).catch(function (err) {
				if (gen !== state.scanGen || state.isCancelling) { finishScanOrCancel(); return; }
				alert('Scan error: ' + err.message);
				finishScanOrCancel();
			});
		}

		function showResults() {
			if (state.items.length === 0) {
				containers.results.style.display = '';
				summaryEl.innerHTML = '<em>' + esc(cfg.emptyMessage) + '</em>';
				toolbarEl.innerHTML = '';
				tableHead.innerHTML = '';
				tableBody.innerHTML = '';
				paginationTop.innerHTML = '';
				paginationBot.innerHTML = '';
				return;
			}
			containers.results.style.display = '';
			render();
		}

		// ── Apply (per-row + bulk) ───────────────────────────────────────
		function applyOne(key) {
			const item = state.items.find(function (i) { return cfg.rowKey(i) === key; });
			if (!item || !cfg.apply) return;
			if (cfg.apply.canApply && !cfg.apply.canApply(item)) return;
			// Surface the apply log details element so the user can see the
			// outcome line (success or error message) without having to
			// remember to expand it after the fact.
			applyLogWrap.style.display = '';
			doApply(item).then(updateBannerForBulk);
		}

		function doApply(item) {
			setRowState(item, 'applying');
			updateRowInPlace(item);

			const params = cfg.apply.getParams
				? cfg.apply.getParams(item)
				: { item_id: cfg.rowKey(item) };

			return post(cfg.apply.action, params).then(function (resp) {
				// Modules whose AJAX wraps a per-file results array (relocator,
				// unused-cleaner, orphan-importer) can supply `isSuccess` and
				// `errorMessage` to read the inner outcome instead of relying
				// on the outer `resp.success` flag alone.
				const isSuccess = cfg.apply.isSuccess
					? cfg.apply.isSuccess(resp, item)
					: !!resp.success;
				const defaultMsg = (resp.data && resp.data.message)
					? resp.data.message
					: 'Apply failed';
				const msg = cfg.apply.errorMessage
					? cfg.apply.errorMessage(resp, item)
					: defaultMsg;

				if (!isSuccess) {
					setRowState(item, 'error', msg);
					if (cfg.apply.updateItem) cfg.apply.updateItem(item, { success: false, message: msg });
					appendLog(applyLogEl, 'error', (item.post_title || cfg.rowKey(item)) + ' — ' + msg);
				} else {
					setRowState(item, 'applied');
					if (cfg.apply.updateItem) cfg.apply.updateItem(item, Object.assign({ success: true }, resp.data || {}));
					appendLog(applyLogEl, 'success', (item.post_title || cfg.rowKey(item)) + ' — applied');
				}
				updateRowInPlace(item);
				renderSummary();
				renderToolbar();
			}).catch(function (err) {
				setRowState(item, 'error', err.message);
				appendLog(applyLogEl, 'error', (item.post_title || cfg.rowKey(item)) + ' — ' + err.message);
				updateRowInPlace(item);
				renderSummary();
			});
		}

		function updateRowInPlace(item) {
			const key = cfg.rowKey(item);
			const row = tableBody.querySelector('.ik-scan-row[data-row-key="' + key.replace(/"/g, '\\"') + '"]');
			if (!row) return;
			row.classList.remove(
				'ik-scan-row-pending', 'ik-scan-row-applying', 'ik-scan-row-applied', 'ik-scan-row-skipped', 'ik-scan-row-error'
			);
			row.classList.add('ik-scan-row-' + getRowStatus(item));
			const statusCell = row.querySelector('.ik-scan-status-cell');
			if (statusCell) statusCell.innerHTML = renderStatusBadge(item);
			const actionCell = row.querySelector('.ik-scan-row-action');
			if (actionCell) {
				const status = getRowStatus(item);
				if (status === 'pending') {
					actionCell.innerHTML = '<button type="button" class="button button-small ik-scan-row-apply">' + esc(cfg.perRowApplyLabel) + '</button>';
					const btn = actionCell.querySelector('.ik-scan-row-apply');
					if (btn) btn.addEventListener('click', function () { applyOne(key); });
				} else if (status === 'applying') {
					actionCell.innerHTML = '<span class="description">Applying…</span>';
				} else {
					actionCell.innerHTML = '';
				}
			}
			// Disable the row's checkbox if no longer selectable.
			const cb = row.querySelector('.ik-scan-row-check');
			if (cb) {
				const selectable = canSelectRow(item);
				cb.disabled = !selectable;
				if (!selectable) {
					cb.checked = false;
					state.selection.delete(key);
				}
			}
		}

		function startBulkApply() {
			if (!cfg.apply || state.selection.size === 0) return;
			const keys = Array.from(state.selection);
			const items = keys.map(function (k) {
				return state.items.find(function (i) { return cfg.rowKey(i) === k; });
			}).filter(Boolean);
			if (items.length === 0) return;

			// Multi-row confirmation handled by the two-step gate on the Apply
			// button in renderToolbar. By the time we get here, the user has
			// already confirmed.

			state.applyQueue = items;
			state.applyAbort = false;
			state.applyTotalForRun = items.length;
			state.applyDoneForRun = 0;
			applyLogWrap.style.display = '';
			showApplyBanner();
			processApplyQueue();
		}

		function showApplyBanner() {
			applyBanner.innerHTML =
				'<span>Applying ' + state.applyDoneForRun + ' / ' + state.applyTotalForRun + '…</span> ' +
				'<button type="button" class="button button-small ik-scan-apply-cancel">Cancel</button>';
			applyBanner.style.display = '';
			const cancel = applyBanner.querySelector('.ik-scan-apply-cancel');
			if (cancel) cancel.addEventListener('click', function () {
				state.applyAbort = true;
				cancel.textContent = 'Cancelling…';
				cancel.disabled = true;
			});
		}

		function hideApplyBanner() {
			applyBanner.style.display = 'none';
			applyBanner.innerHTML = '';
		}

		function updateBannerForBulk() {
			if (state.applyTotalForRun === 0) return;
			const counter = applyBanner.querySelector('span');
			if (counter) counter.textContent = 'Applying ' + state.applyDoneForRun + ' / ' + state.applyTotalForRun + '…';
		}

		function processApplyQueue() {
			if (state.applyAbort) {
				// Mark remaining as still-pending; revert selection.
				state.applyQueue.forEach(function (item) {
					if (getRowStatus(item) === 'applying') {
						setRowState(item, 'pending');
						updateRowInPlace(item);
					}
				});
				state.applyQueue = [];
				hideApplyBanner();
				renderSummary();
				return;
			}
			if (state.applyQueue.length === 0) {
				hideApplyBanner();
				renderSummary();
				renderToolbar();
				return;
			}
			const item = state.applyQueue.shift();
			doApply(item).then(function () {
				state.applyDoneForRun++;
				updateBannerForBulk();
				state.selection.delete(cfg.rowKey(item));
				setTimeout(processApplyQueue, 50);
			});
		}

		// ── CSV export ───────────────────────────────────────────────────
		function getExportItems() {
			// Selection-aware: empty selection means "export everything" (matches
			// the rest of the plugin's "empty = include all" convention).
			if (state.selection.size === 0) return state.items;
			return state.items.filter(function (item) {
				return state.selection.has(cfg.rowKey(item));
			});
		}

		function doExportCSV() {
			if (!cfg.csvExport) return;
			const items = getExportItems();
			let rows;
			if (cfg.csvExport.rows) {
				// One item → many rows (e.g. multiple replacements per post).
				rows = [];
				items.forEach(function (item) {
					const itemRows = cfg.csvExport.rows(item) || [];
					itemRows.forEach(function (r) { rows.push(r); });
				});
			} else {
				rows = items.map(cfg.csvExport.row);
			}
			exportCSV(cfg.csvExport.filename(), cfg.csvExport.columns, rows);
		}

		// ── Reset (after discard) ────────────────────────────────────────
		function reset() {
			state.items = [];
			state.rowState.clear();
			state.selection.clear();
			state.applyQueue = [];
			containers.results.style.display = 'none';
			containers.progress.style.display = 'none';
			startBtn.disabled = false;
		}

		// ── Public controller ────────────────────────────────────────────
		return {
			reset: reset,
			startScan: startScan,
			loadItems: function (items) {
				state.items = items.slice();
				state.rowState.clear();
				state.selection.clear();
				state.page = 1;
				showResults();
			},
			getItems: function () { return state.items; },
			getExportItems: getExportItems,
			getRowStatus: getRowStatus,
			replaceItem: function (updated) {
				const key = cfg.rowKey(updated);
				const idx = state.items.findIndex(function (i) { return cfg.rowKey(i) === key; });
				if (idx === -1) return false;
				state.items[idx] = updated;
				state.rowState.delete(key);
				render();
				return true;
			},
		};
	}

	window.imageKitScanUI = { init: init };
})();
