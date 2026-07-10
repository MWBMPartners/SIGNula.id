<?php
/**
 * ============================================================================
 * 🧪 DSARManager Unit Tests (G-004 Layer 1 — Cycle B)
 * ============================================================================
 *
 * Covers the PURE, DB-free logic inside DSARManager: the guarded state
 * machine's legal/illegal transition map (canTransition()/assertTransition()
 * are public and pure — mirrors SubscriptionStateMachine's identical
 * pattern, see web/private_html/payments/SubscriptionStateMachine.php),
 * dueDate computation from a stubbed SLA, identity-token TTL expiry math,
 * actorType fallback resolution, the rectification column allowlist filter,
 * the truthy-setting parser, and submit()'s requestType allowlist guard
 * (exercisable WITHOUT a live DB because it is the very first statement in
 * submit(), before any Database:: call — see that method's docblock).
 *
 * Everything else (submit()'s DB round-trip, transition()'s persistence,
 * the fulfilment delegators, getQueue()/getComplianceReport()) is DB-bound
 * and lives in the Integration suite
 * (_tests/Integration/Compliance/DSARManagerTest.php), which has a real
 * MySQL connection (matches the established ConsentManagerTest split).
 *
 * @package    SIGNula\Tests\Unit\Compliance
 * @version    2.7.0-beta
 * @see        web/private_html/compliance/DSARManager.php
 * @see        _database/migrations/040_consent_and_dsar.sql
 * @see        .dev-team/specs/G-004.md §2.3, §2.5, §8
 */

namespace SIGNula\Tests\Unit\Compliance;

use SIGNula\Tests\TestCase;
use DSARManager;
use InvalidArgumentException;
use InvalidDSARTransitionException;
use ReflectionMethod;

requireSource('private_html/compliance/InvalidDSARTransitionException.php');
requireSource('private_html/compliance/DSARManager.php');

/**
 * 🛡️ DSARManager pure-logic test suite.
 */
class DSARManagerTest extends TestCase
{
    // ========================================================================
    // 🔧 Reflection helper — invoke a private static method by name.
    // ========================================================================

    /**
     * 🔧 Invoke a private static method on DSARManager via Reflection.
     *
     * @param string $method Method name
     * @param array  $args   Positional arguments
     * @return mixed
     */
    private function callPrivateStatic(string $method, array $args)
    {
        $reflection = new ReflectionMethod(DSARManager::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    // ========================================================================
    // 🗺️ canTransition() / assertTransition() — the guarded state machine
    // ========================================================================

    /**
     * 🔒 Documents the FULL adjacency map (mirrors
     * DSARManager::VALID_TRANSITIONS exactly — if this ever drifts, this
     * test fails loudly). Also doubles as the data source for the legal-pair
     * and illegal-pair assertions below.
     */
    private static function transitionMap(): array
    {
        return [
            'submitted'           => ['identity_pending', 'verified', 'in_progress', 'rejected', 'withdrawn', 'expired'],
            'identity_pending'    => ['verified', 'expired', 'withdrawn'],
            'verified'            => ['in_progress', 'rejected', 'withdrawn'],
            'in_progress'         => ['fulfilled', 'partially_fulfilled', 'rejected', 'awaiting_user', 'withdrawn'],
            'awaiting_user'       => ['in_progress', 'expired', 'withdrawn'],
            'fulfilled'           => [],
            'rejected'            => [],
            'partially_fulfilled' => [],
            'expired'             => [],
            'withdrawn'           => [],
        ];
    }

    private static function allStatuses(): array
    {
        return [
            'submitted', 'identity_pending', 'verified', 'in_progress', 'awaiting_user',
            'fulfilled', 'rejected', 'partially_fulfilled', 'expired', 'withdrawn',
        ];
    }

    public function testCanTransitionAllowsEveryLegalPairInTheMap(): void
    {
        foreach (self::transitionMap() as $from => $legalTargets) {
            foreach ($legalTargets as $to) {
                $this->assertTrue(
                    DSARManager::canTransition($from, $to),
                    "canTransition('{$from}', '{$to}') must be TRUE — it is a documented legal edge"
                );
            }
        }
    }

    public function testCanTransitionRejectsEveryIllegalPairAmongKnownStatuses(): void
    {
        $map = self::transitionMap();
        $statuses = self::allStatuses();

        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if (in_array($to, $map[$from], true)) {
                    continue; // legal edge — covered by the positive test above
                }

                $this->assertFalse(
                    DSARManager::canTransition($from, $to),
                    "canTransition('{$from}', '{$to}') must be FALSE — it is not in DSARManager::VALID_TRANSITIONS"
                );
            }
        }
    }

