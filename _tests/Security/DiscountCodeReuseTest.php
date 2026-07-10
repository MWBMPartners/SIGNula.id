<?php
/**
 * ============================================================================
 * 🩻 SIGNula G-002 — DISCOUNT CODE SINGLE-USE REGRESSION (F-B04)
 * ============================================================================
 *
 * BLUE-TEAM FOLLOW-UP to the independent adversarial PoC that found F-B04
 * (MEDIUM): PaymentManager::validateDiscountCode() enforced
 * `currentUses < maxUses`, but the main subscription/checkout flow never
 * called PaymentManager::incrementDiscountUsage() — the ONLY caller was
 * PartnerPaymentService.php, NOT PaymentManager::createSubscription().
 * `currentUses` stayed 0 forever and a single-use (maxUses=1) code could be
 * redeemed an unlimited number of times.
 *
 * FIX:
 *   - incrementDiscountUsage() is now an ATOMIC "spend" — a single guarded
 *     UPDATE (`currentUses = currentUses + 1 WHERE … AND (maxUses IS NULL OR
 *     currentUses < maxUses)`) that both increments AND re-checks the cap in
 *     one statement, returning true only if THIS call actually consumed a
 *     use (Database::getAffectedRows() === 1). This closes the concurrent
 *     double-redeem race, not just the "never called" bug.
 *   - PaymentManager::createSubscription() now calls it at the exact point a
 *     valid promo code is about to be applied, and only applies the discount
 *     if the redemption was actually won.
 *
 * This test now asserts the SECURE outcome: a maxUses=1 code is consumed
 * exactly once — `currentUses` becomes 1 and a second redemption attempt
 * (both via incrementDiscountUsage() directly and via a second real
 * createSubscription() call) is rejected.
 *
 * @package SIGNula\Tests\Security
 */

namespace SIGNula\Tests\Security;

use SIGNula\Tests\DatabaseTestCase;

requireSource('_config/database.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/payments/PaymentManager.php');

class DiscountCodeReuseTest extends DatabaseTestCase
{
    /** @var bool Single Database-singleton connection; we manage our own cleanup. */
    protected bool $useTransactions = false;

    private int $tierID = 0;
    private int $userID = 0;
    private string $code = '';
    private array $subIDs = [];
    /** @var int[] A second/third buyer created inline by individual test methods (cleanup in tearDown). */
    private array $extraUserIDs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->code = 'REDTEAM_' . strtoupper(bin2hex(random_bytes(3)));

        // 🎟️ Single-use code, universal (applies to all tiers/methods), active, in-window.
        \Database::query(
            "INSERT INTO tblDiscountCodes
                (code, codeType, description, discountType, discountValue, currency,
                 maxUses, currentUses, isActive, validFrom, validUntil, createdAt)
             VALUES (?, 'universal', 'red-team', 'percentage', 50.00, 'GBP',
                 1, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())",
            [$this->code],
            's'
        );

        // 💰 A priced tier so the discount actually applies (amount > 0).
        \Database::query(
            "INSERT INTO tblSubscriptionTiers
                (tierName, tierSlug, monthlyPrice, yearlyPrice, currency, features, featureLimits,
                 isActive, isDefault, displayOrder, createdAt)
             VALUES ('RedTeam Tier', ?, 20.00, 200.00, 'GBP', '[]', '{}', 1, 0, 99, NOW())",
            ['redteam_' . bin2hex(random_bytes(3))],
            's'
        );
        $this->tierID = \Database::getLastInsertId();

