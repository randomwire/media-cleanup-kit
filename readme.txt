=== Media Cleanup Kit ===
Contributors: randomwire
Donate link: https://ko-fi.com/randomwire
Tags: media, images, cleanup, broken images, attachments
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.43
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Nine tools for cleaning up a large WordPress media library: find broken images, restore full-size variants, repair image blocks, flatten uploads, import orphan files, delete unused files, replace low-resolution images, replace embedded Flickr images, and attach unparented media.

== Description ==

**Media Cleanup Kit** is a single admin page that bundles nine focused tools for cleaning up the kind of mess that builds up in a media library over years of editing — broken references in post content, downsized images that should be full-size, files on disk that aren't in the library, attachments that aren't attached to any post, low-resolution scans you want to replace with higher-quality originals.

Every tool follows the same flow: **scan → review → apply**, with a results table that supports sorting, filtering, full-text search, per-row inspection, per-row apply, bulk apply, and CSV export. Destructive actions create per-post HTML backups before mutating; pending-review workflows survive page reloads.

= The nine tools =

* **Find Broken Images** — Scan posts for `<img>` references whose underlying file is missing on disk. Per-row or bulk Remove strips the broken reference (keeping a timestamped HTML backup of the post). Handles Gutenberg blocks (wp:image, wp:gallery, wp:cover, wp:media-text) and raw `<img>` tags.
* **Restore Full Size** — Find images displayed at a sized variant (300x200, scaled, etc.) when a larger original exists in the media library, and swap them for the full-size version. Interactive candidate picker for ambiguous matches. Pending-review workflow.
* **Repair Image Blocks** — Audit Gutenberg `wp:image` blocks for missing metadata (block ID, sizeSlug, wp-image-* class) and produce a corrected block JSON. Per-post apply with exclusion checkboxes. Includes an Upload-replacement control for attachments that can no longer be auto-resolved.
* **Flatten Uploads** — Move images out of year/month subdirectories into the uploads root, rewriting post content URLs so existing links keep working.
* **Import Orphan Files** — Find image files in the uploads directory that aren't in the media library and import them as new attachments (with auto-generated thumbnails).
* **Delete Unused Files** — Find image files (and grouped thumbnail variants) that aren't referenced anywhere in WordPress — post content, featured images, Gutenberg blocks, gallery shortcodes, custom meta — and safely delete them.
* **Replace Low-Res Images** — Find images below a configurable resolution threshold. Generates an rsync hand-off so you can match them against a folder of higher-resolution originals on your workstation using the bundled `tools/photo-match.py` perceptual-hashing CLI, then re-upload the matches via the plugin.
* **Replace Flickr Images** — Scan posts for Gutenberg image blocks whose `<img src>` still points at a Flickr-hosted size variant, then hand the list off to the bundled `tools/flickr-fetch.py` CLI to download the largest available version via the Flickr API. After you rsync the downloads into `wp-content/uploads/flickr-replacements/`, the plugin replaces each file in place (with backup + thumbnail regeneration) and updates the referencing block JSON with the new intrinsic dimensions.
* **Attach Unparented Media** — Find attachments with no parent post and attach them to the first post that references them, via featured image, content URL, or classic gallery shortcode.

= Safety =

Media Cleanup Kit modifies post content, attachment metadata, and files on disk. While each destructive action has its own confirmation step and many create per-post revisions, you are solely responsible for any unintended consequences. Test on a staging site if possible.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New → Upload Plugin** and upload the Media Cleanup Kit zip.
2. Activate the plugin.
3. Open **Tools → Media Cleanup Kit**.
4. Pick a tool from the left sidebar and follow the scan → review → apply flow.

Or via WP-CLI:

`wp plugin install media-cleanup-kit --activate`

== Frequently Asked Questions ==

= Is this safe? Does anything write to my database? =

Yes, scans are read-only and the apply step always requires explicit user action. Destructive operations (post content rewrites, file deletion, attachment re-parenting) create per-post HTML backups where applicable and surface a confirmation step. **Back up your database and uploads directory before running any apply.**

= What is the tools/photo-match.py file for? =

