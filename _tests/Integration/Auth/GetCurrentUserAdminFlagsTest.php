<?php
/**
 * ============================================================================
 * 🧪 Auth::getCurrentUser() Admin/Partner-Flag Enrichment Tests (B-053)
 * ============================================================================
 *
 * B-053 (HIGH, LAST safety-floor item): Auth::getCurrentUser()'s tblUsers
 * SELECT (web/private_html/auth/Auth.php) previously returned only
 * userID, userUUID, username, email, firstName, lastName, accountStatus,
 * accountTier, emailVerified, lastLoginAt (+ a few other plain columns) —
 * NONE of the 4 admin/partner-context flags that 50 call sites across
 * web/public_html read straight off this array:
 *
 *   - $userInfo['isSuperAdmin']   (28 sites)
 *   - $userInfo['isAdmin']        (12 sites)
 *   - (int)($userInfo['partnerID'] ?? 0)  (8 sites)
 *   - $userInfo['isPartnerAdmin'] (2 sites)
 *
 * Every one of those gates therefore failed CLOSED (undefined array key ->
 * falsy/0 in PHP), 403'ing real admins and losing partner context. The fix
 * enriches the base tblUsers row with those 4 keys ADDITIVELY:
 *
 *   - isSuperAdmin   : a REAL tblUsers column (BOOLEAN, migration 009),
 *                      normalised to a genuine PHP bool.
 *   - partnerID      : NOT a tblUsers column — derived from the user's
 *                      "primary" ACTIVE tblPartnerTeamMembers row (root-admin
 *                      memberships preferred, then lowest partnerID), or
 *                      null if the user has no active membership.
 *   - isPartnerAdmin : derived — true iff the user has any active
 *                      tblPartnerTeamMembers row.
 *   - isAdmin        : derived — isSuperAdmin OR isPartnerAdmin (mirrors
 *                      AccessControl::isAdmin() in web/_backend/AccessControl.php).
 *
 * This suite proves the enriched shape end-to-end against a REAL database
 * (signula_test) for the 3 personas the fix must handle correctly, and
 * confirms every PRE-EXISTING key is still present (additive, nothing
 * dropped).
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.7.0-beta
 * @see        web/private_html/auth/Auth.php (getCurrentUser())
 * @see        web/_backend/AccessControl.php (isAdmin()/isPartnerAdmin()
 *             semantics this enrichment mirrors, without instantiating
 *             AccessControl itself — see Auth.php's inline comment on why)
 * @see        _database/migrations/009_multi_tier_admin.sql (isSuperAdmin
 *             column + tblPartnerTeamMembers table definitions)
 * @see        _tests/Integration/Auth/AuthCompleteLoginSessionTest.php
 *             (mirrors its Auth static-cache reset pattern + session-fixture
 *             style)
 * @see        _tests/Integration/Backend/SessionManagerTest.php (mirrors its
 *             establishRealSession() pattern for creating a REAL, resolvable
 *             session without routing through the (separately-tracked)
 *             completeLogin() codepath)
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionClass;

// 📦 Load required source classes. Only Database + Auth are actually needed —
// Auth::getCurrentUserID()/getCurrentUser() call nothing beyond Database::
// fetchOne() for the codepaths this suite exercises.
requireSource('_config/database.php');
requireSource('private_html/auth/Auth.php');

/**
 * Auth::getCurrentUser() Admin/Partner-Flag Enrichment Test Suite
 */
class GetCurrentUserAdminFlagsTest extends DatabaseTestCase
{
    /**
     * Tables to truncate before each test.
     *
     * tblPartnerTeamMembers must be truncated BEFORE tblPartners/tblUsers are
     * recreated each test run (FK parents) — truncateTables() in
     * DatabaseTestCase already disables FOREIGN_KEY_CHECKS around the whole
     * batch, so declaration order here doesn't matter, but it's listed
     * child-first for readability.
     */
    protected array $truncateTables = [
        'tblUserSessions', 'tblPartnerTeamMembers', 'tblUsers', 'tblPartners',
    ];

    /**
     * 🔧 Set up before each test — also resets Auth's memoized static state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetAuthStaticCache();
    }

    /**
     * 🧹 Reset Auth's internal static cache.
     *
     * Auth::getCurrentUserID()/getCurrentUser() memoize their result on
     * private/public static properties once resolved, and PHPUnit runs every
     * test in the SAME PHP process — without this reset, a cached user
     * resolved by an EARLIER test would leak into this suite's assertions
     * regardless of what $_SESSION actually holds. Mirrors the identical
     * pattern in AuthCompleteLoginSessionTest.php / SessionManagerTest.php.
     */
    private function resetAuthStaticCache(): void
    {
        $reflection = new ReflectionClass(\Auth::class);
        $prop = $reflection->getProperty('currentUserID');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        \Auth::$currentUser = null;
    }

