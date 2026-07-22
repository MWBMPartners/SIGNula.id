#!/bin/bash
###############################################################################
# Build Complete Installation SQL Script
#
# Purpose: Generate signula_complete_install_v2.8.0.sql from base + migrations
#          (all migrations through 045_missing_email_templates.sql)
#
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
#
# Version: 1.4.0
# Date: July 22, 2026
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
OUTPUT_FILE="$DB_DIR/signula_complete_install_v2.8.0.sql"

cd "$PROJECT_ROOT"

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Building Complete Installation SQL v2.8.0              ║${NC}"
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
-- Version: 2.8.0
-- Date: 2026-07-22
-- Description: Complete database schema for SIGNula universal authentication
-- Includes: All features through v2.8.0 (all 45 migrations consolidated)
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
--   ✅ Multi-tier admin system (RBAC, feature toggles, triggers)
--   ✅ Webhooks & payment system (Stripe, PayPal, Coinbase Commerce)
--   ✅ Two-tier payment expansion (invoices, credits, service fees)
--   ✅ Ko-fi & Patreon integration
--   ✅ Enhanced security (CSRF tokens, form protection)
--   ✅ Avatar/profile picture support
--   ✅ Credential reset system with email workflow
--   ✅ Usage tracking and billing
--   ✅ Service tier expansion (custom tiers, usage limits)
--   ✅ Password history and reuse prevention
--   ✅ Account deletion with data export
--   ✅ Notification center
--   ✅ Internationalization (i18n) support
--   ✅ Advanced webhook delivery system
--   ✅ User MFA/WebAuthn/passwordless flag + column alignment fixes
--   ✅ Credential reset composite indexes
--   ✅ Multi-organization / enterprise accounts (domains, members, invitations)
--   ✅ Widened activity-log category enum
--   ✅ JWT authentication (signing keys, revocation, refresh tokens)
--   ✅ OAuth2/OIDC provider mode (client registration, token endpoint, pairwise subjects)
--   ✅ Trusted reverse-proxy allowlist setting
--   ✅ Recurring billing engine foundation (subscriptions, invoices, dunning)
--   ✅ Multi-jurisdiction compliance suite (consent, DSAR, regimes, retention,
--      breach notifications, RoPA register, COPPA age-gate)
--   ✅ Corrected {{double-brace}} email templates (account lockout, email
--      change verification, support tickets, contact form)
--
-- Installation:
--   mysql -u your_username -p your_database < signula_complete_install_v2.8.0.sql
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

# 📦 Base schema lives in archive (superseded by consolidated v2.2.3)
BASE_SCHEMA="$DB_DIR/archive/signula_complete_install_v2.0.1.sql"
if [ ! -f "$BASE_SCHEMA" ]; then
    echo -e "${YELLOW}⚠️  Base schema not found at: $BASE_SCHEMA${NC}"
    echo -e "${YELLOW}   The consolidated v2.2.3 install already exists and does not need rebuilding.${NC}"
    exit 1
fi

# Extract everything from v2.0.1 after the USE statement (skip header and setup)
sed -n '/^USE `signula`;/,$p' "$BASE_SCHEMA" | tail -n +2 >> "$OUTPUT_FILE"

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