It is an *optional* command-line helper for the Replace Low-Res Images workflow. The plugin generates an rsync hand-off (download low-res images → run photo-match.py against a folder of full-resolution originals on your workstation → rsync results back). The Python script is not executed by WordPress — it's there for you to run locally. Requires Python 3.8+ and the `imagehash` package. The other seven tools work without it.

= Will this work on a large site? =

Each tool is batched; scans process 25–500 items per AJAX call (depending on the workload) and can be cancelled mid-flight. The reattach scanner runs only two database queries per batch regardless of batch size. Smoke-tested on sites with 50k+ attachments.

= Do you support custom post types? =

Yes. Tools that scan post content (Find Broken Images, Restore Full Size, Repair Image Blocks, Replace Low-Res Images) expose a post-type filter on the configuration panel.

= I'm seeing "attachment_not_found" rows. What do I do? =

Click the **Upload replacement** button on the row to supply the correct file via the WP media uploader. The plugin persists a URL → attachment mapping so future scans resolve automatically.

= How do I uninstall cleanly? =

Deactivate then delete via WordPress. The plugin's uninstall handler drops both custom database tables and removes every option/transient/usermeta it created.

== Screenshots ==

1. The Media Cleanup Kit admin page with the left sidebar showing all nine tools.
2. Find Broken Images results table — sortable, filterable, with per-row Remove and bulk apply.
3. Restore Full Size review panel with before/after thumbnails and the candidate picker.
4. Repair Image Blocks audit with the proposed block JSON and per-replacement exclusion checkboxes.
5. Attach Unparented Media results with the proposed parent post, match type, and per-row Attach.
6. The shared lightbox showing a before/after compare view.

== Changelog ==

= 1.0.43 =
* Attach Unparented Media: reduced scan batch size from 50 → 20 so the progress bar and "Matches found" counter start moving sooner. The scanner is already a fixed 2 DB queries per batch (independent of batch size), so the throughput cost is negligible.

= 1.0.42 =
* Scan-flow UI polish pass: every module's primary scan button now uses the uniform "Scan for <X>" form (was a mix of "Scan", "Run Audit", "Scan Uploads", etc.); confirmation modal title now mirrors the per-module action verb ("Remove selected?" / "Delete selected?" / "Attach selected?") instead of always saying "Apply selected?"; Flatten Uploads' completed-items filter tab now reads "Relocated" (was "Done", the only outlier from the past-tense action-verb convention); inactive sortable column headers show a muted up/down indicator so the active sort column's arrow is actually distinguishable; expand/collapse row chevrons now use the same triangle weight as the sort arrows.

= 1.0.41 =
* Scan-progress bar now starts at `0` instead of `0%`, so the format is consistent with the `X / Y` count the first batch response switches to — no brief "0%" flash before the count appears.

