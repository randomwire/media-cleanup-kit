/**
 * Image Kit — Find Broken Images module.
 *
 * Thin wrapper around window.imageKitScanUI.
 * Read-only for now (no apply phase yet).
 */
(function () {
	'use strict';

	const { escHtml: esc, escAttr } = window.imageKitUtils;
	const config = window.imageKitBrokenImages || {};

	const startBtn   = document.getElementById('ik-bi-scan');
	const configEl   = document.getElementById('ik-bi-config');
	const progressEl = document.getElementById('ik-bi-progress');
	const resultsEl  = document.getElementById('ik-bi-results');
	if (!startBtn || !window.imageKitScanUI) return;

	window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Scanning posts…',

		scan: {
			action: config.action,
			batchSize: config.batchSize || 50,
		},

		counters: [
			{ key: 'posts_scanned', label: 'Posts checked' },
		],

		columns: [
			{
				key: 'post_title', label: 'Post', sortable: true,
				render: function (b) {
					return '<a href="' + escAttr(b.edit_link || '#') + '" target="_blank">' + esc(b.post_title) + '</a>' +
						' <small>(ID: ' + b.post_id + ')</small>';
				},
			},
			{
				key: 'relative_path', label: 'Broken image', sortable: true,
				render: function (b) { return '<code class="ik-path">' + esc(b.relative_path) + '</code>'; },
			},
			{
				key: 'block_type', label: 'Block type', sortable: true,
				render: function (b) { return esc(b.block_type); },
			},
		],

		rowKey: function (b) { return String(b.id); },
		rowStatus: function (b) {
			if (b._removed) return 'applied';
			if (b._error) return 'error';
			return 'pending';
		},

		filters: [
			{ key: 'all',     label: 'All',     predicate: function () { return true; } },
			{ key: 'pending', label: 'Pending', predicate: function (b) { return !b._removed && !b._error; } },
			{ key: 'applied', label: 'Removed', predicate: function (b) { return !!b._removed; } },
			{ key: 'error',   label: 'Errors',  predicate: function (b) { return !!b._error; } },
		],

		searchableFields: ['post_title', 'relative_path', 'block_type'],

		rowDetail: function (b) {
			let html = '<dl class="ik-bi-detail">';
			html += '<dt>Full URL</dt><dd><code>' + esc(b.image_url) + '</code></dd>';
			html += '<dt>Relative path</dt><dd><code>' + esc(b.relative_path) + '</code></dd>';
			html += '<dt>Block type</dt><dd>' + esc(b.block_type) + '</dd>';
			html += '<dt>Post</dt><dd><a href="' + escAttr(b.edit_link) + '" target="_blank">' + esc(b.post_title) + '</a></dd>';
			if (b._backup_file) {
				html += '<dt>Backup</dt><dd><code>' + esc(b._backup_file) + '</code></dd>';
			}
			if (b._error) {
				html += '<dt>Error</dt><dd>' + esc(b._error) + '</dd>';
			}
			html += '</dl>';
			return html;
		},

		apply: {
			action: config.applyRemoveAction,
			batchSize: 1,
			canApply: function (b) { return !b._removed; },
			getParams: function (b) {
				return {
					post_id:    b.post_id,
					image_url:  b.image_url,
					block_type: b.block_type,
				};
			},
			updateItem: function (b, result) {
				if (result.success) {
					b._removed = true;
					b._backup_file = result.backup_file || '';
				} else {
					b._error = result.message || 'Failed';
				}
			},
			confirmMessage: function (count) {
				return 'Remove ' + count + ' broken image reference(s) from your posts?\n\n' +
					'Original post content will be backed up under ' +
					'wp-content/uploads/image-kit-backup/posts/ before any change. ' +
					'This cannot be undone from within the plugin.';
			},
		},
		applyButtonLabel: 'Remove Selected',
		perRowApplyLabel: 'Remove',

		csvExport: {
			filename: function () { return 'broken-images.csv'; },
			columns: ['Post ID', 'Post Title', 'Edit Link', 'Broken Image Path', 'Full URL', 'Block Type'],
			row: function (b) {
				return [b.post_id, b.post_title, b.edit_link, b.relative_path, b.image_url, b.block_type];
			},
		},

		emptyMessage: 'No broken images found.',
	});
})();
