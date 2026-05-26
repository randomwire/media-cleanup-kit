#!/usr/bin/env bash
#
# Universal WordPress plugin packaging script.
#
# - Slug is taken from the repo directory name.
# - Main PHP file is <slug>.php at the repo root.
# - Version is read from its "Version:" header line.
# - File exclusions come from .distignore if present; otherwise a built-in
#   default set is used. `.git`, `dist`, and `build.sh` itself are always
#   excluded.
# - If package.json exposes a `build` script, `npm run build` runs first.
# - Output: dist/<slug>-<version>.zip (old versions preserved alongside).
#
# Usage: ./build.sh

set -euo pipefail

cd "$(dirname "$0")"

SLUG="$(basename "$PWD")"

# Find the main plugin file — the .php at root that carries a Plugin Name
# header. Prefer ${SLUG}.php when present; otherwise scan and pick the first
# match. This survives directory renames that haven't yet been mirrored
# in the main filename (or vice versa).
if [[ -f "${SLUG}.php" ]] && grep -qE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' "${SLUG}.php"; then
	MAIN_FILE="${SLUG}.php"
else
	MAIN_FILE=""
	for f in *.php; do
		[[ -f "$f" ]] || continue
		if grep -qE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' "$f"; then
			MAIN_FILE="$f"
			break
		fi
	done
fi

if [[ -z "$MAIN_FILE" || ! -f "$MAIN_FILE" ]]; then
	echo "Error: could not locate a plugin main file (no *.php with a Plugin Name: header in $(pwd))" >&2
	exit 1
fi

# Re-derive SLUG from the main filename so the output zip name follows the
# plugin's actual identity even if the parent directory has a different name.
SLUG="${MAIN_FILE%.php}"

VERSION="$(grep -m1 -E '^[[:space:]]*\*?[[:space:]]*Version:' "$MAIN_FILE" \
	| sed -E 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*//' \
	| tr -d '[:space:]')"

if [[ -z "$VERSION" ]]; then
	echo "Error: could not read Version header from ${MAIN_FILE}" >&2
	exit 1
fi

echo "Packaging ${SLUG} v${VERSION}..."

# Auto-build when a JS build pipeline is present.
if [[ -f package.json ]] && grep -qE '"build"[[:space:]]*:' package.json; then
	echo "Running npm run build..."
	npm run build
fi

STAGING="$(mktemp -d)"
EXCLUDE_FILE="$(mktemp)"
trap 'rm -rf "$STAGING" "$EXCLUDE_FILE"' EXIT

DEST="${STAGING}/${SLUG}"
mkdir -p "$DEST"

# Always-excluded safety patterns.
cat >"$EXCLUDE_FILE" <<'EOF'
.git
dist
build.sh
EOF

if [[ -f .distignore ]]; then
	grep -vE '^[[:space:]]*(#|$)' .distignore >> "$EXCLUDE_FILE" || true
else
	cat >>"$EXCLUDE_FILE" <<'EOF'
.github
.claude
.cursor
.idea
.vscode
.distignore
.gitignore
.DS_Store
Thumbs.db
node_modules
tests
phpunit.xml
phpunit.xml.dist
composer.lock
package.json
package-lock.json
webpack.config.js
eslint.config.js
.babelrc
*.map
*.zip
CLAUDE.md
AGENTS.md
EOF
	# Exclude src/ only when compiled output is present.
	if [[ -d build ]]; then
		echo "src" >> "$EXCLUDE_FILE"
	fi
fi

rsync -a --exclude-from="$EXCLUDE_FILE" ./ "$DEST/"

mkdir -p dist
OUT="$(pwd)/dist/${SLUG}-${VERSION}.zip"
rm -f "$OUT"

(cd "$STAGING" && zip -r9 "$OUT" "$SLUG" >/dev/null)

SIZE="$(du -h "$OUT" | cut -f1)"
echo "Built: dist/${SLUG}-${VERSION}.zip (${SIZE})"
