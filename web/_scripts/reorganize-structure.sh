#!/bin/bash
###############################################################################
# SIGNula Directory Reorganization Script
#
# Purpose: Safely reorganize project structure to place all server-uploadable
#          files in a web/ directory for easier SFTP deployment
#
# Version: 2.2.0-beta
# Date: February 4, 2026
#
# Usage: bash _scripts/reorganize-structure.sh
#
###############################################################################

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

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║      SIGNula Directory Reorganization Script v2.2.0         ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check we're in the right directory
if [ ! -f "$PROJECT_ROOT/PROJECT_PROGRESS.md" ]; then
    echo -e "${RED}❌ Error: Not in SIGNula project root${NC}"
    echo "   Please run from project root: bash _scripts/reorganize-structure.sh"
    exit 1
fi

echo -e "${GREEN}✅ Project root detected:${NC} $PROJECT_ROOT"
echo ""

# Check if web/ already exists
if [ -d "$PROJECT_ROOT/web" ]; then
    echo -e "${YELLOW}⚠️  Warning: web/ directory already exists!${NC}"
    echo ""
    read -p "   Delete existing web/ and start fresh? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}   Removing existing web/ directory...${NC}"
        rm -rf "$PROJECT_ROOT/web"
        echo -e "${GREEN}   ✅ Removed${NC}"
    else
        echo -e "${RED}   ❌ Aborted${NC}"
        exit 1
    fi
    echo ""
fi

# Confirm with user
echo -e "${YELLOW}This script will:${NC}"
echo "  1. Create web/ directory"
echo "  2. Move server-uploadable directories into web/"
echo "  3. Move _includes from public_html to web/"
echo "  4. Update configuration paths"
echo "  5. Create .gitignore entries"
echo ""
echo -e "${YELLOW}⚠️  IMPORTANT: A backup will be created automatically${NC}"
echo ""
read -p "Continue with reorganization? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ Aborted${NC}"
    exit 1
fi

# Create backup
echo ""
echo -e "${BLUE}📦 Creating backup...${NC}"
BACKUP_NAME="SIGNula-backup-$(date +%Y%m%d_%H%M%S).tar.gz"
BACKUP_PATH="$(dirname "$PROJECT_ROOT")/$BACKUP_NAME"

tar -czf "$BACKUP_PATH" -C "$(dirname "$PROJECT_ROOT")" "$(basename "$PROJECT_ROOT")" 2>/dev/null || {
    echo -e "${RED}❌ Backup failed${NC}"
    exit 1
}

echo -e "${GREEN}✅ Backup created:${NC} $BACKUP_PATH"
echo ""

# Create web directory
echo -e "${BLUE}📁 Creating web/ directory structure...${NC}"
mkdir -p "$PROJECT_ROOT/web"
mkdir -p "$PROJECT_ROOT/web/_includes"
mkdir -p "$PROJECT_ROOT/web/_lib"
echo -e "${GREEN}✅ Created web/ directory${NC}"

# Function to move directory safely
move_dir() {
    local src="$1"
    local dst="$2"
    local name="$3"

    if [ -d "$src" ]; then
        echo -e "   Moving ${YELLOW}$name${NC}..."
        mv "$src" "$dst/"
        echo -e "   ${GREEN}✅${NC} $name"
    else
        echo -e "   ${YELLOW}⚠️${NC}  $name not found (skipping)"
    fi
}

# Move directories to web/
echo ""
echo -e "${BLUE}📂 Moving directories to web/...${NC}"

move_dir "$PROJECT_ROOT/_config" "$PROJECT_ROOT/web" "_config"
move_dir "$PROJECT_ROOT/_private" "$PROJECT_ROOT/web" "_private"
move_dir "$PROJECT_ROOT/_database" "$PROJECT_ROOT/web" "_database"
move_dir "$PROJECT_ROOT/_scripts" "$PROJECT_ROOT/web" "_scripts"
move_dir "$PROJECT_ROOT/_sql" "$PROJECT_ROOT/web/_database" "_sql"
move_dir "$PROJECT_ROOT/_migrations" "$PROJECT_ROOT/web/_database" "_migrations"
move_dir "$PROJECT_ROOT/private_html" "$PROJECT_ROOT/web" "private_html"
move_dir "$PROJECT_ROOT/public_html" "$PROJECT_ROOT/web" "public_html"
move_dir "$PROJECT_ROOT/public_html_dev" "$PROJECT_ROOT/web" "public_html_dev"
move_dir "$PROJECT_ROOT/public_html_landing" "$PROJECT_ROOT/web" "public_html_landing"

