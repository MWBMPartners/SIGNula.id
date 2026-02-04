#!/bin/bash
###############################################################################
# Database Files Reorganization Script
#
# Purpose: Move database files out of web/ directory to project root
#
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
#
# Version: 1.0.0
# Date: February 4, 2026
#
# Usage: bash _scripts/reorganize-database-files.sh
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
echo -e "${BLUE}║        Database Files Reorganization v1.0.0                 ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Confirmation
echo -e "${YELLOW}This script will:${NC}"
echo "  1. Create _database/ directory in project root"
echo "  2. Move all SQL files from web/_database/ to _database/"
echo "  3. Consolidate duplicate migration directories"
echo "  4. Keep complete installation files in _database/"
echo "  5. Remove empty directories from web/_database/"
echo "  6. Update .gitignore"
echo ""
echo -e "${YELLOW}⚠️  This is a structural change to your repository${NC}"
echo ""

read -p "Continue with reorganization? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ Aborted${NC}"
    exit 1
fi

echo ""
echo -e "${BLUE}📦 Creating backup...${NC}"
BACKUP_NAME="database-backup-$(date +%Y%m%d_%H%M%S).tar.gz"
tar -czf "$BACKUP_NAME" web/_database/ 2>/dev/null || true
echo -e "${GREEN}✅ Backup created: $BACKUP_NAME${NC}"
echo ""

# Create directory structure
echo -e "${BLUE}📁 Creating directory structure...${NC}"
mkdir -p _database/migrations
mkdir -p _database/archive
echo -e "${GREEN}✅ Directories created${NC}"
echo ""

# Move files
files_moved=0

echo -e "${BLUE}📂 Moving migration files...${NC}"

# Move from web/_database/migrations/
if [ -d "web/_database/migrations" ]; then
    for file in web/_database/migrations/*.sql; do
        if [ -f "$file" ]; then
            filename=$(basename "$file")
            mv "$file" "_database/migrations/$filename"
            echo -e "   ${GREEN}✓${NC} $filename"
            ((files_moved++))
        fi
    done
fi

# Move from web/_database/_migrations/
if [ -d "web/_database/_migrations" ]; then
    for file in web/_database/_migrations/*.sql; do
        if [ -f "$file" ]; then
            filename=$(basename "$file")
            # Check if file already exists (avoid duplicates)
            if [ ! -f "_database/migrations/$filename" ]; then
                mv "$file" "_database/migrations/$filename"
                echo -e "   ${GREEN}✓${NC} $filename"
                ((files_moved++))
            else
                echo -e "   ${YELLOW}⚠${NC} $filename (already exists, skipped)"
            fi
        fi
    done
fi

echo ""
echo -e "${BLUE}📂 Moving complete installation files...${NC}"

# Move from web/_database/_sql/
if [ -d "web/_database/_sql" ]; then
    for file in web/_database/_sql/*.sql; do
        if [ -f "$file" ]; then
            filename=$(basename "$file")
            mv "$file" "_database/$filename"
            echo -e "   ${GREEN}✓${NC} $filename"
            ((files_moved++))
        fi
    done
fi

# Move standalone email_schema.sql
if [ -f "web/_database/email_schema.sql" ]; then
    mv "web/_database/email_schema.sql" "_database/archive/"
    echo -e "   ${GREEN}✓${NC} email_schema.sql → archive/"
    ((files_moved++))
fi

echo ""
echo -e "${BLUE}🗑️  Cleaning up empty directories...${NC}"

# Remove empty directories
rm -rf web/_database/_migrations 2>/dev/null || true
rm -rf web/_database/_sql 2>/dev/null || true
rm -rf web/_database/migrations 2>/dev/null || true

# Remove web/_database if empty
if [ -d "web/_database" ] && [ -z "$(ls -A web/_database 2>/dev/null)" ]; then
    rmdir web/_database
    echo -e "${GREEN}✅ Removed empty web/_database directory${NC}"
elif [ -d "web/_database" ]; then
    echo -e "${YELLOW}⚠️  web/_database still contains files:${NC}"
    ls -la web/_database/
fi

echo ""
echo -e "${BLUE}📝 Updating .gitignore...${NC}"

# Add _database/ to .gitignore if not already there
if ! grep -q "^_database/\*\.sql$" .gitignore 2>/dev/null; then
    echo "" >> .gitignore
    echo "# Database files (keep structure, ignore generated SQL)" >> .gitignore
    echo "_database/*.sql" >> .gitignore
    echo "!_database/migrations/*.sql" >> .gitignore
    echo -e "${GREEN}✅ Updated .gitignore${NC}"
else
    echo -e "${BLUE}ℹ️  .gitignore already configured${NC}"
fi

echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                  Reorganization Complete!                    ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${GREEN}✅ Moved $files_moved SQL files${NC}"
echo ""

echo -e "${YELLOW}📋 New structure:${NC}"
echo ""
echo "  _database/"
echo "  ├── migrations/              # Individual migration files"
echo "  │   ├── 001_*.sql"
echo "  │   ├── 002_*.sql"
echo "  │   └── ..."
echo "  ├── archive/                 # Old/deprecated files"
echo "  ├── 001_initial_schema.sql   # Historic complete schemas"
echo "  ├── 002_organizations_migration.sql"
echo "  ├── signula_complete_install_v2.0.1.sql"
echo "  ├── signula_email_system_addon_v2.1.0.sql"
echo "  └── signula_complete_install_v2.2.0.sql  # ⭐ TO BE CREATED"
echo ""

echo -e "${GREEN}📦 Backup saved at: $BACKUP_NAME${NC}"
echo ""

echo -e "${YELLOW}⚠️  Next steps:${NC}"
echo "  1. Create signula_complete_install_v2.2.0.sql with current schema"
echo "  2. Update documentation to reference _database/ instead of web/_database/"
echo "  3. Update copyright script paths"
echo "  4. Test database installation"
echo ""

echo -e "${GREEN}🎉 Database files reorganization complete!${NC}"
echo ""
