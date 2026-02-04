#!/bin/bash
###############################################################################
# Copyright Header Management Script
#
# Purpose: Add or update copyright headers in all project files
#
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
#
# Version: 1.0.0
# Date: February 4, 2026
#
# Usage: bash _scripts/add-copyright.sh [--update-year] [--dry-run]
#
###############################################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get current year
CURRENT_YEAR=$(date +%Y)
START_YEAR="2025"

# Copyright text
COMPANY_NAME="MWBM Partners Ltd (t/a MWservices)"
COPYRIGHT_YEAR_RANGE="${START_YEAR}-${CURRENT_YEAR}"
if [ "$START_YEAR" = "$CURRENT_YEAR" ]; then
    COPYRIGHT_YEAR_RANGE="$START_YEAR"
fi

# Options
UPDATE_YEAR=false
DRY_RUN=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --update-year)
            UPDATE_YEAR=true
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --update-year    Update copyright year range to current year"
            echo "  --dry-run        Show what would be changed without modifying files"
            echo "  --help           Show this help message"
            exit 0
            ;;
    esac
done

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║        Copyright Header Management v1.0.0                   ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}Company:${NC} $COMPANY_NAME"
echo -e "${GREEN}Copyright Years:${NC} $COPYRIGHT_YEAR_RANGE"
if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}Mode: DRY RUN (no changes will be made)${NC}"
fi
echo ""

# Counter
files_updated=0
files_skipped=0
files_added=0

###############################################################################
# Function: Update PHP Copyright Header
###############################################################################
update_php_copyright() {
    local file="$1"

    # Check if file already has copyright
    if grep -q "Copyright © .*$COMPANY_NAME" "$file"; then
        if [ "$UPDATE_YEAR" = true ]; then
            # Update year range
            if [ "$DRY_RUN" = false ]; then
                sed -i.bak "s/Copyright © [0-9]\{4\}\(-[0-9]\{4\}\)\? $COMPANY_NAME/Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME/" "$file"
                rm "${file}.bak"
            fi
            echo -e "   ${YELLOW}↻${NC} $(basename "$file") - Updated year"
            ((files_updated++))
        else
            echo -e "   ${GREEN}✓${NC} $(basename "$file") - Already has copyright"
            ((files_skipped++))
        fi
    else
        # Add new copyright header
        if [ "$DRY_RUN" = false ]; then
            # Read first line
            first_line=$(head -n 1 "$file")

            # Create new header
            if [[ "$first_line" == "<?php"* ]]; then
                # PHP file with <?php tag
                {
                    echo '<?php'
                    echo '/**'
                    echo ' * SIGNula - Universal Single Sign-On Authentication System'
                    echo ' * '
                    echo " * Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME. All rights reserved."
                    echo ' * '
                    echo ' * This software is proprietary and confidential. Unauthorized copying,'
                    echo ' * distribution, or use is strictly prohibited.'
                    echo ' * '
                    echo ' * @package SIGNula'
                    echo ' * @version 2.2.0-beta'
                    echo ' */'
                    echo ''
                    tail -n +2 "$file"
                } > "${file}.tmp" && mv "${file}.tmp" "$file"
            fi
        fi
        echo -e "   ${GREEN}+${NC} $(basename "$file") - Added copyright"
        ((files_added++))
    fi
}

###############################################################################
# Function: Update Markdown Copyright Footer
###############################################################################
update_markdown_copyright() {
    local file="$1"

    # Check if file already has copyright
    if grep -q "Copyright © .*$COMPANY_NAME" "$file"; then
        if [ "$UPDATE_YEAR" = true ]; then
            # Update year range
            if [ "$DRY_RUN" = false ]; then
                sed -i.bak "s/Copyright © [0-9]\{4\}\(-[0-9]\{4\}\)\? $COMPANY_NAME/Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME/" "$file"
                rm "${file}.bak"
            fi
            echo -e "   ${YELLOW}↻${NC} $(basename "$file") - Updated year"
            ((files_updated++))
        else
            echo -e "   ${GREEN}✓${NC} $(basename "$file") - Already has copyright"
            ((files_skipped++))
        fi
    else
        # Add copyright footer
        if [ "$DRY_RUN" = false ]; then
            {
                cat "$file"
                echo ''
                echo '---'
                echo ''
                echo "**Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME. All rights reserved.**"
                echo ''
                echo 'This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.'
            } > "${file}.tmp" && mv "${file}.tmp" "$file"
        fi
        echo -e "   ${GREEN}+${NC} $(basename "$file") - Added copyright"
        ((files_added++))
    fi
}