# Move _includes from public_html to web/ root
echo ""
echo -e "${BLUE}📋 Moving _includes out of public_html...${NC}"
if [ -d "$PROJECT_ROOT/web/public_html/_includes" ]; then
    mv "$PROJECT_ROOT/web/public_html/_includes"/* "$PROJECT_ROOT/web/_includes/" 2>/dev/null || true
    rmdir "$PROJECT_ROOT/web/public_html/_includes" 2>/dev/null || rm -rf "$PROJECT_ROOT/web/public_html/_includes"
    echo -e "${GREEN}✅ Moved _includes to web/_includes${NC}"
else
    echo -e "${YELLOW}⚠️  _includes not found in public_html${NC}"
fi

# Update .gitignore
echo ""
echo -e "${BLUE}📝 Updating .gitignore...${NC}"
if [ -f "$PROJECT_ROOT/.gitignore" ]; then
    # Check if entries already exist
    if ! grep -q "web/sftp.json" "$PROJECT_ROOT/.gitignore"; then
        cat >> "$PROJECT_ROOT/.gitignore" << 'EOF'

# SFTP Configuration (contains credentials)
web/sftp.json
web/.vscode/sftp.json
web/sftp-config.json
web/.vscode/

EOF
        echo -e "${GREEN}✅ Added SFTP entries to .gitignore${NC}"
    else
        echo -e "${YELLOW}⚠️  SFTP entries already in .gitignore${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  .gitignore not found${NC}"
fi

# Create example SFTP config
echo ""
echo -e "${BLUE}📄 Creating example SFTP configuration...${NC}"
mkdir -p "$PROJECT_ROOT/web/.vscode"

cat > "$PROJECT_ROOT/web/.vscode/sftp.json.example" << 'EOF'
{
    "name": "SIGNula Production",
    "host": "your-server.com",
    "protocol": "sftp",
    "port": 22,
    "username": "your-username",
    "password": "",
    "privateKeyPath": "~/.ssh/id_rsa",
    "passphrase": "",
    "remotePath": "/home/username/signulo.id",
    "uploadOnSave": false,
    "useTempFile": false,
    "openSsh": false,
    "ignore": [
        ".vscode",
        ".git",
        ".DS_Store",
        "*.log",
        "sftp.json",
        "sftp-config.json"
    ],
    "watcher": {
        "files": "**/*",
        "autoUpload": false,
        "autoDelete": false
    }
}
EOF

echo -e "${GREEN}✅ Created web/.vscode/sftp.json.example${NC}"
echo -e "${YELLOW}   Copy to sftp.json and configure your server details${NC}"

# Create test script
echo ""
echo -e "${BLUE}🧪 Creating verification test script...${NC}"

cat > "$PROJECT_ROOT/web/public_html/test-reorganization.php" << 'EOFPHP'
<?php
/**
 * Reorganization Verification Test
 *
 * Run this script to verify directory reorganization was successful
 *
 * Usage: php web/public_html/test-reorganization.php
 *    OR: Visit in browser: http://localhost/test-reorganization.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "SIGNula Directory Reorganization Test\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Load configuration
echo "Test 1: Loading configuration...\n";
$configPath = __DIR__ . '/../../_config/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    echo "  ✅ Configuration loaded\n";
} else {
    echo "  ❌ Configuration file not found at: $configPath\n";
    exit(1);
}

// Test 2: Check constants
echo "\nTest 2: Checking path constants...\n";
$constants = [
    'ROOT_DIR' => ROOT_DIR,
    'CONFIG_DIR' => CONFIG_DIR,
    'INCLUDES_DIR' => INCLUDES_DIR,
    'PRIVATE_DIR' => PRIVATE_DIR,
    'PUBLIC_DIR' => PUBLIC_DIR,
    'LOGS_DIR' => LOGS_DIR
];

foreach ($constants as $name => $path) {
    echo "  $name: $path\n";
}

// Test 3: Verify directories exist
echo "\nTest 3: Verifying directories exist...\n";
$checkDirs = [
    'CONFIG_DIR' => CONFIG_DIR,
    'INCLUDES_DIR' => INCLUDES_DIR,
    'PRIVATE_DIR' => PRIVATE_DIR,
    'PUBLIC_DIR' => PUBLIC_DIR
];

$allGood = true;
foreach ($checkDirs as $name => $path) {
    if (is_dir($path)) {
        echo "  ✅ $name exists\n";
    } else {
        echo "  ❌ $name does NOT exist: $path\n";
        $allGood = false;
    }
}

// Test 4: Test critical includes
echo "\nTest 4: Testing critical includes...\n";
$testIncludes = [
    'SecurityUtils' => INCLUDES_DIR . '/security/SecurityUtils.php',
    'ErrorLogger' => INCLUDES_DIR . '/utils/ErrorLogger.php',
    'Response' => INCLUDES_DIR . '/api/Response.php'
];

foreach ($testIncludes as $name => $file) {
    if (file_exists($file)) {
        echo "  ✅ $name found\n";
    } else {
        echo "  ❌ $name NOT found: $file\n";
        $allGood = false;
    }
}

// Final result
echo "\n" . str_repeat("=", 50) . "\n";
if ($allGood) {
    echo "✅ ALL TESTS PASSED - Reorganization successful!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED - Check errors above\n";
    exit(1);
}
EOFPHP

echo -e "${GREEN}✅ Created test-reorganization.php${NC}"

# Summary
echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                  Reorganization Complete!                    ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}✅ New structure created in web/ directory${NC}"
echo ""
echo -e "${YELLOW}📋 Next Steps:${NC}"
echo ""
echo "  1. Run verification test:"
echo "     ${BLUE}php web/public_html/test-reorganization.php${NC}"
echo ""
echo "  2. Test website locally:"
echo "     ${BLUE}Make sure your local server points to web/public_html${NC}"
echo ""
echo "  3. Configure SFTP:"
echo "     ${BLUE}Copy web/.vscode/sftp.json.example to sftp.json${NC}"
echo "     ${BLUE}Edit with your server details${NC}"
echo ""
echo "  4. Deploy to server:"
echo "     ${BLUE}Open web/ folder in VS Code${NC}"
echo "     ${BLUE}Right-click → SFTP: Sync Local → Remote${NC}"
echo ""
echo -e "${GREEN}📦 Backup saved at:${NC}"
echo "   $BACKUP_PATH"
echo ""
echo -e "${YELLOW}⚠️  If anything goes wrong, restore with:${NC}"
echo "   ${BLUE}cd $(dirname "$PROJECT_ROOT")${NC}"
echo "   ${BLUE}rm -rf $(basename "$PROJECT_ROOT")${NC}"
echo "   ${BLUE}tar -xzf $BACKUP_NAME${NC}"
echo ""
echo -e "${GREEN}🎉 Happy deploying!${NC}"
echo ""
