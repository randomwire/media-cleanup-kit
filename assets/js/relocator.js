/**
 * Image Kit — Relocator module JS.
 *
 * Sub-tab UI with two wizards: Relocate and Import Orphans.
 */
(function () {
	'use strict';

	const { post, escHtml, appendLog, formatBytes, showStep, updateProgress } = imageKitUtils;
	const { scanAction, applyAction, resetAction, scanOrphansAction, importAction } = imageKitRelocator;

	const $ = id => document.getElementById(id);

	// ── Sub-tab switching ──

	document.querySelectorAll('.ik-sub-tab').forEach(btn => {
		btn.addEventListener('click', function () {
			document.querySelectorAll('.ik-sub-tab').forEach(b => b.classList.remove('active'));
			this.classList.add('active');
			document.querySelectorAll('.ik-rel-subtab-content').forEach(p => p.style.display = 'none');
			const target = $('ik-rel-tab-' + this.dataset.subtab);
			if (target) target.style.display = '';
		});
	});

	// ══════════════════════════════════════════
	// RELOCATE
	// ══════════════════════════════════════════

	let scanItems = [];
	let allResults = [];

	const relPanels = {
		1: $('ik-rel-step-1'),
		2: $('ik-rel-step-2'),
		3: $('ik-rel-step-3'),
	};

	function showRelStep(n) { showStep('#ik-rel-steps', relPanels, n); }

	// Step 1: Scan

	const scanBtn = $('ik-rel-scan');
	if (scanBtn) {
		scanBtn.addEventListener('click', async function () {
			scanBtn.disabled = true;
			scanBtn.textContent = 'Scanning\u2026';
			$('ik-rel-scan-progress').style.display = '';
			$('ik-rel-scan-result').style.display = 'none';
			$('ik-rel-table-wrap').style.display = 'none';

			updateProgress($('ik-rel-scan-progress'), 50, 100, 'Scanning attachments\u2026');

			const res = await post(scanAction);

			updateProgress($('ik-rel-scan-progress'), 100, 100, '');
			scanBtn.disabled = false;
			scanBtn.textContent = 'Scan for Images';

			if (!res.success) {
				$('ik-rel-scan-progress').querySelector('.ik-progress-text').textContent =
					'Error: ' + (res.data || 'Unknown');
				return;
			}

			scanItems = res.data.items;
			const result = $('ik-rel-scan-result');
			result.style.display = '';

			if (!scanItems.length) {
				result.className = 'ik-result ik-result-info';
				result.textContent = 'No images found in subdirectories.';
				return;
			}

			const subdirs = {};
			scanItems.forEach(item => { subdirs[item.subdirectory] = (subdirs[item.subdirectory] || 0) + 1; });
			result.className = 'ik-result ik-result-success';
			result.textContent = 'Found ' + scanItems.length + ' images across ' +
				Object.keys(subdirs).length + ' subdirectories.';

			renderScanTable();
		});
	}

	function renderScanTable() {
		$('ik-rel-table-wrap').style.display = '';
		const tbody = document.querySelector('#ik-rel-table tbody');
		tbody.innerHTML = '';

		scanItems.forEach(item => {
			const collision = item.has_collision
				? ' <span class="ik-collision" title="Filename collision \u2014 will be renamed">&#9888;</span>' : '';
			const tr = document.createElement('tr');
			tr.innerHTML =
				'<td class="ik-col-check"><input type="checkbox" class="ik-rel-check" data-id="' + item.attachment_id + '" checked></td>' +
				'<td class="ik-col-thumb">' + (item.thumb_url ? '<img src="' + escHtml(item.thumb_url) + '" class="ik-thumb">' : '<span class="ik-no-thumb">\u2014</span>') + '</td>' +
				'<td class="ik-path">' + escHtml(item.relative_path) + '</td>' +
				'<td class="ik-path">' + escHtml(item.target_filename) + collision + '</td>' +
				'<td>' + item.thumb_count + (item.has_original_image ? ' + original' : '') + '</td>' +
				'<td>' + item.post_count + '</td>';
			tbody.appendChild(tr);
		});

		updateRelCount();
	}

	function updateRelCount() {
		const checked = document.querySelectorAll('.ik-rel-check:checked').length;
		const total = document.querySelectorAll('.ik-rel-check').length;
		$('ik-rel-selected-count').textContent = checked + ' of ' + total + ' selected';
		const sa = $('ik-rel-select-all');
		sa.checked = checked === total;
		sa.indeterminate = checked > 0 && checked < total;
	}

	$('ik-rel-select-all').addEventListener('change', e => {
		document.querySelectorAll('.ik-rel-check').forEach(cb => cb.checked = e.target.checked);
		updateRelCount();
	});

	document.addEventListener('change', e => {
		if (e.target.matches('.ik-rel-check')) updateRelCount();
	});

	// Step 2: Apply

	$('ik-rel-apply').addEventListener('click', async function () {
		const ids = Array.from(document.querySelectorAll('.ik-rel-check:checked')).map(cb => cb.dataset.id);
		if (!ids.length) { alert('No images selected.'); return; }
		if (!confirm('Relocate ' + ids.length + ' image(s)? Make sure you have a backup.')) return;

		showRelStep(2);
		const log = $('ik-rel-apply-log');
		log.innerHTML = '';
		allResults = [];

		const batchSize = 5;
		let processed = 0;

		for (let i = 0; i < ids.length; i += batchSize) {
			const batch = ids.slice(i, i + batchSize);
			updateProgress($('ik-rel-apply-progress'), processed, ids.length,
				'Processing ' + processed + ' / ' + ids.length + '\u2026');

			const res = await post(applyAction, { attachment_ids: batch });
			if (!res.success) { appendLog(log, 'error', 'Batch error: ' + (res.data || 'Unknown')); break; }

			allResults = allResults.concat(res.data.results);
			res.data.results.forEach(r => {
				const item = scanItems.find(s => s.attachment_id === r.attachment_id);
				const label = item ? item.relative_path : '#' + r.attachment_id;
				if (r.success) {
					const d = r.details || {};
					const extra = [];
					if (d.files_moved) extra.push(d.files_moved + ' files');
					if (d.posts_updated) extra.push(d.posts_updated + ' posts updated');
					if (d.renamed) extra.push('renamed \u2192 ' + d.new_filename);
					appendLog(log, 'success', label + ' \u2014 OK' + (extra.length ? ' (' + extra.join(', ') + ')' : ''));
				} else {
					appendLog(log, 'error', label + ' \u2014 ' + r.message);
				}
			});
			processed += batch.length;
		}

		updateProgress($('ik-rel-apply-progress'), ids.length, ids.length, 'Done.');
		setTimeout(() => showRelResults(), 1000);
	});

	function showRelResults() {
		showRelStep(3);
		const ok = allResults.filter(r => r.success).length;
		const fail = allResults.filter(r => !r.success).length;

		const summary = $('ik-rel-results-summary');
		summary.className = 'ik-result ' + (fail ? 'ik-result-info' : 'ik-result-success');
		summary.textContent = ok + ' relocated, ' + fail + ' failed.';

		const tbody = document.querySelector('#ik-rel-results-table tbody');
		tbody.innerHTML = '';
		allResults.forEach(r => {
			const item = scanItems.find(s => s.attachment_id === r.attachment_id);
			const label = item ? item.relative_path : '#' + r.attachment_id;
			const tr = document.createElement('tr');
			if (r.success) {
				const d = r.details || {};
				tr.innerHTML = '<td class="ik-path">' + escHtml(label) + '</td>' +
					'<td><span class="ik-status ik-status-success">Relocated</span></td>' +
					'<td>' + (d.files_moved || 0) + ' files, ' + (d.posts_updated || 0) + ' posts' +
					(d.renamed ? ', renamed to ' + escHtml(d.new_filename) : '') + '</td>';
			} else {
				tr.innerHTML = '<td class="ik-path">' + escHtml(label) + '</td>' +
					'<td><span class="ik-status ik-status-error">Failed</span></td>' +
					'<td>' + escHtml(r.message) + '</td>';
			}
			tbody.appendChild(tr);
		});
	}

	// Reset
	$('ik-rel-start-over').addEventListener('click', resetRelocate);
	$('ik-rel-reset').addEventListener('click', resetRelocate);

	async function resetRelocate() {
		if (!confirm('Clear all scan results?')) return;
		await post(resetAction);
		scanItems = [];
		allResults = [];
		$('ik-rel-scan-progress').style.display = 'none';
		$('ik-rel-scan-result').style.display = 'none';
		$('ik-rel-table-wrap').style.display = 'none';
		$('ik-rel-apply-log').innerHTML = '';
		showRelStep(1);
	}

	// ══════════════════════════════════════════
	// IMPORT ORPHANS
	// ══════════════════════════════════════════

	let orphanItems = [];
	let importResults = [];

	const orphanPanels = {
		1: $('ik-rel-orphan-step-1'),
		2: $('ik-rel-orphan-step-2'),
		3: $('ik-rel-orphan-step-3'),
	};

	function showOrphanStep(n) { showStep('#ik-rel-orphan-steps', orphanPanels, n); }

	const orphanScanBtn = $('ik-rel-orphan-scan');
	if (orphanScanBtn) {
		orphanScanBtn.addEventListener('click', async function () {
			orphanScanBtn.disabled = true;
			orphanScanBtn.textContent = 'Scanning\u2026';
			$('ik-rel-orphan-progress').style.display = '';
			$('ik-rel-orphan-result').style.display = 'none';
			$('ik-rel-orphan-table-wrap').style.display = 'none';

			updateProgress($('ik-rel-orphan-progress'), 50, 100, 'Scanning uploads\u2026');
			const res = await post(scanOrphansAction);

			updateProgress($('ik-rel-orphan-progress'), 100, 100, '');
			orphanScanBtn.disabled = false;
			orphanScanBtn.textContent = 'Scan for Orphan Files';

			if (!res.success) {
				$('ik-rel-orphan-progress').querySelector('.ik-progress-text').textContent =
					'Error: ' + (res.data || 'Unknown');
				return;
			}

			orphanItems = res.data.items;
			const result = $('ik-rel-orphan-result');
			result.style.display = '';

			if (!orphanItems.length) {
				result.className = 'ik-result ik-result-info';
				result.textContent = 'No orphan files found.';
				return;
			}

			result.className = 'ik-result ik-result-success';
			result.textContent = 'Found ' + orphanItems.length + ' orphan files.';
			renderOrphanTable();
		});
	}

	function renderOrphanTable() {
		$('ik-rel-orphan-table-wrap').style.display = '';
		const tbody = document.querySelector('#ik-rel-orphan-table tbody');
		tbody.innerHTML = '';

		orphanItems.forEach(item => {
			const tr = document.createElement('tr');
			const variants = item.variant_count > 0
				? item.variant_count + ' variant' + (item.variant_count !== 1 ? 's' : '') : 'none';
			tr.innerHTML =
				'<td class="ik-col-check"><input type="checkbox" class="ik-rel-orphan-check" data-path="' + escHtml(item.relative_path) + '" checked></td>' +
				'<td class="ik-path">' + escHtml(item.relative_path) + '</td>' +
				'<td>' + formatBytes(item.file_size) + '</td>' +
				'<td>' + variants + '</td>';
			tbody.appendChild(tr);
		});

		updateOrphanCount();
	}

	function updateOrphanCount() {
		const checked = document.querySelectorAll('.ik-rel-orphan-check:checked').length;
		const total = document.querySelectorAll('.ik-rel-orphan-check').length;
		$('ik-rel-orphan-selected-count').textContent = checked + ' of ' + total + ' selected';
		const sa = $('ik-rel-orphan-select-all');
		sa.checked = checked === total;
		sa.indeterminate = checked > 0 && checked < total;
	}

	$('ik-rel-orphan-select-all').addEventListener('change', e => {
		document.querySelectorAll('.ik-rel-orphan-check').forEach(cb => cb.checked = e.target.checked);
		updateOrphanCount();
	});

	document.addEventListener('change', e => {
		if (e.target.matches('.ik-rel-orphan-check')) updateOrphanCount();
	});

	// Import
	$('ik-rel-start-import').addEventListener('click', async function () {
		const paths = Array.from(document.querySelectorAll('.ik-rel-orphan-check:checked')).map(cb => cb.dataset.path);
		if (!paths.length) { alert('No files selected.'); return; }
		if (!confirm('Import ' + paths.length + ' file(s)? Thumbnails will be regenerated.')) return;

		showOrphanStep(2);
		const log = $('ik-rel-import-log');
		log.innerHTML = '';
		importResults = [];

		const batchSize = 5;
		let processed = 0;

		for (let i = 0; i < paths.length; i += batchSize) {
			const batch = paths.slice(i, i + batchSize);
			updateProgress($('ik-rel-import-progress'), processed, paths.length,
				'Importing ' + processed + ' / ' + paths.length + '\u2026');

			const res = await post(importAction, { paths: batch });
			if (!res.success) { appendLog(log, 'error', 'Batch error: ' + (res.data || 'Unknown')); break; }

			importResults = importResults.concat(res.data.results);
			res.data.results.forEach(r => {
				if (r.success) appendLog(log, 'success', r.relative_path + ' \u2014 Imported (#' + r.attachment_id + ')');
				else appendLog(log, 'error', r.relative_path + ' \u2014 ' + r.message);
			});
			processed += batch.length;
		}

		updateProgress($('ik-rel-import-progress'), paths.length, paths.length, 'Done.');
		setTimeout(() => showImportResults(), 1000);
	});

	function showImportResults() {
		showOrphanStep(3);
		const ok = importResults.filter(r => r.success).length;
		const fail = importResults.filter(r => !r.success).length;

		const summary = $('ik-rel-import-summary');
		summary.className = 'ik-result ' + (fail ? 'ik-result-info' : 'ik-result-success');
		summary.textContent = ok + ' imported, ' + fail + ' failed.';

		const tbody = document.querySelector('#ik-rel-import-table tbody');
		tbody.innerHTML = '';
		importResults.forEach(r => {
			const tr = document.createElement('tr');
			if (r.success) {
				tr.innerHTML = '<td class="ik-path">' + escHtml(r.relative_path) + '</td>' +
					'<td><span class="ik-status ik-status-success">Imported</span></td>' +
					'<td>Attachment #' + r.attachment_id + '</td>';
			} else {
				tr.innerHTML = '<td class="ik-path">' + escHtml(r.relative_path) + '</td>' +
					'<td><span class="ik-status ik-status-error">Failed</span></td>' +
					'<td>' + escHtml(r.message) + '</td>';
			}
			tbody.appendChild(tr);
		});
	}

	$('ik-rel-orphan-start-over').addEventListener('click', function () {
		if (!confirm('Clear results?')) return;
		orphanItems = [];
		importResults = [];
		$('ik-rel-orphan-progress').style.display = 'none';
		$('ik-rel-orphan-result').style.display = 'none';
		$('ik-rel-orphan-table-wrap').style.display = 'none';
		$('ik-rel-import-log').innerHTML = '';
		showOrphanStep(1);
	});

	// Initialize steps.
	showRelStep(1);
	showOrphanStep(1);
})();