    // ========================================================================
    // 🔧 FIXTURE HELPERS
    // ========================================================================

    /**
     * Create a minimal active partner organization and return its partnerID.
     *
     * Mirrors OAuthClientTest::createPartner() field choices (partnerName +
     * partnerEmail are tblPartners' only NOT-NULL-without-default columns).
     *
     * @return int The new partner's partnerID
     */
    private function createPartner(): int
    {
        $suffix = bin2hex(random_bytes(4));

        return $this->insertRecord('tblPartners', [
            'partnerName'  => 'B-053 Test Partner ' . $suffix,
            'partnerEmail' => 'b053_partner_' . $suffix . '@example.com',
            'accountTier'  => 'premium',
            'isActive'     => 1,
        ]);
    }

    /**
     * Create an ACTIVE tblPartnerTeamMembers row linking $userID to
     * $partnerID.
     *
     * @param int   $partnerID Partner to join
     * @param int   $userID    User joining the partner's team
     * @param array $overrides Column overrides (e.g. ['isRootAdmin' => 1])
     * @return int The new memberID
     */
    private function createMembership(int $partnerID, int $userID, array $overrides = []): int
    {
        $defaults = [
            'partnerID'  => $partnerID,
            'userID'     => $userID,
            'role'       => 'admin',
            'isRootAdmin'=> 0,
            'status'     => 'active',
        ];

        return $this->insertRecord('tblPartnerTeamMembers', array_merge($defaults, $overrides));
    }

