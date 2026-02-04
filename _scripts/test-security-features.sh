#!/bin/bash
###############################################################################
# Test Security Features (Rate Limiting & API Keys)
#
# Purpose: Automated testing of security enhancements
#
# Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
#
# Version: 1.0.0
# Date: February 4, 2026
#
# Usage: bash _scripts/test-security-features.sh [api_base_url]
#        Example: bash _scripts/test-security-features.sh http://localhost/signula/api/v1
#
###############################################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
API_BASE_URL="${1:-http://localhost/signula/public_html/api/v1}"
TEST_API_KEY=""  # Will be prompted if needed

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║           Security Features Testing v1.0.0                  ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${BLUE}API Base URL:${NC} $API_BASE_URL"
echo ""

# Check if curl is available
if ! command -v curl &> /dev/null; then
    echo -e "${RED}❌ curl is required but not installed${NC}"
    exit 1
fi

echo -e "${GREEN}✅ curl is available${NC}"
echo ""

###############################################################################
# Test 1: Rate Limiting (Unauthenticated)
###############################################################################

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  Test 1: Rate Limiting (Default IP-based)                   ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${YELLOW}Testing default IP rate limits (100/hour, 10/min, 20/burst)...${NC}"
echo ""

# Make 5 quick requests to test rate limiting
for i in {1..5}; do
    echo -e "${BLUE}Request $i/5:${NC}"

    RESPONSE=$(curl -s -w "\n%{http_code}\n%{header_json}" \
        -H "Content-Type: application/json" \
        "$API_BASE_URL/health" 2>&1)

    HTTP_CODE=$(echo "$RESPONSE" | tail -n 2 | head -n 1)

    if [ "$HTTP_CODE" = "200" ]; then
        echo -e "   ${GREEN}✓${NC} Status: 200 OK"
        # Try to extract rate limit headers (if response includes them)
        LIMIT=$(echo "$RESPONSE" | grep -i "x-ratelimit-limit" | cut -d'"' -f4 2>/dev/null || echo "N/A")
        REMAINING=$(echo "$RESPONSE" | grep -i "x-ratelimit-remaining" | cut -d'"' -f4 2>/dev/null || echo "N/A")
        echo -e "   ${BLUE}ℹ${NC}  Limit: $LIMIT, Remaining: $REMAINING"
    elif [ "$HTTP_CODE" = "429" ]; then
        echo -e "   ${YELLOW}⚠${NC}  Status: 429 Too Many Requests (Rate Limited)"
        echo -e "   ${GREEN}✓${NC} Rate limiting is working!"
        break
    else
        echo -e "   ${RED}✗${NC} Status: $HTTP_CODE"
    fi

    sleep 0.2
done

echo ""

###############################################################################
# Test 2: API Key Authentication
###############################################################################

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  Test 2: API Key Authentication                              ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

read -p "Do you have a test API key to test? (y/N): " -n 1 -r
echo
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    read -p "Enter your test API key (sk_test_xxx): " TEST_API_KEY

    if [ -z "$TEST_API_KEY" ]; then
        echo -e "${YELLOW}⏭️  Skipping API key tests${NC}"
    else
        echo ""
        echo -e "${YELLOW}Testing API key authentication...${NC}"
        echo ""

        # Test 2a: Valid API Key
        echo -e "${BLUE}Test 2a: Valid API Key${NC}"
        RESPONSE=$(curl -s -w "\n%{http_code}" \
            -H "X-API-Key: $TEST_API_KEY" \
            -H "Content-Type: application/json" \
            "$API_BASE_URL/health" 2>&1)

        HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

        if [ "$HTTP_CODE" = "200" ]; then
            echo -e "   ${GREEN}✓${NC} Valid API key accepted (Status: 200)"
        else
            echo -e "   ${YELLOW}⚠${NC}  Status: $HTTP_CODE"
            echo -e "   ${BLUE}ℹ${NC}  Response: $(echo "$RESPONSE" | head -n -1)"
        fi
        echo ""

        # Test 2b: Invalid API Key
        echo -e "${BLUE}Test 2b: Invalid API Key${NC}"
        RESPONSE=$(curl -s -w "\n%{http_code}" \
            -H "X-API-Key: sk_test_invalid_key_12345" \
            -H "Content-Type: application/json" \
            "$API_BASE_URL/health" 2>&1)

        HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

        if [ "$HTTP_CODE" = "401" ]; then
            echo -e "   ${GREEN}✓${NC} Invalid API key rejected (Status: 401)"
        elif [ "$HTTP_CODE" = "200" ]; then
            echo -e "   ${YELLOW}⚠${NC}  Invalid key was accepted - middleware may not be active"
        else
            echo -e "   ${YELLOW}⚠${NC}  Status: $HTTP_CODE"
        fi
        echo ""

        # Test 2c: API Key Rate Limits
        echo -e "${BLUE}Test 2c: API Key Rate Limits${NC}"
        echo -e "${YELLOW}Making 10 rapid requests with API key...${NC}"

        SUCCESS_COUNT=0
        RATE_LIMITED=false

        for i in {1..10}; do
            RESPONSE=$(curl -s -w "\n%{http_code}" \
                -H "X-API-Key: $TEST_API_KEY" \
                -H "Content-Type: application/json" \
                "$API_BASE_URL/health" 2>&1)

            HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

            if [ "$HTTP_CODE" = "200" ]; then
                ((SUCCESS_COUNT++))
            elif [ "$HTTP_CODE" = "429" ]; then
                RATE_LIMITED=true
                echo -e "   ${YELLOW}⚠${NC}  Rate limited at request $i"
                break
            fi
        done

        echo -e "   ${BLUE}ℹ${NC}  Successful requests: $SUCCESS_COUNT/10"

        if [ "$RATE_LIMITED" = true ]; then
            echo -e "   ${GREEN}✓${NC} API key rate limiting is working"
        else
            echo -e "   ${GREEN}✓${NC} All 10 requests succeeded (within rate limit)"
        fi
        echo ""
    fi
