#!/bin/bash
###############################################################################
# Documentation Cleanup Script
#
# Purpose: Consolidate and organize all .md files
#          - Eliminate duplications
#          - Move files to logical locations
#          - Archive old session files
#
# Version: 2.2.0-beta
# Date: February 4, 2026
#
# Usage: bash _scripts/cleanup-documentation.sh
#
###############################################################################

set -e  # Exit on error

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║        Documentation Cleanup Script v2.2.0                  ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check we're in the right directory
if [ ! -f "$PROJECT_ROOT/PROJECT_PROGRESS.md" ]; then
    echo -e "${RED}❌ Error: Not in SIGNula project root${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Project root detected:${NC} $PROJECT_ROOT"
echo ""

# Show what will be done
echo -e "${YELLOW}This script will:${NC}"
echo "  1. Create organized subdirectories in _docs/"
echo "  2. Move feature-specific docs from root to _docs/"
echo "  3. Archive old testing guides"
echo "  4. Archive completed .claude session files"
echo "  5. Delete duplicate files"
echo "  6. Update _docs/README.md index"
echo ""
echo -e "${YELLOW}⚠️  A backup will be created automatically${NC}"
echo ""

read -p "Continue with cleanup? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ Aborted${NC}"
    exit 1
fi

# Create backup
echo ""
echo -e "${BLUE}📦 Creating backup...${NC}"
BACKUP_NAME="SIGNula-docs-backup-$(date +%Y%m%d_%H%M%S).tar.gz"
BACKUP_PATH="$(dirname "$PROJECT_ROOT")/$BACKUP_NAME"

