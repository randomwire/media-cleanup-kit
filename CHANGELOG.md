# Changelog

All notable changes to this project are documented in this file.

## 1.0.31 - 2026-05-26

### Changed
- Internal cleanup: removed dead methods (`Image_Kit_Core_Block_Parser::extract_image_blocks` + `extract_img_srcs`, `Image_Kit_Core_File_Operations::move_file` + `backup_file`, `Image_Kit_Core_Thumbnail_Regenerator::regenerate_metadata`) that had no callers. Collapsed the local `esc()` / `escAttr()` helpers duplicated across 7 module JS files — they now destructure from the shared `window.imageKitUtils`, which was tightened to a null-safe form so `0`/`false` values render correctly. Dropped 3 orphan CSS rules (`.ik-iu-candidates-label`, `.ik-iu-candidate-url`, `.ik-iu-candidate-dims`) that targeted markup nothing renders. No user-visible behaviour change.

## 1.0.30 - 2026-05-26

### Added
- **Upload replacement** for `attachment_not_found` rows in Repair Image Blocks and Restore Full Size. When the lookup chain can't resolve a URL automatically (1.0.27 + 1.0.29 cover the common WordPress filename quirks, but the underlying attachment can still be missing), the skipped row now exposes an *Upload replacement…* button. Clicking opens the WP media frame; pick or upload the correct image and the row resolves in place — no full rescan needed. The URL ↔ attachment mapping is persisted in `wp_options['image_kit_url_aliases']` so the same URL resolves automatically on future scans across both modules.

### Changed
- Internal: removed never-used `posts_updated` and `error_count` columns from `wp_image_kit_upgrader_runs`. Existed only to back a History UI that was never built; every increment site was write-only. Schema version bumped (option `image_kit_run_log_schema_version`); existing installs drop the columns idempotently on next activation. Also removed the orphan `Image_Kit_Core_Run_Log::delete_run()` method (no callers).

## 1.0.29 - 2026-05-26

### Fixed
- **Repair Image Blocks still marked some URLs as `attachment_not_found`** even after 1.0.27. When WordPress auto-scales a large upload (>2560px by default), the file on disk becomes `<name>-scaled.<ext>` and `_wp_attached_file` points at the scaled copy — but the URL inserted into post content references the pre-scale filename. `edited_filename_alternatives()` now also tries each candidate with `-scaled` injected before the extension, so e.g. `image-136-e1462878149868.jpeg` in post content resolves to the attachment whose stored filename is `image-136-e1462878149868-scaled.jpeg`.

## 1.0.28 - 2026-05-26

### Fixed
- **Discard button appeared inert**: the helper used `window.confirm()` for "Discard all results?", which modern Chromium/WebKit can silently suppress for users who have dismissed prior dialogs on the same origin — the suppression returns `false` without showing the prompt, so the button looked broken. Replaced with an inline two-step confirmation: clicking Discard arms the button (turns red, label becomes "Click again to discard"); a second click within 4 seconds carries out the discard. Affects every module's Discard control (Restore Full Size, Repair Image Blocks, Flatten Uploads).

## 1.0.27 - 2026-05-26

### Fixed
- **Repair Image Blocks marked edited-image URLs as `attachment_not_found`**: URLs containing WordPress's edited-image suffix (e.g. `image-129-e1462792051861.jpeg`, written by the WP image editor as `{name}-e{timestamp}.{ext}`) failed every attachment lookup because `_wp_attached_file` still references the *original* filename. The scanner now also tries the stripped basename (and the same with any `-WxH` size suffix removed) so edited variants resolve back to their parent attachment. Affected items now appear as actionable rows in both the table and the CSV export instead of being silently skipped.

## 1.0.26 - 2026-05-24

### Fixed
- **Per-row apply silently masked server-side failures** in Flatten Uploads, Delete Unused Files, and Import Orphan Files. Their AJAX endpoints return `{ success: true, results: [...] }` where the *outer* success only means "the request reached PHP" — actual per-file outcomes are inside `results`. The scan-ui helper previously trusted the outer flag and marked rows "Applied ✓" even when the file relocation / deletion / import had failed. The helper now accepts an `apply.isSuccess` / `apply.errorMessage` hook; the three affected modules supply implementations that read the inner result. Failed rows now correctly show status "Error" with the per-file message.
- **Toolbar input focus loss**: if the search box or page-number input had focus when `render()` ran (e.g. because a per-row apply completed mid-typing), the input was destroyed and the user's typing was interrupted. Focus + selection range are now preserved across rerenders.