###############################################################################
# Function: Update SQL Copyright Header
###############################################################################
update_sql_copyright() {
    local file="$1"

    # Check if file already has copyright
    if grep -q "Copyright © .*$COMPANY_NAME" "$file"; then
        if [ "$UPDATE_YEAR" = true ]; then
            # Update year range
            if [ "$DRY_RUN" = false ]; then
                sed -i.bak "s/Copyright © [0-9]\{4\}\(-[0-9]\{4\}\)\? $COMPANY_NAME/Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME/" "$file"
                rm "${file}.bak"
            fi
            echo -e "   ${YELLOW}↻${NC} $(basename "$file") - Updated year"
            ((files_updated++))
        else
            echo -e "   ${GREEN}✓${NC} $(basename "$file") - Already has copyright"
            ((files_skipped++))
        fi
    else
        # Add new copyright header
        if [ "$DRY_RUN" = false ]; then
            {
                echo '-- ============================================================================'
                echo '-- SIGNula Database Schema'
                echo '-- '
                echo "-- Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME. All rights reserved."
                echo '-- '
                echo '-- This software is proprietary and confidential. Unauthorized copying,'
                echo '-- distribution, or use is strictly prohibited.'
                echo '-- ============================================================================'
                echo ''
                cat "$file"
            } > "${file}.tmp" && mv "${file}.tmp" "$file"
        fi
        echo -e "   ${GREEN}+${NC} $(basename "$file") - Added copyright"
        ((files_added++))
    fi
}

###############################################################################
# Function: Update JavaScript Copyright Header
###############################################################################
update_js_copyright() {
    local file="$1"

    # Check if file already has copyright
    if grep -q "Copyright © .*$COMPANY_NAME" "$file"; then
        if [ "$UPDATE_YEAR" = true ]; then
            if [ "$DRY_RUN" = false ]; then
                sed -i.bak "s/Copyright © [0-9]\{4\}\(-[0-9]\{4\}\)\? $COMPANY_NAME/Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME/" "$file"
                rm "${file}.bak"
            fi
            echo -e "   ${YELLOW}↻${NC} $(basename "$file") - Updated year"
            ((files_updated++))
        else
            echo -e "   ${GREEN}✓${NC} $(basename "$file") - Already has copyright"
            ((files_skipped++))
        fi
    else
        if [ "$DRY_RUN" = false ]; then
            {
                echo '/**'
                echo ' * SIGNula - Universal Single Sign-On Authentication System'
                echo ' * '
                echo " * Copyright © $COPYRIGHT_YEAR_RANGE $COMPANY_NAME. All rights reserved."
                echo ' * '
                echo ' * This software is proprietary and confidential. Unauthorized copying,'
                echo ' * distribution, or use is strictly prohibited.'
                echo ' */'
                echo ''
                cat "$file"
            } > "${file}.tmp" && mv "${file}.tmp" "$file"
        fi
        echo -e "   ${GREEN}+${NC} $(basename "$file") - Added copyright"
        ((files_added++))
    fi
}

###############################################################################
# Process PHP Files
###############################################################################
echo -e "${BLUE}Processing PHP files...${NC}"
find web/private_html web/public_html web/_config web/_includes -type f -name "*.php" 2>/dev/null | while read -r file; do
    update_php_copyright "$file"
done

###############################################################################
# Process Markdown Files
###############################################################################
echo ""
echo -e "${BLUE}Processing Markdown files...${NC}"
find . -maxdepth 1 -name "*.md" -type f | while read -r file; do
    update_markdown_copyright "$file"
done

find _docs -type f -name "*.md" 2>/dev/null | while read -r file; do
    update_markdown_copyright "$file"
done

###############################################################################
# Process SQL Files
###############################################################################
echo ""
echo -e "${BLUE}Processing SQL files...${NC}"
find _database/migrations _database -maxdepth 1 -type f -name "*.sql" 2>/dev/null | while read -r file; do
    update_sql_copyright "$file"
done

###############################################################################
# Process JavaScript Files
###############################################################################
echo ""
echo -e "${BLUE}Processing JavaScript files...${NC}"
find web/public_html/assets/js -type f -name "*.js" 2>/dev/null | while read -r file; do
    update_js_copyright "$file"
done

###############################################################################
# Summary
###############################################################################
echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                      Summary                                 ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}Files with copyright added:${NC} $files_added"
echo -e "${YELLOW}Files with copyright updated:${NC} $files_updated"
echo -e "${BLUE}Files already up-to-date:${NC} $files_skipped"
echo ""

if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}⚠️  DRY RUN MODE - No files were actually modified${NC}"
    echo -e "${YELLOW}Run without --dry-run to apply changes${NC}"
    echo ""
fi

echo -e "${GREEN}✅ Copyright header management complete!${NC}"
echo ""
