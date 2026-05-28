/**
 * Image Kit — Flatten Uploads (Relocator) module.
 *
 * Thin wrapper around window.imageKitScanUI.
 * Per-row Relocate + bulk Relocate Selected. The relocate endpoint
 * accepts an array of attachment IDs; per-row apply sends a single ID.
 */
(function () {
	'use strict';

	const { post, escHtml: esc, escAttr } = window.imageKitUtils;
	const config = window.imageKitRelocator || {};

	const configEl   = document.getElementById('ik-rel-config');
	const progressEl = document.getElementById('ik-rel-progress');
	const resultsEl  = document.getElementById('ik-rel-results');
	const startBtn   = document.getElementById('ik-rel-scan');
	if (!startBtn || !window.imageKitScanUI) return;

	window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Scanning attachments…',

		scan: {
			action: config.scanAction,
			batchSize: config.scanBatchSize || 25,
		},

		counters: [
			{ key: '_items', label: 'Images to relocate' },
		],

		columns: [
			{
				key: 'thumb_url', label: 'Preview', sortable: false,
				render: function (r) {
					return r.thumb_url
						? '<img class="ik-thumb" src="' + escAttr(r.thumb_url) + '" alt="">'
						: '<span class="ik-no-thumb">—</span>';
				},
			},
			{
				key: 'relative_path', label: 'Current path', sortable: true,
				render: function (r) { return '<code class="ik-path">' + esc(r.relative_path) + '</code>'; },
			},
			{
				key: 'target_filename', label: 'Target filename', sortable: true,
				render: function (r) {
					let html = '<code class="ik-path">' + esc(r.target_filename) + '</code>';
					if (r.has_collision) {
						html += ' <span class="ik-collision" title="Filename collision — will be renamed">⚠</span>';
					}
					return html;
				},
			},
			{
				key: 'thumb_count', label: 'Thumbnails', sortable: true,
				render: function (r) { return (r.thumb_count || 0) + (r.has_original_image ? ' + original' : ''); },
			},
			{
				key: 'post_count', label: 'Posts', sortable: true,
				render: function (r) { return r.post_count || 0; },
			},
		],

		rowKey: function (r) { return String(r.attachment_id); },
		rowStatus: function (r) { return r._relocated ? 'applied' : 'pending'; },

		filters: [
			{ key: 'all',     label: 'All',     predicate: function () { return true; } },
			{ key: 'pending', label: 'Pending', predicate: function (r) { return !r._relocated; } },
			{ key: 'applied', label: 'Done',    predicate: function (r) { return !!r._relocated; } },
		],

		searchableFields: ['relative_path', 'target_filename'],

		rowDetail: function (r) {
			let html = '<dl class="ik-rel-detail">';
			html += '<dt>Attachment ID</dt><dd>' + r.attachment_id + '</dd>';
			html += '<dt>Current path</dt><dd><code>' + esc(r.relative_path) + '</code></dd>';
			html += '<dt>Will become</dt><dd><code>' + esc(r.target_filename) + '</code></dd>';
			if (r.has_collision) {
				html += '<dt>Collision</dt><dd>Filename clashes with an existing file in uploads root — will be renamed to avoid overwriting.</dd>';
			}
			html += '<dt>Thumbnails</dt><dd>' + (r.thumb_count || 0) + (r.has_original_image ? ' + original' : '') + ' will move alongside</dd>';
			html += '<dt>Post references</dt><dd>' + (r.post_count || 0) + ' post(s) reference this attachment; URLs will be rewritten on apply.</dd>';
			html += '</dl>';
			return html;
		},

		apply: {
			action: config.applyAction,
			batchSize: 1,
			canApply: function (r) { return !r._relocated; },
			getParams: function (r) { return { attachment_ids: [r.attachment_id] }; },
			// The endpoint always returns outer success=true with per-file
			// results. Treat the row as applied only when the inner result
			// for THIS attachment_id reports success.
			isSuccess: function (resp /*, item */) {
				const inner = resp && resp.data && resp.data.results && resp.data.results[0];
				return !!(resp.success && inner && inner.success);
			},
			errorMessage: function (resp) {
				const inner = resp && resp.data && resp.data.results && resp.data.results[0];
				return (inner && inner.message) ? inner.message : 'Relocate failed';
			},
			updateItem: function (r, result) {
				if (result.success) r._relocated = true;
			},
			confirmMessage: function (count) {
				return 'Relocate ' + count + ' image(s) to the uploads root? Existing URLs in post content will be rewritten. Make sure you have a backup.';
			},
		},
		applyButtonLabel: 'Relocate Selected',
		perRowApplyLabel: 'Relocate',

		csvExport: {
			filename: function () { return 'relocator-' + new Date().toISOString().slice(0, 10) + '.csv'; },
			columns: ['Attachment ID', 'Current Path', 'Target Filename', 'Thumbnails', 'Posts'],
			row: function (r) {
				return [r.attachment_id, r.relative_path, r.target_filename, r.thumb_count || 0, r.post_count || 0];
			},
		},

		emptyMessage: 'No images found in subdirectories.',

		onDiscard: function () {
			if (config.resetAction) post(config.resetAction);
		},
	});
})();
