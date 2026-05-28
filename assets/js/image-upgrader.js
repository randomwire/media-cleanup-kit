/**
 * Image Kit — Restore Full Size (Image Upgrader) module.
 *
 * Thin wrapper around window.imageKitScanUI. Provides module-specific
 * config: which AJAX endpoints to call, what columns to show, how to
 * render the per-row replacement detail, and how to invoke per-item apply.
 */
(function () {
	'use strict';

	const { post, escHtml: esc, escAttr } = window.imageKitUtils;
	const config = window.imageKitUpgrader || {};

	const configEl   = document.getElementById('ik-iu-config');
	const progressEl = document.getElementById('ik-iu-progress');
	const resultsEl  = document.getElementById('ik-iu-results');
	const startBtn   = document.getElementById('ik-iu-start-scan');
	if (!startBtn || !window.imageKitScanUI) return;

	let currentRunId = null;
	let currentPostTypes = [];

	// ── Detail row renderer (lifted from old image-upgrader.js) ─────────

	function renderReplacementDetail(item) {
		if (!item.replacements || item.replacements.length === 0) return '<p>No replacement details.</p>';

		let html = '<div class="ik-iu-replacement-details">';

		item.replacements.forEach(function (rep, idx) {
			if (rep.skipped) {
				const skipLabels = {
					attachment_not_found: 'No matching attachment in media library',
					already_full_size: 'Already at full size',
					file_missing: 'Full-size file not found on disk',
				};
				html += '<div class="ik-iu-replacement-row ik-iu-replacement-skipped">';
				html += '<div class="ik-iu-skip-reason">' + (skipLabels[rep.skip_reason] || esc(rep.skip_reason)) + '</div>';
				html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
				if (rep.from_size && rep.from_size !== 'nonstandard_path') {
					html += '<div class="ik-iu-dim-label">Size: ' + esc(rep.from_size) + '</div>';
				}
				if (rep.skip_reason === 'attachment_not_found' && rep.from_url) {
					html += '<div class="ik-iu-resolve-actions">';
					html += '<button type="button" class="button ik-iu-resolve-btn"' +
						' data-item-id="' + item.id + '"' +
						' data-url="' + escAttr(rep.from_url) + '">Upload replacement…</button>';
					html += '</div>';
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

			html += '<div class="ik-iu-before-after" data-before-url="' + escAttr(rep.from_url) + '" data-after-url="' + escAttr(rep.to_url) + '">';
			html += '<div class="ik-iu-before">';
			html += '<div class="ik-iu-thumb-placeholder" data-src="' + escAttr(rep.from_url) + '"></div>';
			html += '<div class="ik-iu-url-label">' + esc(rep.from_url) + '</div>';
			html += '<div class="ik-iu-dim-label">' + esc(sizeLabel) + '</div>';
			html += '<div class="ik-iu-type-label">' + esc(rep.markup_type || 'unknown') + '</div>';
			html += '</div>';
			html += '<div class="ik-iu-arrow">→</div>';
			html += '<div class="ik-iu-after">';
			html += '<div class="ik-iu-thumb-placeholder" data-src="' + escAttr(rep.to_url) + '"></div>';
			html += '<div class="ik-iu-url-label">' + esc(rep.to_url) + '</div>';
			if (rep.to_dimensions) html += '<div class="ik-iu-dim-label">' + rep.to_dimensions.width + '×' + rep.to_dimensions.height + '</div>';
			if (rep.format_variant_used) html += '<div class="ik-iu-variant-label">Format: ' + esc(rep.format_variant_used) + '</div>';
			html += '</div>';
			html += '</div>';

			if (rep.candidates && rep.candidates.length > 1) {
				html += '<div class="ik-iu-candidates">';
				html += '<p><strong>Multiple media library matches — select one:</strong></p>';
				rep.candidates.forEach(function (c) {
					const isSelected = c.attachment_id === rep.attachment_id;
					const dims = c.to_dimensions ? c.to_dimensions.width + '×' + c.to_dimensions.height : 'unknown';
					html += '<label class="ik-iu-candidate-option">';
					html += '<input type="radio" name="ik-iu-candidate-' + item.id + '-' + idx + '" class="ik-iu-candidate-radio" ';
					html += 'data-item-id="' + item.id + '" data-idx="' + idx + '" data-attachment-id="' + c.attachment_id + '" ';
					html += (isSelected ? 'checked' : '') + '> ';
					html += '<span>' + esc(c.to_url) + '</span> (' + dims + ')';
					if (c.filename) html += ' — ' + esc(c.filename);
					html += '</label>';
				});
				html += '</div>';
			}

			html += '</div>';
		});

		html += '</div>';
		return html;
	}

	function bindReplacementDetail(detailEl, item) {
		// Auto-load all thumbnail placeholders inside the freshly-expanded detail.
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

		// Compare-mode click: clicking either before or after thumb opens the
		// lightbox with both side by side. Intercepts the generic auto-wired
		// single-image handler in lightbox.js.
		detailEl.querySelectorAll('.ik-iu-before-after').forEach(function (group) {
			group.addEventListener('click', function (e) {
				const img = e.target.closest('img.ik-iu-thumbnail');
				if (!img) return;
				if (!window.imageKitLightbox) return;
				const beforeUrl = group.dataset.beforeUrl;
				const afterUrl  = group.dataset.afterUrl;
				if (beforeUrl && afterUrl) {
					window.imageKitLightbox.openCompare(beforeUrl, afterUrl);
					e.preventDefault();
					e.stopPropagation();
				}
			}, true); // capture-phase so we run before the auto-wired listener
		});

		// Candidate radio change → save to server.
		detailEl.querySelectorAll('.ik-iu-candidate-radio').forEach(function (radio) {
			radio.addEventListener('change', function () {
				post(config.selectCandidateAction, {
					item_id: radio.dataset.itemId,
					rep_index: parseInt(radio.dataset.idx, 10),
					attachment_id: radio.dataset.attachmentId,
				}).then(function (res) {
					if (!res.success) alert((res.data && res.data.message) || 'Failed to save selection.');
				});
			});
		});

		// Upload-replacement: opens the WP media frame on click. On select,
		// posts (run_id, item_id, attachment_id, failing URL) to the
		// resolve_missing endpoint; on success swaps the row in place.
		detailEl.querySelectorAll('.ik-iu-resolve-btn').forEach(function (btn) {
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

	// ── Scan-ui config ────────────────────────────────────────────────

	const controller = window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Scanning…',

		scan: {
			action: config.processBatchAction,
			batchSize: config.batchSize || 20,
			getParams: function () {
				const checked = document.querySelectorAll('input[name="ik_iu_post_types[]"]:checked');
				currentPostTypes = Array.from(checked).map(function (cb) { return cb.value; });
				if (!currentPostTypes.length) {
					alert('Please select at least one post type.');
					return false;
				}
				return { post_types: currentPostTypes };
			},
			initRun: function (params) {
				return post(config.startRunAction, { post_types: params.post_types }).then(function (res) {
					if (!res.success) {
						alert((res.data && res.data.message) || 'Failed to start scan.');
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
			{ key: '_items',         label: 'Images found' },
			{ key: 'images_skipped', label: 'Skipped' },
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
			{ key: 'images_replaced', label: 'Replacements', sortable: true, render: function (item) { return item.images_replaced || 0; } },
			{ key: 'images_skipped',  label: 'Skipped',      sortable: true, render: function (item) { return item.images_skipped || 0; } },
		],

		rowKey: function (item) { return String(item.id); },

		rowStatus: function (item) {
			if (item.applied_at) return 'applied';
			if (item.images_replaced === 0 && item.images_skipped > 0) return 'skipped';
			return 'pending';
		},

		filters: [
			{ key: 'all',     label: 'All',          predicate: function () { return true; } },
			{ key: 'pending', label: 'Has replacements', predicate: function (item) {
				return !item.applied_at && item.images_replaced > 0;
			} },
			{ key: 'skipped', label: 'Skipped only', predicate: function (item) {
				return !item.applied_at && item.images_replaced === 0 && item.images_skipped > 0;
			} },
			{ key: 'applied', label: 'Applied',      predicate: function (item) { return !!item.applied_at; } },
		],

		searchableFields: ['post_title'],

		rowDetail: renderReplacementDetail,
		bindRowDetail: bindReplacementDetail,

		apply: {
			action: config.applySingleAction,
			batchSize: 1,
			canApply: function (item) {
				return !item.applied_at && item.images_replaced > 0;
			},
			getParams: function (item) {
				return { run_id: currentRunId || item.run_id, item_id: item.id };
			},
			updateItem: function (item, result) {
				if (result.success) {
					item.applied_at = new Date().toISOString();
					if (typeof result.replaced === 'number') item.images_replaced = result.replaced;
				}
			},
			confirmMessage: function (count) {
				return 'Apply ' + count + ' image replacements? This cannot be undone.';
			},
		},

		applyButtonLabel: 'Apply Selected',

		csvExport: {
			filename: function () { return 'image-upgrader-run-' + (currentRunId || 'unknown') + '.csv'; },
			columns: ['Post ID', 'Post Title', 'From URL', 'To URL', 'From Size', 'To Width', 'To Height', 'Markup Type'],
			rows: function (item) {
				const out = [];
				if (!item.replacements) return out;
				item.replacements.forEach(function (rep) {
					if (rep.skipped) return;
					out.push([
						item.post_id,
						item.post_title || '',
						rep.from_url,
						rep.to_url,
						rep.from_size,
						(rep.to_dimensions && rep.to_dimensions.width) || '',
						(rep.to_dimensions && rep.to_dimensions.height) || '',
						rep.markup_type || '',
					]);
				});
				return out;
			},
		},

		emptyMessage: 'No resized images found.',

		onDiscard: function () {
			if (!currentRunId) return;
			post(config.discardRunAction, { run_id: currentRunId });
			currentRunId = null;
		},
	});

	// ── Pending-review auto-restore ────────────────────────────────────

	if (config.pendingReview) {
		currentRunId = config.pendingReview.run_id;
		post(config.getRunItemsAction, { run_id: currentRunId }).then(function (res) {
			if (!res.success || !res.data.items.length) return;
			controller.loadItems(res.data.items);
		});
	}
})();
