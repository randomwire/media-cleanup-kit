#!/usr/bin/env python3
"""
photo-match.py — Match WordPress images to local photos using perceptual hashing.

Reads a Low Scan CSV export, hashes the downloaded WP images, scans a local
folder of exported photos for perceptual matches, copies matched photos as
max-quality JPEG, and writes a mapping CSV for the WordPress plugin to consume.

Requirements:
    pip install imagehash Pillow pillow-heif

Usage:
    python3 photo-match.py \
        --csv low-scan-results.csv \
        --images-dir ./wp-images/ \
        --photos-dir ./exported-photos/ \
        --output-dir ./replacements/ \
        [--hash-threshold 10] \
        [--cache-file photo-hashes.json] \
        [--resume]
"""

import argparse
import csv
import json
import os
import shutil
import sys
import time
from pathlib import Path

try:
    import imagehash
    from PIL import Image
except ImportError:
    print("Missing dependencies. Install with: pip install imagehash Pillow")
    sys.exit(1)

try:
    import pillow_heif
    pillow_heif.register_heif_opener()
except ImportError:
    pillow_heif = None

IMAGE_EXTENSIONS = (".jpg", ".jpeg", ".png", ".heic", ".heif", ".tif", ".tiff")


def parse_csv(csv_path):
    """Parse the Low Scan CSV export.

    Returns a dict of attachment_id -> {filename, file_path, ...} and
    any metadata from comment lines (date_range, threshold).
    """
    items = {}
    metadata = {}

    with open(csv_path, "r", newline="") as f:
        reader = csv.reader(f)
        header = None

        for row in reader:
            # Parse comment lines for metadata.
            if row and row[0].startswith("#"):
                comment = row[0][1:].strip()
                # Join all fields in case commas split the comment.
                if len(row) > 1:
                    comment = ",".join(row)[1:].strip()
                for part in comment.split():
                    if ":" in part:
                        key, _, value = part.partition(":")
                        metadata[key] = value
                continue

            if header is None:
                header = row
                continue

            data = dict(zip(header, row))
            att_id = data.get("attachment_id", "0")
            if att_id and att_id != "0":
                items[att_id] = {
                    "attachment_id": att_id,
                    "post_id": data.get("post_id", ""),
                    "file_path": data.get("file_path", ""),
                    "src_url": data.get("src_url", ""),
                    "width": data.get("width", "0"),
                    "height": data.get("height", "0"),
                }

    return items, metadata


def hash_wp_images(images_dir, items):
    """Hash downloaded WP images.

    Returns dict of attachment_id -> {hash, filename}.
    Images are matched to items by attachment_id prefix in filename,
    or by matching the basename from file_path.
    """
    hashes = {}
    images_path = Path(images_dir)

    if not images_path.is_dir():
        print(f"Error: Images directory not found: {images_dir}")
        return hashes

    # Build a lookup from basename to attachment_id.
    basename_to_att = {}
    for att_id, info in items.items():
        if info["file_path"]:
            basename = os.path.basename(info["file_path"])
            basename_to_att[basename] = att_id

    image_files = [
        f for f in images_path.rglob("*")
        if f.is_file() and f.suffix.lower() in IMAGE_EXTENSIONS
    ]

    print(f"Hashing {len(image_files)} WP images...")

    for i, img_file in enumerate(image_files, 1):
        # Try to find attachment_id from filename.
        att_id = basename_to_att.get(img_file.name)

        if not att_id:
            # Try prefix match: files might be named like {att_id}_something.jpg
            for candidate_id in items:
                if img_file.name.startswith(candidate_id + "_") or img_file.name.startswith(candidate_id + "."):
                    att_id = candidate_id
                    break

        if not att_id:
            continue

        try:
            img = Image.open(img_file)
            h = imagehash.phash(img)
            hashes[att_id] = {
                "hash": h,
                "filename": img_file.name,
            }
        except Exception as e:
            print(f"  Warning: Could not hash {img_file.name}: {e}")

        if i % 10 == 0:
            print(f"  [{i}/{len(image_files)}] WP images hashed")

    print(f"Hashed {len(hashes)} WP images (matched to attachment IDs).")
    return hashes


def load_hash_cache(cache_file):
    """Load cached photo hashes from JSON file."""
    if not os.path.exists(cache_file):
        return {}

    try:
        with open(cache_file, "r") as f:
            data = json.load(f)
        # Convert hex strings back to ImageHash objects.
        return {key: imagehash.hex_to_hash(h) for key, h in data.items()}
    except Exception as e:
        print(f"Warning: Could not load cache file: {e}")
        return {}


def save_hash_cache(cache_file, cache):
    """Save photo hashes to JSON file."""
    data = {key: str(h) for key, h in cache.items()}
    with open(cache_file, "w") as f:
        json.dump(data, f)


