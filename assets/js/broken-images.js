/**
 * Image Kit — Broken Images module JS.
 *
 * Handles scanning, results display, pagination, and CSV export.
 */
(function () {
	'use strict';

	const { post, escHtml, escAttr, updateProgress, exportCSV, formatNumber } = imageKitUtils;
	const { action, pageSize } = imageKitBrokenImages;

	let allBroken = [];
	let currentPage = 1;

	const scanBtn     = document.getElementById('ik-bi-scan');
	const progressDiv = document.getElementById('ik-bi-progress');
	const resultsDiv  = document.getElementById('ik-bi-results');
	const summaryEl   = document.getElementById('ik-bi-summary');
	const exportWrap  = document.getElementById('ik-bi-export-wrap');
	const exportBtn   = document.getElementById('ik-bi-export');
	const table       = document.getElementById('ik-bi-table');
	const tbody       = document.getElementById('ik-bi-tbody');

	if (!scanBtn) return;

	const postTotal = parseInt(scanBtn.dataset.total, 10) || 0;

	scanBtn.addEventListener('click', function () {
		scanBtn.disabled = true;
		progressDiv.style.display = '';
		resultsDiv.style.display = 'none';
		allBroken = [];
		currentPage = 1;
		runScan(0);
	});

	function runScan(postOffset) {
		post(action, { post_offset: postOffset })
			.then(function (resp) {
				if (!resp.success) {
					alert('Scan error: ' + (resp.data || 'Unknown error'));
					scanBtn.disabled = false;
					return;
				}

				const d = resp.data;
				allBroken = allBroken.concat(d.broken);

				const processed = d.posts_processed;
				const text = formatNumber(processed) + ' / ' + formatNumber(postTotal) +
					' posts checked (' + allBroken.length + ' broken image' +
					(allBroken.length !== 1 ? 's' : '') + ' found)';
				updateProgress(progressDiv, processed, postTotal, text);

				if (!d.done) {
					runScan(processed);
				} else {
					progressDiv.style.display = 'none';
					showResults();
				}
			})
			.catch(function (err) {
				alert('Scan failed: ' + err.message);
				scanBtn.disabled = false;
			});
	}

	function showResults() {
		resultsDiv.style.display = '';
		scanBtn.disabled = false;

		if (allBroken.length === 0) {
			summaryEl.textContent = 'No broken images found. All internal image references point to existing files.';
			return;
		}

		summaryEl.textContent = 'Found ' + allBroken.length + ' broken image reference' +
			(allBroken.length !== 1 ? 's' : '') + ' across your posts.';
		table.style.display = '';
		exportWrap.style.display = '';
		currentPage = 1;
		renderPage();
	}

	function renderPage() {
		const start = (currentPage - 1) * pageSize;
		const end = Math.min(start + pageSize, allBroken.length);
		let html = '';

		for (let i = start; i < end; i++) {
			const b = allBroken[i];
			html += '<tr>' +
				'<td><a href="' + escAttr(b.edit_link) + '" target="_blank">' +
				escHtml(b.post_title) + '</a> <small>(ID: ' + b.post_id + ')</small></td>' +
				'<td><code>' + escHtml(b.relative_path) + '</code></td>' +
				'<td>' + escHtml(b.block_type) + '</td>' +
				'</tr>';
		}

		tbody.innerHTML = html;
		renderPagination();
	}

	function renderPagination() {
		const totalPages = Math.ceil(allBroken.length / pageSize);
		const paginationTop = document.getElementById('ik-bi-pagination');
		const paginationBottom = document.getElementById('ik-bi-pagination-bottom');

		if (totalPages <= 1) {
			paginationTop.innerHTML = '';
			paginationBottom.innerHTML = '';
			return;
		}

		let html = 'Page: ';
		for (let p = 1; p <= totalPages; p++) {
			const cls = 'button ik-page-btn' + (p === currentPage ? ' current' : '');
			html += '<button type="button" class="' + cls + '" data-page="' + p + '">' + p + '</button> ';
		}

		paginationTop.innerHTML = html;
		paginationBottom.innerHTML = html;

		document.querySelectorAll('.ik-page-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				currentPage = parseInt(this.dataset.page, 10);
				renderPage();
			});
		});
	}

	if (exportBtn) {
		exportBtn.addEventListener('click', function () {
			exportCSV(
				'broken-images.csv',
				['Post ID', 'Post Title', 'Edit Link', 'Broken Image Path', 'Full URL', 'Block Type'],
				allBroken.map(function (b) {
					return [b.post_id, b.post_title, b.edit_link, b.relative_path, b.image_url, b.block_type];
				})
			);
		});
	}
})();