# Multi-Tier Admin System (009)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🏢 MULTI-TIER ADMIN SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/009_multi_tier_admin.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 009_multi_tier_admin.sql"
    # Extract ALTER TABLE, CREATE TABLE, INSERT INTO, CREATE INDEX, DELIMITER blocks, CREATE VIEW
    sed -n '/^ALTER TABLE/,/;/p; /^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p; /^CREATE INDEX/p; /^DELIMITER/,/^DELIMITER ;/p; /^CREATE OR REPLACE VIEW/,/;/p' "$DB_DIR/migrations/009_multi_tier_admin.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Webhooks & Payments (010)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 💰 WEBHOOKS & PAYMENT SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/010_webhooks_and_payments.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 010_webhooks_and_payments.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p; /^ALTER TABLE/,/;/p' "$DB_DIR/migrations/010_webhooks_and_payments.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Payment Providers (011)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 💳 PAYMENT PROVIDER INTEGRATION" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/011_payment_providers.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 011_payment_providers.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p; /^ALTER TABLE/,/;/p' "$DB_DIR/migrations/011_payment_providers.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Two-Tier Payment Expansion (012)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🏦 TWO-TIER PAYMENT EXPANSION" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/012_payment_expansion.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 012_payment_expansion.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p; /^ALTER TABLE/,/;/p; /^CREATE EVENT/,/;/p' "$DB_DIR/migrations/012_payment_expansion.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Ko-fi & Patreon (013)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🎨 KO-FI & PATREON INTEGRATION" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/013_kofi_patreon_providers.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 013_kofi_patreon_providers.sql"
    sed -n '/^INSERT INTO/,/;/p; /^ALTER TABLE/,/;/p' "$DB_DIR/migrations/013_kofi_patreon_providers.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Security Enhancements (014)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔒 SECURITY ENHANCEMENTS" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/014_security_enhancements.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 014_security_enhancements.sql"
    sed -n '/^ALTER TABLE/,/;/p; /^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p' "$DB_DIR/migrations/014_security_enhancements.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Form Protection Settings (015)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🛡️  FORM PROTECTION SETTINGS" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/015_form_protection_settings.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 015_form_protection_settings.sql"
    sed -n '/^INSERT INTO/,/;/p; /^ALTER TABLE/,/;/p' "$DB_DIR/migrations/015_form_protection_settings.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Avatar Support (016)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🖼️  AVATAR/PROFILE PICTURE SUPPORT" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/016_avatar_support.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 016_avatar_support.sql"
    sed -n '/^ALTER TABLE/,/;/p' "$DB_DIR/migrations/016_avatar_support.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Credential Reset System (017)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔑 CREDENTIAL RESET SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/017_credential_reset_system.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 017_credential_reset_system.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p' "$DB_DIR/migrations/017_credential_reset_system.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Usage Billing (018)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📊 USAGE TRACKING & BILLING" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/018_usage_billing.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 018_usage_billing.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^ALTER TABLE/,/;/p; /^INSERT INTO/,/;/p' "$DB_DIR/migrations/018_usage_billing.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Tier Expansion (019)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🏆 SERVICE TIER EXPANSION" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/019_tier_expansion.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 019_tier_expansion.sql"
    sed -n '/^ALTER TABLE/,/;/p; /^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p' "$DB_DIR/migrations/019_tier_expansion.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Password History (020)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔐 PASSWORD HISTORY & REUSE PREVENTION" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/020_password_history.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 020_password_history.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p' "$DB_DIR/migrations/020_password_history.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Account Deletion & Export (021)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🗑️  ACCOUNT DELETION WITH DATA EXPORT" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/021_account_deletion_export.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 021_account_deletion_export.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p; /^ALTER TABLE/,/;/p' "$DB_DIR/migrations/021_account_deletion_export.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Notification Center (022)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔔 NOTIFICATION CENTER" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/022_notification_center.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 022_notification_center.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p' "$DB_DIR/migrations/022_notification_center.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Internationalization (023)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🌍 INTERNATIONALIZATION (i18n)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/023_i18n_settings.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 023_i18n_settings.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^INSERT INTO/,/;/p; /^ALTER TABLE/,/;/p' "$DB_DIR/migrations/023_i18n_settings.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Webhook System (024)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🪝 ADVANCED WEBHOOK DELIVERY SYSTEM" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/024_webhook_system.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 024_webhook_system.sql"
    sed -n '/^CREATE TABLE/,/^);/p; /^ALTER TABLE/,/;/p; /^INSERT INTO/,/;/p; /^CREATE INDEX/p' "$DB_DIR/migrations/024_webhook_system.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# ------------------------------------------------------------------------
