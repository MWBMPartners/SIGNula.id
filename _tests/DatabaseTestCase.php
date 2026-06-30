<?php
/**
 * Database Test Case
 * Base class for tests that require database access
 *
 * @package SIGNula\Tests
 * @version 1.0.0
 */

namespace SIGNula\Tests;

use mysqli;

/**
 * DatabaseTestCase for tests requiring database access
 * Automatically handles database setup/teardown and transactions
 */
abstract class DatabaseTestCase extends TestCase
{
    /**
     * Database connection
     *
     * @var mysqli
     */
    protected mysqli $db;

    /**
     * Whether to use transactions (rollback after each test)
     *
     * @var bool
     */
    protected bool $useTransactions = true;

    /**
     * Tables to truncate before each test
     *
     * @var array
     */
    protected array $truncateTables = [];

    /**
     * Set up database connection before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Get database connection
        $this->db = mock_database();
        
        // Start transaction if enabled
        if ($this->useTransactions) {
            $this->db->begin_transaction();
        }
        
        // Truncate specified tables
        if (!empty($this->truncateTables)) {
            $this->truncateTables($this->truncateTables);
        }
    }

    /**
     * Rollback transaction or clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Rollback transaction if enabled
        if ($this->useTransactions && $this->db && $this->db->ping()) {
            $this->db->rollback();
        }
        
        parent::tearDown();
    }

    /**
     * Truncate specified tables
     *
     * @param array $tables Array of table names
     * @return void
     */
    protected function truncateTables(array $tables): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        
        foreach ($tables as $table) {
            $this->db->query("TRUNCATE TABLE `{$table}`");
        }
        
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Insert a record into database and return the inserted ID
     *
     * @param string $table Table name
     * @param array $data Column => value pairs
     * @return int Inserted ID
     */
    protected function insertRecord(string $table, array $data): int
    {
        $columns = array_keys($data);
        $values = array_values($data);
        
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $columnList = implode(', ', $columns);
        
        $query = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})";
        $stmt = $this->db->prepare($query);
        
        // Determine types for bind_param
        $types = '';
        foreach ($values as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        
        return $stmt->insert_id;
    }

    /**
     * Create a test user and return user data
     *
     * @param array $overrides Override default user data
     * @return array User data including userID
     */
    protected function createTestUser(array $overrides = []): array
    {
        $defaults = [
            'email' => random_email('test'),
            'passwordHash' => password_hash('password123', PASSWORD_ARGON2ID),
            'displayName' => 'Test User',
            'firstName' => 'Test',
            'lastName' => 'User',
            'accountStatus' => 'active', // 🔧 B-028: ENUM canonical case ('active', not 'Active')
            'emailVerified' => 1,
            'mfaEnabled' => 0,
            'createdAt' => date('Y-m-d H:i:s')
        ];
        
        $data = array_merge($defaults, $overrides);
        $userID = $this->insertRecord('tblUsers', $data);
        $data['userID'] = $userID;
        
        return $data;
    }

    /**
     * Assert that a record exists in database
     *
     * @param string $table Table name
     * @param array $conditions WHERE conditions
     * @param string $message Optional message
     * @return void
     */
    protected function assertDatabaseHas(string $table, array $conditions, string $message = ''): void
    {
        $whereClause = [];
        $params = [];
        $types = '';
        
        foreach ($conditions as $column => $value) {
            $whereClause[] = "`{$column}` = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $where = implode(' AND ', $whereClause);
        $query = "SELECT COUNT(*) as count FROM {$table} WHERE {$where}";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $this->assertGreaterThan(
            0,
            $result['count'],
            $message ?: "Failed asserting that table '{$table}' has matching record"
        );
    }

    /**
     * Assert that a record does NOT exist in database
     *
     * @param string $table Table name
     * @param array $conditions WHERE conditions
     * @param string $message Optional message
     * @return void
     */
    protected function assertDatabaseMissing(string $table, array $conditions, string $message = ''): void
    {
        $whereClause = [];
        $params = [];
        $types = '';
        
        foreach ($conditions as $column => $value) {
            $whereClause[] = "`{$column}` = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $where = implode(' AND ', $whereClause);
        $query = "SELECT COUNT(*) as count FROM {$table} WHERE {$where}";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $this->assertEquals(
            0,
            $result['count'],
            $message ?: "Failed asserting that table '{$table}' does not have matching record"
        );
    }

    /**
     * Get a record from database
     *
     * @param string $table Table name
     * @param array $conditions WHERE conditions
     * @return array|null Record data or null if not found
     */
    protected function getRecord(string $table, array $conditions): ?array
    {
        $whereClause = [];
        $params = [];
        $types = '';
        
        foreach ($conditions as $column => $value) {
            $whereClause[] = "`{$column}` = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $where = implode(' AND ', $whereClause);
        $query = "SELECT * FROM {$table} WHERE {$where} LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc() ?: null;
    }

    /**
     * Count records in database table
     *
     * @param string $table Table name
     * @param array $conditions Optional WHERE conditions
     * @return int Record count
     */
    protected function countRecords(string $table, array $conditions = []): int
    {
        if (empty($conditions)) {
            $query = "SELECT COUNT(*) as count FROM {$table}";
            $result = $this->db->query($query);
            return $result->fetch_assoc()['count'];
        }
        
        $whereClause = [];
        $params = [];
        $types = '';
        
        foreach ($conditions as $column => $value) {
            $whereClause[] = "`{$column}` = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $where = implode(' AND ', $whereClause);
        $query = "SELECT COUNT(*) as count FROM {$table} WHERE {$where}";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['count'];
    }
}