def scan_local_photos(photos_dir, wp_hashes, threshold, cache_file):
    """Scan a local directory of photos, hash each, and find matches.

    Returns dict of attachment_id -> {source_file, distance, source_filename}.
    """
    photos_path = Path(photos_dir)
    if not photos_path.is_dir():
        print(f"Error: Photos directory not found: {photos_dir}")
        return {}

    photo_files = [
        f for f in photos_path.rglob("*")
        if f.is_file() and f.suffix.lower() in IMAGE_EXTENSIONS
    ]

    if not photo_files:
        print(f"No image files found in {photos_dir}")
        return {}

    print(f"Found {len(photo_files)} photos to scan.")

    # Load hash cache.
    cache = load_hash_cache(cache_file)
    cached_count = len(cache)
    print(f"Loaded {cached_count} cached hashes.")

    matches = {}  # att_id -> {source_file, distance, source_filename}
    total = len(photo_files)
    hashed = 0
    cache_hits = 0
    skipped = 0
    start_time = time.time()

    for i, photo_file in enumerate(photo_files, 1):
        filename = photo_file.name
        cache_key = str(photo_file.relative_to(photos_path))

        # Check cache first.
        if cache_key in cache:
            photo_hash = cache[cache_key]
            cache_hits += 1
        else:
            try:
                img = Image.open(photo_file)
                photo_hash = imagehash.phash(img)
                cache[cache_key] = photo_hash
                hashed += 1
            except Exception as e:
                if photo_file.suffix.lower() in (".heic", ".heif") and pillow_heif is None:
                    if skipped == 0:
                        print(f"  Warning: Cannot open HEIC files. Install with: pip install pillow-heif")
                    skipped += 1
                else:
                    print(f"  Warning: Could not hash {filename}: {e}")
                continue

            # Flush cache periodically.
            if hashed % 100 == 0:
                save_hash_cache(cache_file, cache)

        # Compare against all WP hashes.
        for att_id, wp_data in wp_hashes.items():
            distance = photo_hash - wp_data["hash"]
            if distance <= threshold:
                # Keep best match (lowest distance).
                if att_id not in matches or distance < matches[att_id]["distance"]:
                    matches[att_id] = {
                        "source_file": photo_file,
                        "distance": distance,
                        "source_filename": filename,
                    }

        # Progress reporting.
        if i % 50 == 0 or i == total:
            elapsed = time.time() - start_time
            rate = i / elapsed if elapsed > 0 else 0
            remaining = (total - i) / rate if rate > 0 else 0
            remaining_str = format_time(remaining)
            print(f"  [{i}/{total}] Scanning photos... "
                  f"({hashed} newly hashed, {cache_hits} cached, "
                  f"{len(matches)} matches so far, ~{remaining_str} remaining)")

    # Final cache save.
    save_hash_cache(cache_file, cache)
    new_cached = len(cache) - cached_count
    print(f"Cached {new_cached} new hashes (total: {len(cache)}).")

    if skipped:
        print(f"Skipped {skipped} HEIC files (install pillow-heif to process them).")

    return matches, total


def format_time(seconds):
    """Format seconds into a human-readable string."""
    if seconds < 60:
        return f"{int(seconds)}s"
    if seconds < 3600:
        return f"{int(seconds // 60)}m {int(seconds % 60)}s"
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    return f"{hours}h {minutes}m"


def copy_matches(matches, output_dir, resume=False):
    """Copy matched photos to output directory as max-quality JPEG.

    Returns list of {attachment_id, exported_filename, apple_uuid, confidence}.
    """
    output_path = Path(output_dir)
    output_path.mkdir(parents=True, exist_ok=True)

    results = []

    for att_id, match in matches.items():
        source_file = match["source_file"]
        distance = match["distance"]
        confidence = 1.0 - (distance / 64.0)

        # Build export filename: {attachment_id}_{original_stem}.jpg
        original_stem = source_file.stem
        export_name = f"{att_id}_{original_stem}.jpg"
        export_path = output_path / export_name

        # Skip if resume mode and file already exists.
        if resume and export_path.exists():
            print(f"  Skipping {export_name} (already exported)")
            results.append({
                "attachment_id": att_id,
                "exported_filename": export_name,
                "apple_uuid": match["source_filename"],
                "confidence": confidence,
            })
            continue

        try:
            if source_file.suffix.lower() in (".jpg", ".jpeg"):
                # JPEG: copy directly.
                shutil.copy2(source_file, export_path)
            else:
                # Non-JPEG (HEIC, PNG, etc.): convert to JPEG.
                img = Image.open(source_file)
                img = img.convert("RGB")
                img.save(export_path, "JPEG", quality=98)

            results.append({
                "attachment_id": att_id,
                "exported_filename": export_name,
                "apple_uuid": match["source_filename"],
                "confidence": confidence,
            })
            print(f"  Exported: {export_name} (confidence: {confidence:.1%})")
        except Exception as e:
            print(f"  Error copying attachment {att_id}: {e}")

    return results


