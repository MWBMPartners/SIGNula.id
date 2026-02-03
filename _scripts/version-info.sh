#!/bin/bash
# ============================================================================
# ℹ️  SIGNula Version Information Display
# ============================================================================
# Displays current version and related information
# Usage:
#   ./version-info.sh
# ============================================================================

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
VERSION_FILE="$PROJECT_ROOT/VERSION"
CHANGELOG_FILE="$PROJECT_ROOT/CHANGELOG.md"

# Check if VERSION file exists
if [ ! -f "$VERSION_FILE" ]; then
    echo -e "${RED}❌ VERSION file not found!${NC}"
    exit 1
fi

# Read current version
CURRENT_VERSION=$(cat "$VERSION_FILE")

# Parse version components
if [[ $CURRENT_VERSION =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)(-([a-zA-Z0-9.]+))?$ ]]; then
    MAJOR="${BASH_REMATCH[1]}"
    MINOR="${BASH_REMATCH[2]}"
    PATCH="${BASH_REMATCH[3]}"
    PRERELEASE="${BASH_REMATCH[5]}"
else
    echo -e "${RED}❌ Invalid version format: $CURRENT_VERSION${NC}"
    exit 1
fi

# Determine version type
if [ -z "$PRERELEASE" ]; then
    VERSION_TYPE="Stable Release"
    VERSION_COLOR="$GREEN"
elif [[ $PRERELEASE =~ ^alpha ]]; then
    VERSION_TYPE="Alpha Pre-release"
    VERSION_COLOR="$YELLOW"
elif [[ $PRERELEASE =~ ^beta ]]; then
    VERSION_TYPE="Beta Pre-release"
    VERSION_COLOR="$CYAN"
elif [[ $PRERELEASE =~ ^rc ]]; then
    VERSION_TYPE="Release Candidate"
    VERSION_COLOR="$MAGENTA"
else
    VERSION_TYPE="Pre-release"
    VERSION_COLOR="$YELLOW"
fi

# Display version information
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                   ${GREEN}SIGNula Version Information${BLUE}                ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BLUE}📦 Current Version:${NC}    ${VERSION_COLOR}v${CURRENT_VERSION}${NC}"
echo -e "  ${BLUE}🏷️  Version Type:${NC}      ${VERSION_COLOR}${VERSION_TYPE}${NC}"
echo ""
echo -e "  ${BLUE}🔢 Version Components:${NC}"
echo -e "     ${CYAN}• Major:${NC}           ${MAJOR}"
echo -e "     ${CYAN}• Minor:${NC}           ${MINOR}"
echo -e "     ${CYAN}• Patch:${NC}           ${PATCH}"
if [ -n "$PRERELEASE" ]; then
    echo -e "     ${CYAN}• Pre-release:${NC}     ${PRERELEASE}"
fi

# Git tag information
echo ""
echo -e "  ${BLUE}🏷️  Git Tag Information:${NC}"
if git rev-parse "v$CURRENT_VERSION" >/dev/null 2>&1; then
    TAG_DATE=$(git log -1 --format=%ai "v$CURRENT_VERSION" 2>/dev/null | cut -d' ' -f1)
    TAG_COMMIT=$(git rev-parse --short "v$CURRENT_VERSION")
    echo -e "     ${GREEN}✅ Tag exists${NC}"
    echo -e "     ${CYAN}• Tag:${NC}            v${CURRENT_VERSION}"
    echo -e "     ${CYAN}• Date:${NC}           ${TAG_DATE}"
    echo -e "     ${CYAN}• Commit:${NC}         ${TAG_COMMIT}"
else
    echo -e "     ${YELLOW}⚠️  Tag v${CURRENT_VERSION} does not exist${NC}"
fi

# GitHub release information
echo ""
echo -e "  ${BLUE}🚀 GitHub Release:${NC}"
if command -v gh &> /dev/null; then
    if gh auth status &> /dev/null 2>&1; then
        if gh release view "v$CURRENT_VERSION" &> /dev/null; then
            RELEASE_URL=$(gh release view "v$CURRENT_VERSION" --json url --jq .url)
            echo -e "     ${GREEN}✅ Release exists${NC}"
            echo -e "     ${CYAN}• URL:${NC}            ${RELEASE_URL}"
        else
            echo -e "     ${YELLOW}⚠️  Release v${CURRENT_VERSION} does not exist${NC}"
        fi
    else
        echo -e "     ${YELLOW}⚠️  GitHub CLI not authenticated${NC}"
    fi
else
    echo -e "     ${YELLOW}⚠️  GitHub CLI not installed${NC}"
fi

# Latest changelog entry
echo ""
echo -e "  ${BLUE}📝 Latest Changelog Entry:${NC}"
if [ -f "$CHANGELOG_FILE" ]; then
    VERSION_NO_V="${CURRENT_VERSION}"
    # Extract first 5 lines of the current version section
    CHANGELOG_PREVIEW=$(awk "/## \[$VERSION_NO_V\]/,/## \[/" "$CHANGELOG_FILE" | head -n 6 | tail -n +2)

    if [ -n "$CHANGELOG_PREVIEW" ]; then
        echo -e "${CYAN}${CHANGELOG_PREVIEW}${NC}"
        echo -e "     ${BLUE}...${NC}"
    else
        echo -e "     ${YELLOW}⚠️  No entry found for v${CURRENT_VERSION}${NC}"
    fi
else
    echo -e "     ${YELLOW}⚠️  CHANGELOG.md not found${NC}"
fi

# Available version bump options
echo ""
echo -e "  ${BLUE}🔼 Available Version Bumps:${NC}"
echo -e "     ${CYAN}• Patch:${NC}          v${MAJOR}.${MINOR}.$((PATCH + 1))"
echo -e "     ${CYAN}• Minor:${NC}          v${MAJOR}.$((MINOR + 1)).0"
echo -e "     ${CYAN}• Major:${NC}          v$((MAJOR + 1)).0.0"
if [ -n "$PRERELEASE" ]; then
    echo -e "     ${CYAN}• Release:${NC}        v${MAJOR}.${MINOR}.${PATCH}"
else
    echo -e "     ${CYAN}• Beta:${NC}           v${MAJOR}.${MINOR}.${PATCH}-beta"
    echo -e "     ${CYAN}• RC:${NC}             v${MAJOR}.${MINOR}.${PATCH}-rc.1"
fi

# Quick commands
echo ""
echo -e "  ${BLUE}⚡ Quick Commands:${NC}"
echo -e "     ${CYAN}• Bump version:${NC}    ./version-bump.sh [major|minor|patch|beta|rc|release]"
echo -e "     ${CYAN}• Create release:${NC}  ./create-release.sh"
echo -e "     ${CYAN}• View changelog:${NC}  cat CHANGELOG.md"
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo ""