    public function testTerminalStatusesAreDeadEndsWithNoOutgoingTransitions(): void
    {
        foreach (['fulfilled', 'rejected', 'partially_fulfilled', 'expired', 'withdrawn'] as $terminal) {
            foreach (self::allStatuses() as $to) {
                $this->assertFalse(
                    DSARManager::canTransition($terminal, $to),
                    "A terminal status ('{$terminal}') must have NO legal outgoing transition, including to '{$to}'"
                );
            }
        }
    }

    public function testCanTransitionRejectsAnUnknownFromStatus(): void
    {
        $this->assertFalse(DSARManager::canTransition('not_a_real_status', 'submitted'));
    }

    public function testAssertTransitionThrowsForAnIllegalPair(): void
    {
        $this->expectException(InvalidDSARTransitionException::class);
        DSARManager::assertTransition('submitted', 'fulfilled');
    }

    public function testAssertTransitionThrowsForAnUnknownFromStatus(): void
    {
        $this->expectException(InvalidDSARTransitionException::class);
        DSARManager::assertTransition('bogus_status', 'submitted');
    }

    public function testAssertTransitionThrowsForAnUnknownToStatus(): void
    {
        $this->expectException(InvalidDSARTransitionException::class);
        DSARManager::assertTransition('submitted', 'bogus_status');
    }

    public function testAssertTransitionThrowsForATerminalStatusAttemptingAnyMove(): void
    {
        $this->expectException(InvalidDSARTransitionException::class);
        DSARManager::assertTransition('fulfilled', 'in_progress');
    }

    public function testAssertTransitionDoesNotThrowForALegalPair(): void
    {
        // ✅ No exception == pass. Explicitly asserts nothing was thrown.
        DSARManager::assertTransition('submitted', 'identity_pending');
        DSARManager::assertTransition('in_progress', 'fulfilled');
        $this->assertTrue(true);
    }

    public function testAssertTransitionSameStatusIsIllegalNoSelfEdges(): void
    {
        // 🔒 Unlike SubscriptionStateMachine, the DSAR map has NO self-edges
        // — re-applying the current status is NOT an idempotent no-op here
        // (see DSARManager::transition()'s docblock for why).
        foreach (self::allStatuses() as $status) {
            $this->assertFalse(
                DSARManager::canTransition($status, $status),
                "'{$status}' -> '{$status}' must be illegal (no self-edges in the DSAR map)"
            );
        }
    }

    // ========================================================================
    // ⏰ computeDueDate()
    // ========================================================================

    public function testComputeDueDateAddsTheSlaDayCount(): void
    {
        $this->assertSame(
            '2026-01-31',
            $this->callPrivateStatic('computeDueDate', ['2026-01-01 00:00:00', 30])
        );
    }

    public function testComputeDueDateHandlesMonthRollover(): void
    {
        $this->assertSame(
            '2026-03-02',
            $this->callPrivateStatic('computeDueDate', ['2026-02-28 12:00:00', 2])
        );
    }

    public function testComputeDueDateClampsNegativeSlaDaysToZero(): void
    {
        $this->assertSame(
            '2026-01-01',
            $this->callPrivateStatic('computeDueDate', ['2026-01-01 00:00:00', -10])
        );
    }

    public function testComputeDueDateSupportsCcpaFortyFiveDays(): void
    {
        $this->assertSame(
            '2026-02-15',
            $this->callPrivateStatic('computeDueDate', ['2026-01-01 00:00:00', 45])
        );
    }

    // ========================================================================
    // ⏱️ isTokenExpired()
    // ========================================================================

