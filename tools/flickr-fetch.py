#!/usr/bin/env python3
"""
Flickr Fetch — Download largest available Flickr images from a CSV export.

Usage:
    python3 flickr-fetch.py --csv flickr-images.csv --api-key YOUR_KEY [--output-dir ./downloads]

Reads the CSV exported by the Media Cleanup Kit "Replace Flickr Images" module, queries the
Flickr API for the largest available version of each photo, and downloads it.

Designed for large libraries (5k–20k photos) with adaptive rate limiting,
exponential backoff, fast resume, and persistent HTTP connections.
"""

import argparse
import csv
import json
import os
import sys
import time

# Try requests first, fall back to urllib.
try:
    import requests
    from requests.adapters import HTTPAdapter
    HAS_REQUESTS = True
except ImportError:
    import urllib.request
    import urllib.error
    HAS_REQUESTS = False


# Flickr size suffix → rank (higher = larger).
FLICKR_SIZE_RANK = {
    's': 1,   # Square 75
    'q': 2,   # Square 150
    't': 3,   # Thumbnail 100
    'm': 4,   # Small 240
    'n': 5,   # Small 320
    'w': 6,   # Small 400
    'z': 7,   # Medium 640
    'c': 8,   # Medium 800
    'b': 9,   # Large 1024
    'h': 10,  # Large 1600
    'k': 11,  # Large 2048
    'o': 12,  # Original
}

# Flickr API label → suffix.
LABEL_TO_SUFFIX = {
    'Square':       's',
    'Large Square': 'q',
    'Thumbnail':    't',
    'Small':        'm',
    'Small 320':    'n',
    'Small 400':    'w',
    'Medium':       'z',
    'Medium 640':   'z',
    'Medium 800':   'c',
    'Large':        'b',
    'Large 1024':   'b',
    'Large 1600':   'h',
    'Large 2048':   'k',
    'Original':     'o',
}

# Adaptive rate limiting.
REQUEST_DELAY = 1.0
MAX_DELAY = 60.0

# Persistent HTTP session (connection pooling).
SESSION = None


def init_session():
    """Create a persistent requests session with connection pooling."""
    global SESSION
    if HAS_REQUESTS:
        SESSION = requests.Session()
        adapter = HTTPAdapter(pool_connections=10, pool_maxsize=10)
        SESSION.mount('https://', adapter)
        SESSION.mount('http://', adapter)


def http_get(url):
    """GET request, returns response body as string. Handles 429 rate limits."""
    global REQUEST_DELAY

    if HAS_REQUESTS:
        resp = SESSION.get(url, timeout=30)
        if resp.status_code == 429:
            REQUEST_DELAY = min(REQUEST_DELAY * 2, MAX_DELAY)
            print(f'  Flickr rate limit detected. Sleeping {REQUEST_DELAY}s')
            time.sleep(REQUEST_DELAY)
            resp = SESSION.get(url, timeout=30)
        resp.raise_for_status()
        return resp.text
    else:
        req = urllib.request.Request(url)
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.read().decode('utf-8')
        except urllib.error.HTTPError as e:
            if e.code == 429:
                REQUEST_DELAY = min(REQUEST_DELAY * 2, MAX_DELAY)
                print(f'  Flickr rate limit detected. Sleeping {REQUEST_DELAY}s')
                time.sleep(REQUEST_DELAY)
                with urllib.request.urlopen(req, timeout=30) as resp:
                    return resp.read().decode('utf-8')
            raise


def http_download(url, dest_path):
    """Download a file to disk with streaming (low memory)."""
    if HAS_REQUESTS:
        resp = SESSION.get(url, timeout=60, stream=True)
        if resp.status_code == 429:
            global REQUEST_DELAY
            REQUEST_DELAY = min(REQUEST_DELAY * 2, MAX_DELAY)
            print(f'  Flickr rate limit detected. Sleeping {REQUEST_DELAY}s')
            time.sleep(REQUEST_DELAY)
            resp = SESSION.get(url, timeout=60, stream=True)
        resp.raise_for_status()
        with open(dest_path, 'wb') as f:
            for chunk in resp.iter_content(chunk_size=65536):
                f.write(chunk)
    else:
        urllib.request.urlretrieve(url, dest_path)


def get_sizes(api_key, photo_id):
    """Call flickr.photos.getSizes and return the list of sizes."""
    url = (
        f'https://www.flickr.com/services/rest/?method=flickr.photos.getSizes'
        f'&api_key={api_key}&photo_id={photo_id}'
        f'&format=json&nojsoncallback=1'
    )
    body = http_get(url)
    data = json.loads(body)

    if data.get('stat') != 'ok':
        msg = data.get('message', 'Unknown error')
        raise RuntimeError(f'Flickr API error for photo {photo_id}: {msg}')

    return data.get('sizes', {}).get('size', [])


def find_best_size(sizes, current_suffix):
    """Find the largest available size that is bigger than current_suffix."""
    current_rank = FLICKR_SIZE_RANK.get(current_suffix, 0)
    best = None
    best_rank = current_rank

    for size in sizes:
        label = size.get('label', '')
        suffix = LABEL_TO_SUFFIX.get(label)
        if suffix is None:
            continue
        rank = FLICKR_SIZE_RANK.get(suffix, 0)
        if rank > best_rank:
            best_rank = rank
            best = size

    return best


