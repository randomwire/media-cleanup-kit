/**
 * Image Kit — Unused Cleaner module JS.
 *
 * Handles directory validation, batch scanning, results display with
 * filtering/sorting/pagination, selection, CSV export, and batch deletion.
 */
(function () {
	'use strict';

	const { post, escHtml, formatBytes, exportCSV, updateProgress } = imageKitUtils;
	const { validateAction, scanAction, deleteAction } = imageKitUnusedCleaner;

	const state = {
		directory: '',
		results: [],
		scanning: false,
		deleting: false,
		sortField: 'filename',
		sortDirection: 'asc',
		filter: 'all',
		currentPage: 1,
		perPage: 100,
		deletedCount: 0,
		deleteErrors: [],
	};

	// DOM refs.
	const $ = id => document.getElementById(id);

	const els = {};

	function init() {
		els.directory      = $('ik-uc-directory');
		els.scanBtn        = $('ik-uc-scan-btn');
		els.cancelBtn      = $('ik-uc-cancel-btn');
		els.deleteBtn      = $('ik-uc-delete-btn');
		els.selectAll      = $('ik-uc-select-all');
		els.checkAll       = $('ik-uc-check-all');
		els.progress       = $('ik-uc-progress');
		els.results        = $('ik-uc-results');
		els.tbody          = $('ik-uc-tbody');
		els.selectedCount  = $('ik-uc-selected-count');
		els.deleteProgress = $('ik-uc-delete-progress');
		els.validationErr  = $('ik-uc-validation-error');

		if (!els.scanBtn) return;

		els.scanBtn.addEventListener('click', startScan);
		els.cancelBtn.addEventListener('click', () => { state.scanning = false; });
		els.deleteBtn.addEventListener('click', startDelete);
		$('ik-uc-export-btn').addEventListener('click', doExport);
		els.selectAll.addEventListener('change', toggleSelectAllUnused);
		els.checkAll.addEventListener('change', toggleCheckAll);

		$('ik-uc-page-prev').addEventListener('click', () => {
			if (state.currentPage > 1) { state.currentPage--; renderResults(); }
		});
		$('ik-uc-page-next').addEventListener('click', () => {
			const totalPages = Math.ceil(getFiltered().length / state.perPage);
			if (state.currentPage < totalPages) { state.currentPage++; renderResults(); }
		});

		// Sort headers.
		document.querySelectorAll('.ik-uc-sortable').forEach(th => {
			th.style.cursor = 'pointer';
			th.addEventListener('click', () => {
				const field = th.dataset.sort;
				if (state.sortField === field) {
					state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
				} else {
					state.sortField = field;
					state.sortDirection = 'asc';
				}
				state.currentPage = 1;
				renderResults();
			});
		});

		// Filter tabs.
		document.querySelectorAll('.ik-uc-filter-btn').forEach(btn => {
			btn.addEventListener('click', () => {
				document.querySelectorAll('.ik-uc-filter-btn').forEach(b => b.classList.remove('active'));
				btn.classList.add('active');
				state.filter = btn.dataset.filter;
				state.currentPage = 1;
				renderResults();
			});
		});

		els.directory.addEventListener('keypress', e => {
			if (e.key === 'Enter') startScan();
		});
	}

	function showError(msg) {
		els.validationErr.querySelector('p').textContent = msg;
		els.validationErr.style.display = 'block';
	}

	function hideError() {
		els.validationErr.style.display = 'none';
	}

	// ── Scan ──

	async function startScan() {
		const dir = els.directory.value.trim();
		if (!dir) { showError('Please enter a directory path.'); return; }

		hideError();
		state.scanning = true;
		state.results = [];
		state.currentPage = 1;
		els.scanBtn.disabled = true;
		els.directory.disabled = true;
		els.progress.style.display = '';
		els.results.style.display = 'none';

		$('ik-uc-count-scanned').textContent = '0';
		$('ik-uc-count-used').textContent = '0';
		$('ik-uc-count-unused').textContent = '0';

		try {
			const valResp = await post(validateAction, { directory: dir });
			if (!valResp.success) throw new Error(valResp.data?.message || 'Validation failed');

			state.directory = valResp.data.path;
			await scanLoop(0, valResp.data.count);

			if (state.scanning) {
				if (state.results.length > 0) {
					renderResults();
					els.results.style.display = '';
				} else {
					showError('No images found to process.');
				}
			}
		} catch (err) {
			showError(err.message || 'An error occurred.');
		}

		els.progress.style.display = 'none';
		state.scanning = false;
		els.scanBtn.disabled = false;
		els.directory.disabled = false;
	}

	async function scanLoop(offset, total) {
		if (!state.scanning) return;

		const resp = await post(scanAction, { directory: state.directory, offset });
		if (!resp.success) throw new Error(resp.data?.message || 'Scan error');

		const existing = {};
		state.results.forEach(r => { existing[r.filename] = true; });
		resp.data.results.forEach(r => {
			if (!existing[r.filename]) state.results.push(r);
		});

		const scanned = resp.data.offset;
		let used = 0, unused = 0;
		state.results.forEach(r => { r.is_used ? used++ : unused++; });

		$('ik-uc-count-scanned').textContent = scanned;
		$('ik-uc-count-used').textContent = used;
		$('ik-uc-count-unused').textContent = unused;
		updateProgress(els.progress, scanned, resp.data.total,
			Math.round((scanned / resp.data.total) * 100) + '%');

		if (!resp.data.done) await scanLoop(resp.data.offset, resp.data.total);
	}

	// ── Results ──

	function getFiltered() {
		let filtered = state.results.filter(r => {
			if (state.filter === 'used') return r.is_used;
			if (state.filter === 'unused') return !r.is_used;
			return true;
		});

		filtered.sort((a, b) => {
			let va, vb;
			if (state.sortField === 'filename') { va = a.filename.toLowerCase(); vb = b.filename.toLowerCase(); }
			else { va = a.file_size; vb = b.file_size; }
			if (va < vb) return state.sortDirection === 'asc' ? -1 : 1;
			if (va > vb) return state.sortDirection === 'asc' ? 1 : -1;
			return 0;
		});

		return filtered;
	}

	function renderResults() {
		const filtered = getFiltered();
		const totalPages = Math.ceil(filtered.length / state.perPage) || 1;
		if (state.currentPage > totalPages) state.currentPage = totalPages;

		const start = (state.currentPage - 1) * state.perPage;
		const page = filtered.slice(start, start + state.perPage);

		// Sort arrows.
		document.querySelectorAll('.ik-uc-sortable').forEach(th => {
			const arrow = th.querySelector('.ik-uc-sort-arrow');
			arrow.textContent = th.dataset.sort === state.sortField
				? (state.sortDirection === 'asc' ? ' \u25B2' : ' \u25BC') : '';
		});

		// Summary.
		let used = 0, unused = 0, unusedSize = 0;
		state.results.forEach(r => { r.is_used ? used++ : (unused++, unusedSize += r.file_size); });

		$('ik-uc-summary-total').textContent = state.results.length;
		$('ik-uc-summary-used').textContent = used;
		$('ik-uc-summary-unused').textContent = unused;
		$('ik-uc-summary-size').textContent = formatBytes(unusedSize) + ' unused';

		// Pagination.
		$('ik-uc-page-prev').disabled = state.currentPage <= 1;
		$('ik-uc-page-next').disabled = state.currentPage >= totalPages;
		$('ik-uc-page-info').textContent = 'Page ' + state.currentPage + ' of ' + totalPages +
			' (' + filtered.length + ' items)';

		// Table.
		let html = '';
		page.forEach(r => {
			const statusCls = r.is_used ? 'ik-status-info' : 'ik-status-warning';
			const statusLbl = r.is_used ? 'Used' : 'Unused';
			const disabled = r.is_used ? 'disabled' : '';
			const checked = r._selected ? 'checked' : '';
			const usedIn = r.used_in.length > 0 ? r.used_in.map(escHtml).join('<br>') : '-';
			const thumbCount = r.group.length > 1 ? r.group.length - 1 : 0;
			const thumbHtml = thumbCount > 0
				? '<span title="' + r.group.map(escHtml).join(', ') + '">' + thumbCount + ' thumbnail' + (thumbCount > 1 ? 's' : '') + '</span>'
				: '-';

			html += '<tr>' +
				'<td class="check-column"><input type="checkbox" class="ik-uc-row-check" value="' + escHtml(r.filename) + '" ' + disabled + ' ' + checked + '></td>' +
				'<td>' + escHtml(r.filename) + '</td>' +
				'<td>' + formatBytes(r.file_size) + '</td>' +
				'<td><span class="ik-status ' + statusCls + '">' + statusLbl + '</span></td>' +
				'<td>' + usedIn + '</td>' +
				'<td>' + thumbHtml + '</td>' +
				'</tr>';
		});
		els.tbody.innerHTML = html;

		document.querySelectorAll('.ik-uc-row-check').forEach(cb => {
			cb.addEventListener('change', () => {
				const r = state.results.find(x => x.filename === cb.value);
				if (r) r._selected = cb.checked;
				updateSelectedCount();
			});
		});

		updateSelectedCount();
	}

	function toggleSelectAllUnused() {
		const checked = els.selectAll.checked;
		state.results.forEach(r => { if (!r.is_used) r._selected = checked; });
		document.querySelectorAll('.ik-uc-row-check:not(:disabled)').forEach(cb => { cb.checked = checked; });
		updateSelectedCount();
	}

	function toggleCheckAll() {
		const checked = els.checkAll.checked;
		document.querySelectorAll('.ik-uc-row-check:not(:disabled)').forEach(cb => {
			cb.checked = checked;
			const r = state.results.find(x => x.filename === cb.value);
			if (r) r._selected = checked;
		});
		updateSelectedCount();
	}

	function updateSelectedCount() {
		let count = 0;
		state.results.forEach(r => { if (r._selected) count++; });
		els.deleteBtn.disabled = count === 0;
		els.selectedCount.textContent = count > 0 ? count + ' selected' : '';
	}

	function getSelected() {
		const files = [], attIds = [];
		state.results.forEach(r => {
			if (!r._selected) return;
			r.group.forEach(f => { if (files.indexOf(f) === -1) files.push(f); });
			if (r.attachment_ids) r.attachment_ids.forEach(id => { if (attIds.indexOf(id) === -1) attIds.push(id); });
		});
		return { files, attIds };
	}

	// ── Export ──

	function doExport() {
		if (!state.results.length) return;
		exportCSV(
			'unused-images-' + new Date().toISOString().slice(0, 10) + '.csv',
			['Filename', 'Size (bytes)', 'Status', 'Used In', 'Thumbnails'],
			state.results.map(r => [
				r.filename, r.file_size, r.is_used ? 'Used' : 'Unused',
				r.used_in.join('; '),
				r.group.length > 1 ? r.group.filter(f => f !== r.filename).join('; ') : '',
			])
		);
	}

	// ── Delete ──

	async function startDelete() {
		const sel = getSelected();
		if (!sel.files.length) { alert('Please select at least one image to delete.'); return; }

		let msg = 'Are you sure you want to delete the selected images? This cannot be undone.\n\n' +
			sel.files.length + ' file(s) will be deleted.';
		if (sel.attIds.length > 0) msg += '\n' + sel.attIds.length + ' media library item(s) will also be removed.';
		if (!confirm(msg)) return;

		state.deleting = true;
		state.deletedCount = 0;
		state.deleteErrors = [];
		els.deleteBtn.disabled = true;
		els.deleteProgress.style.display = '';
		els.results.style.display = 'none';

		$('ik-uc-delete-count').textContent = '0';
		$('ik-uc-delete-total').textContent = sel.files.length;
		$('ik-uc-delete-errors').textContent = '0';

		$('ik-uc-cancel-delete-btn').onclick = () => { state.deleting = false; };

		try {
			await deleteLoop(sel.files, sel.attIds, 0);
		} catch (err) {
			if (err.message !== 'cancelled') alert('Error: ' + err.message);
		}

		els.deleteProgress.style.display = 'none';
		state.deleting = false;
		state.results = state.results.filter(r => !r._deleted);
		els.results.style.display = '';
		renderResults();

		let summary = state.deletedCount + ' file(s) deleted.';
		if (state.deleteErrors.length > 0) {
			summary += '\n\nFailed: ' + state.deleteErrors.slice(0, 10).join('\n');
		}
		alert(summary);
	}

	async function deleteLoop(allFiles, allAttIds, offset) {
		if (!state.deleting) throw new Error('cancelled');

		const batchSize = 50;
		const batch = allFiles.slice(offset, offset + batchSize);
		if (!batch.length) return;

		const batchAttIds = offset === 0 ? allAttIds : [];
		const processed = offset + batch.length;
		updateProgress(els.deleteProgress, processed, allFiles.length,
			Math.round((processed / allFiles.length) * 100) + '%');

		const resp = await post(deleteAction, {
			directory: state.directory,
			files: batch,
			attachment_ids: batchAttIds,
		});

		if (!resp.success) throw new Error(resp.data?.message || 'Delete error');

		const deletedFiles = {};
		resp.data.results.forEach(r => {
			if (r.success) { deletedFiles[r.filename] = true; state.deletedCount++; }
			else state.deleteErrors.push(r.filename + ': ' + r.error);
		});

		state.results.forEach(r => {
			if (r.group.every(f => deletedFiles[f])) r._deleted = true;
		});

		$('ik-uc-delete-count').textContent = state.deletedCount;
		$('ik-uc-delete-errors').textContent = state.deleteErrors.length;

		if (offset + batchSize < allFiles.length) {
			await deleteLoop(allFiles, allAttIds, offset + batchSize);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
