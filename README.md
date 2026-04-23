# Image Kit

Image Kit is a WordPress admin plugin for auditing and cleaning up large media libraries.

It adds a single admin page at `Tools > Image Kit` with modular scanners and maintenance workflows for common image-library problems.

## Features

- **Broken Images**: scans posts/pages for internal image references that point to missing files.
- **Image Upgrader**: finds downsized image variants in content and replaces them with higher-quality originals from the Media Library.
- **Relocator**: relocates media files from uploads subdirectories to uploads root, and imports orphan files into the Media Library.
- **Unused Cleaner**: identifies image files not referenced in WordPress (content, media records, featured images, blocks, widgets, and meta) and supports safe deletion.
- **Low Resolution**: reports post-content and featured images below a configurable resolution threshold.

## Requirements

- WordPress `5.0+`
- PHP `7.4+`

## Installation

1. Copy this plugin folder into your WordPress `wp-content/plugins/` directory.
2. Activate **Image Kit** in the WordPress Plugins screen.
3. Open `Tools > Image Kit`.

## Usage Notes

- Most scans run in AJAX batches and are designed for large datasets.
- Some modules are scan-only (reporting), while others include apply/delete workflows.
- Run image/file operations on a backup or staging environment first when possible.

## Project Structure

- `image-kit.php` – plugin bootstrap, metadata, autoloader, lifecycle hooks.
- `includes/class-plugin.php` – module registry and plugin orchestration.
- `includes/admin/` – top-level admin page and shared UI wiring.
- `includes/modules/` – module implementations (scanner + module UI/AJAX handlers).
- `includes/core/` – shared internals (block parsing, file ops, run logs, thumbnail regeneration).
- `assets/` – JavaScript and CSS used by admin modules.
- `CHANGELOG.md` – release history.

## Versioning and Changelog

- Current version is defined in `image-kit.php` via plugin header and `IMAGE_KIT_VERSION`.
- Document release changes in `CHANGELOG.md`.

## License

GPL-2.0-or-later
