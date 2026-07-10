<?php
/**
 * ============================================================================
 * 🧪 SubscriptionStateMachine Unit Tests (G-002 Stage S1 — lifecycle FSM)
 * ============================================================================
 *
 * Covers the pure transition-validation logic (canTransition()/
 * assertTransition()) — no database required. transition() itself (which
 * loads/persists a real tblSubscriptions row) is covered by the Integration
 * suite (SubscriptionStateMachineTransitionTest).
 *
 * Every legal edge asserted here is taken verbatim from the class's own
 * VALID_TRANSITIONS doc-comment, which in turn derives from G-002 spec §4.2.
 *
 * @package    SIGNula\Tests\Unit\Payments
 * @version    2.9.0-beta
 * @see        web/private_html/payments/SubscriptionStateMachine.php
 * @see        .dev-team/specs/G-002.md §4.2/§5
 */

namespace SIGNula\Tests\Unit\Payments;

use SIGNula\Tests\TestCase;
use SubscriptionStateMachine;
use InvalidSubscriptionTransitionException;

requireSource('private_html/payments/InvalidSubscriptionTransitionException.php');
requireSource('private_html/payments/SubscriptionStateMachine.php');

/**
 * 🔄 SubscriptionStateMachine test suite.
 */
class SubscriptionStateMachineTest extends TestCase
{
    // ========================================================================
    // ✅ canTransition() — legal edges
    // ========================================================================

    /**
     * @dataProvider legalTransitionsProvider
     */
    public function testCanTransitionAllowsEveryDocumentedEdge(string $from, string $to): void
    {
        $this->assertTrue(
            SubscriptionStateMachine::canTransition($from, $to),
            "Expected '{$from}' -> '{$to}' to be a legal transition"
        );
    }

    public static function legalTransitionsProvider(): array
    {
        return [
            'pending -> active'    => ['pending', 'active'],
            'pending -> trial'     => ['pending', 'trial'],
            'pending -> cancelled' => ['pending', 'cancelled'],
            'trial -> active'      => ['trial', 'active'],
            'trial -> past_due'    => ['trial', 'past_due'],
            'trial -> paused'      => ['trial', 'paused'],
            'trial -> cancelled'   => ['trial', 'cancelled'],
            'active -> past_due'   => ['active', 'past_due'],
            'active -> paused'     => ['active', 'paused'],
            'active -> cancelled'  => ['active', 'cancelled'],
            'active -> expired'    => ['active', 'expired'],
            'past_due -> active'   => ['past_due', 'active'],
            'past_due -> grace'    => ['past_due', 'grace'],
            'past_due -> cancelled'=> ['past_due', 'cancelled'],
            'grace -> active'      => ['grace', 'active'],
            'grace -> expired'     => ['grace', 'expired'],
            'grace -> cancelled'   => ['grace', 'cancelled'],
            'paused -> active'     => ['paused', 'active'],
            'paused -> cancelled'  => ['paused', 'cancelled'],
            'cancelled -> active'  => ['cancelled', 'active'],
        ];
    }

    // ========================================================================
    // 🚫 canTransition() — illegal edges
    // ========================================================================

    /**
     * @dataProvider illegalTransitionsProvider
     */
    public function testCanTransitionRejectsIllegalEdges(string $from, string $to): void
    {
        $this->assertFalse(
            SubscriptionStateMachine::canTransition($from, $to),
            "Expected '{$from}' -> '{$to}' to be REJECTED"
        );
    }

    public static function illegalTransitionsProvider(): array
    {
        return [
            'expired has no outgoing edges (-> active)'    => ['expired', 'active'],
            'expired has no outgoing edges (-> cancelled)' => ['expired', 'cancelled'],
            'pending cannot skip straight to past_due'     => ['pending', 'past_due'],
            'pending cannot skip straight to grace'        => ['pending', 'grace'],
            'active cannot jump straight to grace'         => ['active', 'grace'],
            'cancelled cannot go to expired directly'      => ['cancelled', 'expired'],
            'cancelled cannot go to past_due'               => ['cancelled', 'past_due'],
            'paused cannot go to past_due'                  => ['paused', 'past_due'],
            'grace cannot go to trial'                      => ['grace', 'trial'],
            'trial cannot skip to grace'                    => ['trial', 'grace'],
        ];
    }

    public function testCanTransitionReturnsFalseForAnUnknownFromState(): void
    {
        $this->assertFalse(SubscriptionStateMachine::canTransition('bogus_state', 'active'));
    }

    public function testCanTransitionReturnsFalseForTheSameStateAsFromAndTo(): void
    {
        // canTransition() is a strict adjacency check — re-applying the SAME
        // state is handled as an idempotent no-op by transition() itself,
        // NOT by this predicate (see the class doc-comment).
        $this->assertFalse(SubscriptionStateMachine::canTransition('active', 'active'));
    }

    public function testExpiredIsTerminalForEveryOtherKnownState(): void
    {
        foreach (SubscriptionStateMachine::VALID_STATES as $target) {
            if ($target === 'expired') {
                continue;
            }
            $this->assertFalse(
                SubscriptionStateMachine::canTransition('expired', $target),
                "'expired' must have no outgoing transitions (tried -> '{$target}')"
            );
        }
    }

    // ========================================================================
    // 🚫 assertTransition() — throws on illegal, silent on legal
    // ========================================================================

    public function testAssertTransitionThrowsOnIllegalTransition(): void
    {
        $this->expectException(InvalidSubscriptionTransitionException::class);
        SubscriptionStateMachine::assertTransition('expired', 'active');
    }

    public function testAssertTransitionThrowsOnUnknownFromStatus(): void
    {
        $this->expectException(InvalidSubscriptionTransitionException::class);
        SubscriptionStateMachine::assertTransition('not_a_real_status', 'active');
    }

    public function testAssertTransitionThrowsOnUnknownToStatus(): void
    {
        $this->expectException(InvalidSubscriptionTransitionException::class);
        SubscriptionStateMachine::assertTransition('active', 'not_a_real_status');
    }

    public function testAssertTransitionIsSilentOnALegalTransition(): void
    {
        SubscriptionStateMachine::assertTransition('past_due', 'grace');
        $this->assertTrue(true, 'assertTransition() must not throw for a legal edge');
    }

    public function testAssertTransitionIsSilentOnEveryLegalGraceEdge(): void
    {
        SubscriptionStateMachine::assertTransition('grace', 'active');
        SubscriptionStateMachine::assertTransition('grace', 'expired');
        SubscriptionStateMachine::assertTransition('grace', 'cancelled');
        $this->assertTrue(true, 'every documented grace-state edge must be legal');
    }
}
