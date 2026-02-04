#!/bin/bash
# ============================================================================
# 🚀 SIGNula GitHub Release Creator
# ============================================================================
# Creates a GitHub release with changelog notes
# Requires: GitHub CLI (gh) installed and authenticated
#
# Usage:
#   ./create-release.sh [version]
#
# Examples:
#   ./create-release.sh              # Uses VERSION file
#   ./create-release.sh v2.0.1-beta  # Specific version
#
# Prerequisites:
#   - GitHub CLI installed: https://cli.github.com/
#   - Authenticated: gh auth login
# ============================================================================

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
VERSION_FILE="$PROJECT_ROOT/VERSION"
CHANGELOG_FILE="$PROJECT_ROOT/CHANGELOG.md"

# Check if gh CLI is installed
if ! command -v gh &> /dev/null; then
    echo -e "${RED}❌ GitHub CLI (gh) is not installed${NC}"
    echo "Install from: https://cli.github.com/"
    exit 1
fi

# Check if authenticated
if ! gh auth status &> /dev/null; then
    echo -e "${RED}❌ GitHub CLI is not authenticated${NC}"
    echo "Run: gh auth login"
    exit 1
fi

# Get version from argument or VERSION file
if [ -n "$1" ]; then
    VERSION="$1"
else
    if [ ! -f "$VERSION_FILE" ]; then
        echo -e "${RED}❌ VERSION file not found!${NC}"
        exit 1
    fi
    VERSION=$(cat "$VERSION_FILE")
fi

# Ensure version has 'v' prefix
if [[ ! $VERSION =~ ^v ]]; then
    VERSION="v$VERSION"
fi

echo -e "${BLUE}🚀 Creating GitHub release for version: ${GREEN}$VERSION${NC}"

# Check if tag exists
if ! git rev-parse "$VERSION" >/dev/null 2>&1; then
    echo -e "${YELLOW}⚠️  Tag $VERSION does not exist${NC}"
    read -p "$(echo -e ${YELLOW}Create tag now? [y/N]: ${NC})" -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git tag -a "$VERSION" -m "Release $VERSION"
        echo -e "${GREEN}✅ Created tag $VERSION${NC}"

        read -p "$(echo -e ${YELLOW}Push tag to remote? [y/N]: ${NC})" -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            git push origin "$VERSION"
            echo -e "${GREEN}✅ Pushed tag to remote${NC}"
        fi
    else
        echo -e "${RED}❌ Cannot create release without tag${NC}"
        exit 1
    fi
fi

# Extract release notes from CHANGELOG.md
if [ -f "$CHANGELOG_FILE" ]; then
    echo -e "${BLUE}📝 Extracting release notes from CHANGELOG.md${NC}"

    # Extract section between [version] and next [version] or end of file
    VERSION_NO_V="${VERSION#v}"
    RELEASE_NOTES=$(awk "/## \[$VERSION_NO_V\]/,/## \[/" "$CHANGELOG_FILE" | sed '1d;$d' | sed '/^$/d')

    if [ -z "$RELEASE_NOTES" ]; then
        echo -e "${YELLOW}⚠️  No release notes found in CHANGELOG.md for $VERSION${NC}"
        RELEASE_NOTES="Release $VERSION

See [CHANGELOG.md](CHANGELOG.md) for details."
    fi
else
    echo -e "${YELLOW}⚠️  CHANGELOG.md not found${NC}"
    RELEASE_NOTES="Release $VERSION

See project documentation for details."
fi

# Save release notes to temporary file
NOTES_FILE=$(mktemp)
echo "$RELEASE_NOTES" > "$NOTES_FILE"

# Show release notes preview
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}📄 Release Notes Preview:${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
cat "$NOTES_FILE"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Determine if pre-release
PRERELEASE_FLAG=""
if [[ $VERSION =~ -(alpha|beta|rc) ]]; then
    PRERELEASE_FLAG="--prerelease"
    echo -e "${YELLOW}🏷️  This will be marked as a pre-release${NC}"
fi

# Confirm with user
read -p "$(echo -e ${YELLOW}Create this GitHub release? [y/N]: ${NC})" -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    rm "$NOTES_FILE"
    echo -e "${RED}❌ Aborted${NC}"
    exit 1
fi

# Create GitHub release
echo -e "${BLUE}📤 Creating GitHub release...${NC}"

if gh release create "$VERSION" \
    --title "Release $VERSION" \
    --notes-file "$NOTES_FILE" \
    $PRERELEASE_FLAG; then

    echo -e "${GREEN}✅ GitHub release created successfully!${NC}"
    echo ""
    echo -e "${BLUE}🔗 View release:${NC}"
    gh release view "$VERSION" --web
else
    echo -e "${RED}❌ Failed to create GitHub release${NC}"
    rm "$NOTES_FILE"
    exit 1
fi

# Cleanup
rm "$NOTES_FILE"

# Show next steps
echo ""
echo -e "${BLUE}📋 Next steps:${NC}"
echo "1. Verify release on GitHub"
echo "2. Announce release to stakeholders"
echo "3. Update production deployment if applicable"
echo ""
echo -e "${GREEN}✨ Release process complete!${NC}"
