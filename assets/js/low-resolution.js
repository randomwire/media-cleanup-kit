/**
 * Image Kit — Low Resolution module JS.
 *
 * Handles scanning, results display with thumbnails, pagination, and CSV export.
 */
(function () {
	'use strict';

	const { post, escHtml, escAttr, updateProgress, exportCSV, formatNumber } = imageKitUtils;
	const { action, pageSize } = imageKitLowRes;

	let allItems = [];
	let currentPage = 1;
	let totalPosts = 0;

	const scanBtn     = document.getElementById('ik-lr-scan');
	const configDiv   = document.getElementById('ik-lr-config');
	const progressDiv = document.getElementById('ik-lr-progress');
	const resultsDiv  = document.getElementById('ik-lr-results');
	const summaryEl   = document.getElementById('ik-lr-summary');
	const exportWrap  = document.getElementById('ik-lr-export-wrap');
	const exportBtn   = document.getElementById('ik-lr-export');
	const table       = document.getElementById('ik-lr-table');
	const tbody       = document.getElementById('ik-lr-tbody');

	if (!scanBtn) return;

	scanBtn.addEventListener('click', startScan);

	function startScan() {
		const postTypes = Array.from(document.querySelectorAll('.ik-lr-post-type:checked'))
			.map(cb => cb.value);

		if (!postTypes.length) {
			alert('Select at least one post type.');
			return;
		}

		const threshold = parseInt(document.getElementById('ik-lr-threshold').value, 10) || 0;
		const dateFrom = document.getElementById('ik-lr-date-from').value || '';
		const dateTo = document.getElementById('ik-lr-date-to').value || '';

		scanBtn.disabled = true;
		progressDiv.style.display = '';
		resultsDiv.style.display = 'none';
		allItems = [];
		currentPage = 1;
		totalPosts = 0;

		runScan(0, postTypes, threshold, dateFrom, dateTo);
	}

	function runScan(offset, postTypes, threshold, dateFrom, dateTo) {
		const params = {
			offset: offset,
			threshold: threshold,
			post_types: postTypes,
			date_from: dateFrom,
			date_to: dateTo,
		};
		if (totalPosts) params.total_posts = totalPosts;

		post(action, params)
			.then(function (resp) {
				if (!resp.success) {
					alert('Scan error: ' + (resp.data?.message || resp.data || 'Unknown error'));
					scanBtn.disabled = false;
					return;
				}

				const d = resp.data;
				allItems = allItems.concat(d.items);
				if (d.total_posts) totalPosts = d.total_posts;

				const text = formatNumber(d.offset) + ' / ' + formatNumber(totalPosts) +
					' posts scanned — ' + allItems.length + ' image' +
					(allItems.length !== 1 ? 's' : '') + ' found';
				updateProgress(progressDiv, d.offset, totalPosts, text);

				if (!d.done) {
					runScan(d.offset, postTypes, threshold, dateFrom, dateTo);
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

		if (allItems.length === 0) {
			summaryEl.textContent = 'No low-resolution images found. All images meet the threshold.';
			return;
		}

		const postCount = new Set(allItems.map(i => i.post_id)).size;
		summaryEl.textContent = 'Found ' + allItems.length + ' low-resolution image' +
			(allItems.length !== 1 ? 's' : '') + ' across ' + postCount + ' post' +
			(postCount !== 1 ? 's' : '') + '.';
		table.style.display = '';
		exportWrap.style.display = '';
		currentPage = 1;
		renderPage();
	}

	function renderPage() {
		const start = (currentPage - 1) * pageSize;
		const end = Math.min(start + pageSize, allItems.length);
		let html = '';

		for (let i = start; i < end; i++) {
			const item = allItems[i];
			const sourceLabel = item.source === 'featured' ? 'Featured Image' : 'Content';
			const dims = item.width && item.height
				? item.width + ' × ' + item.height + ' (max ' + item.longest_side + 'px)'
				: 'Unknown';
			const thumbHtml = item.thumbnail_url
				? '<img class="ik-thumb" src="' + escAttr(item.thumbnail_url) + '" alt="">'
				: '<span class="ik-no-thumb">—</span>';
			const fileName = item.src_url ? item.src_url.split('/').pop() : '';

			html += '<tr>' +
				'<td class="ik-col-thumb">' + thumbHtml + '</td>' +
				'<td>' + escHtml(sourceLabel) + '</td>' +
				'<td><a href="' + escAttr(item.edit_link) + '" target="_blank">' +
				escHtml(item.post_title) + '</a> <small>(ID: ' + item.post_id + ')</small></td>' +
				'<td class="ik-path">' + escHtml(fileName) + '</td>' +
				'<td>' + escHtml(dims) + '</td>' +
				'<td>' + escHtml(item.size_slug || '—') + '</td>' +
				'</tr>';
		}

		tbody.innerHTML = html;
		renderPagination();
	}

	function renderPagination() {
		const totalPages = Math.ceil(allItems.length / pageSize);
		const paginationTop = document.getElementById('ik-lr-pagination');
		const paginationBottom = document.getElementById('ik-lr-pagination-bottom');

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

		document.querySelectorAll('#ik-lr-results .ik-page-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				currentPage = parseInt(this.dataset.page, 10);
				renderPage();
			});
		});
	}

	if (exportBtn) {
		exportBtn.addEventListener('click', function () {
			exportCSV(
				'low-resolution-images.csv',
				['Post ID', 'Post Title', 'Source', 'Attachment ID', 'Image URL', 'File Path', 'Width', 'Height', 'Longest Side', 'Size Slug'],
				allItems.map(function (item) {
					return [
						item.post_id, item.post_title, (item.source || 'content'),
						item.attachment_id,
						item.src_url, item.file_path,
						item.width, item.height, item.longest_side,
						item.size_slug,
					];
				})
			);
		});
	}
})();