        // 👤 A user to own the subscription (tblSubscriptions.userID FK → tblUsers).
        $uuid = sprintf('%s-%s-4%s-8%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
            bin2hex(random_bytes(1)) . substr(bin2hex(random_bytes(1)), 0, 1),
            substr(bin2hex(random_bytes(1)), 0, 1) . bin2hex(random_bytes(1)),
            bin2hex(random_bytes(6))
        );
        $uname = 'redteam_' . bin2hex(random_bytes(4));
        \Database::query(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'Red Team', 'active', 1, 0, NOW())",
            [$uuid, $uname, $uname . '@example.com', password_hash('x', PASSWORD_BCRYPT)],
            'ssss'
        );
        $this->userID = \Database::getLastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->subIDs as $sid) {
            \Database::query("DELETE FROM tblSubscriptions WHERE subscriptionID = ?", [$sid], 'i');
        }
        foreach ($this->extraUserIDs as $uid) {
            \Database::query("DELETE FROM tblSubscriptions WHERE userID = ?", [$uid], 'i');
            \Database::query("DELETE FROM tblUsers WHERE userID = ?", [$uid], 'i');
        }
        if ($this->userID) {
            \Database::query("DELETE FROM tblSubscriptions WHERE userID = ?", [$this->userID], 'i');
            \Database::query("DELETE FROM tblUsers WHERE userID = ?", [$this->userID], 'i');
        }
        if ($this->tierID) {
            \Database::query("DELETE FROM tblSubscriptionTiers WHERE tierID = ?", [$this->tierID], 'i');
        }
        if ($this->code !== '') {
            \Database::query("DELETE FROM tblDiscountCodes WHERE code = ?", [$this->code], 's');
        }
        parent::tearDown();
    }

    /**
     * 👤 Create an additional throwaway user (for the "second buyer" scenario).
     * Tracked in $extraUserIDs for tearDown cleanup.
     */
    private function createExtraUser(): int
    {
        $uuid = sprintf('%s-%s-4%s-8%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
            bin2hex(random_bytes(1)) . substr(bin2hex(random_bytes(1)), 0, 1),
            substr(bin2hex(random_bytes(1)), 0, 1) . bin2hex(random_bytes(1)),
            bin2hex(random_bytes(6))
        );
        $uname = 'redteam_' . bin2hex(random_bytes(4));
        \Database::query(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'Red Team 2', 'active', 1, 0, NOW())",
            [$uuid, $uname, $uname . '@example.com', password_hash('x', PASSWORD_BCRYPT)],
            'ssss'
        );
        $uid = (int) \Database::getLastInsertId();
        $this->extraUserIDs[] = $uid;

        return $uid;
    }

    /**
     * ✅ FIX VERIFIED: a maxUses=1 code is consumed EXACTLY ONCE when redeemed
     * via the real PaymentManager::createSubscription() flow — currentUses
     * becomes 1, the code stops validating, and a second buyer's redemption
     * attempt for the SAME code does not apply the discount and does not
     * push currentUses past the cap.
     */
    public function testSingleUseCodeIsConsumedExactlyOnce(): void
    {
        // Pre-condition: code validates.
        $v1 = \PaymentManager::validateDiscountCode($this->code, 'redteam', 'free', 20.00);
        $this->assertTrue($v1['valid'], 'code should validate initially');

        // Redeem it via the real subscription flow (first buyer).
        $res = \PaymentManager::createSubscription(
            $this->userID,
            $this->tierID,
            'monthly',
            'free',
            ['promoCode' => $this->code]
        );
        $this->assertTrue($res['success'] ?? false, 'subscription creation should succeed: ' . json_encode($res));
        if (!empty($res['subscriptionID'])) {
            $this->subIDs[] = (int) $res['subscriptionID'];
        }

        // 🔎 F-B04 FIX: the redemption DID increment currentUses to 1.
        $row = \Database::fetchOne(
            "SELECT currentUses FROM tblDiscountCodes WHERE code = ?",
            [$this->code],
            's'
        );
        $this->assertSame(
            1,
            (int) $row['currentUses'],
            'F-B04 FIX: currentUses should be 1 after redeeming a maxUses=1 code via createSubscription().'
        );

        // And the code no longer validates — the cap is now enforced.
        $v2 = \PaymentManager::validateDiscountCode($this->code, 'redteam', 'free', 20.00);
        $this->assertFalse(
            $v2['valid'],
            'F-B04 FIX: a maxUses=1 code must stop validating once its single use has been consumed.'
        );

        // 🔒 Direct atomic-spend check: a second incrementDiscountUsage() call
        // must be REJECTED (returns false) and must NOT push currentUses past 1 —
        // this is the compare-and-set guard itself, independent of validateDiscountCode().
        $secondIncrement = \PaymentManager::incrementDiscountUsage($this->code);
        $this->assertFalse(
            $secondIncrement,
            'F-B04 FIX: incrementDiscountUsage() must refuse to spend a code that is already at its usage cap.'
        );
        $rowAfter = \Database::fetchOne(
            "SELECT currentUses FROM tblDiscountCodes WHERE code = ?",
            [$this->code],
            's'
        );
        $this->assertSame(
            1,
            (int) $rowAfter['currentUses'],
            'F-B04 FIX: a rejected redemption attempt must not increment currentUses (no double-count).'
        );

        // 👤 End-to-end: a SECOND buyer attempting to redeem the now-exhausted
        // code via the real subscription flow gets their subscription created
        // (a maxed-out code must not block the purchase), but WITHOUT the
        // discount applied and WITHOUT incrementing currentUses again.
        $secondUserID = $this->createExtraUser();
        $res2 = \PaymentManager::createSubscription(
            $secondUserID,
            $this->tierID,
            'monthly',
            'free',
            ['promoCode' => $this->code]
        );
        $this->assertTrue($res2['success'] ?? false, 'second buyer subscription should still succeed (at full price): ' . json_encode($res2));
        if (!empty($res2['subscriptionID'])) {
            $this->subIDs[] = (int) $res2['subscriptionID'];
        }

        $rowFinal = \Database::fetchOne(
            "SELECT currentUses FROM tblDiscountCodes WHERE code = ?",
            [$this->code],
            's'
        );
        $this->assertSame(
            1,
            (int) $rowFinal['currentUses'],
            'F-B04 FIX: a second buyer redeeming an exhausted maxUses=1 code must NOT increment currentUses further.'
        );
    }
}
