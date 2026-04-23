/**
 * Image Kit — Image Upgrader module JS.
 *
 * Handles scan batch loop, preview with exclude checkboxes, missing file panel,
 * apply flow, CSV export, history tab, and pending review auto-restore.
 */
(function () {
	'use strict';

	const { post, escHtml, appendLog, exportCSV, updateProgress } = imageKitUtils;
	const config = window.imageKitUpgrader || {};
	const {
		startRunAction, processBatchAction, applyRunAction, cancelRunAction,
		discardRunAction, deleteRunAction, getRunItemsAction, applySingleAction,
		getHistoryAction, diagnosticsAction, selectCandidateAction,
	} = config;

	const $ = id => document.getElementById(id);
	const $$ = sel => document.querySelectorAll(sel);

	// ─── State ──────────────────────────────────────────────────────────

	let currentRunId = null;
	let currentPostTypes = [];
	let totalPosts = 0;
	let scanOffset = 0;
	let applyOffset = 0;
	let isCancelling = false;
	let runItems = [];
	let currentMode = 'scan';
	let sortColumn = null;
	let sortAsc = true;
	let previewPage = 1;
	const PREVIEW_PER_PAGE = 25;
	let searchQuery = '';
	let statusFilter = 'all';
	let markupTypeFilter = 'all';
	let issueFilter = 'all';
	let checkedItemIds = new Set();

	// ─── Helpers ────────────────────────────────────────────────────────

	function esc(str) {
		const div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	function showSection(id) {
		['ik-iu-config', 'ik-iu-progress', 'ik-iu-preview', 'ik-iu-apply-progress', 'ik-iu-success'].forEach(s => {
			const el = $(s);
			if (el) el.style.display = s === id ? '' : 'none';
		});
	}

	function updateProgressBar(progressEl, current, total) {
		if (!progressEl) return;
		const pct = total > 0 ? Math.round((current / total) * 100) : 0;
		updateProgress(progressEl, current, total, pct + '%');
	}

	function bindPreviewButtons(container) {
		container.querySelectorAll('.ik-iu-show-preview-btn').forEach(btn => {
			btn.addEventListener('click', () => {
				const row = btn.closest('.ik-iu-replacement-row') || btn.closest('.ik-iu-modal-replacement-row');
				if (row) {
					row.querySelectorAll('.ik-iu-thumb-placeholder').forEach(ph => {
						if (!ph.querySelector('img')) {
							const img = document.createElement('img');
							img.src = ph.dataset.src;
							img.className = 'ik-iu-thumbnail';
							img.loading = 'lazy';
							ph.appendChild(img);
						}
					});
				}
				btn.remove();
			});
		});
	}

	function bindCandidateRadios(container) {
		container.querySelectorAll('.ik-iu-candidate-radio').forEach(radio => {
			radio.addEventListener('change', () => {
				const itemId = radio.dataset.itemId;
				const idx = parseInt(radio.dataset.idx, 10);
				const attachmentId = radio.dataset.attachmentId;

				post(selectCandidateAction, {
					item_id: itemId,
					rep_index: idx,
					attachment_id: attachmentId,
				}).then(res => {
					if (!res.success) {
						alert(res.data?.message || 'Failed to save selection.');
						return;
					}

					const item = runItems.find(i => String(i.id) === itemId);
					if (item && item.replacements[idx]) {
						item.replacements[idx].attachment_id = parseInt(attachmentId, 10);
						item.replacements[idx].to_url = res.data.to_url;
						item.replacements[idx].to_dimensions = res.data.to_dimensions;
					}

					const row = radio.closest('.ik-iu-replacement-row');
					if (row) {
						const afterUrl = row.querySelector('.ik-iu-after .ik-iu-url-label');
						if (afterUrl) afterUrl.textContent = res.data.to_url;

						const afterDim = row.querySelector('.ik-iu-after .ik-iu-dim-label');
						if (afterDim && res.data.to_dimensions) {
							afterDim.textContent = res.data.to_dimensions.width + '\u00d7' + res.data.to_dimensions.height;
						}
					}
				});
			});
		});
	}

	// ─── Sub-tabs ───────────────────────────────────────────────────────

	$$('.ik-sub-tab').forEach(btn => {
		btn.addEventListener('click', function () {
			// Only handle sub-tabs within Image Upgrader.
			const parent = this.closest('.ik-sub-tabs');
			if (!parent || !parent.nextElementSibling || !parent.nextElementSibling.classList.contains('ik-iu-subtab-content')) {
				// Check if this is our sub-tab set by looking for our specific IDs.
				const siblings = parent ? parent.querySelectorAll('.ik-sub-tab') : [];
				let isOurs = false;
				siblings.forEach(s => {
					if (s.dataset.subtab === 'run' || s.dataset.subtab === 'history') isOurs = true;
				});
				if (!isOurs) return;
			}

			parent.querySelectorAll('.ik-sub-tab').forEach(b => b.classList.remove('active'));
			this.classList.add('active');
			$$('.ik-iu-subtab-content').forEach(p => p.style.display = 'none');
			const target = $('ik-iu-tab-' + this.dataset.subtab);
			if (target) target.style.display = '';

			if (this.dataset.subtab === 'history') {
				loadHistory(1);
			}
		});
	});

	// ─── Scan Flow ──────────────────────────────────────────────────────

	const startBtn = $('ik-iu-start-scan');
	if (startBtn) {
		startBtn.addEventListener('click', () => {
			const checked = $$('input[name="ik_iu_post_types[]"]:checked');
			currentPostTypes = Array.from(checked).map(cb => cb.value);
			if (currentPostTypes.length === 0) { alert('Please select at least one post type.'); return; }
			currentMode = 'scan';
			startBtn.disabled = true;
			const auditBtn = $('ik-iu-start-audit');
			if (auditBtn) auditBtn.disabled = true;
			startScan();
		});
	}

	const auditBtn = $('ik-iu-start-audit');
	if (auditBtn) {
		auditBtn.addEventListener('click', () => {
			const checked = $$('input[name="ik_iu_post_types[]"]:checked');
			currentPostTypes = Array.from(checked).map(cb => cb.value);
			if (currentPostTypes.length === 0) { alert('Please select at least one post type.'); return; }
			currentMode = 'audit';
			auditBtn.disabled = true;
			$('ik-iu-start-scan').disabled = true;
			startScan();
		});
	}

	const cancelBtn = $('ik-iu-cancel-scan');
	if (cancelBtn) {
		cancelBtn.addEventListener('click', cancelScan);
	}

	function startScan() {
		post(startRunAction, { post_types: currentPostTypes, mode: currentMode })
			.then(res => {
				if (!res.success) {
					alert(res.data?.message || 'Failed to start scan.');
					resetScanButtons();
					return;
				}

				currentRunId = res.data.run_id;
				totalPosts = res.data.total_posts;
				scanOffset = 0;
				isCancelling = false;

				showSection('ik-iu-progress');
				$('ik-iu-log-panel').innerHTML = '';

				const counterLabel = $('ik-iu-counter-replaced-label');
				if (counterLabel) {
					counterLabel.textContent = currentMode === 'audit' ? 'Issues found:' : 'Images found:';
				}

				scanNextBatch();
			})
			.catch(err => {
				alert('Error: ' + err.message);
				resetScanButtons();
			});
	}

	function scanNextBatch() {
		if (isCancelling) return;

		post(processBatchAction, {
			run_id: currentRunId,
			offset: scanOffset,
			total_posts: totalPosts,
			post_types: currentPostTypes,
		}).then(res => {
			if (!res.success) {
				alert(res.data?.message || 'Batch processing failed.');
				showSection('ik-iu-config');
				resetScanButtons();
				return;
			}

			const d = res.data;
			scanOffset = d.offset;

			$('ik-iu-counter-scanned').textContent = d.progress.posts_scanned;
			$('ik-iu-counter-replaced').textContent = d.progress.images_replaced;
			$('ik-iu-counter-skipped').textContent = d.progress.images_skipped;

			updateProgressBar($('ik-iu-scan-progress'), d.progress.posts_scanned, totalPosts);

			d.log_lines.forEach(line => {
				const text = line.replaced > 0
					? line.title + ' \u2014 ' + line.replaced + ' replacement(s)'
					: line.skipped > 0
						? line.title + ' \u2014 ' + line.skipped + ' skipped'
						: line.title + ' \u2014 no changes';
				appendLog($('ik-iu-log-panel'), line.replaced > 0 ? 'success' : 'info', text);
			});

			if (d.done) {
				onScanComplete();
			} else {
				setTimeout(() => scanNextBatch(), 200);
			}
		}).catch(err => {
			alert('Error: ' + err.message);
		});
	}

	function cancelScan() {
		isCancelling = true;
		cancelBtn.textContent = 'Cancelling\u2026';
		cancelBtn.disabled = true;

		post(cancelRunAction, { run_id: currentRunId }).then(() => {
			cancelBtn.textContent = 'Cancel';
			cancelBtn.disabled = false;
			onScanComplete();
		});
	}

	function onScanComplete() {
		post(getRunItemsAction, { run_id: currentRunId }).then(res => {
			if (!res.success || !res.data.items.length) {
				alert(currentMode === 'audit' ? 'No incomplete markup found.' : 'No resized images found.');
				showSection('ik-iu-config');
				resetScanButtons();
				return;
			}

			runItems = res.data.items;
			renderPreview();
		}).catch(() => {
			showSection('ik-iu-config');
			resetScanButtons();
		});
	}

	function resetScanButtons() {
		if ($('ik-iu-start-scan')) $('ik-iu-start-scan').disabled = false;
		if ($('ik-iu-start-audit')) $('ik-iu-start-audit').disabled = false;
		currentMode = 'scan';
	}

	// ─── Preview ────────────────────────────────────────────────────────

	function renderPreview() {
		showSection('ik-iu-preview');

		searchQuery = '';
		statusFilter = 'all';
		markupTypeFilter = 'all';
		issueFilter = 'all';
		previewPage = 1;
		checkedItemIds.clear();
		sortColumn = null;
		sortAsc = true;

		let totalReplacements = 0;
		let totalSkipped = 0;
		let postsAffected = 0;
		let missingFiles = [];
		const skipReasons = {};

		runItems.forEach(item => {
			if (item.images_replaced > 0) postsAffected++;
			totalReplacements += item.images_replaced;
			totalSkipped += item.images_skipped;

			if (item.replacements) {
				item.replacements.forEach((rep, idx) => {
					if (rep.skip_reason === 'file_missing') missingFiles.push({ item, rep, idx });
					if (rep.skip_reason) skipReasons[rep.skip_reason] = (skipReasons[rep.skip_reason] || 0) + 1;
				});
			}
		});

		let skipDetail = '';
		if (totalSkipped > 0) {
			const labels = { attachment_not_found: 'no attachment found', already_full_size: 'already full size', file_missing: 'file missing' };
			const parts = Object.entries(skipReasons).map(([r, c]) => c + ' ' + (labels[r] || r));
			skipDetail = ' (' + parts.join(', ') + ')';
		}

		const itemLabel = currentMode === 'audit' ? 'markup issues to fix' : 'images to replace';
		$('ik-iu-preview-summary').innerHTML =
			'<strong>' + postsAffected + '</strong> posts affected, ' +
			'<strong>' + totalReplacements + '</strong> ' + itemLabel + ', ' +
			'<strong>' + totalSkipped + '</strong> skipped' + skipDetail + '.';

		renderPreviewTable();

		if (missingFiles.length > 0) renderMissingPanel(missingFiles);
	}

	function getBaseItems() {
		return runItems.filter(item => item.images_replaced > 0 || item.images_skipped > 0);
	}

	function applyNonStatusFilters(items) {
		let filtered = items;
		if (searchQuery) {
			const q = searchQuery.toLowerCase();
			filtered = filtered.filter(item => (item.post_title || '').toLowerCase().includes(q));
		}
		if (markupTypeFilter !== 'all') {
			filtered = filtered.filter(item => item.replacements && item.replacements.some(r => r.markup_type === markupTypeFilter));
		}
		if (issueFilter !== 'all') {
			filtered = filtered.filter(item => item.replacements && item.replacements.some(r => r.issues && r.issues.includes(issueFilter)));
		}
		return filtered;
	}

	function getFilteredItems() {
		let filtered = applyNonStatusFilters(getBaseItems());

		if (statusFilter === 'has_replacements') filtered = filtered.filter(i => i.images_replaced > 0);
		else if (statusFilter === 'skipped_only') filtered = filtered.filter(i => i.images_replaced === 0 && i.images_skipped > 0);
		else if (statusFilter === 'applied') filtered = filtered.filter(i => !!i.applied_at);

		if (sortColumn) {
			filtered.sort((a, b) => {
				let va = a[sortColumn], vb = b[sortColumn];
				if (sortColumn === 'post_title' || sortColumn === 'post_date') {
					va = (va || '').toLowerCase(); vb = (vb || '').toLowerCase();
					return sortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
				}
				va = va || 0; vb = vb || 0;
				return sortAsc ? va - vb : vb - va;
			});
		}

		return filtered;
	}

	function getFilterCounts() {
		const base = applyNonStatusFilters(getBaseItems());
		return {
			all: base.length,
			has_replacements: base.filter(i => i.images_replaced > 0).length,
			skipped_only: base.filter(i => i.images_replaced === 0 && i.images_skipped > 0).length,
			applied: base.filter(i => !!i.applied_at).length,
		};
	}

	function renderSortIndicator(col) {
		if (col !== sortColumn) return ' \u25be';
		return sortAsc ? ' \u25b4' : ' \u25be';
	}

	function renderPreviewTable() {
		const wrap = $('ik-iu-preview-table-wrap');
		const filteredItems = getFilteredItems();
		const counts = getFilterCounts();
		const totalPages = Math.ceil(filteredItems.length / PREVIEW_PER_PAGE) || 1;
		if (previewPage > totalPages) previewPage = totalPages;

		let html = '';

		// Status filter tabs.
		const tabs = [
			{ key: 'all', label: 'All' },
			{ key: 'has_replacements', label: 'Has replacements' },
			{ key: 'skipped_only', label: 'Skipped only' },
			{ key: 'applied', label: 'Applied' },
		].filter(t => t.key === 'all' || counts[t.key] > 0);

		html += '<ul class="subsubsub">';
		tabs.forEach((tab, i) => {
			const current = statusFilter === tab.key ? ' class="current"' : '';
			const sep = i < tabs.length - 1 ? ' |' : '';
			html += '<li><a href="#" data-filter="' + tab.key + '"' + current + ' class="ik-iu-status-filter' + (statusFilter === tab.key ? ' current' : '') + '">';
			html += tab.label + ' <span class="count">(' + counts[tab.key] + ')</span>';
			html += '</a>' + sep + '</li>';
		});
		html += '</ul>';

		// Search box.
		html += '<p class="search-box"><input type="search" id="ik-iu-search-input" value="' + esc(searchQuery) + '" placeholder="Search posts\u2026"> ';
		html += '<input type="submit" id="ik-iu-search-submit" class="button" value="Search"></p>';
		html += '<div style="clear:both;"></div>';

		if (filteredItems.length === 0) {
			html += '<p>No items match the current filters.</p>';
			wrap.innerHTML = html;
			bindTableEvents(wrap);
			return;
		}

		// Pagination.
		const paginationHtml = renderPagination(filteredItems.length, totalPages);

		html += paginationHtml;

		const start = (previewPage - 1) * PREVIEW_PER_PAGE;
		const pageItems = filteredItems.slice(start, start + PREVIEW_PER_PAGE);

		// Table.
		html += '<table class="widefat striped ik-iu-preview-table"><thead><tr>';
		html += '<th class="check-column"><input type="checkbox" class="ik-iu-select-all-cb"></th>';
		html += '<th></th>';
		html += '<th class="ik-iu-sortable" data-sort="post_title">Post' + renderSortIndicator('post_title') + '</th>';
		html += '<th class="ik-iu-sortable" data-sort="post_date">Date' + renderSortIndicator('post_date') + '</th>';
		html += '<th class="ik-iu-sortable" data-sort="images_replaced">Replacements' + renderSortIndicator('images_replaced') + '</th>';
		html += '<th class="ik-iu-sortable" data-sort="images_skipped">Skipped' + renderSortIndicator('images_skipped') + '</th>';
		html += '<th></th>';
		html += '</tr></thead><tbody>';

		pageItems.forEach(item => {
			const canApply = item.images_replaced > 0 && !item.applied_at;
			const isChecked = checkedItemIds.has(String(item.id));

			html += '<tr class="ik-iu-preview-row' + (item.applied_at ? ' ik-iu-applied' : '') + '" data-item-id="' + item.id + '">';
			html += '<th scope="row" class="check-column"><input type="checkbox" class="ik-iu-row-cb" data-item-id="' + item.id + '" ' + (isChecked ? 'checked' : '') + ' ' + (!canApply ? 'disabled' : '') + '></th>';
			html += '<td><button type="button" class="ik-iu-expand-toggle button-link" data-item-id="' + item.id + '">\u25b6</button></td>';
			html += '<td><a href="' + esc(item.view_url) + '" target="_blank">' + esc(item.post_title) + '</a>';
			if (item.edit_url) html += ' <a href="' + esc(item.edit_url) + '" target="_blank" title="Edit post">\u270e</a>';
			html += '</td>';
			html += '<td>' + esc(item.post_date || '') + '</td>';
			html += '<td>' + item.images_replaced + '</td>';
			html += '<td>' + item.images_skipped + '</td>';

			if (item.images_replaced === 0) {
				html += '<td></td>';
			} else if (item.applied_at) {
				html += '<td><button type="button" class="button button-small disabled" disabled>Applied \u2713</button></td>';
			} else {
				html += '<td><button type="button" class="button button-small ik-iu-apply-single-btn" data-item-id="' + item.id + '">Apply</button></td>';
			}

			html += '</tr>';
			html += '<tr class="ik-iu-preview-detail" id="ik-iu-detail-' + item.id + '" style="display:none;"><td colspan="7"></td></tr>';
		});

		html += '</tbody></table>';
		html += paginationHtml;

		wrap.innerHTML = html;
		bindTableEvents(wrap);
	}

	function renderPagination(totalItems, totalPages) {
		if (totalPages <= 1) return '<div class="tablenav"><span class="displaying-num">' + totalItems + ' items</span></div>';

		let html = '<div class="tablenav"><div class="tablenav-pages">';
		html += '<span class="displaying-num">' + totalItems + ' items</span> ';
		html += '<span class="pagination-links">';

		html += previewPage <= 1
			? '<span class="button disabled">&lsaquo;</span>'
			: '<a class="button ik-iu-page-link" data-page="' + (previewPage - 1) + '" href="#">&lsaquo;</a>';

		html += ' <span>' + previewPage + ' / ' + totalPages + '</span> ';

		html += previewPage >= totalPages
			? '<span class="button disabled">&rsaquo;</span>'
			: '<a class="button ik-iu-page-link" data-page="' + (previewPage + 1) + '" href="#">&rsaquo;</a>';

		html += '</span></div></div>';
		return html;
	}

	function bindTableEvents(wrap) {
		wrap.querySelectorAll('.ik-iu-sortable').forEach(th => {
			th.style.cursor = 'pointer';
			th.addEventListener('click', () => {
				const col = th.dataset.sort;
				if (col === sortColumn) sortAsc = !sortAsc;
				else { sortColumn = col; sortAsc = col === 'images_replaced' ? false : true; }
				previewPage = 1;
				renderPreviewTable();
			});
		});

		wrap.querySelectorAll('.ik-iu-page-link').forEach(link => {
			link.addEventListener('click', e => {
				e.preventDefault();
				previewPage = parseInt(link.dataset.page, 10);
				renderPreviewTable();
			});
		});

		wrap.querySelectorAll('.ik-iu-expand-toggle').forEach(btn => {
			btn.addEventListener('click', () => {
				const itemId = btn.dataset.itemId;
				const detail = document.getElementById('ik-iu-detail-' + itemId);
				if (detail.style.display === 'none') {
					const cell = detail.querySelector('td');
					if (!cell.innerHTML) {
						const item = runItems.find(i => String(i.id) === itemId);
						if (item) {
							cell.innerHTML = renderReplacementDetail(item);
							bindPreviewButtons(cell);
							bindCandidateRadios(cell);
						}
					}
					detail.style.display = '';
					btn.textContent = '\u25bc';
				} else {
					detail.style.display = 'none';
					btn.textContent = '\u25b6';
				}
			});
		});

		wrap.querySelectorAll('.ik-iu-apply-single-btn').forEach(btn => {
			btn.addEventListener('click', () => applySingleItem(btn));
		});

		const searchBtn = wrap.querySelector('#ik-iu-search-submit');
		const searchInput = wrap.querySelector('#ik-iu-search-input');
		if (searchBtn && searchInput) {
			searchBtn.addEventListener('click', e => { e.preventDefault(); searchQuery = searchInput.value; previewPage = 1; renderPreviewTable(); });
			searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); searchQuery = searchInput.value; previewPage = 1; renderPreviewTable(); } });
		}

		wrap.querySelectorAll('.ik-iu-status-filter').forEach(link => {
			link.addEventListener('click', e => { e.preventDefault(); statusFilter = link.dataset.filter; previewPage = 1; renderPreviewTable(); });
		});

		wrap.querySelectorAll('.ik-iu-select-all-cb').forEach(cb => {
			cb.addEventListener('change', () => {
				wrap.querySelectorAll('.ik-iu-row-cb:not(:disabled)').forEach(rowCb => {
					rowCb.checked = cb.checked;
					if (cb.checked) checkedItemIds.add(rowCb.dataset.itemId);
					else checkedItemIds.delete(rowCb.dataset.itemId);
				});
			});
		});

		wrap.querySelectorAll('.ik-iu-row-cb').forEach(cb => {
			cb.addEventListener('change', () => {
				if (cb.checked) checkedItemIds.add(cb.dataset.itemId);
				else checkedItemIds.delete(cb.dataset.itemId);
			});
		});
	}

	function applySingleItem(btn) {
		const itemId = btn.dataset.itemId;
		btn.disabled = true;
		btn.textContent = 'Applying\u2026';

		post(applySingleAction, { run_id: currentRunId, item_id: itemId })
			.then(res => {
				if (!res.success) {
					btn.disabled = false;
					btn.textContent = 'Apply';
					alert(res.data?.message || 'Apply failed.');
					return;
				}

				btn.textContent = 'Applied \u2713';
				btn.classList.add('disabled');
				const row = btn.closest('tr');
				if (row) row.classList.add('ik-iu-applied');

				if (row) {
					const cb = row.querySelector('.ik-iu-row-cb');
					if (cb) { cb.checked = false; cb.disabled = true; }
				}
				checkedItemIds.delete(itemId);

				const memItem = runItems.find(i => String(i.id) === itemId);
				if (memItem) memItem.applied_at = new Date().toISOString();
			})
			.catch(err => {
				btn.disabled = false;
				btn.textContent = 'Apply';
				alert('Error: ' + err.message);
			});
	}

	// ─── Replacement Detail ─────────────────────────────────────────────

	function renderReplacementDetail(item) {
		if (!item.replacements || item.replacements.length === 0) return '<p>No replacement details.</p>';

		let html = '<div class="ik-iu-replacement-details">';

		item.replacements.forEach((rep, idx) => {
			if (rep.markup_type === 'gutenberg_audit') {
				html += renderAuditDetail(item, rep, idx);
				return;
			}

			if (rep.skipped) {
				const skipLabels = {
					attachment_not_found: 'No matching attachment in media library',
					already_full_size: 'Already at full size',
					file_missing: 'Full-size file not found on disk',
				};
				html += '<div class="ik-iu-replacement-row ik-iu-replacement-skipped">';
				html += '<div class="ik-iu-skip-reason">' + (skipLabels[rep.skip_reason] || rep.skip_reason) + '</div>';
				html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
				if (rep.from_size && rep.from_size !== 'nonstandard_path') {
					html += '<div class="ik-iu-dim-label">Size: ' + esc(rep.from_size) + '</div>';
				}
				html += '</div>';
				return;
			}

			const checked = !rep.excluded ? 'checked' : '';
			const isFilenameMatch = rep.from_size === 'nonstandard_path';
			const sizeLabel = isFilenameMatch ? 'Non-standard path' : rep.from_size;

			html += '<div class="ik-iu-replacement-row">';
			html += '<label class="ik-iu-exclude-label"><input type="checkbox" class="ik-iu-exclude-cb" ' + checked + ' data-item-id="' + item.id + '" data-idx="' + idx + '"> Include</label>';

			if (isFilenameMatch) html += '<div class="ik-iu-filename-match-badge">Filename match</div>';

			html += '<div class="ik-iu-before-after">';
			html += '<div class="ik-iu-before">';
			html += '<div class="ik-iu-thumb-placeholder" data-src="' + esc(rep.from_url) + '"></div>';
			html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
			html += '<div class="ik-iu-dim-label">' + esc(sizeLabel) + '</div>';
			html += '<div class="ik-iu-type-label">' + esc(rep.markup_type || 'unknown') + '</div>';
			html += '</div>';
			html += '<div class="ik-iu-arrow">\u2192</div>';
			html += '<div class="ik-iu-after">';
			html += '<div class="ik-iu-thumb-placeholder" data-src="' + esc(rep.to_url) + '"></div>';
			html += '<div class="ik-iu-url-label">' + esc(rep.to_url) + '</div>';
			if (rep.to_dimensions) html += '<div class="ik-iu-dim-label">' + rep.to_dimensions.width + '\u00d7' + rep.to_dimensions.height + '</div>';
			if (rep.format_variant_used) html += '<div class="ik-iu-variant-label">Format: ' + esc(rep.format_variant_used) + '</div>';
			html += '</div>';
			html += '</div>';
			html += '<button type="button" class="button button-small ik-iu-show-preview-btn">Show preview</button>';

			if (rep.candidates && rep.candidates.length > 1) {
				html += '<div class="ik-iu-candidates">';
				html += '<p><strong>Multiple media library matches \u2014 select one:</strong></p>';
				rep.candidates.forEach(c => {
					const isSelected = c.attachment_id === rep.attachment_id;
					const dims = c.to_dimensions ? c.to_dimensions.width + '\u00d7' + c.to_dimensions.height : 'unknown';
					html += '<label class="ik-iu-candidate-option">';
					html += '<input type="radio" name="ik-iu-candidate-' + item.id + '-' + idx + '" class="ik-iu-candidate-radio" ';
					html += 'data-item-id="' + item.id + '" data-idx="' + idx + '" data-attachment-id="' + c.attachment_id + '" ';
					html += (isSelected ? 'checked' : '') + '> ';
					html += '<span>' + esc(c.to_url) + '</span> (' + dims + ')';
					if (c.filename) html += ' \u2014 ' + esc(c.filename);
					html += '</label>';
				});
				html += '</div>';
			}

			html += '</div>';
		});

		html += '</div>';
		return html;
	}

	function renderAuditDetail(item, rep, idx) {
		const checked = !rep.excluded ? 'checked' : '';
		const issueLabels = {
			missing_id: 'Missing block ID',
			missing_sizeSlug: 'Missing sizeSlug',
			missing_wp_image_class: 'Missing wp-image class',
			missing_size_class: 'Missing size class on figure',
		};

		let html = '<div class="ik-iu-replacement-row">';
		html += '<label class="ik-iu-exclude-label"><input type="checkbox" class="ik-iu-exclude-cb" ' + checked + ' data-item-id="' + item.id + '" data-idx="' + idx + '"> Include</label>';

		html += '<div class="ik-iu-audit-issues">';
		if (rep.issues) {
			rep.issues.forEach(issue => {
				html += '<span class="ik-status ik-status-warning">' + esc(issueLabels[issue] || issue) + '</span> ';
			});
		}
		html += '</div>';

		html += '<div class="ik-iu-before">';
		html += '<div class="ik-iu-thumb-placeholder" data-src="' + esc(rep.from_url) + '"></div>';
		html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
		if (rep.to_dimensions) html += '<div class="ik-iu-dim-label">' + rep.to_dimensions.width + '\u00d7' + rep.to_dimensions.height + '</div>';
		html += '</div>';

		if (rep.proposed_block_json) {
			html += '<div class="ik-iu-markup-diff"><strong>Proposed block JSON:</strong>';
			html += '<pre><code>' + esc(JSON.stringify(rep.proposed_block_json, null, 2)) + '</code></pre></div>';
		}

		html += '<button type="button" class="button button-small ik-iu-show-preview-btn">Show preview</button>';
		html += '</div>';
		return html;
	}

	function renderMissingPanel(missingFiles) {
		const panel = $('ik-iu-missing-panel');
		panel.style.display = '';

		let html = '<div class="ik-result ik-result-info"><p>' + missingFiles.length + ' full-size images could not be found.</p></div>';
		html += '<table class="widefat"><thead><tr><th>Attachment ID</th><th>Expected File</th><th>Referenced In</th></tr></thead><tbody>';

		missingFiles.forEach(mf => {
			html += '<tr>';
			html += '<td>' + (mf.rep.attachment_id || 'N/A') + '</td>';
			html += '<td>' + esc(mf.rep.original_filename || mf.rep.from_url) + '</td>';
			html += '<td><a href="' + esc(mf.item.edit_url) + '" target="_blank">' + esc(mf.item.post_title) + '</a></td>';
			html += '</tr>';
		});

		html += '</tbody></table>';
		panel.innerHTML = html;
	}

	// ─── Apply Flow ─────────────────────────────────────────────────────

	if ($('ik-iu-apply-changes')) {
		$('ik-iu-apply-changes').addEventListener('click', startApply);
	}
	if ($('ik-iu-discard')) {
		$('ik-iu-discard').addEventListener('click', discardRun);
	}
	if ($('ik-iu-export-csv')) {
		$('ik-iu-export-csv').addEventListener('click', doExportCSV);
	}

	function startApply() {
		let imgCount = 0;
		let postCount = 0;
		const exclusions = collectExclusions();

		runItems.forEach(item => {
			let itemHasApplicable = false;
			if (item.replacements) {
				item.replacements.forEach((rep, idx) => {
					if (rep.skipped) return;
					const isExcluded = exclusions[item.id]?.includes(idx);
					if (!isExcluded) { imgCount++; itemHasApplicable = true; }
				});
			}
			if (itemHasApplicable) postCount++;
		});

		if (imgCount === 0) { alert('No images selected for replacement.'); return; }

		const label = currentMode === 'audit' ? 'markup fixes' : 'image replacements';
		if (!confirm('Apply ' + imgCount + ' ' + label + ' across ' + postCount + ' posts? This cannot be undone.')) return;

		showSection('ik-iu-apply-progress');
		applyOffset = 0;
		$('ik-iu-apply-log-panel').innerHTML = '';

		applyNextBatch(exclusions);
	}

	function applyNextBatch(exclusions) {
		const data = { run_id: currentRunId, offset: applyOffset };

		if (applyOffset === 0 && Object.keys(exclusions).length > 0) {
			data.exclusions = JSON.stringify(exclusions);
		}

		post(applyRunAction, data).then(res => {
			if (!res.success) { alert(res.data?.message || 'Apply failed.'); return; }

			const d = res.data;
			applyOffset = d.offset;

			updateProgressBar($('ik-iu-apply-progress-bar'), d.offset, d.total_to_apply);

			d.log_lines.forEach(line => {
				const text = line.status === 'applied'
					? '\u2713 ' + line.title + ' \u2014 ' + line.replaced + ' replaced'
					: line.status === 'skipped_content_changed'
						? '\u26a0 ' + line.title + ' \u2014 skipped (content changed)'
						: line.status === 'error'
							? '\u2717 ' + line.title + ' \u2014 ' + line.message
							: '\u2014 ' + line.title + ' \u2014 no changes';
				const type = line.status === 'applied' ? 'success' : line.status === 'error' ? 'error' : 'info';
				appendLog($('ik-iu-apply-log-panel'), type, text);
			});

			if (d.done) {
				showSection('ik-iu-success');
				$('ik-iu-success-message').textContent = 'Changes applied successfully!';
				resetScanButtons();
			} else {
				setTimeout(() => applyNextBatch(exclusions), 200);
			}
		}).catch(err => {
			alert('Error: ' + err.message);
		});
	}

	function collectExclusions() {
		const exclusions = {};
		$$('.ik-iu-exclude-cb').forEach(cb => {
			if (!cb.checked) {
				const itemId = cb.dataset.itemId;
				const idx = parseInt(cb.dataset.idx, 10);
				if (!exclusions[itemId]) exclusions[itemId] = [];
				exclusions[itemId].push(idx);
			}
		});
		return exclusions;
	}

	function discardRun() {
		if (!confirm('Discard scan results?')) return;
		post(discardRunAction, { run_id: currentRunId }).then(() => {
			showSection('ik-iu-config');
			resetScanButtons();
			currentRunId = null;
			runItems = [];
		});
	}

	function doExportCSV() {
		if (!runItems.length) return;
		const rows = [];
		runItems.forEach(item => {
			if (!item.replacements) return;
			item.replacements.forEach(rep => {
				if (rep.skipped) return;
				rows.push([
					item.post_id,
					item.post_title || '',
					rep.from_url,
					rep.to_url,
					rep.from_size,
					rep.to_dimensions?.width || '',
					rep.to_dimensions?.height || '',
					rep.markup_type || '',
				]);
			});
		});

		exportCSV(
			'image-upgrader-run-' + currentRunId + '.csv',
			['Post ID', 'Post Title', 'From URL', 'To URL', 'From Size', 'To Width', 'To Height', 'Markup Type'],
			rows
		);
	}

	// ─── History ────────────────────────────────────────────────────────

	if ($('ik-iu-goto-history')) {
		$('ik-iu-goto-history').addEventListener('click', () => {
			$$('.ik-sub-tab').forEach(t => t.classList.remove('active'));
			const historyTab = document.querySelector('.ik-sub-tab[data-subtab="history"]');
			if (historyTab) historyTab.classList.add('active');
			$$('.ik-iu-subtab-content').forEach(p => p.style.display = 'none');
			const target = $('ik-iu-tab-history');
			if (target) target.style.display = '';
			loadHistory(1);
		});
	}

	function loadHistory(page) {
		const wrap = $('ik-iu-history-table-wrap');
		wrap.innerHTML = '<p>Loading\u2026</p>';

		post(getHistoryAction, { page }).then(res => {
			if (!res.success || !res.data.runs.length) {
				wrap.innerHTML = '<p>No runs recorded yet.</p>';
				$('ik-iu-history-pagination').innerHTML = '';
				return;
			}

			renderHistoryTable(res.data.runs);
			renderHistoryPagination(res.data.page, res.data.total_pages);
		});
	}

	function renderHistoryTable(runs) {
		const wrap = $('ik-iu-history-table-wrap');

		let html = '<table class="widefat striped"><thead><tr>';
		html += '<th></th><th>Started</th><th>Status</th><th>Post Types</th>';
		html += '<th>Scanned</th><th>Updated</th><th>Replaced</th>';
		html += '<th>Skipped</th><th>Errors</th><th>Actions</th>';
		html += '</tr></thead><tbody>';

		runs.forEach(run => {
			html += '<tr data-run-id="' + run.id + '">';
			html += '<td><button type="button" class="ik-iu-history-expand button-link" data-run-id="' + run.id + '">\u25b6</button></td>';
			html += '<td>' + esc(run.started_at) + '</td>';
			html += '<td><span class="ik-status ik-status-' + (run.status === 'completed' ? 'success' : run.status === 'failed' ? 'error' : 'info') + '">' + esc(run.status) + '</span></td>';
			html += '<td>' + esc(run.post_types) + '</td>';
			html += '<td>' + run.posts_scanned + '</td>';
			html += '<td>' + run.posts_updated + '</td>';
			html += '<td>' + run.images_replaced + '</td>';
			html += '<td>' + run.images_skipped + '</td>';
			html += '<td>' + run.error_count + '</td>';
			html += '<td><button type="button" class="button button-small ik-iu-delete-run-btn" data-run-id="' + run.id + '">Delete</button></td>';
			html += '</tr>';
			html += '<tr class="ik-iu-history-detail" id="ik-iu-history-detail-' + run.id + '" style="display:none;"><td colspan="10"><p>Loading\u2026</p></td></tr>';
		});

		html += '</tbody></table>';
		wrap.innerHTML = html;

		wrap.querySelectorAll('.ik-iu-history-expand').forEach(btn => {
			btn.addEventListener('click', () => {
				const runId = btn.dataset.runId;
				const detail = document.getElementById('ik-iu-history-detail-' + runId);
				if (detail.style.display === 'none') {
					detail.style.display = '';
					btn.textContent = '\u25bc';
					loadRunDetail(runId);
				} else {
					detail.style.display = 'none';
					btn.textContent = '\u25b6';
				}
			});
		});

		wrap.querySelectorAll('.ik-iu-delete-run-btn').forEach(btn => {
			btn.addEventListener('click', () => {
				if (!confirm('Delete this run and all its data?')) return;
				post(deleteRunAction, { run_id: btn.dataset.runId }).then(() => loadHistory(1));
			});
		});
	}

	function loadRunDetail(runId) {
		const cell = document.querySelector('#ik-iu-history-detail-' + runId + ' td');

		post(getRunItemsAction, { run_id: runId }).then(res => {
			if (!res.success || !res.data.items.length) {
				cell.innerHTML = '<p>No items recorded.</p>';
				return;
			}

			let html = '<table class="widefat striped"><thead><tr>';
			html += '<th>Post ID</th><th>Post Title</th><th>Replaced</th><th>Skipped</th><th>Actions</th>';
			html += '</tr></thead><tbody>';

			res.data.items.forEach(item => {
				html += '<tr>';
				html += '<td>' + item.post_id + '</td>';
				html += '<td><a href="' + esc(item.edit_url) + '" target="_blank">' + esc(item.post_title) + '</a></td>';
				html += '<td>' + item.images_replaced + '</td>';
				html += '<td>' + item.images_skipped + '</td>';
				html += '<td><button type="button" class="button button-small ik-iu-view-changes-btn">View changes</button></td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			cell.innerHTML = html;

			cell.querySelectorAll('.ik-iu-view-changes-btn').forEach((btn, i) => {
				btn.addEventListener('click', () => showDetailModal(res.data.items[i]));
			});
		});
	}

	function renderHistoryPagination(currentPage, totalPages) {
		const wrap = $('ik-iu-history-pagination');
		if (totalPages <= 1) { wrap.innerHTML = ''; return; }

		let html = '<div class="tablenav"><div class="tablenav-pages"><span class="pagination-links">';
		html += currentPage <= 1
			? '<span class="button disabled">&lsaquo;</span>'
			: '<a class="button ik-iu-history-page" data-page="' + (currentPage - 1) + '" href="#">&lsaquo;</a>';
		html += ' <span>' + currentPage + ' / ' + totalPages + '</span> ';
		html += currentPage >= totalPages
			? '<span class="button disabled">&rsaquo;</span>'
			: '<a class="button ik-iu-history-page" data-page="' + (currentPage + 1) + '" href="#">&rsaquo;</a>';
		html += '</span></div></div>';
		wrap.innerHTML = html;

		wrap.querySelectorAll('.ik-iu-history-page').forEach(link => {
			link.addEventListener('click', e => { e.preventDefault(); loadHistory(parseInt(link.dataset.page, 10)); });
		});
	}

	// ─── Detail Modal ───────────────────────────────────────────────────

	function showDetailModal(item) {
		const modal = $('ik-iu-detail-modal');
		$('ik-iu-modal-title').textContent = 'Changes: ' + item.post_title;

		let html = '';
		if (!item.replacements || item.replacements.length === 0) {
			html = '<p>No replacement details available.</p>';
		} else {
			html += '<div class="ik-iu-modal-replacements">';
			item.replacements.forEach(rep => {
				html += '<div class="ik-iu-modal-replacement-row">';
				if (rep.skipped) {
					html += '<div><strong>Skipped:</strong> ' + esc(rep.skip_reason) + '</div>';
					html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
				} else {
					html += '<div class="ik-iu-before-after">';
					html += '<div class="ik-iu-before">';
					html += '<div class="ik-iu-thumb-placeholder" data-src="' + esc(rep.from_url) + '"></div>';
					html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
					html += '<div class="ik-iu-dim-label">' + esc(rep.from_size) + '</div>';
					html += '</div>';
					html += '<div class="ik-iu-arrow">\u2192</div>';
					html += '<div class="ik-iu-after">';
					html += '<div class="ik-iu-thumb-placeholder" data-src="' + esc(rep.to_url) + '"></div>';
					html += '<div class="ik-iu-url-label">' + esc(rep.to_url) + '</div>';
					if (rep.to_dimensions) html += '<div class="ik-iu-dim-label">' + rep.to_dimensions.width + '\u00d7' + rep.to_dimensions.height + '</div>';
					html += '</div>';
					html += '</div>';
					html += '<button type="button" class="button button-small ik-iu-show-preview-btn">Show preview</button>';
				}
				html += '</div>';
			});
			html += '</div>';
		}

		$('ik-iu-modal-body').innerHTML = html;
		bindPreviewButtons($('ik-iu-modal-body'));
		modal.style.display = '';

		modal.querySelector('.ik-modal-close').onclick = () => modal.style.display = 'none';
		modal.querySelector('.ik-modal-overlay').onclick = () => modal.style.display = 'none';
	}

	// ─── Pending Review Auto-Restore ────────────────────────────────────

	if (config.pendingReview) {
		currentRunId = config.pendingReview.run_id;
		if (config.pendingReview.mode === 'audit' || config.pendingReview.mode === 'audit_apply') {
			currentMode = 'audit';
		}

		post(getRunItemsAction, { run_id: currentRunId }).then(res => {
			if (!res.success || !res.data.items.length) return;
			runItems = res.data.items;
			renderPreview();
		});
	}

	// ─── Diagnostics ────────────────────────────────────────────────────

	const diagBtn = $('ik-iu-run-diagnostics');
	if (diagBtn) {
		diagBtn.addEventListener('click', () => {
			diagBtn.disabled = true;
			diagBtn.textContent = 'Running\u2026';

			post(diagnosticsAction).then(res => {
				diagBtn.disabled = false;
				diagBtn.textContent = 'Run Diagnostics';

				if (!res.success) { alert('Diagnostics failed.'); return; }

				const panel = $('ik-iu-diagnostics');
				const output = $('ik-iu-diagnostics-output');
				panel.style.display = '';

				const d = res.data;
				let html = '<table class="widefat">';
				html += '<tr><th>site_url()</th><td><code>' + esc(d.site_url) + '</code></td></tr>';
				html += '<tr><th>home_url()</th><td><code>' + esc(d.home_url) + '</code></td></tr>';
				html += '<tr><th>uploads baseurl</th><td><code>' + esc(d.uploads_baseurl) + '</code></td></tr>';
				html += '<tr><th>uploads basedir</th><td><code>' + esc(d.uploads_basedir) + '</code></td></tr>';
				html += '<tr><th>URL aliases</th><td>' + (d.url_aliases.length > 0 ? d.url_aliases.map(a => '<code>' + esc(a) + '</code>').join('<br>') : '<em>None</em>') + '</td></tr>';
				html += '<tr><th>Posts sampled</th><td>' + d.posts_sampled + '</td></tr>';
				html += '<tr><th>Posts with images</th><td>' + d.posts_with_images + '</td></tr>';
				html += '<tr><th>Regex matches</th><td>' + d.sample_regex_hits.length + '</td></tr>';
				html += '</table>';

				if (d.sample_img_urls.length > 0) {
					html += '<h4>Sample image URLs</h4><ol>';
					d.sample_img_urls.forEach(url => { html += '<li style="word-break:break-all;"><code>' + esc(url) + '</code></li>'; });
					html += '</ol>';
				}

				if (d.sample_regex_hits.length > 0) {
					html += '<h4>Sample regex matches</h4><ol>';
					d.sample_regex_hits.forEach(url => { html += '<li style="word-break:break-all;"><code>' + esc(url) + '</code></li>'; });
					html += '</ol>';
				}

				output.innerHTML = html;
			}).catch(err => {
				diagBtn.disabled = false;
				diagBtn.textContent = 'Run Diagnostics';
				alert('Error: ' + err.message);
			});
		});
	}
})();