def main():
    global REQUEST_DELAY

    parser = argparse.ArgumentParser(
        description='Download largest available Flickr images from CSV export.'
    )
    parser.add_argument('--csv', required=True, help='Path to the CSV file exported by Media Cleanup Kit’s Replace Flickr Images module.')
    parser.add_argument('--api-key', required=True, help='Flickr API key.')
    parser.add_argument('--output-dir', default='./downloads', help='Directory to save downloaded files (default: ./downloads).')
    args = parser.parse_args()

    if not os.path.isfile(args.csv):
        print(f'Error: CSV file not found: {args.csv}', file=sys.stderr)
        sys.exit(1)

    os.makedirs(args.output_dir, exist_ok=True)
    init_session()

    # Read CSV and deduplicate by photo_id.
    photos = {}  # photo_id → { current_size_suffix, post_titles }
    with open(args.csv, 'r', newline='', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        for row in reader:
            pid = row.get('flickr_photo_id', '').strip()
            if not pid:
                continue
            if pid not in photos:
                photos[pid] = {
                    'current_size_suffix': row.get('current_size_suffix', '').strip(),
                    'post_titles': [],
                }
            title = row.get('post_title', '').strip()
            if title:
                photos[pid]['post_titles'].append(title)

    total = len(photos)
    print(f'Found {total} unique Flickr photos in CSV.\n')

    results = []
    downloaded = 0
    skipped = 0
    errors = 0

    for i, (photo_id, info) in enumerate(photos.items(), 1):
        current_suffix = info['current_size_suffix']
        prefix = f'[{i}/{total}] Photo {photo_id}'

        # Fast resume: skip if any file for this photo_id already exists.
        existing = [f for f in os.listdir(args.output_dir) if f.startswith(photo_id + '_')]
        if existing:
            print(f'{prefix}: Already downloaded ({existing[0]}), skipping.')
            results.append({
                'flickr_photo_id': photo_id,
                'status': 'already_downloaded',
                'downloaded_filename': existing[0],
                'best_size': '',
                'original_size': current_suffix,
                'error': '',
            })
            skipped += 1
            continue

        # API call with retry for rate limits.
        try:
            sizes = get_sizes(args.api_key, photo_id)
        except Exception as e:
            print(f'{prefix}: ERROR getting sizes — {e}')
            results.append({
                'flickr_photo_id': photo_id,
                'status': 'error',
                'downloaded_filename': '',
                'best_size': '',
                'original_size': current_suffix,
                'error': str(e),
            })
            errors += 1
            time.sleep(REQUEST_DELAY)
            continue

        best = find_best_size(sizes, current_suffix)

        if best is None:
            print(f'{prefix}: Already at largest available size ({current_suffix}), skipping.')
            results.append({
                'flickr_photo_id': photo_id,
                'status': 'already_largest',
                'downloaded_filename': '',
                'best_size': current_suffix,
                'original_size': current_suffix,
                'error': '',
            })
            skipped += 1
            time.sleep(REQUEST_DELAY)
            continue

        best_label = best.get('label', 'Unknown')
        best_suffix = LABEL_TO_SUFFIX.get(best_label, '?')
        source_url = best.get('source', '')

        if not source_url:
            print(f'{prefix}: No source URL for best size ({best_label}), skipping.')
            results.append({
                'flickr_photo_id': photo_id,
                'status': 'no_source_url',
                'downloaded_filename': '',
                'best_size': best_suffix,
                'original_size': current_suffix,
                'error': 'No source URL',
            })
            errors += 1
            time.sleep(REQUEST_DELAY)
            continue

        # Derive filename from source URL.
        filename = os.path.basename(source_url.split('?')[0])
        if not filename:
            filename = f'{photo_id}_{best_suffix}.jpg'

        dest_path = os.path.join(args.output_dir, filename)

        # Download with exponential backoff (up to 5 retries).
        print(f'{prefix}: Downloading {best_label} ({best.get("width", "?")}x{best.get("height", "?")}) → {filename}')
        download_success = False
        for attempt in range(5):
            try:
                http_download(source_url, dest_path)
                downloaded += 1
                results.append({
                    'flickr_photo_id': photo_id,
                    'status': 'downloaded',
                    'downloaded_filename': filename,
                    'best_size': best_suffix,
                    'original_size': current_suffix,
                    'error': '',
                })
                download_success = True
                # Gradually recover speed after success.
                REQUEST_DELAY = max(1.0, REQUEST_DELAY * 0.9)
                break
            except Exception as e:
                wait = 5 * (attempt + 1)
                if attempt < 4:
                    print(f'  Retry in {wait}s after error: {e}')
                    time.sleep(wait)
                else:
                    print(f'  FAILED after {attempt + 1} attempts: {e}')
                    results.append({
                        'flickr_photo_id': photo_id,
                        'status': 'download_error',
                        'downloaded_filename': '',
                        'best_size': best_suffix,
                        'original_size': current_suffix,
                        'error': str(e),
                    })
                    errors += 1
                    # Clean up partial file.
                    if os.path.isfile(dest_path):
                        os.remove(dest_path)

        time.sleep(REQUEST_DELAY)

    # Summary.
    print(f'\n{"=" * 50}')
    print(f'Summary: {downloaded} downloaded, {skipped} skipped, {errors} errors (out of {total} unique photos)')

    # Write results CSV.
    results_path = os.path.join(args.output_dir, 'flickr-fetch-results.csv')
    with open(results_path, 'w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=[
            'flickr_photo_id', 'status', 'downloaded_filename',
            'best_size', 'original_size', 'error',
        ])
        writer.writeheader()
        writer.writerows(results)

    print(f'Results written to: {results_path}')


if __name__ == '__main__':
    main()
