/**
 * Image Kit — Import Orphan Files module.
 *
 * Thin wrapper around window.imageKitScanUI.
 *
 * Two-phase scan: initRun() walks the uploads tree (heavy, uncancellable)
 * and returns a token + total. The scan-ui batch loop then drives the
 * comparison phase via scan_batch with the token; the final batch returns
 * grouped orphans, which the helper renders.
 */
(function () {
	'use strict';

	const { post, formatBytes, escHtml: esc } = window.imageKitUtils;
	const config = window.imageKitOrphanImporter || {};

	const configEl   = document.getElementById('ik-oi-config');
	const progressEl = document.getElementById('ik-oi-progress');
	const resultsEl  = document.getElementById('ik-oi-results');
	const startBtn   = document.getElementById('ik-oi-scan');
	const indexingEl = document.getElementById('ik-oi-indexing');
	if (!startBtn || !window.imageKitScanUI) return;

	let currentToken = '';

	window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Comparing files against media library…',

		scan: {
			action: config.scanBatchAction,
			batchSize: config.scanBatchSize || 500,
			initRun: function () {
				if (indexingEl) indexingEl.style.display = '';
				return post(config.initScanAction).then(function (res) {
					if (indexingEl) indexingEl.style.display = 'none';
					if (!res.success) {
						alert((res.data && res.data.message) || 'Init scan failed.');
						return null;
					}
					currentToken = res.data.token;
					return { token: res.data.token, total: res.data.total };
				}).catch(function (err) {
					if (indexingEl) indexingEl.style.display = 'none';
					alert('Init scan error: ' + err.message);
					return null;
				});
			},
		},

		counters: [
			{ key: 'files_compared', label: 'Files compared' },
		],

		columns: [
			{
				key: 'relative_path', label: 'File path', sortable: true,
				render: function (r) { return '<code class="ik-path">' + esc(r.relative_path) + '</code>'; },
			},
			{
				key: 'file_size', label: 'Size', sortable: true,
				render: function (r) { return formatBytes(r.file_size); },
			},
			{
				key: 'variant_count', label: 'Variants', sortable: true,
				render: function (r) {
					const n = r.variant_count || 0;
					return n === 0 ? '—' : n + ' variant' + (n !== 1 ? 's' : '');
				},
			},
		],

		rowKey: function (r) { return r.id || r.relative_path; },
		rowStatus: function (r) { return r._imported ? 'applied' : 'pending'; },

		filters: [
			{ key: 'all',     label: 'All',      predicate: function () { return true; } },
			{ key: 'pending', label: 'Pending',  predicate: function (r) { return !r._imported; } },
			{ key: 'applied', label: 'Imported', predicate: function (r) { return !!r._imported; } },
		],

		searchableFields: ['relative_path', 'filename'],

		rowDetail: function (r) {
			let html = '<dl class="ik-oi-detail">';
			html += '<dt>File path</dt><dd><code>' + esc(r.relative_path) + '</code></dd>';
			html += '<dt>Directory</dt><dd><code>' + esc(r.directory || '(uploads root)') + '</code></dd>';
			html += '<dt>Size</dt><dd>' + formatBytes(r.file_size) + '</dd>';
			if (r.variant_files && r.variant_files.length) {
				html += '<dt>Variant files</dt><dd><ul>';
				r.variant_files.forEach(function (f) { html += '<li><code>' + esc(f) + '</code></li>'; });
				html += '</ul></dd>';
			}
			html += '</dl>';
			return html;
		},

		apply: {
			action: config.importAction,
			batchSize: 1,
			canApply: function (r) { return !r._imported; },
			getParams: function (r) { return { paths: [r.relative_path] }; },
			// The endpoint returns outer success=true with per-file results.
			// Mark the row imported only when the inner result reports success.
			isSuccess: function (resp /*, item */) {
				const inner = resp && resp.data && resp.data.results && resp.data.results[0];
				return !!(resp.success && inner && inner.success);
			},
			errorMessage: function (resp) {
				const inner = resp && resp.data && resp.data.results && resp.data.results[0];
				return (inner && inner.message) ? inner.message : 'Import failed';
			},
			updateItem: function (r, result) {
				if (result.success && result.results && result.results[0]) {
					const inner = result.results[0];
					if (inner.success) {
						r._imported = true;
						r._attachment_id = inner.attachment_id;
					}
				}
			},
			confirmMessage: function (count) {
				return 'Import ' + count + ' orphan file(s) into the media library? Thumbnails will be regenerated.';
			},
		},
		applyButtonLabel: 'Import Selected',
		perRowApplyLabel: 'Import',

		csvExport: {
			filename: function () { return 'orphan-files-' + new Date().toISOString().slice(0, 10) + '.csv'; },
			columns: ['File path', 'Size (bytes)', 'Variants'],
			row: function (r) { return [r.relative_path, r.file_size, r.variant_count || 0]; },
		},

		emptyMessage: 'No orphan files found.',
	});
})();