# Migrations 025-045
# ------------------------------------------------------------------------
# 🔧 Unlike 001-024 (which hand-pick CREATE TABLE/ALTER TABLE/INSERT INTO
#    line ranges via `sed`), migrations 025+ lean heavily on guarded,
#    idempotent patterns — `SET @sql = IF(condition, 'ALTER TABLE ...',
#    'SELECT "skipped"'); PREPARE stmt FROM @sql; EXECUTE stmt;
#    DEALLOCATE PREPARE stmt;` — where the actual DDL lives INSIDE a quoted
#    string, not as a top-level `ALTER TABLE` statement. A `sed` line-range
#    extraction would silently drop that guarded logic (and the safety
#    checks that make each migration idempotent), so migrations 025-045 are
#    appended IN FULL via `cat` instead. Every migration in this range is
#    confirmed multi_query-safe (no `DELIMITER` blocks; 030's single-statement
#    `CREATE EVENT` needs none) and self-contained, so concatenation is safe.
# ------------------------------------------------------------------------

# User Auth Flag Columns (025)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔐 USER AUTHENTICATION FLAG COLUMNS (mfaEnabled/webauthnEnabled/passwordlessEnabled/organizationID)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/025_user_mfa_flags.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 025_user_mfa_flags.sql"
    cat "$DB_DIR/migrations/025_user_mfa_flags.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# tblUserMFA Column Alignment (026)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔐 tblUserMFA COLUMN ALIGNMENT" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/026_user_mfa_columns.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 026_user_mfa_columns.sql"
    cat "$DB_DIR/migrations/026_user_mfa_columns.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Credential Reset Composite Indexes (027)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔐 CREDENTIAL RESET COMPOSITE INDEXES" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/027_credential_reset_indexes.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 027_credential_reset_indexes.sql"
    cat "$DB_DIR/migrations/027_credential_reset_indexes.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Multi-Organization Support (028)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🏢 MULTI-ORGANIZATION / ENTERPRISE ACCOUNT SUPPORT" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/028_organizations.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 028_organizations.sql"
    cat "$DB_DIR/migrations/028_organizations.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Activity Category ENUM Widen (029)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📋 ACTIVITY LOG CATEGORY ENUM WIDEN" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/029_activity_category_enum_widen.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 029_activity_category_enum_widen.sql"
    cat "$DB_DIR/migrations/029_activity_category_enum_widen.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# JWT Authentication (030)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔑 JWT AUTHENTICATION (G-003)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/030_jwt_authentication.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 030_jwt_authentication.sql"
    cat "$DB_DIR/migrations/030_jwt_authentication.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# OAuth2/OIDC Provider - Client Registration (031)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🪪 OAUTH2/OIDC PROVIDER: CLIENT REGISTRATION (G-001 Stage A1)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/031_oauth_provider_clients.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 031_oauth_provider_clients.sql"
    cat "$DB_DIR/migrations/031_oauth_provider_clients.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# OAuth2/OIDC Provider - Token Endpoint (032)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🪪 OAUTH2/OIDC PROVIDER: TOKEN ENDPOINT REPLAY-REVOKE LINKAGE (G-001 Stage A3)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/032_oauth_token_endpoint.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 032_oauth_token_endpoint.sql"
    cat "$DB_DIR/migrations/032_oauth_token_endpoint.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# OAuth2/OIDC Provider - Pairwise Subject Store (033)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🕵️ OAUTH2/OIDC PROVIDER: PAIRWISE-SUBJECT RESOLUTION STORE (G-001 red-team F-01)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/033_oauth_subject_store.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 033_oauth_subject_store.sql"
    cat "$DB_DIR/migrations/033_oauth_subject_store.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Trusted Reverse-Proxy Allowlist (034)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🛡️  TRUSTED REVERSE-PROXY ALLOWLIST SETTING (B-061)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/034_trusted_proxies_setting.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 034_trusted_proxies_setting.sql"
    cat "$DB_DIR/migrations/034_trusted_proxies_setting.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# OAuth Subjects Composite UNIQUE (035)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🕵️ OAUTH2/OIDC PROVIDER: tblOAuthSubjects COMPOSITE UNIQUE (B-062)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/035_oauth_subjects_composite_unique.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 035_oauth_subjects_composite_unique.sql"
    cat "$DB_DIR/migrations/035_oauth_subjects_composite_unique.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Recurring Billing Engine Foundation (036)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 💳 RECURRING BILLING ENGINE FOUNDATION (G-002 Stage S1)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/036_recurring_billing_foundation.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 036_recurring_billing_foundation.sql"
    cat "$DB_DIR/migrations/036_recurring_billing_foundation.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Invoices Subscription Link (037)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🧾 tblInvoices.subscriptionID (G-002 Stage S2)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/037_invoices_subscription_link.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 037_invoices_subscription_link.sql"
    cat "$DB_DIR/migrations/037_invoices_subscription_link.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Payments PaymentMethod Stripe/Manual Fix (038)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 💳 tblPayments.paymentMethod — RESTORE 'stripe' + 'manual' (G-002 S3)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/038_payments_paymentmethod_stripe_manual_fix.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 038_payments_paymentmethod_stripe_manual_fix.sql"
    cat "$DB_DIR/migrations/038_payments_paymentmethod_stripe_manual_fix.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Dunning Retry Ladder (039)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🔔 DUNNING RETRY LADDER (G-002 Stage S4)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/039_dunning_retry_ladder.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 039_dunning_retry_ladder.sql"
    cat "$DB_DIR/migrations/039_dunning_retry_ladder.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Consent Records + DSAR Tracker (040)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📜 CONSENT RECORDS + DATA-SUBJECT-REQUEST TRACKER (G-004 Layer 1a)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/040_consent_and_dsar.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 040_consent_and_dsar.sql"
    cat "$DB_DIR/migrations/040_consent_and_dsar.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Consent Categories + Policy Versions (041)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🍪 CONSENT-MANAGEMENT SURFACE — CATEGORIES + POLICY VERSIONS (G-004 Layer 2)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/041_consent_categories_and_policy_versions.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 041_consent_categories_and_policy_versions.sql"
    cat "$DB_DIR/migrations/041_consent_categories_and_policy_versions.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Data-Driven Compliance Regimes (042)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🌍 DATA-DRIVEN REGIME CONFIGURATION MODEL (G-004 Layer 3)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/042_compliance_regimes.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 042_compliance_regimes.sql"
    cat "$DB_DIR/migrations/042_compliance_regimes.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Retention Policies + Auto-Purge Cron Foundation (043)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🗑️  RETENTION POLICIES + AUTO-PURGE CRON FOUNDATION (G-004 Layer 4a)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/043_retention_policies.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 043_retention_policies.sql"
    cat "$DB_DIR/migrations/043_retention_policies.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Breach Notification + RoPA Register + COPPA Age-Gate (044)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 🚨 BREACH NOTIFICATION + ROPA REGISTER + COPPA AGE-GATE (G-004 Layer 4b)" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/044_breach_ropa_agegate.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 044_breach_ropa_agegate.sql"
    cat "$DB_DIR/migrations/044_breach_ropa_agegate.sql" >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
fi

# Missing (Non-Organization) Email Templates (045)
echo "" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "-- 📧 RECREATE MISSING (NON-ORGANIZATION) EMAIL TEMPLATES" >> "$OUTPUT_FILE"
echo "-- ============================================================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

if [ -f "$DB_DIR/migrations/045_missing_email_templates.sql" ]; then
    echo -e "   ${GREEN}✓${NC} 045_missing_email_templates.sql"
    cat "$DB_DIR/migrations/045_missing_email_templates.sql" >> "$OUTPUT_FILE"
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
    'SIGNula v2.8.0 database installation complete!' AS status,
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
echo -e "${GREEN}✅ Created: signula_complete_install_v2.8.0.sql${NC}"
echo -e "${GREEN}📦 Size: $FILE_SIZE${NC}"
echo ""

echo -e "${YELLOW}📋 Test installation:${NC}"
echo "   mysql -u your_username -p < _database/signula_complete_install_v2.8.0.sql"
echo ""

exit 0
