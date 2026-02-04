#!/bin/bash
###############################################################################
# SSH Key Setup Script for SIGNula SFTP
#
# Purpose: Generate SSH keys and help configure them for SFTP deployment
#
# Version: 2.2.0-beta
# Date: February 4, 2026
#
# Usage: bash _scripts/setup-ssh-key.sh
#
###############################################################################

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║           SIGNula SSH Key Setup v2.2.0                      ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if SSH keys already exist
echo -e "${BLUE}🔍 Checking for existing SSH keys...${NC}"
echo ""

if [ -f "$HOME/.ssh/id_ed25519" ]; then
    echo -e "${GREEN}✅ Ed25519 key found:${NC} ~/.ssh/id_ed25519"
    HAS_ED25519=true
fi

if [ -f "$HOME/.ssh/id_rsa" ]; then
    echo -e "${GREEN}✅ RSA key found:${NC} ~/.ssh/id_rsa"
    HAS_RSA=true
fi

if [ "$HAS_ED25519" = true ] || [ "$HAS_RSA" = true ]; then
    echo ""
    echo -e "${YELLOW}You already have SSH keys!${NC}"
    echo ""
    read -p "Would you like to generate a NEW key anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo ""
        echo -e "${BLUE}📋 Your existing keys:${NC}"
        ls -lh ~/.ssh/id_* 2>/dev/null
        echo ""
        echo -e "${GREEN}To use your existing key, update web/.vscode/sftp.json:${NC}"
        if [ "$HAS_ED25519" = true ]; then
            echo -e '  "privateKeyPath": "~/.ssh/id_ed25519"'
        elif [ "$HAS_RSA" = true ]; then
            echo -e '  "privateKeyPath": "~/.ssh/id_rsa"'
        fi
        echo ""
        echo -e "${YELLOW}Next step: Copy public key to server${NC}"
        if [ "$HAS_ED25519" = true ]; then
            echo -e "${BLUE}cat ~/.ssh/id_ed25519.pub${NC}"
        elif [ "$HAS_RSA" = true ]; then
            echo -e "${BLUE}cat ~/.ssh/id_rsa.pub${NC}"
        fi
        exit 0
    fi
fi

# Ask for email
echo ""
echo -e "${YELLOW}Email address (for key identification):${NC}"
read -p "Email: " EMAIL

if [ -z "$EMAIL" ]; then
    EMAIL="noreply@signulo.id"
fi

# Choose key type
echo ""
echo -e "${YELLOW}Choose SSH key type:${NC}"
echo "  1) Ed25519 (Recommended - modern, secure, fast)"
echo "  2) RSA 4096-bit (For older servers)"
echo ""
read -p "Choice [1]: " KEY_TYPE

if [ -z "$KEY_TYPE" ]; then
    KEY_TYPE=1
fi

# Choose custom name
echo ""
echo -e "${YELLOW}Key filename (leave empty for default):${NC}"
echo "  Default: id_ed25519 or id_rsa"
echo "  Custom example: signula_prod, dreamhost_key, etc."
echo ""
read -p "Filename: " CUSTOM_NAME

if [ -n "$CUSTOM_NAME" ]; then
    KEY_PATH="$HOME/.ssh/$CUSTOM_NAME"
else
    if [ "$KEY_TYPE" = "2" ]; then
        KEY_PATH="$HOME/.ssh/id_rsa"
    else
        KEY_PATH="$HOME/.ssh/id_ed25519"
    fi
fi

# Generate key
echo ""
echo -e "${BLUE}🔐 Generating SSH key...${NC}"
echo ""

if [ "$KEY_TYPE" = "2" ]; then
    ssh-keygen -t rsa -b 4096 -C "$EMAIL" -f "$KEY_PATH"
else
    ssh-keygen -t ed25519 -C "$EMAIL" -f "$KEY_PATH"
fi

if [ $? -ne 0 ]; then
    echo ""
    echo -e "${RED}❌ Key generation failed${NC}"
    exit 1
fi

# Set correct permissions
chmod 600 "$KEY_PATH"
chmod 644 "$KEY_PATH.pub"

echo ""
echo -e "${GREEN}✅ SSH key generated successfully!${NC}"
echo ""

# Display key info
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                    🔑 Your SSH Keys                         ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}Private Key:${NC} $KEY_PATH"
echo -e "  ${YELLOW}⚠️  Keep this SECRET - never share!${NC}"
echo ""
echo -e "${GREEN}Public Key:${NC} $KEY_PATH.pub"
echo -e "  ${BLUE}Safe to upload to server${NC}"
echo ""

# Display public key
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                  📋 Your Public Key                         ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
cat "$KEY_PATH.pub"
echo ""

# Copy to clipboard (Mac only)
if command -v pbcopy &> /dev/null; then
    cat "$KEY_PATH.pub" | pbcopy
    echo -e "${GREEN}✅ Public key copied to clipboard!${NC}"
    echo ""
fi

# Instructions
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                    📋 Next Steps                            ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${YELLOW}1. Copy public key to your server:${NC}"
echo ""
echo "   Option A - Using ssh-copy-id (easiest):"
echo -e "   ${BLUE}ssh-copy-id -i $KEY_PATH.pub username@server.com${NC}"
echo ""
echo "   Option B - Manual copy:"
echo "   a) SSH into server: ${BLUE}ssh username@server.com${NC}"
echo "   b) Run these commands on server:"
echo -e "      ${BLUE}mkdir -p ~/.ssh${NC}"
echo -e "      ${BLUE}chmod 700 ~/.ssh${NC}"
echo -e "      ${BLUE}nano ~/.ssh/authorized_keys${NC}"
echo "   c) Paste your public key (already in clipboard!)"
echo "   d) Save and exit (Ctrl+X, Y, Enter)"
echo -e "      ${BLUE}chmod 600 ~/.ssh/authorized_keys${NC}"
echo ""

echo -e "${YELLOW}2. Update web/.vscode/sftp.json:${NC}"
echo ""
cat << EOF
   {
       "host": "your-server.com",
       "username": "your-username",
       "password": "",
       "privateKeyPath": "$KEY_PATH",
       "passphrase": "your-passphrase-if-you-set-one",
       "remotePath": "/home/username/signulo.id"
   }
EOF
echo ""

echo -e "${YELLOW}3. Test SSH connection:${NC}"
echo -e "   ${BLUE}ssh -i $KEY_PATH username@server.com${NC}"
echo "   ${GREEN}Should login WITHOUT password!${NC}"
echo ""

echo -e "${YELLOW}4. Test VS Code SFTP:${NC}"
echo "   - Open web/ folder in VS Code"
echo "   - Cmd+Shift+P → SFTP: List"
echo "   - Should see remote files!"
echo ""

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                     🔒 Security Tips                        ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo "  ✅ Private key is in: $KEY_PATH"
echo "  ✅ Public key is in: $KEY_PATH.pub"
echo "  ✅ Both have correct permissions"
echo "  ✅ Keys are in ~/.ssh (NOT in your project)"
echo "  ✅ .gitignore prevents accidental commits"
echo ""
echo -e "${YELLOW}⚠️  NEVER share your private key with anyone!${NC}"
echo ""

echo -e "${GREEN}🎉 SSH key setup complete!${NC}"
echo ""