## 1.0.25 - 2026-05-24

### Fixed
- **CSV export selection regression**: Export CSV now only exports the rows you've checked. Empty selection still exports everything (matches the existing "empty = include all" convention). Replace Low-Res Images' sibling `low-resolution-files.txt` follows the same selection.
- **Expandable detail rows on filenames with CSS-special characters**: Delete Unused Files and Import Orphan Files use real filenames / paths as the row key. Expanding a row whose key contained `[`, `]`, or other CSS-meaningful characters could throw or expand the wrong row. The helper now finds the detail row by sibling traversal instead of an attribute selector.
- **Cross-module stale-run cleanup**: starting a Restore Full Size scan no longer auto-fails an in-flight Repair Image Blocks run (and vice versa). The self-heal in `clear_stale_active_runs` is now scoped to the calling module's modes.
- **Per-row Apply log visibility**: clicking a single-row Apply now reveals the Apply log details element so the outcome line is visible, not buried in a collapsed section.
- **Empty-state and detail-row colspan**: `<td colspan>` now matches the actual rendered column count when a module has no Apply action.
- **Lightbox compare-mode loading indicator**: the before/after panes now each show a spinner while the full-size image is loading (parity with single-image mode).

## 1.0.24 - 2026-05-24

### Added
- Click any thumbnail inside Image Kit to open a lightbox at native size. ESC, × button, or backdrop click closes; ← / → cycle through the other thumbs on the current results page. Loading spinner shown while the full-size image fetches.
- **Compare mode** in Restore Full Size — clicking either thumb in a before/after replacement row opens the lightbox with the sized and full-size images side by side.
- Auto-wired via a new `assets/js/lightbox.js` (exposes `window.imageKitLightbox` for modules that want explicit compare/collection control). No per-module wiring needed beyond what existed.

### Changed
- Restore Full Size and Repair Image Blocks no longer require a "Show preview" click in the row detail. Thumbnails load automatically the moment a row is expanded; clicking the loaded thumbnail then opens the lightbox.
- Replace Low-Res Images thumbnails now carry `data-lightbox-src` pointing at the full-size URL, so the lightbox opens the original image rather than the 150×150 thumbnail.

## 1.0.23 - 2026-05-24

### Added
- **Find Broken Images** now has an apply path. Per-row Remove + bulk Remove Selected strips the broken `<img>` reference from post content:
  - `wp:image` blocks containing the broken URL are removed entirely.
  - `wp:gallery` / `wp:cover` / `wp:media-text` / raw `<img>` tags have just the offending `<img>` (and a wrapping `<a href="URL">` if any) stripped.
  - Broken featured images call `delete_post_thumbnail()`.
- Before any mutation, the post's current `post_content` is snapshotted to `wp-content/uploads/image-kit-backup/posts/{post_id}-{timestamp}-{token}.html` so the user has a manual undo path. The expanded row detail surfaces the backup file path after a successful removal.
- New filter tabs on Find Broken Images results: All / Pending / Removed / Errors.

## 1.0.22 - 2026-05-24

### Fixed
- Restore Full Size / Repair Image Blocks could refuse to start with "A run is already in progress" after a previous scan was interrupted (closed tab, JS error, network drop). The scan-ui Cancel button now also POSTs to the module's `cancel_run` endpoint so the server marks the run cancelled, and `ajax_start_run` self-heals by auto-failing any 'running'/'applying' row older than 5 minutes before checking for an active run. Existing stuck rows clear the next time you click Scan.

### Added
- New `Image_Kit_Core_Run_Log::clear_stale_active_runs( $max_age_seconds )` helper, plus an `onCancel` hook on `imageKitScanUI` scan config.

## 1.0.21 - 2026-05-24

