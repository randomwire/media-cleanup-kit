/**
 * Image Kit — Replace Low-Res Images module.
 *
 * The main scan uses window.imageKitScanUI. After results render, a custom
 * "handoff" panel below shows rsync commands + photo-match.py instructions,
 * plus the matched-photos apply flow (still uses its existing per-row apply
 * UI — that's pre-helper code preserved here as a custom panel).
 */
(function () {
	'use strict';

	const { post, escHtml: esc, escAttr, formatNumber } = window.imageKitUtils;
	const config = window.imageKitLowRes || {};

	const configEl   = document.getElementById('ik-lr-config');
	const progressEl = document.getElementById('ik-lr-progress');
	const resultsEl  = document.getElementById('ik-lr-results');
	const startBtn   = document.getElementById('ik-lr-scan');
	if (!startBtn || !window.imageKitScanUI) return;

	const handoffEl       = document.getElementById('ik-lr-handoff');
	const downCmdEl       = document.getElementById('ik-lr-rsync-down');
	const pyCmdEl         = document.getElementById('ik-lr-pymatch-cmd');
	const upCmdEl         = document.getElementById('ik-lr-rsync-up');
	const scanMatchedBtn  = document.getElementById('ik-lr-scan-matched');
	const applyBtn        = document.getElementById('ik-lr-apply-btn');
	const cleanupBtn      = document.getElementById('ik-lr-cleanup-btn');
	const applyErrorsEl   = document.getElementById('ik-lr-apply-errors');
	const applyResultsEl  = document.getElementById('ik-lr-apply-results');
	const applyTbody      = document.getElementById('ik-lr-apply-tbody');
	const applySummaryEl  = document.getElementById('ik-lr-apply-summary');
	const applyProgress   = document.getElementById('ik-lr-apply-progress');
	const applySelectAll  = document.getElementById('ik-lr-apply-select-all');

	const uploadsBasedir   = config.uploadsBasedir;
	const matchedDirName   = config.matchedDirName || 'matched-photos';
	const scanMatchedAction    = config.scanMatchedAction;
	const applyMatchedAction   = config.applyMatchedAction;
	const cleanupMatchedAction = config.cleanupMatchedAction;

	function formatSourceLabel(src) {
		switch (src) {
			case 'featured':       return 'Featured Image';
			case 'content':        return 'Content';
			case 'wp:gallery':     return 'Gallery';
			case 'wp:cover':       return 'Cover';
			case 'wp:media-text':  return 'Media + Text';
			case 'img tag':        return 'Raw <img>';
			default:               return src || 'Content';
		}
	}

	function downloadTextFile(filename, text) {
		const blob = new Blob([text], { type: 'text/plain' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		a.click();
		URL.revokeObjectURL(url);
	}

	function renderHandoff() {
		if (!handoffEl || !uploadsBasedir) return;
		const downCmd = 'rsync -avz --files-from=low-resolution-files.txt \\\n  user@your-server:' +
			uploadsBasedir + '/ ./wp-images/';
		const pyCmd = 'python3 ./tools/photo-match.py \\\n' +
			'  --csv low-resolution-images.csv \\\n' +
			'  --images-dir ./wp-images/ \\\n' +
			'  --photos-dir ./exported-photos/ \\\n' +
			'  --output-dir ./' + matchedDirName + '/';
		const upCmd = 'rsync -avz ./' + matchedDirName + '/ \\\n  user@your-server:' +
			uploadsBasedir + '/' + matchedDirName + '/';
		if (downCmdEl) downCmdEl.textContent = downCmd;
		if (pyCmdEl) pyCmdEl.textContent = pyCmd;
		if (upCmdEl) upCmdEl.textContent = upCmd;
		handoffEl.style.display = '';
	}

	// ── Main scan via scan-ui ──────────────────────────────────────────

	let currentItems = [];

	const controller = window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Scanning posts…',

		scan: {
			action: config.action,
			batchSize: config.batchSize || 20,
			getParams: function () {
				const postTypes = Array.from(document.querySelectorAll('.ik-lr-post-type:checked')).map(function (cb) { return cb.value; });
				if (!postTypes.length) { alert('Select at least one post type.'); return false; }
				const threshold = parseInt(document.getElementById('ik-lr-threshold').value, 10) || 0;
				const dateFrom = document.getElementById('ik-lr-date-from').value || '';
				const dateTo = document.getElementById('ik-lr-date-to').value || '';
				const sizeSlugs = Array.from(document.querySelectorAll('.ik-lr-size-slug:checked')).map(function (cb) { return cb.value; });
				return {
					post_types: postTypes,
					threshold: threshold,
					date_from: dateFrom,
					date_to: dateTo,
					size_slugs: sizeSlugs,
				};
			},
			onItemsLoaded: function (items) {
				currentItems = items;
				renderHandoff();
			},
		},

		counters: [
			{ key: 'posts_scanned', label: 'Posts scanned' },
		],

		columns: [
			{
				key: 'thumbnail_url', label: 'Thumb', sortable: false,
				render: function (it) {
					if (!it.thumbnail_url) return '<span class="ik-no-thumb">—</span>';
					const fullSrc = it.src_url || it.thumbnail_url;
					return '<img class="ik-thumb" src="' + escAttr(it.thumbnail_url) +
						'" data-lightbox-src="' + escAttr(fullSrc) + '" alt="">';
				},
			},
			{
				key: 'source', label: 'Source', sortable: true,
				render: function (it) { return esc(formatSourceLabel(it.source)); },
			},
			{
				key: 'post_title', label: 'Post', sortable: true,
				render: function (it) {
					return '<a href="' + escAttr(it.edit_link) + '" target="_blank">' + esc(it.post_title) + '</a>' +
						' <small>(ID: ' + it.post_id + ')</small>';
				},
			},
			{
				key: 'src_url', label: 'Image', sortable: false,
				render: function (it) {
					const fileName = it.src_url ? it.src_url.split('/').pop() : '';
					return '<code class="ik-path">' + esc(fileName) + '</code>';
				},
			},
			{
				key: 'longest_side', label: 'Dimensions', sortable: true,
				render: function (it) {
					if (!it.width || !it.height) return 'Unknown';
					return it.width + ' × ' + it.height + ' (max ' + it.longest_side + 'px)';
				},
			},
			{
				key: 'size_slug', label: 'Size slug', sortable: true,
				render: function (it) { return esc(it.size_slug || '—'); },
			},
		],

		rowKey: function (it) { return String(it.id); },
		rowStatus: function () { return 'pending'; },

		filters: [
			{ key: 'all', label: 'All', predicate: function () { return true; } },
		],

		searchableFields: ['post_title', 'src_url'],

		rowDetail: function (it) {
			let html = '<dl class="ik-lr-detail">';
			html += '<dt>Attachment ID</dt><dd>' + (it.attachment_id || '—') + '</dd>';
			html += '<dt>Source</dt><dd>' + esc(formatSourceLabel(it.source)) + '</dd>';
			html += '<dt>Image URL</dt><dd><a href="' + escAttr(it.src_url) + '" target="_blank"><code>' + esc(it.src_url) + '</code></a></dd>';
			html += '<dt>File path</dt><dd><code>' + esc(it.file_path || '—') + '</code></dd>';
			html += '<dt>Size slug</dt><dd>' + esc(it.size_slug || '—') + '</dd>';
			if (it.width && it.height) {
				html += '<dt>Dimensions</dt><dd>' + it.width + ' × ' + it.height + ' (max ' + it.longest_side + 'px)</dd>';
			}
			html += '</dl>';
			return html;
		},

		// No apply — Low-Res scan is followed by the photo-match handoff.
		apply: null,

		csvExport: {
			filename: function () { return 'low-resolution-images.csv'; },
			columns: ['post_id', 'post_title', 'source', 'attachment_id', 'src_url', 'file_path', 'width', 'height', 'longest_side', 'size_slug'],
			row: function (it) {
				return [
					it.post_id, it.post_title, (it.source || 'content'),
					it.attachment_id, it.src_url, it.file_path,
					it.width, it.height, it.longest_side, it.size_slug,
				];
			},
		},

		emptyMessage: 'No low-resolution images found. All images meet the threshold.',
	});

	// ── Sibling files-list TXT on CSV export ──────────────────────────
	// The helper's CSV export click is bound after init; intercept by
	// listening for clicks on its export button via delegation.
	document.addEventListener('click', function (e) {
		if (!e.target || !e.target.classList || !e.target.classList.contains('ik-scan-export-btn')) return;
		if (!resultsEl.contains(e.target)) return;
		// Helper has just exported the CSV; emit the sibling TXT using the
		// SAME selection-aware set the CSV used.
		setTimeout(function () {
			const items = controller && controller.getExportItems
				? controller.getExportItems()
				: currentItems;
			if (!items.length) return;
			const prefix = uploadsBasedir ? uploadsBasedir.replace(/\/+$/, '') + '/' : '';
			const paths = items
				.map(function (it) {
					const p = it.file_path || '';
					if (!p) return '';
					return (prefix && p.indexOf(prefix) === 0) ? p.slice(prefix.length) : p;
				})
				.filter(function (p) { return p.length > 0; });
			if (paths.length) downloadTextFile('low-resolution-files.txt', paths.join('\n') + '\n');
		}, 0);
	});

	// ─────────────────────────────────────────────────────────────────
	// Matched-photos apply UI (pre-helper code; kept as a custom panel
	// inside the handoff section). This drives the second scan via the
	// existing ajax_scan_matched / ajax_apply_matched / ajax_cleanup_matched
	// endpoints.
	// ─────────────────────────────────────────────────────────────────

	let applyItems = [];

	function showApplyErrors(message, details) {
		if (!applyErrorsEl) return;
		let html = '<div class="notice notice-error inline"><p><strong>' + esc(message || 'Error') + '</strong></p>';
		if (Array.isArray(details) && details.length) {
			html += '<ul style="margin-left:18px;list-style:disc;">';
			details.forEach(function (d) { html += '<li>' + esc(d) + '</li>'; });
			html += '</ul>';
		}
		html += '</div>';
		applyErrorsEl.innerHTML = html;
		applyErrorsEl.style.display = '';
	}

	function clearApplyErrors() {
		if (!applyErrorsEl) return;
		applyErrorsEl.innerHTML = '';
		applyErrorsEl.style.display = 'none';
	}

	if (scanMatchedBtn) {
		scanMatchedBtn.addEventListener('click', function () {
			clearApplyErrors();
			scanMatchedBtn.disabled = true;
			scanMatchedBtn.textContent = 'Scanning…';
			applyResultsEl.style.display = 'none';

			post(scanMatchedAction).then(function (resp) {
				scanMatchedBtn.disabled = false;
				scanMatchedBtn.textContent = 'Scan matched-photos directory';

				if (!resp.success) {
					const data = resp.data || {};
					showApplyErrors(data.message || 'Scan failed', data.details);
					return;
				}

				applyItems = resp.data.items || [];
				if (!applyItems.length) {
					showApplyErrors('No matches found in matched-photos/photo-match-results.csv.', []);
					return;
				}
				renderApplyTable();
				applyResultsEl.style.display = '';
				cleanupBtn.style.display = '';
			}).catch(function (err) {
				scanMatchedBtn.disabled = false;
				scanMatchedBtn.textContent = 'Scan matched-photos directory';
				showApplyErrors('Scan failed: ' + err.message, []);
			});
		});
	}

	function renderApplyTable() {
		let html = '';
		applyItems.forEach(function (item, i) {
			const thumb = item.original_thumb_url
				? '<img class="ik-thumb" src="' + escAttr(item.original_thumb_url) + '" alt="">'
				: '<span class="ik-no-thumb">—</span>';
			const confidence = (item.confidence * 100).toFixed(1) + '%';
			const disabled = (item.attachment_exists && item.replacement_exists) ? '' : 'disabled';
			const checked  = disabled ? '' : 'checked';
			let status     = '';
			if (!item.attachment_exists) status = '<span class="ik-status ik-status-error">Attachment missing</span>';
			else if (!item.replacement_exists) status = '<span class="ik-status ik-status-error">Replacement missing</span>';
			else status = '<span class="ik-status ik-status-info">Ready</span>';

			html += '<tr data-idx="' + i + '" data-att-id="' + item.attachment_id + '">' +
				'<td class="ik-col-check"><input type="checkbox" class="ik-lr-apply-check" data-idx="' + i + '" ' + checked + ' ' + disabled + '></td>' +
				'<td>' + thumb + ' <a href="' + escAttr(item.edit_link) + '" target="_blank">#' + item.attachment_id + '</a>' +
				(item.post_title ? '<br><small>' + esc(item.post_title) + '</small>' : '') + '</td>' +
				'<td class="ik-path">' + esc(item.original_filename) + '</td>' +
				'<td class="ik-path">' + esc(item.replacement_filename) + '</td>' +
				'<td>' + confidence + '</td>' +
				'<td class="ik-lr-apply-status">' + status + '</td>' +
				'</tr>';
		});
		applyTbody.innerHTML = html;
		applySummaryEl.textContent = applyItems.length + ' match' + (applyItems.length !== 1 ? 'es' : '') + ' ready to review.';
	}

	if (applySelectAll) {
		applySelectAll.addEventListener('change', function () {
			const checked = this.checked;
			applyTbody.querySelectorAll('.ik-lr-apply-check:not([disabled])').forEach(function (cb) {
				cb.checked = checked;
			});
		});
	}

	function updateApplyProgress(progressEl, current, total, text) {
		const fill = progressEl.querySelector('.ik-progress-fill');
		const txt  = progressEl.querySelector('.ik-progress-text');
		const pct  = total > 0 ? Math.round((current / total) * 100) : 0;
		if (fill) fill.style.width = pct + '%';
		if (txt) txt.textContent = text || (pct + '%');
	}

	if (applyBtn) {
		applyBtn.addEventListener('click', async function () {
			clearApplyErrors();
			const selected = Array.from(applyTbody.querySelectorAll('.ik-lr-apply-check:checked'))
				.map(function (cb) { return parseInt(cb.dataset.idx, 10); });
			if (!selected.length) {
				showApplyErrors('Select at least one match to apply.', []);
				return;
			}
			if (!confirm('Apply ' + selected.length + ' replacement(s)? Originals will be backed up to wp-content/uploads/image-kit-backup/.')) {
				return;
			}

			applyBtn.disabled = true;
			applyProgress.style.display = '';
			let processed = 0;

			for (const idx of selected) {
				const item = applyItems[idx];
				updateApplyProgress(applyProgress, processed, selected.length,
					'Applying ' + (processed + 1) + ' / ' + selected.length + '…');

				const row = applyTbody.querySelector('tr[data-idx="' + idx + '"]');
				const statusCell = row ? row.querySelector('.ik-lr-apply-status') : null;
				if (statusCell) statusCell.innerHTML = '<em>Working…</em>';

				try {
					const resp = await post(applyMatchedAction, { attachment_id: item.attachment_id });
					if (resp.success) {
						if (statusCell) {
							const updated = resp.data.posts_updated || 0;
							statusCell.innerHTML = '<span class="ik-status ik-status-success">Applied</span>' +
								(updated > 0 ? ' <small>(' + updated + ' post' + (updated !== 1 ? 's' : '') + ' updated)</small>' : '');
						}
					} else {
						const msg = (resp.data && resp.data.message) || 'Failed';
						if (statusCell) statusCell.innerHTML = '<span class="ik-status ik-status-error">' + esc(msg) + '</span>';
					}
				} catch (err) {
					if (statusCell) statusCell.innerHTML = '<span class="ik-status ik-status-error">' + esc(err.message) + '</span>';
				}
				processed++;
			}

			updateApplyProgress(applyProgress, selected.length, selected.length, 'Done.');
			applyBtn.disabled = false;
			setTimeout(function () { applyProgress.style.display = 'none'; }, 1500);
		});
	}

	if (cleanupBtn) {
		cleanupBtn.addEventListener('click', function () {
			if (!confirm('Delete wp-content/uploads/' + matchedDirName + '/ on the server? Backups are kept.')) return;
			clearApplyErrors();
			cleanupBtn.disabled = true;
			cleanupBtn.textContent = 'Deleting…';

			post(cleanupMatchedAction).then(function (resp) {
				cleanupBtn.disabled = false;
				cleanupBtn.textContent = 'Delete matched-photos directory';
				if (!resp.success) {
					const data = resp.data || {};
					showApplyErrors(data.message || 'Cleanup failed', data.undeletable);
					return;
				}
				alert(resp.data.message || 'Removed.');
				cleanupBtn.style.display = 'none';
				applyResultsEl.style.display = 'none';
				applyItems = [];
			}).catch(function (err) {
				cleanupBtn.disabled = false;
				cleanupBtn.textContent = 'Delete matched-photos directory';
				showApplyErrors('Cleanup failed: ' + err.message, []);
			});
		});
	}
})();
