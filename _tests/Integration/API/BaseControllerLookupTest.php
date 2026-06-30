<?php
/**
 * ============================================================================
 * 🧪 BaseController DB-Lookup Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Pins the database-backed user resolvers behind the API auth seam — the
 * helpers getCurrentUser() falls through to: getUserById(), getUserByApiKey().
 * These are private and query tblUsers / tblAPIKeys, so they live under
 * Integration/ and are exercised via reflection against signula_test. Without a
 * live DB they error/skip exactly like the other Integration suites (expected).
 *
 * The JWT stub (getUserByToken → null) and the 401 emission are pinned in the
 * pure-path unit test Unit/API/BaseControllerAuthTest.php.
 *
 * ⚠️ NEEDS-LEAD-REVIEW (documented, NOT fixed here)
 * --------------------------------------------------
 *  • Account-status casing mismatch. getUserById()/getUserByApiKey() filter on
 *    accountStatus = 'active' (lowercase), while the rest of the app and the
 *    test fixtures use 'Active' (title-case — see Auth.php / DatabaseTestCase /
 *    AuthLoginTest). On a case-SENSITIVE collation this means an otherwise-valid
 *    'Active' user would NOT resolve through the API auth path. Pinned by
 *    testGetUserByIdActiveCasing(): the test asserts whichever behaviour the
 *    test DB's collation produces and documents the divergence for the lead.
 *
 * @package    SIGNula\Tests\Integration\API
 * @version    2.7.0-beta
 * @see        web/private_html/api/BaseController.php
 */

namespace SIGNula\Tests\Integration\API;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionMethod;

// 📦 Source classes. ErrorLogger is referenced inside the catch blocks.
requireSource('_config/database.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/api/Response.php');
requireSource('private_html/api/Router.php');
requireSource('private_html/api/BaseController.php');

/**
 * Concrete BaseController so it can be instantiated for reflection-driven tests.
 */
class LookupProbeController extends \BaseController
{
    public function __construct()
    {
        // No router needed: we call the private DB resolvers directly.
    }
}

/**
 * BaseController DB-Lookup Characterization Test Suite
 */
class BaseControllerLookupTest extends DatabaseTestCase
{
    /**
     * Tables touched by this suite (rolled back per test).
     */
    protected array $truncateTables = ['tblUsers'];

    /**
     * Call a private BaseController method via reflection.
     */
    private function callPrivate(\BaseController $c, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(\BaseController::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($c, $args);
    }

    // ========================================================================
    // 🔍 getUserById()
    // ========================================================================

    /**
     * getUserById() returns null for an id that does not exist.
     */
    public function testGetUserByIdReturnsNullForUnknownId(): void
    {
        $controller = new LookupProbeController();

        $this->assertNull($this->callPrivate($controller, 'getUserById', [999999]));
    }

    /**
     * getUserById() characterizes the accountStatus = 'active' filter.
     *
     * 📝 The query filters on lowercase 'active'. Depending on the test DB's
     * collation (case-insensitive utf8mb4_* by default vs a case-sensitive _bin
     * collation) a title-case 'Active' user may or may not resolve. We assert
     * the OBSERVED behaviour and document the casing divergence above.
     */
    public function testGetUserByIdActiveCasing(): void
    {
        $controller = new LookupProbeController();

        $userID = $this->insertRecord('tblUsers', [
            // 🆔 userUUID is NOT NULL / UNIQUE with no DB default
            'userUUID'      => self::generateTestUuid(),
            'email'         => random_email('bc'),
            'username'      => 'bc_' . uniqid(),
            'passwordHash'  => password_hash('x', PASSWORD_ARGON2ID),
            'displayName'   => 'BC User',
            'accountStatus' => 'Active', // title-case, as the rest of the app uses
            'emailVerified' => 1,
            'createdAt'     => date('Y-m-d H:i:s'),
        ]);

        $result = $this->callPrivate($controller, 'getUserById', [$userID]);

        // 🔎 Determine the DB's effective case sensitivity for this comparison.
        $collationRow = $this->db->query(
            "SELECT ('Active' = 'active') AS caseInsensitive"
        )->fetch_assoc();
        $caseInsensitive = (int) $collationRow['caseInsensitive'] === 1;

        if ($caseInsensitive) {
            // Default MySQL collation: 'Active' == 'active' → user resolves.
            $this->assertIsArray($result, "On a case-insensitive collation a title-case 'Active' user resolves");
            $this->assertSame($userID, (int) $result['userID']);
        } else {
            // Case-sensitive collation: the lowercase filter excludes 'Active'.
            $this->assertNull($result, "On a case-sensitive collation the 'active' filter excludes 'Active' users (divergence)");
        }
    }

    /**
     * getUserById() resolves a user explicitly stored with lowercase 'active'.
     *
     * This proves the resolver works for the status string it actually filters
     * on, independent of collation.
     */
    public function testGetUserByIdResolvesLowercaseActiveUser(): void
    {
        $controller = new LookupProbeController();

        $userID = $this->insertRecord('tblUsers', [
            // 🆔 userUUID is NOT NULL / UNIQUE with no DB default
            'userUUID'      => self::generateTestUuid(),
            'email'         => random_email('bc'),
            'username'      => 'bc_' . uniqid(),
            'passwordHash'  => password_hash('x', PASSWORD_ARGON2ID),
            'displayName'   => 'BC User',
            'accountStatus' => 'active', // exact lowercase the query filters on
            'emailVerified' => 1,
            'createdAt'     => date('Y-m-d H:i:s'),
        ]);

        $result = $this->callPrivate($controller, 'getUserById', [$userID]);

        $this->assertIsArray($result);
        $this->assertSame($userID, (int) $result['userID']);
    }

    // ========================================================================
    // 🔑 getUserByApiKey()
    // ========================================================================

    /**
     * getUserByApiKey() returns null for an unknown / non-existent key.
     */
    public function testGetUserByApiKeyReturnsNullForUnknownKey(): void
    {
        $controller = new LookupProbeController();

        $this->assertNull(
            $this->callPrivate($controller, 'getUserByApiKey', ['nonexistent-api-key-value'])
        );
    }
}
