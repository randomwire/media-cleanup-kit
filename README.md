# Media Cleanup Kit

Media Cleanup Kit is a WordPress admin plugin for auditing and cleaning up large media libraries.

It adds a single admin page at `Tools > Media Cleanup Kit` with modular scanners and maintenance workflows for common image-library problems.

## The nine tools

- **Find Broken Images** — scans posts and pages for `<img>` references whose underlying file is missing on disk. Per-row or bulk Remove (keeps a timestamped HTML backup of the post). Handles Gutenberg blocks (`wp:image`, `wp:gallery`, `wp:cover`, `wp:media-text`) and raw `<img>` tags.
- **Restore Full Size** — finds images displayed at a sized variant (300×200, scaled, etc.) when a larger original exists in the media library, and swaps them for the full-size version. Interactive candidate picker for ambiguous matches.
- **Repair Image Blocks** — audits Gutenberg `wp:image` blocks for missing metadata (block ID, `sizeSlug`, `wp-image-*` class) and produces a corrected block JSON. Per-post apply with exclusion checkboxes.
- **Flatten Uploads** — moves images out of year/month subdirectories into the uploads root, rewriting post content URLs so existing links keep working.
- **Import Orphan Files** — finds image files in the uploads directory that aren't in the media library and imports them as new attachments (with auto-generated thumbnails).
- **Delete Unused Files** — finds image files (and grouped thumbnail variants) that aren't referenced anywhere in WordPress and safely deletes them.
- **Replace Low-Res Images** — finds images below a configurable resolution threshold, then hands the list off to the bundled `tools/photo-match.py` CLI to match them against a folder of higher-resolution originals on your workstation. Re-upload the matches and the plugin applies them with backup + thumbnail regeneration.
- **Replace Flickr Images** — scans posts for Gutenberg image blocks whose `<img src>` still points at a Flickr-hosted size variant, then hands the list off to the bundled `tools/flickr-fetch.py` CLI to download the largest available version via the Flickr API. After you rsync the downloads into `wp-content/uploads/flickr-replacements/`, the plugin replaces each file in place (with backup + thumbnail regeneration) and updates the referencing block JSON with the new intrinsic dimensions.
- **Attach Unparented Media** — finds attachments with no parent post and attaches them to the first post that references them, via featured image, content URL, or classic gallery shortcode.

Every tool follows the same scan → review → apply flow with sortable/filterable/searchable results, per-row inspection, per-row apply, bulk apply (gated by an in-plugin confirmation modal), and CSV export.

## Requirements

- WordPress `5.0+`
- PHP `7.4+`

## Installation

1. Copy this plugin folder into your WordPress `wp-content/plugins/` directory.
2. Activate **Media Cleanup Kit** in the WordPress Plugins screen.
3. Open `Tools > Media Cleanup Kit`.

## Usage Notes

- Most scans run in AJAX batches and are designed for large datasets.
- Some modules are scan-only (reporting), while others include apply/delete workflows.
- Run image/file operations on a backup or staging environment first when possible.

## Project Structure

- `media-cleanup-kit.php` – plugin bootstrap, metadata, autoloader, lifecycle hooks.
- `includes/class-plugin.php` – module registry and plugin orchestration.
- `includes/admin/` – top-level admin page and shared UI wiring.
- `includes/modules/` – module implementations (scanner + module UI/AJAX handlers).
- `includes/core/` – shared internals (block parsing, file ops, run logs, thumbnail regeneration).
- `assets/` – JavaScript and CSS used by admin modules (includes the shared scan-UI helper, confirmation modal, and lightbox).
- `tools/` – bundled offline CLI helpers (`photo-match.py` for Replace Low-Res Images, `flickr-fetch.py` for Replace Flickr Images).
- `CHANGELOG.md` – release history.

## Versioning and Changelog

- Current version is defined in `media-cleanup-kit.php` via plugin header and `IMAGE_KIT_VERSION` (internal constant name preserved for in-place upgrade compatibility).
- Document release changes in `CHANGELOG.md`.

## License

GPL-2.0-or-later
