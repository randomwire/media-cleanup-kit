/**
 * Image Kit — Attach Unparented Media module.
 *
 * Thin wrapper around window.imageKitScanUI.
 * Scans for attachments with post_parent=0 and proposes a parent post
 * for each based on URL / wp-image-class / block-id / featured-image refs.
 * Per-row Attach + bulk Attach Selected.
 */
(function () {
	'use strict';

	const { post, formatBytes, escHtml: esc, escAttr } = window.imageKitUtils;
	const config = window.imageKitReattach || {};

	const configEl   = document.getElementById('ik-ra-config');
	const progressEl = document.getElementById('ik-ra-progress');
	const resultsEl  = document.getElementById('ik-ra-results');
	const startBtn   = document.getElementById('ik-ra-scan');
	if (!startBtn || !window.imageKitScanUI) return;

	const MATCH_LABELS = {
		featured_image:    'Featured image',
		gallery_shortcode: 'Gallery',
		content_url:       'Content URL',
	};

	function renderMatchBadge(type) {
		if (!type) return '<em>—</em>';
		const label = MATCH_LABELS[type] || type;
		const cls = type === 'featured_image' ? 'ik-status ik-status-success' : 'ik-status ik-status-info';
		return '<span class="' + cls + '">' + esc(label) + '</span>';
	}

	window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Scanning unattached media…',

		scan: {
			action: config.scanAction,
			batchSize: config.scanBatchSize || 50,
		},

		counters: [
			{ key: 'attachments_scanned', label: 'Attachments scanned' },
			{ key: 'matches_found',       label: 'Matches found' },
		],

		columns: [
			{
				key: 'thumbnail_url', label: 'Preview', sortable: false,
				render: function (r) {
					return r.thumbnail_url
						? '<img class="ik-thumb" src="' + escAttr(r.thumbnail_url) + '" alt="">'
						: '<span class="ik-no-thumb">—</span>';
				},
			},
			{
				key: 'filename', label: 'Filename', sortable: true,
				render: function (r) { return '<code class="ik-path">' + esc(r.filename) + '</code>'; },
			},
			{
				key: 'parent_title', label: 'Attach to', sortable: true,
				render: function (r) {
					if (!r.parent_id) return '<em>No match</em>';
					const title = esc(r.parent_title || '(no title)');
					if (r.parent_edit_url) {
						return '<a href="' + escAttr(r.parent_edit_url) + '" target="_blank">' + title + '</a>';
					}
					return title;
				},
			},
			{
				key: 'match_type', label: 'Match', sortable: true,
				render: function (r) { return renderMatchBadge(r.match_type); },
			},
			{
				key: 'id', label: 'ID', sortable: true,
				render: function (r) { return r.id; },
			},
		],

		rowKey: function (r) { return String(r.id); },
		rowStatus: function (r) {
			if (r._attached) return 'applied';
			if (!r.parent_id) return 'skipped';
			return 'pending';
		},

		filters: [
			{ key: 'all',       label: 'All',        predicate: function () { return true; } },
			{ key: 'pending',   label: 'Pending',    predicate: function (r) { return !r._attached && !!r.parent_id; } },
			{ key: 'attached',  label: 'Attached',   predicate: function (r) { return !!r._attached; } },
			{ key: 'unmatched', label: 'No match',   predicate: function (r) { return !r._attached && !r.parent_id; } },
		],

		searchableFields: ['filename', 'parent_title'],

		rowDetail: function (r) {
			let html = '<dl class="ik-ra-detail">';
			html += '<dt>Attachment ID</dt><dd>' + r.id + '</dd>';
			html += '<dt>File</dt><dd><code>' + esc(r.attached_file || r.filename) + '</code></dd>';
			if (r.file_size) html += '<dt>Size</dt><dd>' + formatBytes(r.file_size) + '</dd>';
			if (r.parent_id) {
				html += '<dt>Proposed parent</dt><dd>';
				html += '<a href="' + escAttr(r.parent_edit_url || '#') + '" target="_blank">' + esc(r.parent_title) + '</a>';
				if (r.parent_view_url) {
					html += ' &nbsp;<a href="' + escAttr(r.parent_view_url) + '" target="_blank">(view)</a>';
				}
				html += '</dd>';
				html += '<dt>Why this post</dt><dd>' + renderMatchBadge(r.match_type);
				if (r.match_evidence) {
					html += ' &nbsp;<code>' + esc(r.match_evidence) + '</code>';
				}
				html += '</dd>';
			} else {
				html += '<dt>Proposed parent</dt><dd><em>No referencing post found. This attachment can\'t be auto-attached.</em></dd>';
			}
			html += '</dl>';
			return html;
		},

		apply: {
			action: config.applyAction,
			batchSize: 1,
			canApply: function (r) { return !r._attached && !!r.parent_id; },
			getParams: function (r) {
				return { items: JSON.stringify([{ attachment_id: r.id, parent_id: r.parent_id }]) };
			},
			// Outer success is always true; inner per-item success drives the row.
			isSuccess: function (resp) {
				const inner = resp && resp.data && resp.data.results && resp.data.results[0];
				return !!(resp.success && inner && inner.success);
			},
			errorMessage: function (resp) {
				const inner = resp && resp.data && resp.data.results && resp.data.results[0];
				return (inner && inner.message) ? inner.message : 'Attach failed';
			},
			updateItem: function (r, result) {
				if (result.success && result.results && result.results[0] && result.results[0].success) {
					r._attached = true;
				}
			},
			confirmMessage: function (count) {
				return 'Attach ' + count + ' media item(s) to their proposed parent posts?';
			},
		},
		applyButtonLabel: 'Attach Selected',
		perRowApplyLabel: 'Attach',

		csvExport: {
			filename: function () { return 'attach-unparented-' + new Date().toISOString().slice(0, 10) + '.csv'; },
			columns: ['Attachment ID', 'Filename', 'Proposed Parent ID', 'Proposed Parent Title', 'Match Type'],
			row: function (r) {
				return [r.id, r.filename, r.parent_id || '', r.parent_title || '', r.match_type || ''];
			},
		},

		emptyMessage: 'No unattached media found.',
	});
})();