    /**
     * Establish a real, working authenticated session for $userID — a real
     * tblUserSessions row plus the matching $_SESSION keys
     * Auth::getCurrentUserID() reads (user_id, session_id, session_token).
     * Mirrors SessionManagerTest::establishRealSession() exactly.
     *
     * @param int $userID User to log in
     * @return void
     */
    private function establishRealSession(int $userID): void
    {
        $sessionToken = bin2hex(random_bytes(32));

        $sessionID = $this->insertRecord('tblUserSessions', [
            'sessionToken' => hash('sha256', $sessionToken),
            'userID'       => $userID,
            'deviceType'   => 'web',
            'ipAddress'    => '127.0.0.1',
            'isActive'     => 1,
            'expiresAt'    => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $_SESSION['user_id']       = $userID;
        $_SESSION['session_id']    = $sessionID;
        $_SESSION['session_token'] = $sessionToken;
    }

    /**
     * Assert every PRE-EXISTING key Auth::getCurrentUser() already returned
     * before the B-053 fix is still present and correctly valued — the fix
     * must be purely ADDITIVE.
     *
     * @param array $expectedUser The fixture array (createTestUser() output)
     * @param array $result       Auth::getCurrentUser()'s return value
     */
    private function assertPreExistingKeysIntact(array $expectedUser, array $result): void
    {
        $this->assertSame($expectedUser['userID'], $result['userID'] ?? null, 'userID must survive enrichment');
        $this->assertSame($expectedUser['userUUID'], $result['userUUID'] ?? null, 'userUUID must survive enrichment');
        $this->assertSame($expectedUser['username'], $result['username'] ?? null, 'username must survive enrichment');
        $this->assertSame($expectedUser['email'], $result['email'] ?? null, 'email must survive enrichment');
        $this->assertArrayHasKey('accountStatus', $result, 'accountStatus must survive enrichment');
        $this->assertArrayHasKey('accountTier', $result, 'accountTier must survive enrichment');
        $this->assertArrayHasKey('emailVerified', $result, 'emailVerified must survive enrichment');
        $this->assertArrayHasKey('lastLoginAt', $result, 'lastLoginAt must survive enrichment');
    }

    // ========================================================================
    // ✅ PERSONA #1 — SUPER ADMIN (no partner membership)
    // ========================================================================

    /**
     * A super admin (tblUsers.isSuperAdmin = 1, no tblPartnerTeamMembers row)
     * must resolve to: isSuperAdmin=true, isAdmin=true, isPartnerAdmin=false,
     * partnerID=null — proving the real column is read AND that isAdmin
     * derives to true purely from isSuperAdmin when there is no membership.
     */
    public function testSuperAdminUserGetsEnrichedFlags(): void
    {
        $user = $this->createTestUser(['isSuperAdmin' => 1]);
        $this->establishRealSession($user['userID']);

        $result = \Auth::getCurrentUser();

        $this->assertIsArray($result, 'getCurrentUser() should return an array for an authenticated session');

        $this->assertSame(true, $result['isSuperAdmin'], 'isSuperAdmin must be TRUE for a real super admin');
        $this->assertSame(true, $result['isAdmin'], 'isAdmin must be TRUE (derived from isSuperAdmin) for a super admin');
        $this->assertSame(false, $result['isPartnerAdmin'], 'isPartnerAdmin must be FALSE — this user has no partner membership');
        $this->assertNull($result['partnerID'], 'partnerID must be NULL — this user has no active partner membership');

        $this->assertPreExistingKeysIntact($user, $result);
    }

    // ========================================================================
    // ✅ PERSONA #2 — PARTNER TEAM MEMBER (not a super admin)
    // ========================================================================

    /**
     * A user with ONE active tblPartnerTeamMembers row (isSuperAdmin=0) must
     * resolve to: isSuperAdmin=false, isPartnerAdmin=true, isAdmin=true (via
     * the membership, NOT via isSuperAdmin), partnerID=that partner's real
     * (int) partnerID.
     */
    public function testPartnerTeamMemberGetsEnrichedFlags(): void
    {
        $user = $this->createTestUser(); // isSuperAdmin defaults to 0 (DB default)
        $partnerID = $this->createPartner();
        $this->createMembership($partnerID, $user['userID']);
        $this->establishRealSession($user['userID']);

        $result = \Auth::getCurrentUser();

        $this->assertIsArray($result);

        $this->assertSame(false, $result['isSuperAdmin'], 'isSuperAdmin must be FALSE — this user is not a super admin');
        $this->assertSame(true, $result['isPartnerAdmin'], 'isPartnerAdmin must be TRUE — this user has an active partner membership');
        $this->assertSame(true, $result['isAdmin'], 'isAdmin must be TRUE (derived from the membership) even though isSuperAdmin is FALSE');
        $this->assertSame($partnerID, $result['partnerID'], 'partnerID must be the (int) partnerID of the active membership');
        $this->assertIsInt($result['partnerID'], 'partnerID must be a genuine PHP int, not a numeric string from mysqli');

        $this->assertPreExistingKeysIntact($user, $result);
    }

    /**
     * When a user has MULTIPLE active memberships, the root-admin membership
     * must be preferred as the "primary" partnerID (ORDER BY isRootAdmin DESC,
     * partnerID ASC, LIMIT 1 in Auth::getCurrentUser()).
     */
    public function testPartnerTeamMemberWithMultipleMembershipsPrefersRootAdmin(): void
    {
        $user = $this->createTestUser();

        // 🔢 A non-root membership at a LOWER partnerID (would win on a plain
        //    "partnerID ASC" tiebreak alone) plus a root-admin membership at a
        //    HIGHER partnerID — proving isRootAdmin DESC is applied FIRST.
        $nonRootPartnerID = $this->createPartner();
        $rootPartnerID = $this->createPartner();
        $this->createMembership($nonRootPartnerID, $user['userID'], ['role' => 'admin', 'isRootAdmin' => 0]);
        $this->createMembership($rootPartnerID, $user['userID'], ['role' => 'root-admin', 'isRootAdmin' => 1]);

        $this->establishRealSession($user['userID']);

        $result = \Auth::getCurrentUser();

        $this->assertSame(
            $rootPartnerID,
            $result['partnerID'],
            'The root-admin membership must be preferred as the primary partnerID, regardless of partnerID ordering'
        );
        $this->assertTrue($result['isPartnerAdmin']);
        $this->assertTrue($result['isAdmin']);
    }

    // ========================================================================
    // ✅ PERSONA #3 — PLAIN USER (no super admin, no membership)
    // ========================================================================

    /**
     * A plain user (isSuperAdmin=0, zero tblPartnerTeamMembers rows) must
     * resolve to ALL FOUR flags being "no admin access": isSuperAdmin=false,
     * isAdmin=false, isPartnerAdmin=false, partnerID=null. This also proves
     * the membership lookup is resilient to zero rows (no fatal/exception).
     *
     * Also proves the enriched array is what gets CACHED — a second,
     * same-request call to getCurrentUser() must return the identical
     * enriched shape (not re-hit the DB and somehow drop the new keys).
     */
    public function testPlainUserGetsAllFalseFlags(): void
    {
        $user = $this->createTestUser();
        $this->establishRealSession($user['userID']);

        $result = \Auth::getCurrentUser();

        $this->assertIsArray($result);
        $this->assertSame(false, $result['isSuperAdmin']);
        $this->assertSame(false, $result['isPartnerAdmin']);
        $this->assertSame(false, $result['isAdmin']);
        $this->assertNull($result['partnerID']);

        $this->assertPreExistingKeysIntact($user, $result);

        // 💾 Repeat call must hit the static cache and return the SAME
        // enriched array (Auth::$currentUser caches the enriched result, not
        // the raw pre-enrichment DB row).
        $second = \Auth::getCurrentUser();
        $this->assertSame($result, $second, 'A second call within the same request must return the identical cached, enriched array');
    }
}