= 1.0.40 =
* Scan-progress strip: replaced the duplicative "Posts scanned / Files scanned / Attachments checked" counter (which restated the progress bar's X / Y fraction) with a per-module "found"-shaped counter that increments as actionable rows arrive — "Broken images found", "Low-res images found", "Matches found", "Flickr images found", etc. Driven entirely client-side from the items the table is already showing, so it's always exactly right; incidentally fixes the relocator's per-batch (non-cumulative) "To relocate" count.

= 1.0.39 =
* New module: **Replace Flickr Images**. Scans posts for Gutenberg image blocks whose source still points at a Flickr-hosted size variant, exports a CSV for the bundled `tools/flickr-fetch.py` CLI (which uses the Flickr API to download the largest available version), then re-ingests the downloads from `wp-content/uploads/flickr-replacements/` and applies them with backup + thumbnail regeneration + block JSON cleanup (sizeSlug → full, new intrinsic dimensions). Same scan → handoff → apply shape as Replace Low-Res Images. Supersedes the standalone Flickr Upgrader plugin.
* Fixed: Replace Low-Res Images handoff instructions referenced the pre-rename plugin path (`wp-content/plugins/image-kit/…`). Corrected to `wp-content/plugins/media-cleanup-kit/…`.
* Fixed: Replace Low-Res Images description text accurately describes the scan + replace flow (was previously labelled "scan and report only").

= 1.0.38 =
* Replaced the two-step "click again to confirm" gates and the remaining `window.confirm()` dialogs with a built-in confirmation modal. Single-row Apply still fires immediately; multi-row Apply, Discard, and the Replace Low-Res apply/cleanup actions now open a styled modal you can dismiss with Cancel, ESC, the close button, or a backdrop click.

= 1.0.37 =
* Consistent per-tool panel header: every module now renders an `<h2>` heading matching the tab name plus a descriptive subheading inside the scan-controls box. Removes inconsistent `<h3>` / inline-`<p>` patterns and the duplicate description that previously appeared above the box.

= 1.0.36 =
* Renamed plugin to Media Cleanup Kit (was previously "Image Kit" — renamed to avoid a name collision with the commercial ImageKit.io service).
* Full WordPress.org compliance pass: added `readme.txt`, `LICENSE`, translation loader, build exclusions, prominent backup warning on the admin page.
* Code-quality: `stripslashes` → `wp_unslash`, `@unlink` → `wp_delete_file`, `set_time_limit` calls now guarded.
* Plugin row on the Plugins screen now shows Donate + GitHub links.

= 1.0.35 =
* **Attach Unparented Media:** stricter resolver — the file path or filename must actually appear in the candidate post to count as a content match. Weak signals (wp-image-N class, "id":N) are no longer matchable on their own (they outlive the underlying file and produce false positives). Classic [gallery ids="…"] remains a standalone signal via precise id-list parse.

= 1.0.34 =
* Bulk Apply button worked around browser-suppressed `window.confirm()` — multi-row applies now arm the button (turns amber) and require a second click. Same pattern as the Discard fix from 1.0.28. Affects every module with a bulk apply.

= 1.0.33 =
* Attach Unparented Media scan-batch perf — collapsed N×2 database queries per batch to a constant 2, ~25× faster on dense sites.

= 1.0.32 =
* New **Attach Unparented Media** module — finds `post_parent = 0` attachments and proposes a parent post via featured image, content URL, or classic gallery shortcode. Supersedes the standalone Post Attach plugin.

= 1.0.31 =
* Internal cleanup: removed dead methods, collapsed duplicated JS helpers to shared utils, dropped orphan CSS.

= 1.0.30 =
* Upload-replacement control on `attachment_not_found` rows in Repair Image Blocks and Restore Full Size. Persistent URL → attachment alias map.
* Pruned never-used History columns and methods.

= 1.0.27–1.0.29 =
* attachment_not_found resolution: handle `-e<timestamp>` edited-image variants and `-scaled` big-image variants in the URL-to-attachment lookup chain.

= 1.0.21–1.0.26 =
* Scan-UX unification across all modules via shared scan-ui helper.
* Built-in lightbox with compare mode.
* Selection-aware CSV export.
* Numerous bug-sweep fixes (focus retention, colspan correction, per-row apply failure propagation, etc.).

= 1.0.1–1.0.20 =
* Initial five-plugin consolidation, sidebar navigation, cancel on every scan.

For the full version-by-version history see the `CHANGELOG.md` file in the GitHub repository.

== Upgrade Notice ==

= 1.0.43 =
UI polish — Attach Unparented Media scans in batches of 20 (was 50) so progress is visible sooner.

= 1.0.42 =
UI polish — uniform "Scan for X" button labels across modules, action-verb modal titles, fixed-up sort indicators, glyph-consistent expand chevrons.

= 1.0.41 =
UI polish — scan-progress bar starts at `0` instead of `0%` so it switches cleanly to the `X / Y` count on the first batch.

= 1.0.40 =
UI polish — the scan-progress strip now shows "Broken images found", "Low-res images found", "Matches found" etc. instead of the duplicative "Posts scanned" count that restated the progress bar.

= 1.0.39 =
Adds a Replace Flickr Images module that supersedes the standalone Flickr Upgrader plugin. If you have Flickr Upgrader installed, you can deactivate it after this upgrade.

= 1.0.38 =
UI polish — destructive actions now use an in-plugin confirmation modal instead of the two-step "click again" gate and the suppressible browser `confirm()` dialog. No data or behaviour changes.

= 1.0.37 =
UI polish only — every tool tab now shows a uniform heading + subheading inside its scan-controls box. No data or behaviour changes.

= 1.0.36 =
Plugin renamed from "Image Kit" to "Media Cleanup Kit". No data migration required — internal database tables and options keep their existing names. WP.org compliance pass.