else
    echo -e "${YELLOW}⏭️  Skipping API key tests${NC}"
    echo -e "${BLUE}ℹ${NC}  To generate a test API key, run:${NC}"
    echo "   php _scripts/generate_test_api_key.php"
    echo ""
fi

###############################################################################
# Test 3: Progressive Blocking
###############################################################################

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  Test 3: Progressive Blocking (Optional)                     ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

read -p "Test progressive blocking (requires many requests)? (y/N): " -n 1 -r
echo
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}Making 25 rapid requests to trigger rate limiting...${NC}"
    echo -e "${BLUE}(This will test if progressive blocking escalates)${NC}"
    echo ""

    BLOCKED=false
    BLOCK_REQUEST=0

    for i in {1..25}; do
        RESPONSE=$(curl -s -w "\n%{http_code}" \
            -H "Content-Type: application/json" \
            "$API_BASE_URL/health" 2>&1)

        HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

        if [ "$HTTP_CODE" = "429" ] && [ "$BLOCKED" = false ]; then
            BLOCKED=true
            BLOCK_REQUEST=$i
            echo -e "   ${YELLOW}⚠${NC}  Rate limited at request $i"
        fi

        # Brief pause
        sleep 0.1
    done

    if [ "$BLOCKED" = true ]; then
        echo ""
        echo -e "   ${GREEN}✓${NC} Progressive blocking triggered after $BLOCK_REQUEST requests"
        echo -e "   ${BLUE}ℹ${NC}  Check database for block records:"
        echo "      SELECT * FROM tblRateLimits WHERE identifier LIKE '%your_ip%' ORDER BY createdAt DESC;"
    else
        echo -e "   ${YELLOW}⚠${NC}  Did not trigger rate limit in 25 requests"
        echo -e "   ${BLUE}ℹ${NC}  Default limit is 100/hour, 10/min, 20/burst"
    fi
    echo ""
else
    echo -e "${YELLOW}⏭️  Skipping progressive blocking test${NC}"
    echo ""
fi

###############################################################################
# Test 4: Endpoint-Specific Limits
###############################################################################

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  Test 4: Endpoint-Specific Limits (Login Protection)        ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${YELLOW}Testing strict limits on /api/v1/auth/login endpoint...${NC}"
echo -e "${BLUE}(Limits: 20/hour, 5/min, 10/burst)${NC}"
echo ""

read -p "Test login endpoint rate limiting? (y/N): " -n 1 -r
echo
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    SUCCESS_COUNT=0

    for i in {1..12}; do
        RESPONSE=$(curl -s -w "\n%{http_code}" \
            -X POST \
            -H "Content-Type: application/json" \
            -d '{"email":"test@example.com","password":"wrongpassword"}' \
            "$API_BASE_URL/auth/login" 2>&1)

        HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

        if [ "$HTTP_CODE" = "429" ]; then
            echo -e "   ${YELLOW}⚠${NC}  Rate limited at request $i"
            echo -e "   ${GREEN}✓${NC} Login endpoint rate limiting is active"
            break
        elif [ "$HTTP_CODE" = "401" ] || [ "$HTTP_CODE" = "400" ]; then
            ((SUCCESS_COUNT++))
        fi

        sleep 0.2
    done

    if [ $SUCCESS_COUNT -eq 12 ]; then
        echo -e "   ${BLUE}ℹ${NC}  All 12 requests processed (within rate limit)"
    fi
    echo ""
else
    echo -e "${YELLOW}⏭️  Skipping endpoint-specific tests${NC}"
    echo ""
fi

###############################################################################
# Summary
###############################################################################

echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                    Testing Complete                          ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${GREEN}✅ Basic security testing completed${NC}"
echo ""

echo -e "${YELLOW}📊 Manual Verification Queries:${NC}"
echo ""
echo "1. Check rate limit records:"
echo "   ${BLUE}SELECT * FROM tblRateLimits ORDER BY createdAt DESC LIMIT 10;${NC}"
echo ""
echo "2. Check API key usage:"
echo "   ${BLUE}SELECT * FROM tblAPIKeyUsage ORDER BY usedAt DESC LIMIT 10;${NC}"
echo ""
echo "3. Check blocked IPs:"
echo "   ${BLUE}SELECT * FROM tblRateLimits WHERE isBlocked = 1;${NC}"
echo ""
echo "4. View rate limit configuration:"
echo "   ${BLUE}SELECT * FROM tblRateLimitConfig;${NC}"
echo ""

echo -e "${YELLOW}📚 For comprehensive testing, see:${NC}"
echo "   _docs/SECURITY_DEPLOYMENT_GUIDE.md"
echo ""

exit 0
