/**
 * Image Kit — Delete Unused Files module.
 *
 * Thin wrapper around window.imageKitScanUI.
 * Per-row Delete + bulk Delete Selected. The "apply" verb is delete here;
 * the helper's apply mechanism is reused.
 */
(function () {
	'use strict';

	const { post, formatBytes, escHtml: esc } = window.imageKitUtils;
	const config = window.imageKitUnusedCleaner || {};

	const configEl   = document.getElementById('ik-uc-config');
	const progressEl = document.getElementById('ik-uc-progress');
	const resultsEl  = document.getElementById('ik-uc-results');
	const startBtn   = document.getElementById('ik-uc-scan-btn');
	if (!startBtn || !window.imageKitScanUI) return;

	window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Scanning uploads…',

		scan: {
			action: config.scanAction,
			batchSize: 100,
		},

		counters: [
			{ key: 'files_scanned', label: 'Files scanned' },
		],

		columns: [
			{
				key: 'filename', label: 'Filename', sortable: true,
				render: function (r) { return '<code class="ik-path">' + esc(r.filename) + '</code>'; },
			},
			{
				key: 'file_size', label: 'Size', sortable: true,
				render: function (r) { return formatBytes(r.file_size); },
			},
			{
				key: 'used_in', label: 'Used in', sortable: false,
				render: function (r) {
					if (!r.used_in || !r.used_in.length) return '<em>—</em>';
					if (r.used_in.length === 1) return esc(r.used_in[0]);
					return r.used_in.length + ' references';
				},
			},
			{
				key: 'group', label: 'Variants', sortable: false,
				render: function (r) {
					const n = (r.group && r.group.length > 1) ? r.group.length - 1 : 0;
					return n === 0 ? '—' : n + ' thumbnail' + (n !== 1 ? 's' : '');
				},
			},
		],

		rowKey: function (r) { return r.filename; },
		rowStatus: function (r) {
			if (r._deleted) return 'applied';
			if (r.is_used) return 'skipped';
			return 'pending';
		},

		filters: [
			{ key: 'all',    label: 'All',         predicate: function () { return true; } },
			{ key: 'unused', label: 'Unused only', predicate: function (r) { return !r.is_used && !r._deleted; } },
			{ key: 'used',   label: 'Used only',   predicate: function (r) { return r.is_used; } },
			{ key: 'deleted',label: 'Deleted',     predicate: function (r) { return !!r._deleted; } },
		],

		searchableFields: ['filename'],

		rowDetail: function (r) {
			let html = '<dl class="ik-uc-detail">';
			html += '<dt>Status</dt><dd>' + (r.is_used ? 'In use' : 'Unused') + '</dd>';
			html += '<dt>Size</dt><dd>' + formatBytes(r.file_size) + '</dd>';
			if (r.used_in && r.used_in.length) {
				html += '<dt>Referenced by</dt><dd><ul>';
				r.used_in.forEach(function (ref) { html += '<li>' + esc(ref) + '</li>'; });
				html += '</ul></dd>';
			}
			if (r.group && r.group.length > 1) {
				html += '<dt>All variants in group</dt><dd><ul>';
				r.group.forEach(function (f) { html += '<li><code>' + esc(f) + '</code></li>'; });
				html += '</ul></dd>';
			}
			if (r.attachment_ids && r.attachment_ids.length) {
				html += '<dt>Attachment IDs</dt><dd>' + r.attachment_ids.join(', ') + '</dd>';
			}
			html += '</dl>';
			return html;
		},

		apply: {
			action: config.deleteAction,
			batchSize: 1,
			canApply: function (r) { return !r.is_used && !r._deleted; },
			getParams: function (r) {
				return {
					files: r.group && r.group.length ? r.group : [r.filename],
					attachment_ids: r.attachment_ids || [],
				};
			},
			// The endpoint returns outer success=true with per-file results.
			// Mark the row applied only when EVERY file in the group deleted.
			isSuccess: function (resp /*, item */) {
				if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.results)) return false;
				if (resp.data.results.length === 0) return false;
				return resp.data.results.every(function (r) { return r && r.success; });
			},
			errorMessage: function (resp) {
				const results = resp && resp.data && resp.data.results;
				if (Array.isArray(results)) {
					const failed = results.filter(function (r) { return r && !r.success; });
					if (failed.length) {
						return failed.length + ' file(s) could not be deleted: ' +
							failed.slice(0, 3).map(function (r) { return r.filename + ' (' + r.error + ')'; }).join('; ');
					}
				}
				return 'Delete failed';
			},
			updateItem: function (r, result) {
				if (result.success) r._deleted = true;
			},
			confirmMessage: function (count) {
				return 'Delete ' + count + ' file group(s)? This cannot be undone.';
			},
		},
		applyButtonLabel: 'Delete Selected',
		perRowApplyLabel: 'Delete',

		csvExport: {
			filename: function () { return 'unused-images-' + new Date().toISOString().slice(0, 10) + '.csv'; },
			columns: ['Filename', 'Size (bytes)', 'Status', 'Used In', 'Thumbnails'],
			row: function (r) {
				return [
					r.filename,
					r.file_size,
					r.is_used ? 'Used' : 'Unused',
					(r.used_in || []).join('; '),
					(r.group || []).filter(function (f) { return f !== r.filename; }).join('; '),
				];
			},
		},

		emptyMessage: 'No image files found in uploads.',
	});
})();
