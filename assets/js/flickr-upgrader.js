/**
 * Media Cleanup Kit — Replace Flickr Images module.
 *
 * Mirrors low-resolution.js. The main scan uses window.imageKitScanUI.
 * After results render, a handoff panel below shows the flickr-fetch.py
 * + rsync commands, then a second-scan apply UI that reads the
 * flickr-replacements/ drop directory and applies replacements with
 * backup + thumbnail regen + block JSON cleanup.
 */
(function () {
	'use strict';

	const { post, escHtml: esc, escAttr } = window.imageKitUtils;
	const config = window.imageKitFlickr || {};

	const configEl   = document.getElementById('ik-fu-config');
	const progressEl = document.getElementById('ik-fu-progress');
	const resultsEl  = document.getElementById('ik-fu-results');
	const startBtn   = document.getElementById('ik-fu-scan');
	if (!startBtn || !window.imageKitScanUI) return;

	const handoffEl      = document.getElementById('ik-fu-handoff');
	const fetchCmdEl     = document.getElementById('ik-fu-fetch-cmd');
	const upCmdEl        = document.getElementById('ik-fu-rsync-up');
	const scanDropBtn    = document.getElementById('ik-fu-scan-drop');
	const applyBtn       = document.getElementById('ik-fu-apply-btn');
	const cleanupBtn     = document.getElementById('ik-fu-cleanup-btn');
	const applyErrorsEl  = document.getElementById('ik-fu-apply-errors');
	const applyResultsEl = document.getElementById('ik-fu-apply-results');
	const applyTbody     = document.getElementById('ik-fu-apply-tbody');
	const applySummaryEl = document.getElementById('ik-fu-apply-summary');
	const applyProgress  = document.getElementById('ik-fu-apply-progress');
	const applySelectAll = document.getElementById('ik-fu-apply-select-all');

	const uploadsBasedir   = config.uploadsBasedir;
	const dropDirName      = config.dropDirName || 'flickr-replacements';
	const scanDropAction   = config.scanDropAction;
	const applyAction      = config.applyAction;
	const cleanupDropAction= config.cleanupDropAction;

	function renderHandoff() {
		if (!handoffEl || !uploadsBasedir) return;
		const fetchCmd = 'python3 ./tools/flickr-fetch.py \\\n' +
			'  --csv flickr-images.csv \\\n' +
			'  --api-key YOUR_FLICKR_API_KEY \\\n' +
			'  --output-dir ./' + dropDirName + '/';
		const upCmd = 'rsync -avz ./' + dropDirName + '/ \\\n  user@your-server:' +
			uploadsBasedir + '/' + dropDirName + '/';
		if (fetchCmdEl) fetchCmdEl.textContent = fetchCmd;
		if (upCmdEl) upCmdEl.textContent = upCmd;
		handoffEl.style.display = '';
	}

	// ── Main scan via scan-ui ──────────────────────────────────────────

	let currentItems = [];

	window.imageKitScanUI.init({
		containers: { config: configEl, progress: progressEl, results: resultsEl },
		startButton: startBtn,
		progressTitle: 'Scanning posts…',

		scan: {
			action: config.action,
			batchSize: config.batchSize || 20,
			getParams: function () {
				const postTypes = Array.from(document.querySelectorAll('.ik-fu-post-type:checked')).map(function (cb) { return cb.value; });
				if (!postTypes.length) { alert('Select at least one post type.'); return false; }
				const dateFrom = document.getElementById('ik-fu-date-from').value || '';
				const dateTo   = document.getElementById('ik-fu-date-to').value || '';
				return {
					post_types: postTypes,
					date_from: dateFrom,
					date_to: dateTo,
				};
			},
			onItemsLoaded: function (items) {
				currentItems = items;
				renderHandoff();
			},
		},

		counters: [
			{ key: '_items', label: 'Flickr images found' },
		],

		columns: [
			{
				key: 'thumbnail_url', label: 'Thumb', sortable: false,
				render: function (it) {
					if (!it.thumbnail_url) return '<span class="ik-no-thumb">—</span>';
					return '<img class="ik-thumb" src="' + escAttr(it.thumbnail_url) +
						'" data-lightbox-src="' + escAttr(it.current_url || it.thumbnail_url) + '" alt="">';
				},
			},
			{
				key: 'post_title', label: 'Post', sortable: true,
				render: function (it) {
					return '<a href="' + escAttr(it.edit_link) + '" target="_blank">' + esc(it.post_title) + '</a>' +
						' <small>(ID: ' + it.post_id + ')</small>';
				},
			},
			{
				key: 'current_filename', label: 'Current file', sortable: false,
				render: function (it) {
					return '<code class="ik-path">' + esc(it.current_filename) + '</code>';
				},
			},
			{
				key: 'current_size_suffix', label: 'Size', sortable: true,
				render: function (it) { return '<code>' + esc(it.current_size_suffix) + '</code>'; },
			},
			{
				key: 'flickr_photo_id', label: 'Photo ID', sortable: true,
				render: function (it) { return '<code>' + esc(it.flickr_photo_id) + '</code>'; },
			},
			{
				key: 'attachment_id', label: 'Attachment', sortable: true,
				render: function (it) {
					return it.attachment_id ? ('#' + it.attachment_id) : '<em>—</em>';
				},
			},
		],

		rowKey: function (it) { return String(it.id); },
		rowStatus: function () { return 'pending'; },

		filters: [
			{ key: 'all', label: 'All', predicate: function () { return true; } },
		],

		searchableFields: ['post_title', 'current_url', 'flickr_photo_id'],

		rowDetail: function (it) {
			let html = '<dl class="ik-fu-detail">';
			html += '<dt>Photo ID</dt><dd>' + esc(it.flickr_photo_id) + '</dd>';
			html += '<dt>Secret</dt><dd><code>' + esc(it.secret) + '</code></dd>';
			html += '<dt>Current size suffix</dt><dd><code>' + esc(it.current_size_suffix) + '</code></dd>';
			html += '<dt>Current URL</dt><dd><a href="' + escAttr(it.current_url) + '" target="_blank"><code>' + esc(it.current_url) + '</code></a></dd>';
			html += '<dt>Attachment ID</dt><dd>' + (it.attachment_id || '—') + '</dd>';
			html += '</dl>';
			return html;
		},

		apply: null,

		csvExport: {
			filename: function () { return 'flickr-images.csv'; },
			columns: ['flickr_photo_id', 'secret', 'current_size_suffix', 'current_filename', 'current_url', 'post_id', 'post_title', 'attachment_id'],
			row: function (it) {
				return [
					it.flickr_photo_id,
					it.secret,
					it.current_size_suffix,
					it.current_filename,
					it.current_url,
					it.post_id,
					it.post_title,
					it.attachment_id || '',
				];
			},
		},

		emptyMessage: 'No Flickr images found in the selected post types.',
	});

	// ─────────────────────────────────────────────────────────────────
	// Drop-directory scan + apply UI (pre-helper code, kept as custom
	// panel inside the handoff section, identical pattern to low-res's
	// matched-photos table).
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

	if (scanDropBtn) {
		scanDropBtn.addEventListener('click', function () {
			clearApplyErrors();
			scanDropBtn.disabled = true;
			scanDropBtn.textContent = 'Scanning…';
			applyResultsEl.style.display = 'none';

			post(scanDropAction).then(function (resp) {
				scanDropBtn.disabled = false;
				scanDropBtn.textContent = 'Scan flickr-replacements directory';

				if (!resp.success) {
					const data = resp.data || {};
					showApplyErrors(data.message || 'Scan failed', data.details);
					return;
				}

				applyItems = resp.data.items || [];
				if (!applyItems.length) {
					showApplyErrors('No replacement files found in flickr-replacements/.', []);
					return;
				}
				renderApplyTable();
				applyResultsEl.style.display = '';
				cleanupBtn.style.display = '';
			}).catch(function (err) {
				scanDropBtn.disabled = false;
				scanDropBtn.textContent = 'Scan flickr-replacements directory';
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
			const disabled = item.attachment_exists ? '' : 'disabled';
			const checked  = disabled ? '' : 'checked';
			let status     = '';
			if (!item.attachment_exists) {
				status = '<span class="ik-status ik-status-error">Attachment missing</span>';
			} else {
				status = '<span class="ik-status ik-status-info">Ready</span>';
			}

			const attachmentCell = item.attachment_exists
				? (thumb + ' <a href="' + escAttr(item.edit_link) + '" target="_blank">#' + item.attachment_id + '</a>' +
					(item.post_title ? '<br><small>' + esc(item.post_title) + '</small>' : ''))
				: ('<em>Photo ID ' + esc(item.flickr_photo_id) + ' — no attachment</em>');

			html += '<tr data-idx="' + i + '" data-att-id="' + item.attachment_id + '">' +
				'<td class="ik-col-check"><input type="checkbox" class="ik-fu-apply-check" data-idx="' + i + '" ' + checked + ' ' + disabled + '></td>' +
				'<td>' + attachmentCell + '</td>' +
				'<td class="ik-path">' + esc(item.original_filename || '—') + '</td>' +
				'<td class="ik-path">' + esc(item.replacement_filename) + '</td>' +
				'<td><code>' + esc(item.flickr_photo_id) + '</code></td>' +
				'<td class="ik-fu-apply-status">' + status + '</td>' +
				'</tr>';
		});
		applyTbody.innerHTML = html;
		applySummaryEl.textContent = applyItems.length + ' replacement' + (applyItems.length !== 1 ? 's' : '') + ' ready to review.';
	}

	if (applySelectAll) {
		applySelectAll.addEventListener('change', function () {
			const checked = this.checked;
			applyTbody.querySelectorAll('.ik-fu-apply-check:not([disabled])').forEach(function (cb) {
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
			const selected = Array.from(applyTbody.querySelectorAll('.ik-fu-apply-check:checked'))
				.map(function (cb) { return parseInt(cb.dataset.idx, 10); });
			if (!selected.length) {
				showApplyErrors('Select at least one replacement to apply.', []);
				return;
			}
			const ok = await window.imageKitModal.confirm({
				title:        'Apply Flickr replacements?',
				message:      'Apply ' + selected.length + ' replacement(s)? Originals will be backed up to wp-content/uploads/image-kit-backup/.',
				confirmLabel: 'Apply',
				danger:       true,
			});
			if (!ok) return;

			applyBtn.disabled = true;
			applyProgress.style.display = '';
			let processed = 0;

			for (const idx of selected) {
				const item = applyItems[idx];
				updateApplyProgress(applyProgress, processed, selected.length,
					'Applying ' + (processed + 1) + ' / ' + selected.length + '…');

				const row = applyTbody.querySelector('tr[data-idx="' + idx + '"]');
				const statusCell = row ? row.querySelector('.ik-fu-apply-status') : null;
				if (statusCell) statusCell.innerHTML = '<em>Working…</em>';

				try {
					const resp = await post(applyAction, { attachment_id: item.attachment_id });
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
			window.imageKitModal.confirm({
				title:        'Delete flickr-replacements directory?',
				message:      'Delete wp-content/uploads/' + dropDirName + '/ on the server? Backups are kept.',
				confirmLabel: 'Delete',
				danger:       true,
			}).then(function (ok) {
				if (!ok) return;
				runCleanup();
			});
		});

		const runCleanup = function () {
			clearApplyErrors();
			cleanupBtn.disabled = true;
			cleanupBtn.textContent = 'Deleting…';

			post(cleanupDropAction).then(function (resp) {
				cleanupBtn.disabled = false;
				cleanupBtn.textContent = 'Delete flickr-replacements directory';
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
				cleanupBtn.textContent = 'Delete flickr-replacements directory';
				showApplyErrors('Cleanup failed: ' + err.message, []);
			});
		};
	}
})();
