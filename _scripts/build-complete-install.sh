#!/bin/bash
###############################################################################
# Build Complete Installation SQL Script
#
# Purpose: Generate signula_complete_install_v2.2.0.sql from base + migrations
#
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
#
# Version: 1.0.0
# Date: February 4, 2026
#
# Usage: bash _scripts/build-complete-install.sh
#
###############################################################################

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DB_DIR="$PROJECT_ROOT/_database"
OUTPUT_FILE="$DB_DIR/signula_complete_install_v2.2.0.sql"

cd "$PROJECT_ROOT"

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Building Complete Installation SQL v2.2.0              ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Create header
cat > "$OUTPUT_FILE" << 'EOF'
-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- 📁 SIGNula Universal Login System - Complete Installation Script
-- ============================================================================
-- Version: 2.2.0-beta
-- Date: 2026-02-04
-- Description: Complete database schema for SIGNula universal authentication
-- Includes: All features through v2.2.0-beta (RESTful API, rate limiting, partner keys)
--
-- Supports: MySQL 8.0+, MariaDB 10.5+
-- Character Set: utf8mb4 (full Unicode support including emojis)
-- Collation: utf8mb4_unicode_ci (case-insensitive Unicode)
--
-- Features Included:
--   ✅ Core authentication system
--   ✅ Multi-factor authentication (TOTP, WebAuthn/Passkeys)
--   ✅ OAuth 2.0 integration (Google, Microsoft, Apple, etc.)
--   ✅ Multi-account OAuth support
--   ✅ Email system with templates, tracking, A/B testing
--   ✅ Drip campaigns and recurring schedules
--   ✅ Contact form submissions
--   ✅ Blog/news system
--   ✅ Support ticket system
--   ✅ Delegate mailbox support
--   ✅ WebAuthn passkeys
--   ✅ RESTful API with rate limiting
--   ✅ Partner API key management
--
-- Installation:
--   mysql -u your_username -p your_database < signula_complete_install_v2.2.0.sql
--
-- ============================================================================

-- Set session variables
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- 🗄️ DATABASE CREATION
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `signula`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `signula`;

EOF

echo -e "${BLUE}📄 Extracting base schema from v2.0.1...${NC}"

# Extract everything from v2.0.1 after the USE statement (skip header and setup)
sed -n '/^USE `signula`;/,$p' "$DB_DIR/signula_complete_install_v2.0.1.sql" | tail -n +2 >> "$OUTPUT_FILE"

echo -e "${GREEN}✅ Base schema added${NC}"
echo ""

# Add migrations that came after v2.0.1
echo -e "${BLUE}📂 Adding migrations...${NC}"

# Email System Migrations (001-004)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📧 EMAIL SYSTEM ENHANCEMENTS" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

for migration in "001_email_system_upgrade.sql" "002_email_ab_testing.sql" "003_email_drip_campaigns.sql" "004_email_recurring_schedules.sql"; do
    if [ -f "$DB_DIR/migrations/$migration" ]; then
        echo -e "   ${GREEN}✓${NC} $migration"
        # Extract only CREATE TABLE statements and INSERTs (skip comments and headers)
        sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/p' "$DB_DIR/migrations/$migration" >> "$OUTPUT_FILE"
        echo "" >> "$OUTPUT_FILE"
    fi
done

# OAuth Multi-Account (003)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔐 OAUTH MULTI-ACCOUNT SUPPORT" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/003_oauth_multi_account_support.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 003_oauth_multi_account_support.sql"
    sed -n '/^ALTER TABLE/,/;/p' "$DB_DIR/migrations/003_oauth_multi_account_support.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Contact System (004)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📬 CONTACT FORM SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/004_contact_submissions.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 004_contact_submissions.sql"
    sed -n '/^CREATE TABLE/,/^);/p' "$DB_DIR/migrations/004_contact_submissions.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Blog System (005)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📰 BLOG/NEWS SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/005_blog_system.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 005_blog_system.sql"
    sed -n '/^CREATE TABLE/,/^);/p' "$DB_DIR/migrations/005_blog_system.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# WebAuthn Passkeys (005)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔑 WEBAUTHN PASSKEYS" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/005_webauthn_passkeys.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 005_webauthn_passkeys.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^CREATE INDEX/p' "$DB_DIR/migrations/005_webauthn_passkeys.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Delegate Mailbox Support (006)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📮 DELEGATE MAILBOX SUPPORT" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/006_delegate_mailbox_support.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 006_delegate_mailbox_support.sql"
    sed -n '/^ALTER TABLE/,/;/p; /^CREATE TABLE/,/^);/p' "$DB_DIR/migrations/006_delegate_mailbox_support.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Support System (006)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🎫 SUPPORT TICKET SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/006_support_system.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 006_support_system.sql"
    sed -n '/^CREATE TABLE/,/^);/p' "$DB_DIR/migrations/006_support_system.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Rate Limiting (007)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- ⏱️  RATE LIMITING SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/007_rate_limiting.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 007_rate_limiting.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/p' "$DB_DIR/migrations/007_rate_limiting.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Partner API Keys (008)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔐 PARTNER API KEY MANAGEMENT" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/008_partner_api_keys.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 008_partner_api_keys.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^CREATE INDEX/p' "$DB_DIR/migrations/008_partner_api_keys.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Add footer
cat >> "$OUTPUT_FILE" << 'EOF'

-- ============================================================================
-- ✅ INSTALLATION COMPLETE
-- ============================================================================
--
-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify installation
SELECT
    'SIGNula v2.2.0-beta database installation complete!' AS status,
    DATABASE() AS database_name,
    COUNT(*) AS table_count
FROM information_schema.tables
WHERE table_schema = DATABASE();

-- ============================================================================
EOF

echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Build Complete!                                 ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Get file size
FILE_SIZE=$(ls -lh "$OUTPUT_FILE" | awk '{print $5}')
echo -e "${GREEN}✅ Created: signula_complete_install_v2.2.0.sql${NC}"
echo -e "${GREEN}📦 Size: $FILE_SIZE${NC}"
echo ""

echo -e "${YELLOW}📋 Test installation:${NC}"
echo "   mysql -u your_username -p < _database/signula_complete_install_v2.2.0.sql"
echo ""

exit 0
