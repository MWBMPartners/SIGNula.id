#!/bin/bash
###############################################################################
# Setup Copyright Management Git Hooks
#
# Purpose: Install Git hooks for automatic copyright year management
#
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
#
# Version: 1.0.0
# Date: February 4, 2026
#
# Usage: bash _scripts/setup-copyright-hooks.sh
#
###############################################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Copyright Management Git Hooks Setup v1.0.0             ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if we're in a git repository
if [ ! -d ".git" ]; then
    echo -e "${RED}❌ Error: Not a git repository${NC}"
    echo "Please run this script from the project root"
    exit 1
fi

echo -e "${GREEN}✅ Git repository detected${NC}"
echo ""

# Check if .git/hooks directory exists
if [ ! -d ".git/hooks" ]; then
    mkdir -p ".git/hooks"
    echo -e "${GREEN}✅ Created .git/hooks directory${NC}"
fi

# Copy pre-commit hook
if [ -f "_scripts/git-hooks/pre-commit" ]; then
    cp "_scripts/git-hooks/pre-commit" ".git/hooks/pre-commit"
    chmod +x ".git/hooks/pre-commit"
    echo -e "${GREEN}✅ Installed pre-commit hook${NC}"
    echo -e "   ${BLUE}Location:${NC} .git/hooks/pre-commit"
else
    echo -e "${RED}❌ Error: pre-commit hook template not found${NC}"
    echo "   Expected: _scripts/git-hooks/pre-commit"
    exit 1
fi

echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                    Setup Complete!                           ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${GREEN}✅ Git hooks installed successfully!${NC}"
echo ""

echo -e "${YELLOW}📋 What happens now:${NC}"
echo ""
echo "  1. ${GREEN}Pre-commit hook installed${NC}"
echo "     - Automatically updates copyright years before each commit"
echo "     - Updates year range to: 2025-$(date +%Y)"
echo "     - Only processes staged files"
echo ""
echo "  2. ${GREEN}Manual copyright management${NC}"
echo "     - Run: ${BLUE}bash _scripts/add-copyright.sh${NC}"
echo "     - Adds copyright to all files without it"
echo "     - Updates existing copyrights: ${BLUE}--update-year${NC}"
echo "     - Test without changes: ${BLUE}--dry-run${NC}"
echo ""

echo -e "${YELLOW}🧪 Test the setup:${NC}"
echo ""
echo "  1. Make a small change to any file"
echo "  2. Stage it: ${BLUE}git add [file]${NC}"
echo "  3. Commit: ${BLUE}git commit -m \"test\"${NC}"
echo "  4. Copyright year will be auto-updated if needed!"
echo ""

echo -e "${YELLOW}📚 Usage examples:${NC}"
echo ""
echo "  ${BLUE}# Add copyright to all files${NC}"
echo "  bash _scripts/add-copyright.sh"
echo ""
echo "  ${BLUE}# Update copyright years to current year${NC}"
echo "  bash _scripts/add-copyright.sh --update-year"
echo ""
echo "  ${BLUE}# Preview changes without modifying files${NC}"
echo "  bash _scripts/add-copyright.sh --update-year --dry-run"
echo ""

echo -e "${GREEN}🎉 Copyright management system ready!${NC}"
echo ""
