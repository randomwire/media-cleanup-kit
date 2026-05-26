/**
 * Image Kit — Repair Image Blocks (Markup Audit) module.
 *
 * Thin wrapper around window.imageKitScanUI for the audit flow.
 */
(function () {
	'use strict';

	const { post, escHtml: esc, escAttr } = window.imageKitUtils;
	const config = window.imageKitMarkupAudit || {};

	const configEl   = document.getElementById('ik-ma-config');
	const progressEl = document.getElementById('ik-ma-progress');
	const resultsEl  = document.getElementById('ik-ma-results');
	const startBtn   = document.getElementById('ik-ma-start');
	if (!startBtn || !window.imageKitScanUI) return;

	let currentRunId = null;

	const ISSUE_LABELS = {
		missing_id:              'Missing block ID',
		missing_sizeSlug:        'Missing sizeSlug',
		missing_wp_image_class:  'Missing wp-image class',
		missing_size_class:      'Missing size class on figure',
	};

	function renderAuditDetail(item) {
		if (!item.replacements || item.replacements.length === 0) return '<p>No issue details.</p>';

		let html = '<div class="ik-iu-replacement-details">';

		item.replacements.forEach(function (rep, idx) {
			if (rep.skipped) {
				html += '<div class="ik-iu-replacement-row ik-iu-replacement-skipped">';
				html += '<div class="ik-iu-skip-reason">' + esc(rep.skip_reason || 'Skipped') + '</div>';
				html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
				if (rep.skip_reason === 'attachment_not_found' && rep.from_url) {
					html += '<div class="ik-iu-resolve-actions">';
					html += '<button type="button" class="button ik-ma-resolve-btn"' +
						' data-item-id="' + item.id + '"' +
						' data-url="' + escAttr(rep.from_url) + '">Upload replacement…</button>';
					html += '</div>';
				}
				html += '</div>';
				return;
			}

			const checked = !rep.excluded ? 'checked' : '';

			html += '<div class="ik-iu-replacement-row">';
			html += '<label class="ik-iu-exclude-label"><input type="checkbox" class="ik-ma-exclude-cb" ' + checked + ' data-item-id="' + item.id + '" data-idx="' + idx + '"> Include</label>';

			html += '<div class="ik-iu-audit-issues">';
			if (rep.issues) {
				rep.issues.forEach(function (issue) {
					html += '<span class="ik-status ik-status-warning">' + esc(ISSUE_LABELS[issue] || issue) + '</span> ';
				});
			}
			html += '</div>';

			html += '<div class="ik-iu-before">';
			html += '<div class="ik-iu-thumb-placeholder" data-src="' + escAttr(rep.from_url) + '"></div>';
			html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
			if (rep.to_dimensions) html += '<div class="ik-iu-dim-label">' + rep.to_dimensions.width + '×' + rep.to_dimensions.height + '</div>';
			html += '</div>';

			if (rep.proposed_block_json) {
				html += '<div class="ik-iu-markup-diff"><strong>Proposed block JSON:</strong>';
				html += '<pre><code>' + esc(JSON.stringify(rep.proposed_block_json, null, 2)) + '</code></pre></div>';
			}

			html += '</div>';
		});

		html += '</div>';
		return html;
	}

	function bindAuditDetail(detailEl /*, item */) {
		// Auto-load thumbnails on expand. Clicks then go through the shared
		// lightbox's auto-wired delegated listener.
		detailEl.querySelectorAll('.ik-iu-thumb-placeholder').forEach(function (ph) {
			if (!ph.querySelector('img')) {
				const img = document.createElement('img');
				img.src = ph.dataset.src;
				img.className = 'ik-iu-thumbnail';
				img.loading = 'lazy';
				img.alt = '';
				ph.appendChild(img);
			}
		});

		// Upload-replacement: opens the WP media frame on click. On select,
		// posts (run_id, item_id, attachment_id, failing URL) to the
		// resolve_missing endpoint; on success swaps the row in place.
		detailEl.querySelectorAll('.ik-ma-resolve-btn').forEach(function (btn) {
			if (btn.dataset.bound) return;
			btn.dataset.bound = '1';
			btn.addEventListener('click', function () {
				if (!window.wp || !window.wp.media) {
					alert('WP media library failed to load.');
					return;
				}
				const itemId = btn.dataset.itemId;
				const url    = btn.dataset.url;
				const frame  = window.wp.media({
					title: 'Upload replacement',
					multiple: false,
					library: { type: 'image' },
					button: { text: 'Use this image' },
				});
				frame.on('select', function () {
					const sel = frame.state().get('selection').first();
					if (!sel) return;
					const attachmentId = sel.get('id');
					btn.disabled = true;
					btn.textContent = 'Resolving…';
					post(config.resolveMissingAction, {
						run_id: currentRunId,
						item_id: itemId,
						attachment_id: attachmentId,
						url: url,
					}).then(function (res) {
						if (!res.success || !res.data || !res.data.item) {
							alert((res.data && res.data.message) || 'Failed to resolve.');
							btn.disabled = false;
							btn.textContent = 'Upload replacement…';
							return;
						}
						controller.replaceItem(res.data.item);
					}).catch(function (err) {
						alert('Resolve error: ' + err.message);
						btn.disabled = false;
						btn.textContent = 'Upload replacement…';
					});
				});
				frame.open();
			});
		});
	}

	const controller = window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Auditing posts…',

		scan: {
			action: config.processBatchAction,
			batchSize: config.batchSize || 20,
			getParams: function () {
				const checked = document.querySelectorAll('input[name="ik_ma_post_types[]"]:checked');
				const types = Array.from(checked).map(function (cb) { return cb.value; });
				if (!types.length) { alert('Please select at least one post type.'); return false; }
				return { post_types: types };
			},
			initRun: function (params) {
				return post(config.startRunAction, { post_types: params.post_types }).then(function (res) {
					if (!res.success) {
						alert((res.data && res.data.message) || 'Failed to start audit.');
						return null;
					}
					currentRunId = res.data.run_id;
					return {
						run_id: res.data.run_id,
						post_types: res.data.post_types,
						total_posts: res.data.total_posts,
					};
				});
			},
			afterDone: function (runParams) {
				return post(config.getRunItemsAction, { run_id: runParams.run_id }).then(function (res) {
					return res.success ? (res.data.items || []) : [];
				});
			},
			onCancel: function (runParams) {
				if (runParams && runParams.run_id) {
					post(config.cancelRunAction, { run_id: runParams.run_id });
				}
			},
		},

		counters: [
			{ key: 'posts_scanned',   label: 'Posts audited' },
			{ key: 'images_replaced', label: 'Issues found' },
			{ key: 'images_skipped',  label: 'Skipped' },
		],

		columns: [
			{
				key: 'post_title', label: 'Post', sortable: true,
				render: function (item) {
					let html = '<a href="' + escAttr(item.view_url || '#') + '" target="_blank">' + esc(item.post_title) + '</a>';
					if (item.edit_url) html += ' <a href="' + escAttr(item.edit_url) + '" target="_blank" title="Edit post" class="ik-iu-edit-icon">✎</a>';
					return html;
				},
			},
			{ key: 'post_date', label: 'Date', sortable: true, render: function (item) { return esc(item.post_date || ''); } },
			{ key: 'images_replaced', label: 'Issues', sortable: true, render: function (item) { return item.images_replaced || 0; } },
			{ key: 'images_skipped',  label: 'Skipped', sortable: true, render: function (item) { return item.images_skipped || 0; } },
		],

		rowKey: function (item) { return String(item.id); },
		rowStatus: function (item) {
			if (item.applied_at) return 'applied';
			if (item.images_replaced === 0 && item.images_skipped > 0) return 'skipped';
			return 'pending';
		},

		filters: [
			{ key: 'all',     label: 'All',          predicate: function () { return true; } },
			{ key: 'pending', label: 'Has issues',   predicate: function (item) {
				return !item.applied_at && item.images_replaced > 0;
			} },
			{ key: 'skipped', label: 'Skipped only', predicate: function (item) {
				return !item.applied_at && item.images_replaced === 0 && item.images_skipped > 0;
			} },
			{ key: 'applied', label: 'Applied',      predicate: function (item) { return !!item.applied_at; } },
		],

		searchableFields: ['post_title'],

		rowDetail: renderAuditDetail,
		bindRowDetail: bindAuditDetail,

		apply: {
			action: config.applySingleAction,
			batchSize: 1,
			canApply: function (item) { return !item.applied_at && item.images_replaced > 0; },
			getParams: function (item) { return { run_id: currentRunId || item.run_id, item_id: item.id }; },
			updateItem: function (item, result) {
				if (result.success) {
					item.applied_at = new Date().toISOString();
					if (typeof result.replaced === 'number') item.images_replaced = result.replaced;
				}
			},
			confirmMessage: function (count) {
				return 'Apply ' + count + ' markup fixes? This cannot be undone.';
			},
		},
		applyButtonLabel: 'Apply Selected',

		csvExport: {
			filename: function () { return 'markup-audit-run-' + (currentRunId || 'unknown') + '.csv'; },
			columns: ['Post ID', 'Post Title', 'Image URL', 'Issues', 'Attachment ID', 'Proposed Block JSON'],
			rows: function (item) {
				const out = [];
				if (!item.replacements) return out;
				item.replacements.forEach(function (rep) {
					if (rep.skipped) return;
					out.push([
						item.post_id,
						item.post_title || '',
						rep.from_url,
						(rep.issues || []).join('; '),
						rep.attachment_id || '',
						JSON.stringify(rep.proposed_block_json || {}),
					]);
				});
				return out;
			},
		},

		emptyMessage: 'No incomplete markup found.',

		onDiscard: function () {
			if (!currentRunId) return;
			post(config.discardRunAction, { run_id: currentRunId });
			currentRunId = null;
		},
	});

	if (config.pendingReview) {
		currentRunId = config.pendingReview.run_id;
		post(config.getRunItemsAction, { run_id: currentRunId }).then(function (res) {
			if (!res.success || !res.data.items.length) return;
			controller.loadItems(res.data.items);
		});
	}
})();
