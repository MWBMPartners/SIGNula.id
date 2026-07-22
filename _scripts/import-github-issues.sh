#!/bin/bash
###############################################################################
# Import GitHub Issues from Templates
#
# Prerequisites:
#   - GitHub CLI installed: https://cli.github.com/
#   - Authenticated: gh auth login
#
# Usage:
#   bash _scripts/import-github-issues.sh
###############################################################################

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ISSUES_DIR="$PROJECT_ROOT/.github/proposed-issues"

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║        GitHub Issues Import Script                           ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if gh is installed
if ! command -v gh &> /dev/null; then
    echo -e "${RED}❌ GitHub CLI (gh) is not installed${NC}"
    echo ""
    echo "Please install it from: https://cli.github.com/"
    echo ""
    echo "macOS:   brew install gh"
    echo "Windows: winget install GitHub.cli"
    echo "Linux:   See https://github.com/cli/cli/blob/trunk/docs/install_linux.md"
    exit 1
fi

# Check authentication
if ! gh auth status &> /dev/null; then
    echo -e "${YELLOW}⚠️  Not authenticated with GitHub${NC}"
    echo ""
    echo "Please run: gh auth login"
    exit 1
fi

echo -e "${GREEN}✅ GitHub CLI found and authenticated${NC}"
echo ""

# Check if issues directory exists
if [ ! -d "$ISSUES_DIR" ]; then
    echo -e "${RED}❌ Issues directory not found: $ISSUES_DIR${NC}"
    exit 1
fi

# Count issue files
ISSUE_COUNT=$(ls -1 "$ISSUES_DIR"/*.md 2>/dev/null | grep -v README.md | wc -l)
echo -e "${BLUE}📋 Found $ISSUE_COUNT issue templates${NC}"
echo ""

# Confirm before proceeding
read -p "Create $ISSUE_COUNT GitHub issues? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

echo ""
echo -e "${BLUE}Creating issues...${NC}"
echo ""

# Process each issue file
CREATED=0
FAILED=0

for issue_file in "$ISSUES_DIR"/*.md; do
    # Skip README
    if [[ $(basename "$issue_file") == "README.md" ]]; then
        continue
    fi

    filename=$(basename "$issue_file")

    # Extract title from YAML frontmatter
    title=$(grep '^title:' "$issue_file" | sed 's/title: *"\(.*\)"/\1/' | sed "s/title: *'\(.*\)'/\1/" | sed 's/title: *//')

    # Extract labels from YAML frontmatter
    labels=$(grep '^labels:' "$issue_file" | sed 's/labels: *\[\(.*\)\]/\1/' | tr ',' '\n' | sed 's/^[[:space:]]*"\(.*\)"[[:space:]]*$/\1/' | tr '\n' ',' | sed 's/,$//')

    # Remove YAML frontmatter from body
    body=$(sed '/^---$/,/^---$/d' "$issue_file")

    if [ -z "$title" ]; then
        echo -e "${RED}❌ Failed: $filename (no title found)${NC}"
        ((FAILED++))
        continue
    fi

    # Create the issue
    if [ -n "$labels" ]; then
        if gh issue create --title "$title" --body "$body" --label "$labels" > /dev/null 2>&1; then
            echo -e "${GREEN}✓${NC} Created: $title"
            ((CREATED++))
        else
            echo -e "${RED}✗${NC} Failed: $title"
            ((FAILED++))
        fi
    else
        if gh issue create --title "$title" --body "$body" > /dev/null 2>&1; then
            echo -e "${GREEN}✓${NC} Created: $title"
            ((CREATED++))
        else
            echo -e "${RED}✗${NC} Failed: $title"
            ((FAILED++))
        fi
    fi

    # Small delay to avoid rate limiting
    sleep 0.5
done

echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Import Complete!                                ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}✅ Created: $CREATED issues${NC}"
if [ $FAILED -gt 0 ]; then
    echo -e "${RED}❌ Failed: $FAILED issues${NC}"
fi
echo ""
echo "View issues: gh issue list"
echo "Or visit: https://github.com/MWBMPartners/SIGNula.id/issues"
echo ""
