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
MAIN_FILE="${SLUG}.php"

if [[ ! -f "$MAIN_FILE" ]]; then
	echo "Error: expected main plugin file ${MAIN_FILE} in $(pwd)" >&2
	exit 1
fi

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
