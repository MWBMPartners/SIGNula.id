<?php
/**
 * ============================================================================
 * 🧪 PaymentManager::calculateProration() Unit Tests (G-002 Stage S2)
 * ============================================================================
 *
 * Pure bcmath proration-formula tests — NO database required.
 * calculateProration() is a side-effect-free static method (no Database::*,
 * no ActivityLogger, no other payments/ class), so it is fully testable in
 * isolation, with an explicit $asOfDate override so every case below is
 * deterministic regardless of the real wall-clock date the suite runs on.
 *
 * Every expected amount in this file was HAND-COMPUTED from the documented
 * formula (G-002 spec §5.3):
 *   unusedFraction = remainingDays / totalDays
 *   unusedCredit   = round2(oldAmount * unusedFraction)   // half-up
 *   proratedNew    = round2(newAmount * unusedFraction)   // half-up
 *   netProration   = proratedNew - unusedCredit
 * — never by calling calculateProration() itself to "check" calculateProration().
 *
 * @package    SIGNula\Tests\Unit\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/PaymentManager.php::calculateProration()
 * @see        .dev-team/specs/G-002.md §5.3
 */

namespace SIGNula\Tests\Unit\Payments;

use SIGNula\Tests\TestCase;
use PaymentManager;

requireSource('private_html/payments/PaymentManager.php');

/**
 * 🧮 PaymentManager::calculateProration() test suite.
 */
class PaymentManagerProrationTest extends TestCase
{
    // ========================================================================
    // 📅 CORE FRACTION CASES
    // ========================================================================

    /**
     * Same-day change: asOfDate === periodStart, so the WHOLE period is still
     * "unused" — unusedFraction must be exactly 1.0 (≈ full credit).
     *
     * periodStart=2026-01-01, periodEnd=2026-01-31 (30 days), asOf=2026-01-01
     * -> totalDays=30, remainingDays=30, fraction=1.0
     * oldAmount=30.00, newAmount=60.00
     * -> unusedCredit = 30.00 * 1.0 = 30.00
     * -> proratedNew  = 60.00 * 1.0 = 60.00
     * -> netProration = 60.00 - 30.00 = 30.00
     */
    public function testSameDayChangeYieldsFullCredit(): void
    {
        $result = PaymentManager::calculateProration(
            '30.00',
            '60.00',
            '2026-01-01',
            '2026-01-31',
            '2026-01-01'
        );

        $this->assertSame(30, $result['totalDays']);
        $this->assertSame(30, $result['remainingDays']);
        $this->assertSame('1.0000000000', $result['unusedFraction']);
        $this->assertSame('30.00', $result['unusedCredit']);
        $this->assertSame('60.00', $result['proratedNew']);
        $this->assertSame('30.00', $result['netProration']);
    }

    /**
     * Mid-cycle change: exactly half the period remains.
     *
     * periodStart=2026-01-01, periodEnd=2026-01-31 (30 days), asOf=2026-01-16
     * -> totalDays=30, remainingDays=15, fraction=0.5
     * oldAmount=20.00, newAmount=40.00
     * -> unusedCredit = 20.00 * 0.5 = 10.00
     * -> proratedNew  = 40.00 * 0.5 = 20.00
     * -> netProration = 20.00 - 10.00 = 10.00
     */
    public function testMidCycleChangeYieldsHalfProration(): void
    {
        $result = PaymentManager::calculateProration(
            '20.00',
            '40.00',
            '2026-01-01',
            '2026-01-31',
            '2026-01-16'
        );

        $this->assertSame(30, $result['totalDays']);
        $this->assertSame(15, $result['remainingDays']);
        $this->assertSame('0.5000000000', $result['unusedFraction']);
        $this->assertSame('10.00', $result['unusedCredit']);
        $this->assertSame('20.00', $result['proratedNew']);
        $this->assertSame('10.00', $result['netProration']);
    }

    /**
     * End-of-cycle change: asOfDate === periodEnd, so ZERO time remains —
     * unusedFraction must be exactly 0 (≈ no credit, no prorated charge).
     *
     * periodStart=2026-01-01, periodEnd=2026-01-31, asOf=2026-01-31
     * -> totalDays=30, remainingDays=0, fraction=0
     */
    public function testEndOfCycleChangeYieldsZeroProration(): void
    {
        $result = PaymentManager::calculateProration(
            '20.00',
            '40.00',
            '2026-01-01',
            '2026-01-31',
            '2026-01-31'
        );

        $this->assertSame(30, $result['totalDays']);
        $this->assertSame(0, $result['remainingDays']);
        $this->assertSame('0.0000000000', $result['unusedFraction']);
        $this->assertSame('0.00', $result['unusedCredit']);
        $this->assertSame('0.00', $result['proratedNew']);
        $this->assertSame('0.00', $result['netProration']);
    }

    // ========================================================================
    // 🔄 CROSS-CYCLE CASES (monthly <-> yearly)
    // ========================================================================

    /**
     * Monthly -> yearly change: the CURRENT period is a 30-day monthly
     * period; the NEW tier's full YEARLY price is prorated against the
     * fraction of the CURRENT (monthly) period remaining — per the task
     * brief: "compute against the CURRENT period's real length", regardless
     * of what cycle the new amount itself represents.
     *
     * periodStart=2026-01-01, periodEnd=2026-01-31 (30 days), asOf=2026-01-16
     * -> totalDays=30, remainingDays=15, fraction=0.5
     * oldAmount=10.00 (monthly), newAmount=120.00 (yearly)
     * -> unusedCredit = 10.00 * 0.5 = 5.00
     * -> proratedNew  = 120.00 * 0.5 = 60.00
     * -> netProration = 60.00 - 5.00 = 55.00 (a charge — upgrading to annual)
     */
    public function testMonthlyToYearlyChangeProratesAgainstTheCurrentMonthlyPeriod(): void
    {
        $result = PaymentManager::calculateProration(
            '10.00',
            '120.00',
            '2026-01-01',
            '2026-01-31',
            '2026-01-16'
        );

        $this->assertSame('5.00', $result['unusedCredit']);
        $this->assertSame('60.00', $result['proratedNew']);
        $this->assertSame('55.00', $result['netProration']);
    }

