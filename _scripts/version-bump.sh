#!/bin/bash
# ============================================================================
# 🔢 SIGNula Version Bump Script
# ============================================================================
# Increments version numbers following Semantic Versioning
# Usage:
#   ./version-bump.sh [major|minor|patch|beta|rc|release]
#
# Examples:
#   ./version-bump.sh patch       # 2.0.1 -> 2.0.2
#   ./version-bump.sh minor       # 2.0.1 -> 2.1.0
#   ./version-bump.sh major       # 2.0.1 -> 3.0.0
#   ./version-bump.sh beta        # 2.0.1 -> 2.0.1-beta
#   ./version-bump.sh rc          # 2.0.1-beta -> 2.0.1-rc.1
#   ./version-bump.sh release     # 2.0.1-beta -> 2.0.1
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

# Check if VERSION file exists
if [ ! -f "$VERSION_FILE" ]; then
    echo -e "${RED}❌ VERSION file not found!${NC}"
    exit 1
fi

# Read current version
CURRENT_VERSION=$(cat "$VERSION_FILE")
echo -e "${BLUE}📌 Current version: ${GREEN}$CURRENT_VERSION${NC}"

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

# Get bump type from argument
BUMP_TYPE="${1:-patch}"

# Calculate new version based on bump type
case "$BUMP_TYPE" in
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        PRERELEASE=""
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        PRERELEASE=""
        ;;
    patch)
        PATCH=$((PATCH + 1))
        PRERELEASE=""
        ;;
    beta)
        if [ -z "$PRERELEASE" ]; then
            PRERELEASE="beta"
        elif [[ $PRERELEASE =~ ^beta\.([0-9]+)$ ]]; then
            BETA_NUM="${BASH_REMATCH[1]}"
            PRERELEASE="beta.$((BETA_NUM + 1))"
        else
            PRERELEASE="beta"
        fi
        ;;
    rc)
        if [[ $PRERELEASE =~ ^rc\.([0-9]+)$ ]]; then
            RC_NUM="${BASH_REMATCH[1]}"
            PRERELEASE="rc.$((RC_NUM + 1))"
        else
            PRERELEASE="rc.1"
        fi
        ;;
    release)
        PRERELEASE=""
        ;;
    *)
        echo -e "${RED}❌ Invalid bump type: $BUMP_TYPE${NC}"
        echo "Valid types: major, minor, patch, beta, rc, release"
        exit 1
        ;;
esac

# Construct new version
if [ -z "$PRERELEASE" ]; then
    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
else
    NEW_VERSION="$MAJOR.$MINOR.$PATCH-$PRERELEASE"
fi

echo -e "${BLUE}🆕 New version: ${GREEN}$NEW_VERSION${NC}"

# Confirm with user
read -p "$(echo -e ${YELLOW}Proceed with version bump? [y/N]: ${NC})" -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ Aborted${NC}"
    exit 1
fi

# Update VERSION file
echo "$NEW_VERSION" > "$VERSION_FILE"
echo -e "${GREEN}✅ Updated VERSION file${NC}"

# Update PROJECT_PROGRESS.md
PROGRESS_FILE="$PROJECT_ROOT/PROJECT_PROGRESS.md"
if [ -f "$PROGRESS_FILE" ]; then
    # Update version in header
    if grep -q "Current Version:" "$PROGRESS_FILE"; then
        if [[ "$OSTYPE" == "darwin"* ]]; then
            # macOS
            sed -i '' "s/\*\*Current Version:\*\* .*/\*\*Current Version:\*\* $NEW_VERSION/" "$PROGRESS_FILE"
        else
            # Linux
            sed -i "s/\*\*Current Version:\*\* .*/\*\*Current Version:\*\* $NEW_VERSION/" "$PROGRESS_FILE"
        fi
        echo -e "${GREEN}✅ Updated PROJECT_PROGRESS.md${NC}"
    fi

    # Update Last Updated date
    CURRENT_DATE=$(date +"%Y-%m-%d")
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/\*\*Last Updated:\*\* .*/\*\*Last Updated:\*\* $CURRENT_DATE/" "$PROGRESS_FILE"
    else
        sed -i "s/\*\*Last Updated:\*\* .*/\*\*Last Updated:\*\* $CURRENT_DATE/" "$PROGRESS_FILE"
    fi
fi

# Show next steps
echo ""
echo -e "${BLUE}📋 Next steps:${NC}"
echo "1. Update CHANGELOG.md with changes for v$NEW_VERSION"
echo "2. Review and commit changes:"
echo -e "   ${YELLOW}git add VERSION PROJECT_PROGRESS.md CHANGELOG.md${NC}"
echo -e "   ${YELLOW}git commit -m \"chore: Release v$NEW_VERSION\"${NC}"
echo "3. Create Git tag:"
echo -e "   ${YELLOW}git tag -a v$NEW_VERSION -m \"Release v$NEW_VERSION\"${NC}"
echo "4. Push changes and tags:"
echo -e "   ${YELLOW}git push origin main --tags${NC}"
echo "5. Create GitHub Release using the tag"
echo ""
echo -e "${GREEN}✨ Version bump complete!${NC}"