def load_existing_results(csv_path):
    """Load existing mapping CSV results, keyed by attachment_id.

    Returns dict of attachment_id -> {exported_filename, apple_uuid, confidence, wp_filename}.
    """
    existing = {}
    if not os.path.exists(csv_path):
        return existing

    try:
        with open(csv_path, "r", newline="") as f:
            reader = csv.DictReader(f)
            for row in reader:
                att_id = row.get("attachment_id", "")
                if att_id:
                    existing[att_id] = {
                        "attachment_id": att_id,
                        "exported_filename": row.get("exported_filename", ""),
                        "apple_uuid": row.get("apple_photos_uuid", ""),
                        "confidence": float(row.get("match_confidence", "0")),
                        "wp_filename": row.get("wp_filename", ""),
                    }
    except Exception as e:
        print(f"Warning: Could not read existing results CSV: {e}")

    return existing


def write_mapping_csv(results, output_dir, wp_items):
    """Write the mapping CSV for the WordPress plugin.

    Merges new results with any existing CSV, keeping the highest
    confidence match per attachment_id.
    """
    csv_path = Path(output_dir) / "photo-match-results.csv"

    # Load previous results and merge, keeping best confidence per attachment.
    existing = load_existing_results(csv_path)
    merged_count = 0

    for r in results:
        att_id = r["attachment_id"]
        if att_id in existing and existing[att_id]["confidence"] >= r["confidence"]:
            continue  # Previous match is better or equal.
        existing[att_id] = {
            "attachment_id": att_id,
            "exported_filename": r["exported_filename"],
            "apple_uuid": r["apple_uuid"],
            "confidence": r["confidence"],
            "wp_filename": "",
        }
        if att_id in wp_items:
            existing[att_id]["wp_filename"] = os.path.basename(
                wp_items[att_id].get("file_path", "")
            )

    all_results = sorted(existing.values(), key=lambda r: r["attachment_id"])

    with open(csv_path, "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow([
            "attachment_id",
            "wp_filename",
            "exported_filename",
            "apple_photos_uuid",
            "match_confidence",
        ])

        for r in all_results:
            writer.writerow([
                r["attachment_id"],
                r["wp_filename"],
                r["exported_filename"],
                r["apple_uuid"],
                f"{r['confidence']:.4f}",
            ])

    new_count = len(all_results) - len(existing) + len(results)
    if len(all_results) > len(results):
        print(f"\nMapping CSV written to: {csv_path} "
              f"({len(all_results)} total matches, merged with {len(all_results) - len(results)} from previous runs)")
    else:
        print(f"\nMapping CSV written to: {csv_path}")
    return str(csv_path)


def main():
    parser = argparse.ArgumentParser(
        description="Match WordPress images to local photos using perceptual hashing."
    )
    parser.add_argument(
        "--csv", required=True,
        help="Path to Low Scan CSV export (low-scan-results.csv)"
    )
    parser.add_argument(
        "--images-dir", required=True,
        help="Directory containing downloaded WP images"
    )
    parser.add_argument(
        "--photos-dir", required=True,
        help="Directory containing exported photos to match against"
    )
    parser.add_argument(
        "--output-dir", required=True,
        help="Directory for exported replacement images"
    )
    parser.add_argument(
        "--hash-threshold", type=int, default=10, choices=range(0, 65),
        metavar="0-64",
        help="Max Hamming distance for a match (0-64, default: 10)"
    )
    parser.add_argument(
        "--cache-file",
        help="Path to hash cache JSON file (default: <output-dir>/photo-hashes.json)"
    )
    parser.add_argument(
        "--resume", action="store_true",
        help="Skip already-exported replacement files"
    )

    args = parser.parse_args()

    # Default cache file.
    if not args.cache_file:
        args.cache_file = os.path.join(args.output_dir, "photo-hashes.json")

    # Parse CSV.
    print(f"Reading CSV: {args.csv}")
    items, metadata = parse_csv(args.csv)
    print(f"Found {len(items)} unique attachment IDs in CSV.")

    # Hash WP images.
    wp_hashes = hash_wp_images(args.images_dir, items)
    if not wp_hashes:
        print("No WP images could be hashed. Check --images-dir.")
        sys.exit(1)

    # Scan local photos.
    matches, photos_scanned = scan_local_photos(
        args.photos_dir,
        wp_hashes,
        args.hash_threshold,
        args.cache_file,
    )

    if not matches:
        print("\nNo matches found.")
        sys.exit(0)

    print(f"\nFound {len(matches)} matches.")

    # Copy matched photos to output dir.
    print("\nCopying matched photos...")
    results = copy_matches(matches, args.output_dir, args.resume)

    # Write mapping CSV.
    write_mapping_csv(results, args.output_dir, items)

    # Summary.
    matched = len(results)
    unmatched = len(wp_hashes) - matched
    print(f"\n{'=' * 50}")
    print(f"Summary:")
    print(f"  WP images hashed:    {len(wp_hashes)}")
    print(f"  Photos scanned:      {photos_scanned}")
    print(f"  Matches found:       {matched}")
    print(f"  Unmatched:           {unmatched}")
    print(f"  Output directory:    {args.output_dir}")
    print(f"{'=' * 50}")


if __name__ == "__main__":
    main()