    /**
     * Yearly -> monthly change: the CURRENT period is a 365-day annual
     * period (2026-01-01 to 2027-01-01); asOf=2026-08-08 is EXACTLY 146 days
     * before periodEnd, and 146/365 = 2/5 = 0.4 exactly (a clean fraction,
     * chosen deliberately so the expected values are hand-verifiable).
     *
     * -> totalDays=365, remainingDays=146, fraction=0.4
     * oldAmount=120.00 (yearly), newAmount=15.00 (monthly)
     * -> unusedCredit = 120.00 * 0.4 = 48.00
     * -> proratedNew  = 15.00 * 0.4 = 6.00
     * -> netProration = 6.00 - 48.00 = -42.00 (a CREDIT — downgrading off an annual plan)
     */
    public function testYearlyToMonthlyChangeProratesAgainstTheCurrentYearlyPeriodAndYieldsACredit(): void
    {
        $result = PaymentManager::calculateProration(
            '120.00',
            '15.00',
            '2026-01-01',
            '2027-01-01',
            '2026-08-08'
        );

        $this->assertSame(365, $result['totalDays']);
        $this->assertSame(146, $result['remainingDays']);
        $this->assertSame('0.4000000000', $result['unusedFraction']);
        $this->assertSame('48.00', $result['unusedCredit']);
        $this->assertSame('6.00', $result['proratedNew']);
        $this->assertSame('-42.00', $result['netProration']);
    }

    // ========================================================================
    // 🔢 ROUNDING RULE (half-up, bcmath — never native float)
    // ========================================================================

    /**
     * Constructs an EXACT .xx5 rounding boundary (unusedFraction = 0.005
     * exactly, via remainingDays=1/totalDays=200) to prove the documented
     * half-up rounding rule holds precisely — a naive float-based
     * `round((float)$x, 2)` is well known to mis-round some exact .xx5
     * decimal boundaries because 0.005 has no exact binary floating-point
     * representation (see https://0.30000000000000004.com/). bcmath's
     * string-based arithmetic never has this problem, which is exactly why
     * this project's rounding rule is bcmath-only (see the method doc-comment).
     *
     * periodStart=2026-01-01, periodEnd=2026-07-20 (200 days), asOf=2026-07-19
     * -> totalDays=200, remainingDays=1, fraction=0.005 exactly
     * oldAmount=1.00 -> unusedCreditRaw = 0.005 exactly -> half-up -> 0.01
     */
    public function testRoundingIsHalfUpAtAnExactPointZeroZeroFiveBoundary(): void
    {
        $result = PaymentManager::calculateProration(
            '1.00',
            '1.00',
            '2026-01-01',
            '2026-07-20',
            '2026-07-19'
        );

        $this->assertSame(200, $result['totalDays']);
        $this->assertSame(1, $result['remainingDays']);
        $this->assertSame('0.0050000000', $result['unusedFraction']);
        $this->assertSame('0.01', $result['unusedCredit'], 'Exact .005 boundary must round HALF-UP to 0.01, not truncate/round-down to 0.00');
        $this->assertSame('0.01', $result['proratedNew']);
        $this->assertSame('0.00', $result['netProration']);
    }

    // ========================================================================
    // 🛡️ GUARDS — divide-by-zero / already-lapsed / clock-skew
    // ========================================================================

    /**
     * Zero-length period (periodStart === periodEnd) must never divide by
     * zero — fails SAFE towards "no credit, no discounted charge" rather
     * than an error or a runaway fraction.
     */
    public function testZeroLengthPeriodGuardsAgainstDivideByZero(): void
    {
        $result = PaymentManager::calculateProration(
            '20.00',
            '40.00',
            '2026-01-01',
            '2026-01-01',
            '2026-01-01'
        );

        $this->assertSame(0, $result['totalDays']);
        $this->assertSame(0, $result['remainingDays']);
        $this->assertSame('0.00', $result['unusedCredit']);
        $this->assertSame('0.00', $result['proratedNew']);
        $this->assertSame('0.00', $result['netProration']);
    }

    /**
     * A period that has ALREADY fully lapsed (asOfDate beyond periodEnd)
     * must clamp remainingDays to 0, never negative.
     */
    public function testAlreadyLapsedPeriodClampsRemainingDaysToZero(): void
    {
        $result = PaymentManager::calculateProration(
            '20.00',
            '40.00',
            '2026-01-01',
            '2026-01-31',
            '2026-03-01'
        );

        $this->assertSame(0, $result['remainingDays']);
        $this->assertSame('0.00', $result['unusedCredit']);
        $this->assertSame('0.00', $result['proratedNew']);
    }

    /**
     * A clock-skew case where "today" is somehow BEFORE periodStart must
     * clamp remainingDays to totalDays (never claim MORE than the whole
     * period is unused).
     */
    public function testTodayBeforePeriodStartClampsRemainingDaysToTotalDays(): void
    {
        $result = PaymentManager::calculateProration(
            '20.00',
            '40.00',
            '2026-01-01',
            '2026-01-31',
            '2025-12-01'
        );

        $this->assertSame(30, $result['totalDays']);
        $this->assertSame(30, $result['remainingDays']);
        $this->assertSame('1.0000000000', $result['unusedFraction']);
    }
}
