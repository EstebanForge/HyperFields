#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PACKAGE_NAME="$(basename "$PROJECT_DIR")"

CURRENT_VERSION=$(awk -F'"' '/"version":/{print $4; exit}' "$PROJECT_DIR/composer.json")

if [[ -z "${CURRENT_VERSION:-}" ]]; then
  echo "Error: Could not detect current version from composer.json"
  exit 1
fi

echo ""
echo "┌─────────────────────────────────────┐"
echo "│   ${PACKAGE_NAME} Version Bump"
echo "└─────────────────────────────────────┘"
echo ""
echo "  Current version: $CURRENT_VERSION"
echo ""

# --- bump math (shared) ---
bump_version() {
  local cur="$1" level="$2" major minor patch
  IFS='.' read -r major minor patch <<< "$cur"
  case "$level" in
    major) major=$((major+1)); minor=0; patch=0 ;;
    minor) minor=$((minor+1)); patch=0 ;;
    patch) patch=$((patch+1)) ;;
    *) return 1 ;;
  esac
  echo "${major}.${minor}.${patch}"
}

usage() {
  cat >&2 <<EOF
Usage:
  scripts/version-bump.sh                 # interactive prompt
  scripts/version-bump.sh --patch         # bump patch (1.2.3 -> 1.2.4)
  scripts/version-bump.sh --minor         # bump minor
  scripts/version-bump.sh --major         # bump major
  scripts/version-bump.sh --version 1.2.4 # explicit version
EOF
}

# --- resolve target version ---
BUMP_LEVEL=""
NEW_VERSION=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) NEW_VERSION="${2:-}"; shift 2 ;;
    --patch|--minor|--major) BUMP_LEVEL="${1#--}"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
  esac
done

if [[ -z "$NEW_VERSION" && -z "$BUMP_LEVEL" ]]; then
  # Interactive mode (default when no flags are passed)
  while true; do
    read -rp "  Enter new version (X.Y.Z): " NEW_VERSION
    if [[ -z "$NEW_VERSION" ]]; then echo "  ✗ Version cannot be empty"; continue; fi
    if [[ ! "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then echo "  ✗ Invalid format. Use X.Y.Z (e.g., 1.2.3)"; continue; fi
    if [[ "$NEW_VERSION" == "$CURRENT_VERSION" ]]; then echo "  ✗ New version must be different from current version"; continue; fi
    break
  done
elif [[ -n "$BUMP_LEVEL" ]]; then
  NEW_VERSION="$(bump_version "$CURRENT_VERSION" "$BUMP_LEVEL")" || { echo "  ✗ Invalid bump level" >&2; exit 1; }
  echo "  Computed ($BUMP_LEVEL): $CURRENT_VERSION -> $NEW_VERSION"
else
  if [[ ! "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then echo "  ✗ Invalid format. Use X.Y.Z (e.g., 1.2.3)" >&2; exit 1; fi
  if [[ "$NEW_VERSION" == "$CURRENT_VERSION" ]]; then echo "  ✗ New version must be different from current version" >&2; exit 1; fi
fi

sedi() {
  if [[ "$OSTYPE" == darwin* ]]; then
    sed -i '' "$@"
  else
    sed -i "$@"
  fi
}

echo ""
echo "  Bumping: $CURRENT_VERSION -> $NEW_VERSION"
echo ""

# Update composer.json version field
sedi "s/\"version\": \"$CURRENT_VERSION\"/\"version\": \"$NEW_VERSION\"/" "$PROJECT_DIR/composer.json"
echo "  ✓ composer.json"

# Update package.json version field (React assets)
if [[ -f "$PROJECT_DIR/package.json" ]]; then
  sedi "s/\"version\": \"$CURRENT_VERSION\"/\"version\": \"$NEW_VERSION\"/" "$PROJECT_DIR/package.json"
  echo "  ✓ package.json"
fi

# Update bootstrap.php fallback/default version
if [[ -f "$PROJECT_DIR/bootstrap.php" ]]; then
  # Preferred pattern (current architecture): single source of truth constant.
  if grep -q "HYPERFIELDS_DEFAULT_VERSION" "$PROJECT_DIR/bootstrap.php"; then
    sedi -E "s/(define\('HYPERFIELDS_DEFAULT_VERSION',[[:space:]]*')[^']+('\);)/\1$NEW_VERSION\2/" "$PROJECT_DIR/bootstrap.php"
  fi

  # Legacy safety net: update any remaining exact old-version literals.
  sedi "s/'$CURRENT_VERSION'/'$NEW_VERSION'/g" "$PROJECT_DIR/bootstrap.php"
  echo "  ✓ bootstrap.php (default/fallback versions)"
fi

# Update OptionsPage.php fallback versions
if [[ -f "$PROJECT_DIR/src/OptionsPage.php" ]]; then
  # Update fallback versions in OptionsPage
  # Match patterns like: '2.0.7', '2.1.0', etc.
  sedi "s/'$CURRENT_VERSION'/'$NEW_VERSION'/g" "$PROJECT_DIR/src/OptionsPage.php"
  echo "  ✓ src/OptionsPage.php (fallback versions)"
fi

# Scan ALL PHP files under src/ for any remaining old-version literals the
# targeted steps above missed (e.g. class-level `const VERSION = '...'` stamps
# like src/LibraryBootstrap.php). Idempotent: files already bumped have no match.
while IFS= read -r -d '' src_file; do
    if grep -q "'$CURRENT_VERSION'" "$src_file" 2>/dev/null; then
        sedi "s/'$CURRENT_VERSION'/'$NEW_VERSION'/g" "$src_file"
        echo "  ✓ ${src_file#$PROJECT_DIR/} (src/ version literal)"
    fi
done < <(find "$PROJECT_DIR/src" -type f -name '*.php' -print0 2>/dev/null)

# Update WordPress plugin header version if present
for candidate in hyperpress.php api-for-htmx.php hyperfields.php hyperblocks.php; do
  file="$PROJECT_DIR/$candidate"
  if [[ -f "$file" ]]; then
    sedi -E "s/^(\s*\*\s*Version:\s*).*/\1$NEW_VERSION/" "$file"
    echo "  ✓ $candidate"
  fi
done

echo ""
echo "┌─────────────────────────────────────┐"
echo "│  Version bumped to $NEW_VERSION"
echo "└─────────────────────────────────────┘"
echo ""
echo "  Next steps:"
echo "    1. Update changelog/release notes"
echo "    2. composer production"
echo "    3. git add -A && git commit -m 'Bump version to $NEW_VERSION'"
echo ""
echo "RESULT: $CURRENT_VERSION -> $NEW_VERSION"