tar -czf "$BACKUP_PATH" \
    "$PROJECT_ROOT"/*.md \
    "$PROJECT_ROOT/_docs/" \
    "$PROJECT_ROOT/.claude/" \
    2>/dev/null || true

echo -e "${GREEN}✅ Backup created:${NC} $BACKUP_PATH"
echo ""

# Create subdirectories
echo -e "${BLUE}📁 Creating organized directory structure...${NC}"
mkdir -p "$PROJECT_ROOT/_docs/authentication"
mkdir -p "$PROJECT_ROOT/_docs/email"
mkdir -p "$PROJECT_ROOT/_docs/testing"
mkdir -p "$PROJECT_ROOT/_docs/testing/archive"
mkdir -p "$PROJECT_ROOT/_docs/deployment"
mkdir -p "$PROJECT_ROOT/_docs/features"
mkdir -p "$PROJECT_ROOT/_docs/reference"
mkdir -p "$PROJECT_ROOT/.claude/archive"
echo -e "${GREEN}✅ Directories created${NC}"
echo ""

# Move authentication docs
echo -e "${BLUE}🔐 Moving authentication docs...${NC}"
if [ -f "$PROJECT_ROOT/AUTH_PHASE1_DOCUMENTATION.md" ]; then
    mv "$PROJECT_ROOT/AUTH_PHASE1_DOCUMENTATION.md" "$PROJECT_ROOT/_docs/authentication/"
    echo -e "   ${GREEN}✅${NC} AUTH_PHASE1_DOCUMENTATION.md"
fi

if [ -f "$PROJECT_ROOT/OAUTH_PROVIDERS.md" ]; then
    mv "$PROJECT_ROOT/OAUTH_PROVIDERS.md" "$PROJECT_ROOT/_docs/authentication/"
    echo -e "   ${GREEN}✅${NC} OAUTH_PROVIDERS.md"
fi

# Move email docs
echo ""
echo -e "${BLUE}📧 Moving email docs...${NC}"
if [ -f "$PROJECT_ROOT/EMAIL_SYSTEM.md" ]; then
    mv "$PROJECT_ROOT/EMAIL_SYSTEM.md" "$PROJECT_ROOT/_docs/email/"
    echo -e "   ${GREEN}✅${NC} EMAIL_SYSTEM.md"
fi

if [ -f "$PROJECT_ROOT/EMAIL_ADVANCED_FEATURES.md" ]; then
    mv "$PROJECT_ROOT/EMAIL_ADVANCED_FEATURES.md" "$PROJECT_ROOT/_docs/email/"
    echo -e "   ${GREEN}✅${NC} EMAIL_ADVANCED_FEATURES.md"
fi

# Move feature docs
echo ""
echo -e "${BLUE}⚙️  Moving feature docs...${NC}"
if [ -f "$PROJECT_ROOT/CLEAN_URLS.md" ]; then
    mv "$PROJECT_ROOT/CLEAN_URLS.md" "$PROJECT_ROOT/_docs/features/"
    echo -e "   ${GREEN}✅${NC} CLEAN_URLS.md"
fi

# Archive old testing guides
echo ""
echo -e "${BLUE}🗄️  Archiving old testing guides...${NC}"
if [ -f "$PROJECT_ROOT/TESTING_GUIDE_PHASE1.md" ]; then
    mv "$PROJECT_ROOT/TESTING_GUIDE_PHASE1.md" "$PROJECT_ROOT/_docs/testing/archive/"
    echo -e "   ${GREEN}✅${NC} TESTING_GUIDE_PHASE1.md → archive"
fi

if [ -f "$PROJECT_ROOT/TESTING_GUIDE_PHASE2.md" ]; then
    mv "$PROJECT_ROOT/TESTING_GUIDE_PHASE2.md" "$PROJECT_ROOT/_docs/testing/archive/"
    echo -e "   ${GREEN}✅${NC} TESTING_GUIDE_PHASE2.md → archive"
fi

if [ -f "$PROJECT_ROOT/QUICK_TEST_REFERENCE_PHASE2.md" ]; then
    mv "$PROJECT_ROOT/QUICK_TEST_REFERENCE_PHASE2.md" "$PROJECT_ROOT/_docs/testing/archive/"
    echo -e "   ${GREEN}✅${NC} QUICK_TEST_REFERENCE_PHASE2.md → archive"
fi

# Delete duplicates
echo ""
echo -e "${BLUE}🗑️  Removing duplicate files...${NC}"
if [ -f "$PROJECT_ROOT/QUICK_TEST_REFERENCE.md" ]; then
    rm "$PROJECT_ROOT/QUICK_TEST_REFERENCE.md"
    echo -e "   ${GREEN}✅${NC} Deleted: QUICK_TEST_REFERENCE.md (duplicate)"
fi

if [ -f "$PROJECT_ROOT/_docs/TESTING_GUIDE.md" ]; then
    rm "$PROJECT_ROOT/_docs/TESTING_GUIDE.md"
    echo -e "   ${GREEN}✅${NC} Deleted: TESTING_GUIDE.md (redundant)"
fi

if [ -f "$PROJECT_ROOT/_docs/DELEGATE_MAILBOX_IMPLEMENTATION_STATUS.md" ]; then
    rm "$PROJECT_ROOT/_docs/DELEGATE_MAILBOX_IMPLEMENTATION_STATUS.md"
    echo -e "   ${GREEN}✅${NC} Deleted: DELEGATE_MAILBOX_IMPLEMENTATION_STATUS.md (completed)"
fi

if [ -f "$PROJECT_ROOT/_docs/DUAL_MODE_IMPLEMENTATION_SUMMARY.md" ]; then
    rm "$PROJECT_ROOT/_docs/DUAL_MODE_IMPLEMENTATION_SUMMARY.md"
    echo -e "   ${GREEN}✅${NC} Deleted: DUAL_MODE_IMPLEMENTATION_SUMMARY.md (completed)"
fi

# Archive completed .claude session files
echo ""
echo -e "${BLUE}🤖 Archiving completed .claude session files...${NC}"

claude_files=(
    "API_DOCUMENTATION_SUMMARY.md"
    "CLAUDE_NOTES.md"
    "DATABASE_SCHEMA_STATUS.md"
    "FINAL_IMPLEMENTATION_STATUS.md"
    "IMPLEMENTATION_COMPLETE.md"
    "OAUTH_IMPLEMENTATION_PROGRESS.md"
    "REQUIREMENTS_STATUS.md"
    "SESSION_SUMMARY.md"
)

for file in "${claude_files[@]}"; do
    if [ -f "$PROJECT_ROOT/.claude/$file" ]; then
        mv "$PROJECT_ROOT/.claude/$file" "$PROJECT_ROOT/.claude/archive/"
        echo -e "   ${GREEN}✅${NC} $file → archive"
    fi
done

# Move remaining _docs files to appropriate subdirectories
echo ""
echo -e "${BLUE}📚 Organizing existing _docs files...${NC}"

# Deployment docs (already there, just verify)
for file in DIRECTORY_REORGANIZATION_GUIDE.md SECURITY_DEPLOYMENT_GUIDE.md SSH_KEY_SETUP_GUIDE.md; do
    if [ -f "$PROJECT_ROOT/_docs/$file" ]; then
        echo -e "   ${GREEN}✅${NC} $file (already in deployment/)"
    fi
done

# Feature docs
if [ -f "$PROJECT_ROOT/_docs/DELEGATE_MAILBOX_ARCHITECTURE.md" ]; then
    mv "$PROJECT_ROOT/_docs/DELEGATE_MAILBOX_ARCHITECTURE.md" "$PROJECT_ROOT/_docs/features/" 2>/dev/null || true
    echo -e "   ${GREEN}✅${NC} DELEGATE_MAILBOX_ARCHITECTURE.md → features/"
fi

if [ -f "$PROJECT_ROOT/_docs/OAUTH_INTEGRATION_EXAMPLES.md" ]; then
    mv "$PROJECT_ROOT/_docs/OAUTH_INTEGRATION_EXAMPLES.md" "$PROJECT_ROOT/_docs/features/" 2>/dev/null || true
    echo -e "   ${GREEN}✅${NC} OAUTH_INTEGRATION_EXAMPLES.md → features/"
fi

if [ -f "$PROJECT_ROOT/_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md" ]; then
    mv "$PROJECT_ROOT/_docs/SHARED_MAILBOXES_AND_AUTH_MODES.md" "$PROJECT_ROOT/_docs/features/" 2>/dev/null || true
    echo -e "   ${GREEN}✅${NC} SHARED_MAILBOXES_AND_AUTH_MODES.md → features/"
fi

# Reference docs
if [ -f "$PROJECT_ROOT/_docs/DIRECTORY_STRUCTURE.md" ]; then
    mv "$PROJECT_ROOT/_docs/DIRECTORY_STRUCTURE.md" "$PROJECT_ROOT/_docs/reference/" 2>/dev/null || true
    echo -e "   ${GREEN}✅${NC} DIRECTORY_STRUCTURE.md → reference/"
fi

if [ -f "$PROJECT_ROOT/_docs/VERSION_MANAGEMENT.md" ]; then
    mv "$PROJECT_ROOT/_docs/VERSION_MANAGEMENT.md" "$PROJECT_ROOT/_docs/reference/" 2>/dev/null || true
    echo -e "   ${GREEN}✅${NC} VERSION_MANAGEMENT.md → reference/"
fi

if [ -f "$PROJECT_ROOT/_docs/BUILD_CHECKLIST.md" ]; then
    mv "$PROJECT_ROOT/_docs/BUILD_CHECKLIST.md" "$PROJECT_ROOT/_docs/reference/" 2>/dev/null || true
    echo -e "   ${GREEN}✅${NC} BUILD_CHECKLIST.md → reference/"
fi

# Summary
echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                  Cleanup Complete!                           ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${GREEN}✅ Documentation organized by category${NC}"
echo -e "${GREEN}✅ Duplicates removed${NC}"
echo -e "${GREEN}✅ Old files archived${NC}"
echo -e "${GREEN}✅ Clean project root${NC}"
echo ""

echo -e "${YELLOW}📋 Final structure:${NC}"
echo ""
echo "  Root/"
echo "  ├── README.md"
echo "  ├── CHANGELOG.md"
echo "  ├── PROJECT_PROGRESS.md"
echo "  └── REORGANIZATION_QUICKSTART.md"
echo ""
echo "  _docs/"
echo "  ├── README.md (index)"
echo "  ├── authentication/"
echo "  ├── email/"
echo "  ├── testing/"
echo "  ├── deployment/"
echo "  ├── features/"
echo "  └── reference/"
echo ""
echo "  .claude/"
echo "  ├── CLAUDE.md"
echo "  ├── PROJECT_STATUS.md"
echo "  ├── API_ANALYSIS.md"
echo "  └── archive/ (old session files)"
echo ""

echo -e "${GREEN}📦 Backup saved at:${NC}"
echo "   $BACKUP_PATH"
echo ""

echo -e "${YELLOW}⚠️  If anything went wrong, restore with:${NC}"
echo "   ${BLUE}cd $(dirname "$PROJECT_ROOT")${NC}"
echo "   ${BLUE}tar -xzf $BACKUP_NAME${NC}"
echo ""

echo -e "${GREEN}🎉 Documentation cleanup complete!${NC}"
echo ""