    public function testIsTokenExpiredFalseWhenWellWithinTtl(): void
    {
        $this->assertFalse(
            $this->callPrivateStatic('isTokenExpired', ['2026-07-10 12:00:00', 30, '2026-07-10 12:05:00'])
        );
    }

    public function testIsTokenExpiredTrueWhenPastTtl(): void
    {
        $this->assertTrue(
            $this->callPrivateStatic('isTokenExpired', ['2026-07-10 12:00:00', 30, '2026-07-10 12:31:00'])
        );
    }

    public function testIsTokenExpiredFalseAtExactBoundary(): void
    {
        // 🔎 Documents current behaviour: expiry uses a strict ">" comparison,
        // so the EXACT boundary instant is still considered valid (not expired).
        $this->assertFalse(
            $this->callPrivateStatic('isTokenExpired', ['2026-07-10 12:00:00', 30, '2026-07-10 12:30:00'])
        );
    }

    public function testIsTokenExpiredClampsNegativeTtlToZero(): void
    {
        // 🛡️ A negative TTL must never grant extra time — clamps to 0,
        // meaning anything after the exact issuance instant is expired.
        $this->assertTrue(
            $this->callPrivateStatic('isTokenExpired', ['2026-07-10 12:00:00', -5, '2026-07-10 12:00:01'])
        );
    }

    // ========================================================================
    // 🎭 resolveActorType()
    // ========================================================================

    public function testResolveActorTypeAcceptsEveryValueInTheEnumAllowlist(): void
    {
        foreach (['user', 'admin', 'system', 'cron'] as $value) {
            $this->assertSame($value, $this->callPrivateStatic('resolveActorType', [$value]));
        }
    }

    public function testResolveActorTypeFallsBackToUserForNull(): void
    {
        $this->assertSame('user', $this->callPrivateStatic('resolveActorType', [null]));
    }

    public function testResolveActorTypeFallsBackToUserForAnUnrecognisedValue(): void
    {
        $this->assertSame('user', $this->callPrivateStatic('resolveActorType', ['not_a_real_actor']));
    }

    // ========================================================================
    // ✏️ filterRectifiableColumns()
    // ========================================================================

    public function testFilterRectifiableColumnsAppliesOnlyAllowlistedColumns(): void
    {
        [$applied, $rejected] = $this->callPrivateStatic('filterRectifiableColumns', [[
            'firstName'    => 'Jane',
            'lastName'     => 'Doe',
            'passwordHash' => 'attacker-controlled-hash',
            'accountStatus' => 'active',
        ]]);

        $this->assertSame(['firstName' => 'Jane', 'lastName' => 'Doe'], $applied);
        $this->assertSame(['passwordHash', 'accountStatus'], $rejected);
    }

    public function testFilterRectifiableColumnsAcceptsAllFourDocumentedColumns(): void
    {
        $input = [
            'firstName'   => 'A',
            'lastName'    => 'B',
            'displayName' => 'C',
            'phoneNumber' => '+10000000000',
        ];

        [$applied, $rejected] = $this->callPrivateStatic('filterRectifiableColumns', [$input]);

        $this->assertSame($input, $applied);
        $this->assertSame([], $rejected);
    }

    public function testFilterRectifiableColumnsRejectsEverythingWhenNoneAllowlisted(): void
    {
        [$applied, $rejected] = $this->callPrivateStatic('filterRectifiableColumns', [[
            'isSuperAdmin' => 1,
            'email'        => 'attacker@example.com',
        ]]);

        $this->assertSame([], $applied);
        $this->assertSame(['isSuperAdmin', 'email'], $rejected);
    }

    public function testFilterRectifiableColumnsHandlesEmptyInput(): void
    {
        [$applied, $rejected] = $this->callPrivateStatic('filterRectifiableColumns', [[]]);
        $this->assertSame([], $applied);
        $this->assertSame([], $rejected);
    }

    // ========================================================================
    // 🔧 isTruthySetting()
    // ========================================================================

    /**
     * @dataProvider truthyValuesProvider
     */
    public function testIsTruthySettingRecognisesTruthyStrings(string $value): void
    {
        $this->assertTrue($this->callPrivateStatic('isTruthySetting', [$value]));
    }

