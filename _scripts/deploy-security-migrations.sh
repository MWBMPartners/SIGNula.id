#!/bin/bash
###############################################################################
# Deploy Security Migrations (007 & 008)
#
# Purpose: Deploy rate limiting and API key management migrations
#
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
#
# Version: 1.0.0
# Date: February 4, 2026
#
# Usage: bash _scripts/deploy-security-migrations.sh
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
echo -e "${BLUE}║        Security Migrations Deployment v1.0.0               ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if migrations exist
if [ ! -f "_database/migrations/007_rate_limiting.sql" ]; then
    echo -e "${RED}❌ Error: Migration 007_rate_limiting.sql not found${NC}"
    exit 1
fi

if [ ! -f "_database/migrations/008_partner_api_keys.sql" ]; then
    echo -e "${RED}❌ Error: Migration 008_partner_api_keys.sql not found${NC}"
    exit 1
fi

echo -e "${YELLOW}This script will deploy:${NC}"
echo "  1. Migration 007: Rate Limiting System"
echo "  2. Migration 008: Partner API Key Management"
echo ""
echo -e "${YELLOW}⚠️  Prerequisites:${NC}"
echo "  - Database backup recommended"
echo "  - MySQL/MariaDB credentials required"
echo ""

# Prompt for database credentials
read -p "Database host (default: localhost): " DB_HOST
DB_HOST=${DB_HOST:-localhost}

read -p "Database name (default: signula): " DB_NAME
DB_NAME=${DB_NAME:-signula}

read -p "Database user: " DB_USER

if [ -z "$DB_USER" ]; then
    echo -e "${RED}❌ Database user is required${NC}"
    exit 1
fi

read -sp "Database password: " DB_PASS
echo ""
echo ""

# Test database connection
echo -e "${BLUE}🔌 Testing database connection...${NC}"
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "USE $DB_NAME; SELECT 1;" > /dev/null 2>&1

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ Failed to connect to database${NC}"
    echo "Please check your credentials and try again."
    exit 1
fi

echo -e "${GREEN}✅ Database connection successful${NC}"
echo ""

# Check if migrations already applied
echo -e "${BLUE}🔍 Checking migration status...${NC}"

RATE_LIMIT_TABLE=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -se "SHOW TABLES LIKE 'tblRateLimits';" 2>/dev/null)
API_KEY_TABLE=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -se "SHOW TABLES LIKE 'tblAPIKeys';" 2>/dev/null)

MIGRATION_007_APPLIED=false
MIGRATION_008_APPLIED=false

if [ -n "$RATE_LIMIT_TABLE" ]; then
    echo -e "   ${YELLOW}⚠${NC} Migration 007 appears to be already applied (tblRateLimits exists)"
    MIGRATION_007_APPLIED=true
fi

if [ -n "$API_KEY_TABLE" ]; then
    echo -e "   ${YELLOW}⚠${NC} Migration 008 appears to be already applied (tblAPIKeys exists)"
    MIGRATION_008_APPLIED=true
fi

if [ "$MIGRATION_007_APPLIED" = true ] && [ "$MIGRATION_008_APPLIED" = true ]; then
    echo ""
    echo -e "${YELLOW}⚠️  Both migrations appear to be already applied.${NC}"
    read -p "Do you want to continue anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${BLUE}ℹ️  Deployment cancelled${NC}"
        exit 0
    fi
fi

echo ""

# Offer to create backup
read -p "Create database backup before deployment? (Y/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    echo -e "${BLUE}📦 Creating backup...${NC}"
    BACKUP_FILE="backup_before_security_$(date +%Y%m%d_%H%M%S).sql"
    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null

    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Backup created: $BACKUP_FILE${NC}"
    else
        echo -e "${RED}❌ Backup failed${NC}"
        read -p "Continue without backup? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
    echo ""
fi

###############################################################################
# Deploy Migration 007: Rate Limiting
###############################################################################

if [ "$MIGRATION_007_APPLIED" = false ]; then
    echo -e "${BLUE}📂 Deploying Migration 007: Rate Limiting...${NC}"

    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < _database/migrations/007_rate_limiting.sql

    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Migration 007 deployed successfully${NC}"

        # Verify
        echo -e "${BLUE}   Verifying...${NC}"
        CONFIG_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -se "SELECT COUNT(*) FROM tblRateLimitConfig;" 2>/dev/null)
        SETTINGS_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -se "SELECT COUNT(*) FROM tblSettings WHERE settingKey LIKE 'rate_limiting.%';" 2>/dev/null)

        echo -e "   ${GREEN}✓${NC} Rate limit configurations: $CONFIG_COUNT (expected: 13)"
        echo -e "   ${GREEN}✓${NC} Rate limit settings: $SETTINGS_COUNT (expected: 5)"
    else
        echo -e "${RED}❌ Migration 007 failed${NC}"
        exit 1
    fi
    echo ""
else
    echo -e "${YELLOW}⏭️  Skipping Migration 007 (already applied)${NC}"
    echo ""
fi

###############################################################################
# Deploy Migration 008: Partner API Keys
###############################################################################

if [ "$MIGRATION_008_APPLIED" = false ]; then
    echo -e "${BLUE}📂 Deploying Migration 008: Partner API Keys...${NC}"

    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < _database/migrations/008_partner_api_keys.sql

    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Migration 008 deployed successfully${NC}"

        # Verify
        echo -e "${BLUE}   Verifying...${NC}"
        PARTNER_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -se "SELECT COUNT(*) FROM tblPartners;" 2>/dev/null)
        SETTINGS_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -se "SELECT COUNT(*) FROM tblSettings WHERE settingKey LIKE 'api_keys.%';" 2>/dev/null)

        echo -e "   ${GREEN}✓${NC} Test partners created: $PARTNER_COUNT (expected: 1)"
        echo -e "   ${GREEN}✓${NC} API key settings: $SETTINGS_COUNT (expected: 11)"
    else
        echo -e "${RED}❌ Migration 008 failed${NC}"
        exit 1
    fi
    echo ""
else
    echo -e "${YELLOW}⏭️  Skipping Migration 008 (already applied)${NC}"
    echo ""
fi

###############################################################################
# Deployment Summary
###############################################################################

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Deployment Complete!                            ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${GREEN}✅ Security migrations deployed successfully${NC}"
echo ""

echo -e "${YELLOW}📋 Next Steps:${NC}"
echo ""
echo "1. Generate a test API key:"
echo "   ${BLUE}php _scripts/generate_test_api_key.php${NC}"
echo ""
echo "2. Test rate limiting:"
echo "   See: _docs/SECURITY_DEPLOYMENT_GUIDE.md (Step 3)"
echo ""
echo "3. Test API key authentication:"
echo "   See: _docs/SECURITY_DEPLOYMENT_GUIDE.md (Step 4)"
echo ""
echo "4. Monitor usage:"
echo "   ${BLUE}SELECT * FROM tblRateLimits ORDER BY createdAt DESC LIMIT 10;${NC}"
echo "   ${BLUE}SELECT * FROM tblAPIKeyUsage ORDER BY usedAt DESC LIMIT 10;${NC}"
echo ""

echo -e "${GREEN}🎉 Security enhancements are now active!${NC}"
echo ""

exit 0