### Changed
- All remaining modules migrated to the shared `imageKitScanUI` helper. Every tab now has the unified scan + progress + log + cancel UI, results table with filter tabs / search / sortable columns / WP-style pagination / current-page select-all / expandable detail rows / per-row Apply button + bulk Apply Selected (where applicable) / CSV export, and an inline apply that updates row status in place (no separate apply/success screens):
  - **Repair Image Blocks** — same scan/apply pattern as Restore Full Size; row detail renders audit-issue badges + proposed block JSON.
  - **Find Broken Images** — read-only for now (apply phase will arrive in 1.0.22). Row detail shows full image URL.
  - **Delete Unused Files** — old "Select all unused" custom checkbox replaced by WP-style header select-all + Unused-only filter tab. Row detail shows variants + references.
  - **Flatten Uploads** — wizard step indicator dropped. Per-row Relocate + bulk Relocate Selected; row detail shows collision warning + post references.
  - **Import Orphan Files** — wizard step indicator dropped. The two-phase scan (indexing → batched compare) is preserved via the helper's `initRun` hook. Row detail shows variant filenames + directory.
  - **Replace Low-Res Images** — main scan migrated. The photo-match.py handoff panel + matched-photos apply UI remain as custom panels below the results (their flow is unique enough that the standard inline-apply doesn't fit, but they reuse the existing endpoints).

### Notes
- Phase 2 of the cross-module scan-UX unification. Phase 3 (1.0.22) will add Find Broken Images' content-mutation apply path.
- Backend response shapes were aligned where modules previously used module-specific keys: scan AJAX handlers now return `items`, `offset`, `total`, `done`, `progress`, `log_lines` so the shared helper can drive the UI without per-module adapters.

## 1.0.20 - 2026-05-24

### Fixed
- Scan progress was showing `n / n` (e.g. `120 / 120`) instead of `n / total` for modules whose batch handler doesn't echo back the total per response. The scan-ui helper now also reads `total_posts` / `total` from the params returned by `initRun`, so the bar fills correctly during the scan.

## 1.0.19 - 2026-05-24

### Added
- New shared scan-UI helper at `assets/js/scan-ui.js` (`window.imageKitScanUI.init({…})`). Owns the standard scan → review-and-apply experience: AJAX batch loop with cancel, progress bar, counters, log; results table with filter tabs, search, sortable headers, WP-style pagination, current-page select-all, expandable detail rows, per-row status badges, per-row Apply button, sticky apply-progress banner, bulk Apply Selected, CSV export, Discard.
- Shared CSS section (`.ik-scan-config / .ik-scan-progress / .ik-scan-results / .ik-scan-status-*` etc.) for the unified shell.

### Changed
- **Restore Full Size** migrated to the shared helper. Review and apply are now combined into a single panel: rows show inline status (Pending → Applying → Applied), per-row Apply works alongside bulk Apply Selected, and the table stays visible throughout instead of being replaced by a separate apply panel + success screen.

### Notes
- Phase 1 of the cross-module scan-UX unification. Other modules continue to use their existing UIs until they're migrated in upcoming versions.

## 1.0.18 - 2026-05-24

### Added
- Cancel buttons on every scan that lacked one: **Find Broken Images**, **Replace Low-Res Images**, **Flatten Uploads**, and **Import Orphan Files**. Clicking Cancel mid-scan immediately hides progress and short-circuits the next batch.

### Changed
- **Flatten Uploads** scan is now batched (25 attachments per AJAX) instead of one long single-shot query, so the new Cancel button can genuinely stop further work between batches.
- **Import Orphan Files** scan now runs in two phases: an initial "Indexing files…" step that walks the uploads tree and caches the candidate list in a transient, then a cancellable batched comparison loop. The final batch runs variant grouping and cleans up the transient.

## 1.0.17 - 2026-05-23

### Changed
- Renamed all primary nav tabs to verb-first, action/goal-oriented labels:
  - Broken Images → **Find Broken Images**
  - Image Upgrader → **Restore Full Size**
  - Markup Audit → **Repair Image Blocks**
  - Relocator → **Flatten Uploads**
  - Import Orphans → **Import Orphan Files**
  - Unused Cleaner → **Delete Unused Files**
  - Low Resolution → **Replace Low-Res Images**
- Switched the tab navigation from a horizontal strip to a left sidebar so the longer labels fit cleanly and the layout scales to more tools. Container widened from 960px → 1200px.

## 1.0.16 - 2026-05-23

### Changed
- Promoted `Markup Audit` from a button inside Image Upgrader to a standalone top-level tab. The two flows now have their own UIs and don't share buttons. Image Upgrader is purely the URL-upgrade scan; Markup Audit only fixes Gutenberg block JSON. Both still share the underlying scanner, batch runner and run-log table, so pending review picks up the correct tab.

### Added
- Optional `$modes` filter on `Image_Kit_Core_Run_Log::get_pending_review()` so each tab only auto-restores its own runs.

## 1.0.15 - 2026-05-23

### Removed
- Removed the bottom "Reset" button from Relocator. The "Start Over" button at the end of the wizard provides the same behaviour.

## 1.0.14 - 2026-05-23

### Fixed
- `Low Resolution`: pagination rendered too many rows on every page after page 1. `wp_localize_script` stringifies scalar values, so `pageSize` was the string `"50"` on the JS side. The pagination slice used `start + pageSize`, and once `start > 0` this became string concatenation (`50 + "50" = "5050"`), so `Math.min(start + pageSize, allItems.length)` collapsed to `allItems.length` — page 2 rendered rows 50–end, page 3 rendered rows 100–end, etc. Fixed by coercing `pageSize` to a Number at destructure.

### Changed
- Removed the diagnostic `console.log` calls added in 1.0.13.

## 1.0.13 - 2026-05-23

### Changed
- `Low Resolution`: temporary console diagnostics on every pagination render (since reverted).

## 1.0.12 - 2026-05-23

### Fixed
- `Low Resolution`: scan results are now deduplicated client-side by `(post_id, attachment_id, src_url)` as they arrive. Some hosting setups (object caches, query-level caching plugins) were causing later batches to return rows from earlier batches, so pages 4+ of the results table displayed literal copies of earlier pages. Also guards against stale AJAX responses if a second scan is started before the first completes. Duplicate-drop count is logged to the browser console.

## 1.0.11 - 2026-05-23

### Fixed
- `Low Resolution`: `low-resolution-files.txt` now contains paths relative to the uploads basedir so that `rsync --files-from=…` resolves correctly (previously paths were absolute, producing "No such file or directory" against the rsync source).
- `Low Resolution`: CSV column headers are now snake_case (`attachment_id`, `src_url`, `file_path`, etc.) so `photo-match.py` can read them.

## 1.0.10 - 2026-05-23

### Changed
- `Low Resolution` scanner now also catches images inside `wp:gallery`, `wp:cover`, `wp:media-text`, self-closing `wp:image` blocks, and raw `<img>` tags — previously only top-level `wp:image` blocks were detected. The Source column now reflects the block type (Gallery, Cover, Media + Text, Raw <img>) in addition to Content and Featured Image.

## 1.0.9 - 2026-05-23

### Fixed
- `Low Resolution` scanner no longer emits ghost rows for images whose attachment or dimensions cannot be resolved. Previously these `longest_side = 0` items passed the threshold check, inflating result counts and pagination (e.g. showing 7 pages when only the first two had real photos).

## 1.0.8 - 2026-05-23

### Added
- `Low Resolution`: complete photo-match workflow. CSV export now writes a sibling `low-resolution-files.txt` for `rsync --files-from`. New hand-off panel shows pre-filled rsync commands (download images, upload matched-photos) and the photo-match.py invocation. New "Scan matched-photos directory" / "Apply Selected" / "Delete matched-photos directory" flow reads `wp-content/uploads/matched-photos/photo-match-results.csv`, backs up originals to `wp-content/uploads/image-kit-backup/<id>/`, replaces files via the core thumbnail regenerator, and rewrites referencing Gutenberg image blocks to `sizeSlug:"full"`.
- Bundled `tools/photo-match.py` (perceptual-hash matcher) — runs locally on the user's Mac.

## 1.0.7 - 2026-05-23

### Removed
- Removed the `Run Diagnostics` button and its panel from Image Upgrader (UI, AJAX handler, and supporting scanner method).

## 1.0.6 - 2026-05-23

### Added
- `Low Resolution` results table now has per-row checkboxes (with a header select-all) so the CSV export can be narrowed to chosen rows. Selections persist across paginated pages; empty selection still exports everything.

## 1.0.5 - 2026-05-23

### Changed
- `Unused Cleaner` now always scans the WordPress uploads directory; removed the manual directory input and its validation.

## 1.0.4 - 2026-05-23

### Changed
- Promoted `Import Orphans` from a sub-tab of Relocator to a top-level `Import Orphans` module.

## 1.0.3 - 2026-05-23

### Removed
- Removed the `History` sub-tab from Image Upgrader (UI, AJAX handlers, and associated CSS). Run-log records are still written during scans for the in-flight preview/apply workflow.

## 1.0.2 - 2026-05-23

### Added
- `Low Resolution` results now show a `Source` column distinguishing content images from featured images.
- `Low Resolution` scanner can now be restricted to specific size slugs (registered WP sizes, `featured-image`, or `Unspecified`).
- Added `build.sh` for packaging a distributable zip.

## 1.0.1 - 2026-04-23

### Added
- Added featured image support to `Broken Images` scans.
- Added featured image support to `Low Resolution` scans.

### Changed
- Updated module descriptions to reflect featured image coverage.

## 1.0.0 - 2026-04-23

### Added
- Initial plugin release with admin tooling and modules:
  - Broken Images
  - Image Upgrader
  - Relocator
  - Unused Cleaner
  - Low Resolution