    public static function truthyValuesProvider(): array
    {
        return [
            'lowercase true' => ['true'],
            'uppercase TRUE' => ['TRUE'],
            'one'            => ['1'],
            'yes'            => ['yes'],
            'on'             => ['on'],
        ];
    }

    /**
     * @dataProvider falsyValuesProvider
     */
    public function testIsTruthySettingRecognisesFalsyStrings(string $value): void
    {
        $this->assertFalse($this->callPrivateStatic('isTruthySetting', [$value]));
    }

    public static function falsyValuesProvider(): array
    {
        return [
            'false' => ['false'],
            'zero'  => ['0'],
            'no'    => ['no'],
            'off'   => ['off'],
            'empty' => [''],
            'junk'  => ['not-a-boolean'],
        ];
    }

    // ========================================================================
    // 📝 submit() — requestType allowlist guard (DB-free: throws before any
    // Database:: call — see submit()'s docblock)
    // ========================================================================

    public function testSubmitThrowsInvalidArgumentExceptionForAnUnrecognisedRequestType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DSARManager::submit(['requestType' => 'not_a_real_type']);
    }

    public function testSubmitThrowsInvalidArgumentExceptionWhenRequestTypeIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DSARManager::submit(['userID' => 1]);
    }

    /**
     * @dataProvider validRequestTypesProvider
     */
    public function testSubmitRequestTypeGuardAcceptsEveryValueInTheEnumAllowlist(string $requestType): void
    {
        // 🔒 We can't call submit() all the way through without a live DB,
        // but we CAN prove the guard itself doesn't reject a valid ENUM
        // member by asserting it does NOT throw InvalidArgumentException —
        // it will instead fail later trying to reach Database (a DIFFERENT
        // exception/error), which this test deliberately does not care about.
        try {
            DSARManager::submit(['requestType' => $requestType]);
        } catch (InvalidArgumentException $e) {
            $this->fail("submit() must not reject the valid ENUM member '{$requestType}' with InvalidArgumentException");
        } catch (\Throwable $e) {
            // ✅ Expected — no live DB in the Unit suite, so it fails later
            // for an unrelated reason. The requestType guard itself passed.
            $this->assertTrue(true);
        }
    }

    public static function validRequestTypesProvider(): array
    {
        return [
            'access'                     => ['access'],
            'portability'                => ['portability'],
            'erasure'                    => ['erasure'],
            'rectification'              => ['rectification'],
            'restriction'                => ['restriction'],
            'object'                     => ['object'],
            'do_not_sell'                => ['do_not_sell'],
            'withdraw_consent'           => ['withdraw_consent'],
            'automated_decision_optout'  => ['automated_decision_optout'],
        ];
    }

    // ========================================================================
    // 📨 DSAR type allowlist (settings/privacy.php) — regression sentinel
    // ========================================================================

    /**
     * 📨 The request_dsar POST action in settings/privacy.php gates every
     * requestType through a server-authoritative allowlist ($DSAR_TYPE_LABELS).
     * That page can't be exercised directly in a Unit test (full HTTP + DB
     * bootstrap), so this is a lightweight source sentinel mirroring
     * ConsentManagerTest's identical pattern: fails loudly if the four
     * user-initiable Layer-1 request types this cycle ships are ever removed
     * from the allowlist without a deliberate edit.
     */
    public function testPrivacyPageDsarAllowlistContainsTheFourUserInitiableTypes(): void
    {
        $privacyPagePath = \PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'privacy.php';

        $source = file_get_contents($privacyPagePath);
        $this->assertNotFalse($source, 'settings/privacy.php must be readable for this sentinel test');

        $matched = preg_match('/\$DSAR_TYPE_LABELS\s*=\s*\[(.*?)\];/s', $source, $matches);
        $this->assertSame(1, $matched, '$DSAR_TYPE_LABELS array literal must exist in settings/privacy.php');

        $arrayBody = $matches[1];
        foreach (['access', 'portability', 'erasure', 'rectification'] as $expectedType) {
            $this->assertStringContainsString(
                "'{$expectedType}'",
                $arrayBody,
                "settings/privacy.php's DSAR allowlist must include '{$expectedType}'"
            );
        }
    }
}
