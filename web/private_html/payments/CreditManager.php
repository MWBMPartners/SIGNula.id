<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.4.0-beta
 */

/**
 * ============================================================================
 * 💰 SIGNula - Credit Manager
 * ============================================================================
 *
 * Purpose: Manage credit balances and transactions for users and partners
 * PHP Version: 8.3+
 *
 * Features:
 * - Multi-owner credit balances (user accounts and partner accounts)
 * - Row-level locking (SELECT ... FOR UPDATE) to prevent race conditions
 * - Atomic deposit, withdrawal, payment, refund, adjustment, and transfer operations
 * - Full transaction history with pagination and filtering
 * - Partner-scoped balances (optional partnerID isolation)
 * - Multi-currency support (ISO 4217)
 * - Admin dashboard statistics
 * - Email notifications for credit top-ups
 * - Comprehensive activity logging for audit trail
 *
 * Database Tables:
 * - tblCreditBalances: Stores current balance and lifetime totals per owner
 * - tblCreditTransactions: Immutable ledger of all credit mutations
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html (FOR UPDATE locking)
 * @see https://en.wikipedia.org/wiki/ISO_4217 (Currency codes)
 * @see https://www.php.net/manual/en/class.mysqli.php (MySQLi reference)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.4.0-beta
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access — this file must be loaded through the SIGNula bootstrap
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 💳 Credit Manager Class
 *
 * Static utility class for managing credit balances and transactions.
 * All methods are public static — no instantiation required.
 *
 * Every balance-mutating operation uses MySQL row-level locking (SELECT ... FOR UPDATE)
 * inside an explicit transaction to guarantee atomicity and prevent race conditions
 * when concurrent requests target the same balance.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html
 */
class CreditManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string Default currency (ISO 4217) — configurable via tblSettings 'credit.default_currency' */
    const DEFAULT_CURRENCY = 'GBP';

    /** @var array Valid owner types for credit balances */
    const VALID_OWNER_TYPES = ['user', 'partner'];

    /**
     * @var array Valid transaction types matching the REAL `type` ENUM column
     * on tblCreditTransactions (migration 012 — verified against
     * information_schema; the column is named `type`, NOT `transactionType`,
     * and its ENUM does NOT include a 'transfer' member — B-071 fix).
     * self::transfer() therefore records its two ledger rows as an ordinary
     * 'withdrawal' (source) + 'deposit' (destination), tagged
     * `referenceType = 'transfer'` so they remain identifiable/linkable —
     * see self::transfer()'s doc-comment.
     */
    const VALID_TRANSACTION_TYPES = [
        'deposit', 'withdrawal', 'payment', 'refund', 'adjustment', 'remittance', 'promotional'
    ];

    /** @var float Minimum transaction amount (must be positive and non-zero) */
    const MIN_TRANSACTION_AMOUNT = 0.01;

    // ========================================================================
    // 💰 BALANCE RETRIEVAL
    // ========================================================================

    /**
     * 💰 Get the current credit balance for an owner
     *
     * Returns the current balance as a float. If no balance record exists for the
     * specified owner, a new record is created automatically with a 0.00 balance.
     *
     * @param string   $ownerType  Owner type: 'user' or 'partner'
     * @param int      $ownerID    The owner's unique identifier (userID or partnerID)
     * @param int|null $partnerID  Optional partner scope (null = global/SIGNula-wide balance)
     * @param string   $currency   ISO 4217 currency code (default: 'GBP')
     *
     * @return float Current balance amount (always >= 0.00)
     *
     * @see self::getBalanceRecord() For the full balance record with totals
     * @see https://en.wikipedia.org/wiki/ISO_4217 (Currency codes)
     */
    public static function getBalance(
        string $ownerType,
        int $ownerID,
        ?int $partnerID = null,
        string $currency = 'GBP'
    ): float {
        // 📋 Retrieve or create the full balance record
        $record = self::getBalanceRecord($ownerType, $ownerID, $partnerID, $currency);

        // 💰 Return the balance as a float, defaulting to 0.00 if somehow null
        return (float)($record['balance'] ?? 0.00);
    }

    /**
     * 📋 Get the full balance record for an owner
     *
     * Returns the complete row from tblCreditBalances including balanceID,
     * balance, currency, lastTransactionAt, timestamps, etc. (the table has
     * no totalDeposited/totalWithdrawn columns — see B-071 fix note in
     * self::getBalanceRecord()'s body). If no record exists, one is created
     * with a 0.00 initial balance.
     *
     * @param string   $ownerType  Owner type: 'user' or 'partner'
     * @param int      $ownerID    The owner's unique identifier
     * @param int|null $partnerID  Optional partner scope
     * @param string   $currency   ISO 4217 currency code (default: 'GBP')
     *
     * @return array Associative array of the balance record from tblCreditBalances
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
     */
    public static function getBalanceRecord(
        string $ownerType,
        int $ownerID,
        ?int $partnerID = null,
        string $currency = 'GBP'
    ): array {
        // 🛡️ Validate the owner type to prevent invalid ENUM values
        if (!in_array($ownerType, self::VALID_OWNER_TYPES, true)) {
            // ⚠️ Log a warning and default to 'user' to avoid SQL errors
            ActivityLogger::log(
                null,
                'credit_invalid_owner_type',
                'payment',
                'warning',
                'Invalid owner type provided to CreditManager::getBalanceRecord: ' . $ownerType,
                ['ownerType' => $ownerType, 'ownerID' => $ownerID]
            );
            $ownerType = 'user';
        }

        // 🔍 Attempt to fetch the existing balance record
        if ($partnerID !== null) {
            // 🏢 Partner-scoped balance lookup
            $record = Database::fetchOne(
                "SELECT * FROM tblCreditBalances
                 WHERE ownerType = ? AND ownerID = ? AND partnerID = ? AND currency = ?",
                [$ownerType, $ownerID, $partnerID, $currency],
                'siis'
            );
        } else {
            // 🌐 Global (non-partner-scoped) balance lookup — partnerID IS NULL
            $record = Database::fetchOne(
                "SELECT * FROM tblCreditBalances
                 WHERE ownerType = ? AND ownerID = ? AND partnerID IS NULL AND currency = ?",
                [$ownerType, $ownerID, $currency],
                'sis'
            );
        }

        // ✅ If record exists, return it immediately
        if ($record) {
            return $record;
        }

        // 🆕 No record found — create a new one with 0.00 balance
        //
        // 🩹 B-071 fix: the REAL tblCreditBalances table (migration 012) has
        // NO `totalDeposited`/`totalWithdrawn` columns — only `balance`. The
        // running-lifetime-total columns this class previously wrote to are
        // an immutable ledger concern, not a balance-row concern: every
        // deposit/withdrawal/payment/refund/adjustment/transfer already
        // writes a full audit row to tblCreditTransactions (balanceBefore/
        // balanceAfter/amount/type), so a lifetime total is always exactly
        // `SUM(amount) WHERE type = 'deposit'` (etc.) over that ledger —
        // nothing is lost by not persisting a running total here, and NOT
        // persisting one removes an entire class of drift bug (a running
        // total that silently disagrees with its own ledger after a manual
        // DB fix, a failed mid-transaction write, etc). No caller anywhere
        // in the codebase reads totalDeposited/totalWithdrawn (grep-verified
        // — see the CreditManager.php class doc-comment / build report), so
        // this is a clean removal, not a stub.
        try {
            Database::query(
                "INSERT INTO tblCreditBalances
                    (ownerType, ownerID, partnerID, currency, balance)
                 VALUES (?, ?, ?, ?, 0.00)",
                [$ownerType, $ownerID, $partnerID, $currency],
                'siis'
            );

            $balanceID = Database::getLastInsertId();

            // 📝 Log the creation of a new credit balance record
            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_balance_created',
                'payment',
                'info',
                'New credit balance record created for ' . $ownerType . ' #' . $ownerID,
                [
                    'balanceID'  => $balanceID,
                    'ownerType'  => $ownerType,
                    'ownerID'    => $ownerID,
                    'partnerID'  => $partnerID,
                    'currency'   => $currency,
                ]
            );

            // 🔍 Fetch the newly created record and return it
            $newRecord = Database::fetchOne(
                "SELECT * FROM tblCreditBalances WHERE balanceID = ?",
                [$balanceID],
                'i'
            );

            return $newRecord ?: [
                'balanceID'        => $balanceID,
                'ownerType'        => $ownerType,
                'ownerID'          => $ownerID,
                'partnerID'        => $partnerID,
                'currency'         => $currency,
                'balance'          => '0.00',
                'lastTransactionAt' => null,
                'createdAt'        => date('Y-m-d H:i:s'),
                'updatedAt'        => date('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            // ⚠️ Insert may fail due to race condition (another request created it first)
            // Attempt to fetch again in case of duplicate key
            if ($partnerID !== null) {
                $record = Database::fetchOne(
                    "SELECT * FROM tblCreditBalances
                     WHERE ownerType = ? AND ownerID = ? AND partnerID = ? AND currency = ?",
                    [$ownerType, $ownerID, $partnerID, $currency],
                    'siis'
                );
            } else {
                $record = Database::fetchOne(
                    "SELECT * FROM tblCreditBalances
                     WHERE ownerType = ? AND ownerID = ? AND partnerID IS NULL AND currency = ?",
                    [$ownerType, $ownerID, $currency],
                    'sis'
                );
            }

            if ($record) {
                return $record;
            }

            // 🚨 If still no record, something is seriously wrong — log and return a safe default
            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_balance_error',
                'payment',
                'error',
                'Failed to create or retrieve credit balance record: ' . $e->getMessage(),
                [
                    'ownerType' => $ownerType,
                    'ownerID'   => $ownerID,
                    'partnerID' => $partnerID,
                    'currency'  => $currency,
                    'error'     => $e->getMessage(),
                ]
            );

            // Return a safe default so callers don't break
            return [
                'balanceID'        => 0,
                'ownerType'        => $ownerType,
                'ownerID'          => $ownerID,
                'partnerID'        => $partnerID,
                'currency'         => $currency,
                'balance'          => '0.00',
                'lastTransactionAt' => null,
                'createdAt'        => date('Y-m-d H:i:s'),
                'updatedAt'        => date('Y-m-d H:i:s'),
            ];
        }
    }

    // ========================================================================
    // 💵 DEPOSIT
    // ========================================================================

    /**
     * 💵 Deposit credits into an owner's balance
     *
     * Adds the specified amount to the owner's credit balance using row-level
     * locking (SELECT ... FOR UPDATE) to prevent race conditions. Creates the
     * balance record if it does not exist. Records the transaction in
     * tblCreditTransactions and optionally sends an email notification.
     *
     * @param string $ownerType   Owner type: 'user' or 'partner'
     * @param int    $ownerID     The owner's unique identifier
     * @param float  $amount      Amount to deposit (must be > 0)
     * @param string $description Human-readable description of the deposit
     * @param array  $options     Optional parameters:
     *                            - 'partnerID'     (int|null)  Partner scope
     *                            - 'currency'      (string)    ISO 4217 code (default: 'GBP')
     *                            - 'referenceType'  (string)   e.g. 'payment', 'promo', 'manual'
     *                            - 'referenceID'    (int|null) Linked record ID
     *                            - 'performedBy'    (int|null) Admin/user who initiated
     *                            - 'metadata'       (array)    Additional JSON metadata
     *
     * @return array Result array:
     *               - 'success'       (bool)  Whether the deposit succeeded
     *               - 'transactionID' (int)   The new transaction record ID
     *               - 'newBalance'    (float) The balance after the deposit
     *               - 'message'       (string) Error message on failure
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html (FOR UPDATE)
     */
    public static function deposit(
        string $ownerType,
        int $ownerID,
        float $amount,
        string $description,
        array $options = []
    ): array {
        // 🛡️ Validate amount — must be positive
        if ($amount < self::MIN_TRANSACTION_AMOUNT) {
            return [
                'success' => false,
                'message' => 'Deposit amount must be at least ' . number_format(self::MIN_TRANSACTION_AMOUNT, 2),
            ];
        }

        // 🛡️ Validate owner type
        if (!in_array($ownerType, self::VALID_OWNER_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid owner type: ' . $ownerType];
        }

        // 📦 Extract options with sensible defaults
        $partnerID     = $options['partnerID'] ?? null;
        $currency      = $options['currency'] ?? self::DEFAULT_CURRENCY;
        $referenceType = $options['referenceType'] ?? null;
        $referenceID   = $options['referenceID'] ?? null;
        $performedBy   = $options['performedBy'] ?? null;
        $metadata      = $options['metadata'] ?? [];
        $ipAddress     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 💰 Normalise the incoming float to a canonical 2dp bcmath STRING
        // ONCE, at the boundary — every arithmetic step below is then pure
        // bcmath (bcadd/bccomp, scale 2), never native float, matching the
        // house convention established by PaymentManager::changeTier()
        // (§5.3 bcmath rule). B-071 fix.
        $amountStr = number_format($amount, 2, '.', '');

        try {
            // 🔒 BEGIN TRANSACTION — all balance mutations must be atomic.
            // 🩹 B-071 fix: Database::query() ALWAYS prepares its statement
            // (see Database::query()), and mysqli rejects "START
            // TRANSACTION"/"COMMIT"/"ROLLBACK" via the prepared-statement
            // protocol (MySQL error 1295, "This command is not supported in
            // the prepared statement protocol yet") — every one of these
            // calls raised a fatal on a real MySQL server. The fix is the
            // ALREADY-EXISTING Database::beginTransaction()/commit()/
            // rollback() helpers, which call mysqli's native
            // begin_transaction()/commit()/rollback() directly (no prepare).
            Database::beginTransaction();

            // 📋 Ensure the balance record exists (creates if needed)
            $balanceRecord = self::getBalanceRecord($ownerType, $ownerID, $partnerID, $currency);
            $balanceID = (int)$balanceRecord['balanceID'];

            // 🔒 Lock the row with SELECT ... FOR UPDATE to prevent concurrent modifications
            // This ensures no other transaction can read or modify this row until we COMMIT
            $locked = Database::fetchOne(
                "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                [$balanceID],
                'i'
            );

            if (!$locked) {
                // 🚨 Should not happen if getBalanceRecord succeeded — rollback to be safe
                Database::rollback();
                return ['success' => false, 'message' => 'Balance record not found during lock acquisition'];
            }

            // 💰 Calculate new balance — bcmath throughout (B-071 fix)
            $balanceBeforeStr = number_format((float)$locked['balance'], 2, '.', '');
            $balanceAfterStr  = bcadd($balanceBeforeStr, $amountStr, 2);
            $balanceBefore    = (float)$balanceBeforeStr;
            $balanceAfter     = (float)$balanceAfterStr;

            // 📝 Update the balance record.
            // 🩹 B-071 fix: tblCreditBalances has NO totalDeposited column —
            // see the "B-071 fix" comment in self::getBalanceRecord().
            Database::query(
                "UPDATE tblCreditBalances
                 SET balance = ?,
                     lastTransactionAt = NOW(),
                     updatedAt = NOW()
                 WHERE balanceID = ?",
                [$balanceAfter, $balanceID],
                'di'
            );

            // 📝 Create the transaction record in the immutable ledger.
            // 🩹 B-071 fix: the real column is `type`, not `transactionType`
            // (grep + information_schema-verified against migration 012).
            Database::query(
                "INSERT INTO tblCreditTransactions
                    (balanceID, type, amount, balanceBefore, balanceAfter,
                     currency, description, referenceType, referenceID,
                     performedBy, ipAddress, metadata, createdAt)
                 VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $balanceID,
                    $amount,
                    $balanceBefore,
                    $balanceAfter,
                    $currency,
                    $description,
                    $referenceType,
                    $referenceID,
                    $performedBy,
                    $ipAddress,
                    !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                ],
                'idddsssiiss'
            );

            $transactionID = Database::getLastInsertId();

            // ✅ COMMIT — all changes are now permanent
            Database::commit();

            // 📝 Log the deposit activity for audit trail
            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_deposit',
                'payment',
                'info',
                'Credit deposit: ' . $currency . ' ' . number_format($amount, 2) . ' — ' . $description,
                [
                    'transactionID' => $transactionID,
                    'balanceID'     => $balanceID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'balanceBefore' => $balanceBefore,
                    'balanceAfter'  => $balanceAfter,
                    'referenceType' => $referenceType,
                    'referenceID'   => $referenceID,
                    'performedBy'   => $performedBy,
                ]
            );

            // 📧 Send email notification for credit top-up (user accounts only)
            if ($ownerType === 'user') {
                self::sendCreditTopUpEmail($ownerID, $amount, $balanceAfter, $currency, $description);
            }

            // 🔗 Dispatch webhook event if WebhookManager is available
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('credit.deposited', [
                    'transactionID' => $transactionID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'newBalance'    => $balanceAfter,
                ]);
            }

            return [
                'success'       => true,
                'transactionID' => $transactionID,
                'newBalance'    => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            // 🚨 ROLLBACK — undo any partial changes on failure
            try {
                Database::rollback();
            } catch (\Throwable $rollbackException) {
                // ⚠️ Rollback itself failed — log but continue with the original error
                ActivityLogger::log(
                    ($ownerType === 'user') ? $ownerID : null,
                    'credit_rollback_error',
                    'payment',
                    'critical',
                    'Rollback failed during deposit: ' . $rollbackException->getMessage(),
                    ['originalError' => $e->getMessage()]
                );
            }

            // 📝 Log the deposit failure
            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_deposit_error',
                'payment',
                'error',
                'Credit deposit failed: ' . $e->getMessage(),
                [
                    'ownerType' => $ownerType,
                    'ownerID'   => $ownerID,
                    'amount'    => $amount,
                    'currency'  => $currency,
                    'error'     => $e->getMessage(),
                ]
            );

            return ['success' => false, 'message' => 'Failed to process credit deposit'];
        }
    }

    // ========================================================================
    // 💸 WITHDRAWAL
    // ========================================================================

    /**
     * 💸 Withdraw credits from an owner's balance
     *
     * Deducts the specified amount from the owner's credit balance using row-level
     * locking (SELECT ... FOR UPDATE). Checks for sufficient funds before deducting.
     * Records the transaction in tblCreditTransactions.
     *
     * @param string $ownerType   Owner type: 'user' or 'partner'
     * @param int    $ownerID     The owner's unique identifier
     * @param float  $amount      Amount to withdraw (must be > 0)
     * @param string $description Human-readable description of the withdrawal
     * @param array  $options     Optional parameters:
     *                            - 'partnerID'     (int|null)  Partner scope
     *                            - 'currency'      (string)    ISO 4217 code (default: 'GBP')
     *                            - 'referenceType'  (string)   e.g. 'payout', 'fee'
     *                            - 'referenceID'    (int|null) Linked record ID
     *                            - 'performedBy'    (int|null) Admin/user who initiated
     *                            - 'metadata'       (array)    Additional JSON metadata
     *
     * @return array Result array:
     *               - 'success'       (bool)  Whether the withdrawal succeeded
     *               - 'transactionID' (int)   The new transaction record ID (on success)
     *               - 'newBalance'    (float) The balance after the withdrawal (on success)
     *               - 'message'       (string) Error message on failure (e.g. insufficient funds)
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html (FOR UPDATE)
     */
    public static function withdraw(
        string $ownerType,
        int $ownerID,
        float $amount,
        string $description,
        array $options = []
    ): array {
        // 🛡️ Validate amount — must be positive
        if ($amount < self::MIN_TRANSACTION_AMOUNT) {
            return [
                'success' => false,
                'message' => 'Withdrawal amount must be at least ' . number_format(self::MIN_TRANSACTION_AMOUNT, 2),
            ];
        }

        // 🛡️ Validate owner type
        if (!in_array($ownerType, self::VALID_OWNER_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid owner type: ' . $ownerType];
        }

        // 📦 Extract options
        $partnerID     = $options['partnerID'] ?? null;
        $currency      = $options['currency'] ?? self::DEFAULT_CURRENCY;
        $referenceType = $options['referenceType'] ?? null;
        $referenceID   = $options['referenceID'] ?? null;
        $performedBy   = $options['performedBy'] ?? null;
        $metadata      = $options['metadata'] ?? [];
        $ipAddress     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 💰 Normalise to a canonical 2dp bcmath string once, at the boundary (B-071 fix).
        $amountStr = number_format($amount, 2, '.', '');

        try {
            // 🔒 BEGIN TRANSACTION (B-071 fix — see self::deposit()'s doc-comment)
            Database::beginTransaction();

            // 📋 Ensure the balance record exists
            $balanceRecord = self::getBalanceRecord($ownerType, $ownerID, $partnerID, $currency);
            $balanceID = (int)$balanceRecord['balanceID'];

            // 🔒 Lock the row with SELECT ... FOR UPDATE
            $locked = Database::fetchOne(
                "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                [$balanceID],
                'i'
            );

            if (!$locked) {
                Database::rollback();
                return ['success' => false, 'message' => 'Balance record not found during lock acquisition'];
            }

            // 💰 Check sufficient balance — bcmath comparison (B-071 fix)
            $balanceBeforeStr = number_format((float)$locked['balance'], 2, '.', '');
            $balanceBefore    = (float)$balanceBeforeStr;

            if (bccomp($balanceBeforeStr, $amountStr, 2) < 0) {
                // 🚫 Insufficient funds — rollback the transaction lock
                Database::rollback();

                // 📝 Log the failed withdrawal attempt
                ActivityLogger::log(
                    ($ownerType === 'user') ? $ownerID : null,
                    'credit_insufficient_funds',
                    'payment',
                    'warning',
                    'Insufficient credit balance for withdrawal: requested ' . $currency . ' ' . number_format($amount, 2) . ', available ' . $currency . ' ' . number_format($balanceBefore, 2),
                    [
                        'ownerType'     => $ownerType,
                        'ownerID'       => $ownerID,
                        'requested'     => $amount,
                        'available'     => $balanceBefore,
                        'currency'      => $currency,
                        'description'   => $description,
                    ]
                );

                return [
                    'success'  => false,
                    'message'  => 'Insufficient credit balance. Available: ' . $currency . ' ' . number_format($balanceBefore, 2),
                    'balance'  => $balanceBefore,
                ];
            }

            // 💰 Calculate new balance — bcmath (B-071 fix)
            $balanceAfterStr = bcsub($balanceBeforeStr, $amountStr, 2);
            $balanceAfter    = (float)$balanceAfterStr;

            // 📝 Update the balance record.
            // 🩹 B-071 fix: tblCreditBalances has NO totalWithdrawn column.
            Database::query(
                "UPDATE tblCreditBalances
                 SET balance = ?,
                     lastTransactionAt = NOW(),
                     updatedAt = NOW()
                 WHERE balanceID = ?",
                [$balanceAfter, $balanceID],
                'di'
            );

            // 📝 Create the transaction record.
            // 🩹 B-071 fix: the real column is `type`, not `transactionType`.
            Database::query(
                "INSERT INTO tblCreditTransactions
                    (balanceID, type, amount, balanceBefore, balanceAfter,
                     currency, description, referenceType, referenceID,
                     performedBy, ipAddress, metadata, createdAt)
                 VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $balanceID,
                    $amount,
                    $balanceBefore,
                    $balanceAfter,
                    $currency,
                    $description,
                    $referenceType,
                    $referenceID,
                    $performedBy,
                    $ipAddress,
                    !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                ],
                'idddsssiiss'
            );

            $transactionID = Database::getLastInsertId();

            // ✅ COMMIT
            Database::commit();

            // 📝 Log the successful withdrawal
            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_withdrawal',
                'payment',
                'info',
                'Credit withdrawal: ' . $currency . ' ' . number_format($amount, 2) . ' — ' . $description,
                [
                    'transactionID' => $transactionID,
                    'balanceID'     => $balanceID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'balanceBefore' => $balanceBefore,
                    'balanceAfter'  => $balanceAfter,
                    'referenceType' => $referenceType,
                    'referenceID'   => $referenceID,
                    'performedBy'   => $performedBy,
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('credit.withdrawn', [
                    'transactionID' => $transactionID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'newBalance'    => $balanceAfter,
                ]);
            }

            return [
                'success'       => true,
                'transactionID' => $transactionID,
                'newBalance'    => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            // 🚨 ROLLBACK on failure
            try {
                Database::rollback();
            } catch (\Throwable $rollbackException) {
                ActivityLogger::log(
                    ($ownerType === 'user') ? $ownerID : null,
                    'credit_rollback_error',
                    'payment',
                    'critical',
                    'Rollback failed during withdrawal: ' . $rollbackException->getMessage(),
                    ['originalError' => $e->getMessage()]
                );
            }

            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_withdrawal_error',
                'payment',
                'error',
                'Credit withdrawal failed: ' . $e->getMessage(),
                [
                    'ownerType' => $ownerType,
                    'ownerID'   => $ownerID,
                    'amount'    => $amount,
                    'currency'  => $currency,
                    'error'     => $e->getMessage(),
                ]
            );

            return ['success' => false, 'message' => 'Failed to process credit withdrawal'];
        }
    }

    // ========================================================================
    // 🛒 PAYMENT FROM CREDITS
    // ========================================================================

    /**
     * 🛒 Pay for a purchase using credits
     *
     * Convenience wrapper around withdraw() that sets transactionType to 'payment'
     * and optionally links the transaction to a paymentID from tblPayments.
     *
     * @param string   $ownerType   Owner type: 'user' or 'partner'
     * @param int      $ownerID     The owner's unique identifier
     * @param float    $amount      Payment amount (must be > 0)
     * @param string   $description Human-readable description (e.g. "Subscription renewal")
     * @param int|null $paymentID   Optional paymentID from tblPayments to link
     * @param array    $options     Optional parameters:
     *                              - 'partnerID'     (int|null)  Partner scope
     *                              - 'currency'      (string)    ISO 4217 code
     *                              - 'performedBy'   (int|null)  Initiator
     *                              - 'metadata'      (array)     Extra data
     *
     * @return array Result array with 'success', 'transactionID', 'newBalance'
     *
     * @see self::withdraw() For the underlying operation
     */
    public static function payFromCredits(
        string $ownerType,
        int $ownerID,
        float $amount,
        string $description,
        ?int $paymentID = null,
        array $options = []
    ): array {
        // 🛡️ Validate amount
        if ($amount < self::MIN_TRANSACTION_AMOUNT) {
            return [
                'success' => false,
                'message' => 'Payment amount must be at least ' . number_format(self::MIN_TRANSACTION_AMOUNT, 2),
            ];
        }

        // 🛡️ Validate owner type
        if (!in_array($ownerType, self::VALID_OWNER_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid owner type: ' . $ownerType];
        }

        // 📦 Extract options
        $partnerID   = $options['partnerID'] ?? null;
        $currency    = $options['currency'] ?? self::DEFAULT_CURRENCY;
        $performedBy = $options['performedBy'] ?? null;
        $metadata    = $options['metadata'] ?? [];
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 🔗 Include paymentID in metadata for cross-referencing
        if ($paymentID !== null) {
            $metadata['paymentID'] = $paymentID;
        }

        // 💰 Normalise to a canonical 2dp bcmath string once, at the boundary (B-071 fix).
        $amountStr = number_format($amount, 2, '.', '');

        try {
            // 🔒 BEGIN TRANSACTION (B-071 fix — see self::deposit()'s doc-comment)
            Database::beginTransaction();

            // 📋 Ensure balance record exists
            $balanceRecord = self::getBalanceRecord($ownerType, $ownerID, $partnerID, $currency);
            $balanceID = (int)$balanceRecord['balanceID'];

            // 🔒 Lock the row with FOR UPDATE
            $locked = Database::fetchOne(
                "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                [$balanceID],
                'i'
            );

            if (!$locked) {
                Database::rollback();
                return ['success' => false, 'message' => 'Balance record not found during lock acquisition'];
            }

            // 💰 Insufficient funds check — bcmath comparison (B-071 fix)
            $balanceBeforeStr = number_format((float)$locked['balance'], 2, '.', '');
            $balanceBefore    = (float)$balanceBeforeStr;

            if (bccomp($balanceBeforeStr, $amountStr, 2) < 0) {
                Database::rollback();

                ActivityLogger::log(
                    ($ownerType === 'user') ? $ownerID : null,
                    'credit_payment_insufficient',
                    'payment',
                    'warning',
                    'Insufficient credits for payment: requested ' . $currency . ' ' . number_format($amount, 2) . ', available ' . $currency . ' ' . number_format($balanceBefore, 2),
                    [
                        'ownerType'   => $ownerType,
                        'ownerID'     => $ownerID,
                        'requested'   => $amount,
                        'available'   => $balanceBefore,
                        'paymentID'   => $paymentID,
                        'description' => $description,
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Insufficient credit balance. Available: ' . $currency . ' ' . number_format($balanceBefore, 2),
                    'balance' => $balanceBefore,
                ];
            }

            // 💰 Deduct the payment — bcmath (B-071 fix)
            $balanceAfterStr = bcsub($balanceBeforeStr, $amountStr, 2);
            $balanceAfter    = (float)$balanceAfterStr;

            // 🩹 B-071 fix: tblCreditBalances has NO totalWithdrawn column.
            Database::query(
                "UPDATE tblCreditBalances
                 SET balance = ?,
                     lastTransactionAt = NOW(),
                     updatedAt = NOW()
                 WHERE balanceID = ?",
                [$balanceAfter, $balanceID],
                'di'
            );

            // 📝 Create the transaction record — note: type = 'payment'.
            // 🩹 B-071 fix: the real column is `type`, not `transactionType`.
            Database::query(
                "INSERT INTO tblCreditTransactions
                    (balanceID, type, amount, balanceBefore, balanceAfter,
                     currency, description, referenceType, referenceID,
                     performedBy, ipAddress, metadata, createdAt)
                 VALUES (?, 'payment', ?, ?, ?, ?, ?, 'payment', ?, ?, ?, ?, NOW())",
                [
                    $balanceID,
                    $amount,
                    $balanceBefore,
                    $balanceAfter,
                    $currency,
                    $description,
                    $paymentID,
                    $performedBy,
                    $ipAddress,
                    !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                ],
                'idddssiiss'
            );

            $transactionID = Database::getLastInsertId();

            // ✅ COMMIT
            Database::commit();

            // 📝 Log the payment
            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_payment',
                'payment',
                'info',
                'Credit payment: ' . $currency . ' ' . number_format($amount, 2) . ' — ' . $description,
                [
                    'transactionID' => $transactionID,
                    'balanceID'     => $balanceID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'balanceBefore' => $balanceBefore,
                    'balanceAfter'  => $balanceAfter,
                    'paymentID'     => $paymentID,
                    'performedBy'   => $performedBy,
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('credit.payment', [
                    'transactionID' => $transactionID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'newBalance'    => $balanceAfter,
                    'paymentID'     => $paymentID,
                ]);
            }

            return [
                'success'       => true,
                'transactionID' => $transactionID,
                'newBalance'    => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            // 🚨 ROLLBACK
            try {
                Database::rollback();
            } catch (\Throwable $rollbackException) {
                ActivityLogger::log(
                    ($ownerType === 'user') ? $ownerID : null,
                    'credit_rollback_error',
                    'payment',
                    'critical',
                    'Rollback failed during credit payment: ' . $rollbackException->getMessage(),
                    ['originalError' => $e->getMessage()]
                );
            }

            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_payment_error',
                'payment',
                'error',
                'Credit payment failed: ' . $e->getMessage(),
                [
                    'ownerType' => $ownerType,
                    'ownerID'   => $ownerID,
                    'amount'    => $amount,
                    'paymentID' => $paymentID,
                    'error'     => $e->getMessage(),
                ]
            );

            return ['success' => false, 'message' => 'Failed to process credit payment'];
        }
    }

    // ========================================================================
    // 🔄 REFUND TO CREDITS
    // ========================================================================

    /**
     * 🔄 Refund credits back to an owner's balance
     *
     * Convenience wrapper around deposit() that sets transactionType to 'refund'
     * and optionally links the transaction to a paymentID from tblPayments.
     *
     * @param string   $ownerType   Owner type: 'user' or 'partner'
     * @param int      $ownerID     The owner's unique identifier
     * @param float    $amount      Refund amount (must be > 0)
     * @param string   $description Human-readable description
     * @param int|null $paymentID   Optional paymentID to link to
     * @param array    $options     Optional parameters:
     *                              - 'partnerID'     (int|null)  Partner scope
     *                              - 'currency'      (string)    ISO 4217 code
     *                              - 'performedBy'   (int|null)  Initiator
     *                              - 'metadata'      (array)     Extra data
     *
     * @return array Result array with 'success', 'transactionID', 'newBalance'
     *
     * @see self::deposit() For the underlying credit addition operation
     */
    public static function refundToCredits(
        string $ownerType,
        int $ownerID,
        float $amount,
        string $description,
        ?int $paymentID = null,
        array $options = []
    ): array {
        // 🛡️ Validate amount
        if ($amount < self::MIN_TRANSACTION_AMOUNT) {
            return [
                'success' => false,
                'message' => 'Refund amount must be at least ' . number_format(self::MIN_TRANSACTION_AMOUNT, 2),
            ];
        }

        // 🛡️ Validate owner type
        if (!in_array($ownerType, self::VALID_OWNER_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid owner type: ' . $ownerType];
        }

        // 📦 Extract options
        $partnerID   = $options['partnerID'] ?? null;
        $currency    = $options['currency'] ?? self::DEFAULT_CURRENCY;
        $performedBy = $options['performedBy'] ?? null;
        $metadata    = $options['metadata'] ?? [];
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 🔗 Include paymentID in metadata for cross-referencing
        if ($paymentID !== null) {
            $metadata['paymentID'] = $paymentID;
        }

        // 💰 Normalise to a canonical 2dp bcmath string once, at the boundary (B-071 fix).
        $amountStr = number_format($amount, 2, '.', '');

        try {
            // 🔒 BEGIN TRANSACTION (B-071 fix — see self::deposit()'s doc-comment)
            Database::beginTransaction();

            // 📋 Ensure balance record exists
            $balanceRecord = self::getBalanceRecord($ownerType, $ownerID, $partnerID, $currency);
            $balanceID = (int)$balanceRecord['balanceID'];

            // 🔒 Lock the row with FOR UPDATE
            $locked = Database::fetchOne(
                "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                [$balanceID],
                'i'
            );

            if (!$locked) {
                Database::rollback();
                return ['success' => false, 'message' => 'Balance record not found during lock acquisition'];
            }

            // 💰 Calculate new balance (refund adds to balance) — bcmath (B-071 fix)
            $balanceBeforeStr = number_format((float)$locked['balance'], 2, '.', '');
            $balanceAfterStr  = bcadd($balanceBeforeStr, $amountStr, 2);
            $balanceBefore    = (float)$balanceBeforeStr;
            $balanceAfter     = (float)$balanceAfterStr;

            // 🩹 B-071 fix: tblCreditBalances has NO totalDeposited column.
            Database::query(
                "UPDATE tblCreditBalances
                 SET balance = ?,
                     lastTransactionAt = NOW(),
                     updatedAt = NOW()
                 WHERE balanceID = ?",
                [$balanceAfter, $balanceID],
                'di'
            );

            // 📝 Create the transaction record — note: type = 'refund'.
            // 🩹 B-071 fix: the real column is `type`, not `transactionType`.
            Database::query(
                "INSERT INTO tblCreditTransactions
                    (balanceID, type, amount, balanceBefore, balanceAfter,
                     currency, description, referenceType, referenceID,
                     performedBy, ipAddress, metadata, createdAt)
                 VALUES (?, 'refund', ?, ?, ?, ?, ?, 'payment', ?, ?, ?, ?, NOW())",
                [
                    $balanceID,
                    $amount,
                    $balanceBefore,
                    $balanceAfter,
                    $currency,
                    $description,
                    $paymentID,
                    $performedBy,
                    $ipAddress,
                    !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                ],
                'idddssiiss'
            );

            $transactionID = Database::getLastInsertId();

            // ✅ COMMIT
            Database::commit();

            // 📝 Log the refund
            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_refund',
                'payment',
                'info',
                'Credit refund: ' . $currency . ' ' . number_format($amount, 2) . ' — ' . $description,
                [
                    'transactionID' => $transactionID,
                    'balanceID'     => $balanceID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'balanceBefore' => $balanceBefore,
                    'balanceAfter'  => $balanceAfter,
                    'paymentID'     => $paymentID,
                    'performedBy'   => $performedBy,
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('credit.refunded', [
                    'transactionID' => $transactionID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'newBalance'    => $balanceAfter,
                    'paymentID'     => $paymentID,
                ]);
            }

            return [
                'success'       => true,
                'transactionID' => $transactionID,
                'newBalance'    => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            // 🚨 ROLLBACK
            try {
                Database::rollback();
            } catch (\Throwable $rollbackException) {
                ActivityLogger::log(
                    ($ownerType === 'user') ? $ownerID : null,
                    'credit_rollback_error',
                    'payment',
                    'critical',
                    'Rollback failed during credit refund: ' . $rollbackException->getMessage(),
                    ['originalError' => $e->getMessage()]
                );
            }

            ActivityLogger::log(
                ($ownerType === 'user') ? $ownerID : null,
                'credit_refund_error',
                'payment',
                'error',
                'Credit refund failed: ' . $e->getMessage(),
                [
                    'ownerType' => $ownerType,
                    'ownerID'   => $ownerID,
                    'amount'    => $amount,
                    'paymentID' => $paymentID,
                    'error'     => $e->getMessage(),
                ]
            );

            return ['success' => false, 'message' => 'Failed to process credit refund'];
        }
    }

    // ========================================================================
    // ⚖️ ADMIN ADJUSTMENT
    // ========================================================================

    /**
     * ⚖️ Adjust a credit balance (admin operation)
     *
     * Allows an administrator to manually adjust (increase or decrease) a credit
     * balance. The performedBy parameter is required to ensure accountability.
     * A positive amount increases the balance; a negative amount decreases it.
     *
     * @param string $ownerType   Owner type: 'user' or 'partner'
     * @param int    $ownerID     The owner's unique identifier
     * @param float  $amount      Adjustment amount (positive = credit, negative = debit)
     * @param string $description Reason for the adjustment
     * @param int    $performedBy Admin userID who authorised the adjustment (REQUIRED)
     * @param array  $options     Optional parameters:
     *                            - 'partnerID'     (int|null)  Partner scope
     *                            - 'currency'      (string)    ISO 4217 code
     *                            - 'referenceType'  (string)   e.g. 'support_ticket', 'correction'
     *                            - 'referenceID'    (int|null) Linked record ID
     *                            - 'metadata'       (array)    Extra data
     *
     * @return array Result array with 'success', 'transactionID', 'newBalance'
     */
    public static function adjustBalance(
        string $ownerType,
        int $ownerID,
        float $amount,
        string $description,
        int $performedBy,
        array $options = []
    ): array {
        // 🛡️ Validate: amount must not be zero
        if (abs($amount) < self::MIN_TRANSACTION_AMOUNT) {
            return [
                'success' => false,
                'message' => 'Adjustment amount must be at least ' . number_format(self::MIN_TRANSACTION_AMOUNT, 2) . ' (positive or negative)',
            ];
        }

        // 🛡️ Validate owner type
        if (!in_array($ownerType, self::VALID_OWNER_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid owner type: ' . $ownerType];
        }

        // 📦 Extract options
        $partnerID     = $options['partnerID'] ?? null;
        $currency      = $options['currency'] ?? self::DEFAULT_CURRENCY;
        $referenceType = $options['referenceType'] ?? null;
        $referenceID   = $options['referenceID'] ?? null;
        $metadata      = $options['metadata'] ?? [];
        $ipAddress     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 📝 Record which admin performed this in metadata
        $metadata['adjustedBy'] = $performedBy;
        $metadata['adjustmentDirection'] = ($amount > 0) ? 'credit' : 'debit';

        // 💰 Normalise to a canonical 2dp bcmath string once, at the boundary
        // (B-071 fix). $amount may be negative here (debit) — bcadd() below
        // handles the sign correctly since it's a signed bcmath operand.
        $amountStr = number_format($amount, 2, '.', '');

        try {
            // 🔒 BEGIN TRANSACTION (B-071 fix — see self::deposit()'s doc-comment)
            Database::beginTransaction();

            // 📋 Ensure balance record exists
            $balanceRecord = self::getBalanceRecord($ownerType, $ownerID, $partnerID, $currency);
            $balanceID = (int)$balanceRecord['balanceID'];

            // 🔒 Lock the row with FOR UPDATE
            $locked = Database::fetchOne(
                "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                [$balanceID],
                'i'
            );

            if (!$locked) {
                Database::rollback();
                return ['success' => false, 'message' => 'Balance record not found during lock acquisition'];
            }

            // 💰 Calculate new balance — bcmath (B-071 fix)
            $balanceBeforeStr = number_format((float)$locked['balance'], 2, '.', '');
            $balanceAfterStr  = bcadd($balanceBeforeStr, $amountStr, 2);
            $balanceBefore    = (float)$balanceBeforeStr;
            $balanceAfter     = (float)$balanceAfterStr;

            // 🚫 Prevent negative balance from adjustment
            if (bccomp($balanceAfterStr, '0.00', 2) < 0) {
                Database::rollback();

                ActivityLogger::log(
                    $performedBy,
                    'credit_adjustment_denied',
                    'payment',
                    'warning',
                    'Adjustment denied: would result in negative balance for ' . $ownerType . ' #' . $ownerID,
                    [
                        'ownerType'     => $ownerType,
                        'ownerID'       => $ownerID,
                        'amount'        => $amount,
                        'balanceBefore' => $balanceBefore,
                        'wouldBe'       => $balanceAfter,
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Adjustment would result in negative balance. Current: ' . $currency . ' ' . number_format($balanceBefore, 2) . ', adjustment: ' . $currency . ' ' . number_format($amount, 2),
                    'balance' => $balanceBefore,
                ];
            }

            // 🩹 B-071 fix: tblCreditBalances has NO totalDeposited/
            // totalWithdrawn columns — the running-lifetime-total update
            // that used to live here is removed; see self::getBalanceRecord().
            Database::query(
                "UPDATE tblCreditBalances
                 SET balance = ?,
                     lastTransactionAt = NOW(),
                     updatedAt = NOW()
                 WHERE balanceID = ?",
                [$balanceAfter, $balanceID],
                'di'
            );

            // 📝 Create the transaction record — type = 'adjustment'.
            // Note: amount stored is signed (positive=credit, negative=debit);
            // direction is also inferrable from balanceBefore/After.
            // 🩹 B-071 fix: the real column is `type`, not `transactionType`.
            Database::query(
                "INSERT INTO tblCreditTransactions
                    (balanceID, type, amount, balanceBefore, balanceAfter,
                     currency, description, referenceType, referenceID,
                     performedBy, ipAddress, metadata, createdAt)
                 VALUES (?, 'adjustment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $balanceID,
                    $amount,
                    $balanceBefore,
                    $balanceAfter,
                    $currency,
                    $description,
                    $referenceType,
                    $referenceID,
                    $performedBy,
                    $ipAddress,
                    !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                ],
                'idddsssiiss'
            );

            $transactionID = Database::getLastInsertId();

            // ✅ COMMIT
            Database::commit();

            // 📝 Log the adjustment — use the admin's userID as the actor
            $direction = ($amount > 0) ? 'increase' : 'decrease';
            ActivityLogger::log(
                $performedBy,
                'credit_adjustment',
                'payment',
                'warning',
                'Admin credit adjustment (' . $direction . '): ' . $currency . ' ' . number_format(abs($amount), 2) . ' for ' . $ownerType . ' #' . $ownerID . ' — ' . $description,
                [
                    'transactionID' => $transactionID,
                    'balanceID'     => $balanceID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'direction'     => $direction,
                    'currency'      => $currency,
                    'balanceBefore' => $balanceBefore,
                    'balanceAfter'  => $balanceAfter,
                    'performedBy'   => $performedBy,
                    'referenceType' => $referenceType,
                    'referenceID'   => $referenceID,
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('credit.adjusted', [
                    'transactionID' => $transactionID,
                    'ownerType'     => $ownerType,
                    'ownerID'       => $ownerID,
                    'amount'        => $amount,
                    'direction'     => $direction,
                    'currency'      => $currency,
                    'newBalance'    => $balanceAfter,
                    'performedBy'   => $performedBy,
                ]);
            }

            return [
                'success'       => true,
                'transactionID' => $transactionID,
                'newBalance'    => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            // 🚨 ROLLBACK
            try {
                Database::rollback();
            } catch (\Throwable $rollbackException) {
                ActivityLogger::log(
                    $performedBy,
                    'credit_rollback_error',
                    'payment',
                    'critical',
                    'Rollback failed during adjustment: ' . $rollbackException->getMessage(),
                    ['originalError' => $e->getMessage()]
                );
            }

            ActivityLogger::log(
                $performedBy,
                'credit_adjustment_error',
                'payment',
                'error',
                'Credit adjustment failed: ' . $e->getMessage(),
                [
                    'ownerType'   => $ownerType,
                    'ownerID'     => $ownerID,
                    'amount'      => $amount,
                    'performedBy' => $performedBy,
                    'error'       => $e->getMessage(),
                ]
            );

            return ['success' => false, 'message' => 'Failed to process credit adjustment'];
        }
    }

    // ========================================================================
    // 📜 TRANSACTION HISTORY
    // ========================================================================

    /**
     * 📜 Get paginated transaction history for an owner
     *
     * Returns an array of transaction records from tblCreditTransactions,
     * ordered by most recent first. Supports filtering by partner, currency,
     * transaction type, and date range.
     *
     * @param string $ownerType Owner type: 'user' or 'partner'
     * @param int    $ownerID   The owner's unique identifier
     * @param int    $limit     Maximum number of records to return (default: 25, max: 100)
     * @param int    $offset    Offset for pagination (default: 0)
     * @param array  $filters   Optional filters:
     *                          - 'partnerID'       (int|null) Filter by partner scope
     *                          - 'currency'        (string)   Filter by currency code
     *                          - 'transactionType' (string)   Filter by type (deposit, withdrawal, etc.)
     *                          - 'dateFrom'        (string)   Start date (Y-m-d format)
     *                          - 'dateTo'          (string)   End date (Y-m-d format)
     *
     * @return array Array of transaction records (associative arrays)
     *
     * @see self::getTransactionCount() For the total count matching the same filters
     */
    public static function getTransactionHistory(
        string $ownerType,
        int $ownerID,
        int $limit = 25,
        int $offset = 0,
        array $filters = []
    ): array {
        // 🛡️ Enforce reasonable pagination limits
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);

        // 🏗️ Build the query dynamically based on filters
        // Join tblCreditTransactions with tblCreditBalances to scope by owner
        $sql = "SELECT ct.*
                FROM tblCreditTransactions ct
                INNER JOIN tblCreditBalances cb ON ct.balanceID = cb.balanceID
                WHERE cb.ownerType = ? AND cb.ownerID = ?";
        $params = [$ownerType, $ownerID];
        $types  = 'si';

        // 🏢 Filter by partnerID (if provided)
        if (isset($filters['partnerID'])) {
            if ($filters['partnerID'] === null) {
                $sql .= " AND cb.partnerID IS NULL";
            } else {
                $sql .= " AND cb.partnerID = ?";
                $params[] = (int)$filters['partnerID'];
                $types .= 'i';
            }
        }

        // 💱 Filter by currency
        if (!empty($filters['currency'])) {
            $sql .= " AND ct.currency = ?";
            $params[] = $filters['currency'];
            $types .= 's';
        }

        // 🏷️ Filter by transaction type
        if (!empty($filters['transactionType'])) {
            // 🛡️ Validate the transaction type to prevent injection
            if (in_array($filters['transactionType'], self::VALID_TRANSACTION_TYPES, true)) {
                $sql .= " AND ct.type = ?";
                $params[] = $filters['transactionType'];
                $types .= 's';
            }
        }

        // 📅 Filter by date range — from
        if (!empty($filters['dateFrom'])) {
            $sql .= " AND ct.createdAt >= ?";
            $params[] = $filters['dateFrom'] . ' 00:00:00';
            $types .= 's';
        }

        // 📅 Filter by date range — to
        if (!empty($filters['dateTo'])) {
            $sql .= " AND ct.createdAt <= ?";
            $params[] = $filters['dateTo'] . ' 23:59:59';
            $types .= 's';
        }

        // 🔄 Order by most recent first, then apply pagination
        $sql .= " ORDER BY ct.createdAt DESC, ct.transactionID DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        return Database::fetchAll($sql, $params, $types);
    }

    /**
     * 🔢 Get the total count of transactions for an owner (for pagination)
     *
     * Returns the total number of transactions matching the given filters,
     * suitable for calculating page counts in paginated UIs.
     *
     * @param string $ownerType Owner type: 'user' or 'partner'
     * @param int    $ownerID   The owner's unique identifier
     * @param array  $filters   Same filters as getTransactionHistory()
     *
     * @return int Total count of matching transactions
     *
     * @see self::getTransactionHistory() For the paginated records
     */
    public static function getTransactionCount(
        string $ownerType,
        int $ownerID,
        array $filters = []
    ): int {
        // 🏗️ Build the count query with the same filter logic
        $sql = "SELECT COUNT(*) AS total
                FROM tblCreditTransactions ct
                INNER JOIN tblCreditBalances cb ON ct.balanceID = cb.balanceID
                WHERE cb.ownerType = ? AND cb.ownerID = ?";
        $params = [$ownerType, $ownerID];
        $types  = 'si';

        // 🏢 Filter by partnerID
        if (isset($filters['partnerID'])) {
            if ($filters['partnerID'] === null) {
                $sql .= " AND cb.partnerID IS NULL";
            } else {
                $sql .= " AND cb.partnerID = ?";
                $params[] = (int)$filters['partnerID'];
                $types .= 'i';
            }
        }

        // 💱 Filter by currency
        if (!empty($filters['currency'])) {
            $sql .= " AND ct.currency = ?";
            $params[] = $filters['currency'];
            $types .= 's';
        }

        // 🏷️ Filter by transaction type
        if (!empty($filters['transactionType'])) {
            if (in_array($filters['transactionType'], self::VALID_TRANSACTION_TYPES, true)) {
                $sql .= " AND ct.type = ?";
                $params[] = $filters['transactionType'];
                $types .= 's';
            }
        }

        // 📅 Filter by date range — from
        if (!empty($filters['dateFrom'])) {
            $sql .= " AND ct.createdAt >= ?";
            $params[] = $filters['dateFrom'] . ' 00:00:00';
            $types .= 's';
        }

        // 📅 Filter by date range — to
        if (!empty($filters['dateTo'])) {
            $sql .= " AND ct.createdAt <= ?";
            $params[] = $filters['dateTo'] . ' 23:59:59';
            $types .= 's';
        }

        $result = Database::fetchOne($sql, $params, $types);

        return $result ? (int)$result['total'] : 0;
    }

    // ========================================================================
    // 🔀 TRANSFER BETWEEN ACCOUNTS
    // ========================================================================

    /**
     * 🔀 Transfer credits between two accounts
     *
     * Atomically moves credits from one owner to another. Uses two FOR UPDATE
     * locks within a single transaction to ensure both balances are updated
     * consistently. Creates two transaction records (one withdrawal, one deposit)
     * linked together via metadata.
     *
     * @param string $fromOwnerType Source owner type: 'user' or 'partner'
     * @param int    $fromOwnerID   Source owner's unique identifier
     * @param string $toOwnerType   Destination owner type: 'user' or 'partner'
     * @param int    $toOwnerID     Destination owner's unique identifier
     * @param float  $amount        Amount to transfer (must be > 0)
     * @param string $description   Human-readable description
     * @param int    $performedBy   Admin/user who authorised the transfer (REQUIRED)
     * @param array  $options       Optional parameters:
     *                              - 'fromPartnerID' (int|null) Source partner scope
     *                              - 'toPartnerID'   (int|null) Destination partner scope
     *                              - 'currency'      (string)   ISO 4217 code
     *                              - 'referenceType' (string)   e.g. 'inter_account'
     *                              - 'referenceID'   (int|null) Linked record ID
     *                              - 'metadata'      (array)    Extra data
     *
     * @return array Result array:
     *               - 'success'              (bool)  Whether the transfer succeeded
     *               - 'withdrawalTransactionID' (int) Source debit transaction ID
     *               - 'depositTransactionID'    (int) Destination credit transaction ID
     *               - 'fromNewBalance'       (float) Source balance after transfer
     *               - 'toNewBalance'         (float) Destination balance after transfer
     *               - 'message'              (string) Error message on failure
     */
    public static function transfer(
        string $fromOwnerType,
        int $fromOwnerID,
        string $toOwnerType,
        int $toOwnerID,
        float $amount,
        string $description,
        int $performedBy,
        array $options = []
    ): array {
        // 🛡️ Validate amount
        if ($amount < self::MIN_TRANSACTION_AMOUNT) {
            return [
                'success' => false,
                'message' => 'Transfer amount must be at least ' . number_format(self::MIN_TRANSACTION_AMOUNT, 2),
            ];
        }

        // 🛡️ Validate owner types
        if (!in_array($fromOwnerType, self::VALID_OWNER_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid source owner type: ' . $fromOwnerType];
        }
        if (!in_array($toOwnerType, self::VALID_OWNER_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid destination owner type: ' . $toOwnerType];
        }

        // 🛡️ Prevent self-transfer (same owner to same owner)
        if ($fromOwnerType === $toOwnerType && $fromOwnerID === $toOwnerID) {
            // Allow if partner scopes differ (different wallets for the same entity)
            $fromPartnerID = $options['fromPartnerID'] ?? null;
            $toPartnerID   = $options['toPartnerID'] ?? null;
            if ($fromPartnerID === $toPartnerID) {
                return ['success' => false, 'message' => 'Cannot transfer credits to the same account'];
            }
        }

        // 📦 Extract options
        $fromPartnerID = $options['fromPartnerID'] ?? null;
        $toPartnerID   = $options['toPartnerID'] ?? null;
        $currency      = $options['currency'] ?? self::DEFAULT_CURRENCY;
        $referenceType = $options['referenceType'] ?? 'transfer';
        $referenceID   = $options['referenceID'] ?? null;
        $metadata      = $options['metadata'] ?? [];
        $ipAddress     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 💰 Normalise to a canonical 2dp bcmath string once, at the boundary (B-071 fix).
        $amountStr = number_format($amount, 2, '.', '');

        try {
            // 🔒 BEGIN TRANSACTION (B-071 fix — see self::deposit()'s doc-comment)
            Database::beginTransaction();

            // 📋 Ensure both balance records exist
            $fromRecord = self::getBalanceRecord($fromOwnerType, $fromOwnerID, $fromPartnerID, $currency);
            $toRecord   = self::getBalanceRecord($toOwnerType, $toOwnerID, $toPartnerID, $currency);

            $fromBalanceID = (int)$fromRecord['balanceID'];
            $toBalanceID   = (int)$toRecord['balanceID'];

            // 🔒 Lock BOTH rows with FOR UPDATE
            // Lock in consistent order (lower balanceID first) to prevent deadlocks
            // @see https://dev.mysql.com/doc/refman/8.0/en/innodb-deadlocks-handling.html
            if ($fromBalanceID <= $toBalanceID) {
                $fromLocked = Database::fetchOne(
                    "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                    [$fromBalanceID],
                    'i'
                );
                $toLocked = Database::fetchOne(
                    "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                    [$toBalanceID],
                    'i'
                );
            } else {
                $toLocked = Database::fetchOne(
                    "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                    [$toBalanceID],
                    'i'
                );
                $fromLocked = Database::fetchOne(
                    "SELECT * FROM tblCreditBalances WHERE balanceID = ? FOR UPDATE",
                    [$fromBalanceID],
                    'i'
                );
            }

            if (!$fromLocked || !$toLocked) {
                Database::rollback();
                return ['success' => false, 'message' => 'One or both balance records not found during lock acquisition'];
            }

            // 💰 Check sufficient balance on source — bcmath comparison (B-071 fix)
            $fromBalanceBeforeStr = number_format((float)$fromLocked['balance'], 2, '.', '');
            $fromBalanceBefore    = (float)$fromBalanceBeforeStr;

            if (bccomp($fromBalanceBeforeStr, $amountStr, 2) < 0) {
                Database::rollback();

                ActivityLogger::log(
                    $performedBy,
                    'credit_transfer_insufficient',
                    'payment',
                    'warning',
                    'Insufficient credits for transfer from ' . $fromOwnerType . ' #' . $fromOwnerID . ': requested ' . $currency . ' ' . number_format($amount, 2) . ', available ' . $currency . ' ' . number_format($fromBalanceBefore, 2),
                    [
                        'fromOwnerType' => $fromOwnerType,
                        'fromOwnerID'   => $fromOwnerID,
                        'toOwnerType'   => $toOwnerType,
                        'toOwnerID'     => $toOwnerID,
                        'requested'     => $amount,
                        'available'     => $fromBalanceBefore,
                    ]
                );

                return [
                    'success'     => false,
                    'message'     => 'Insufficient credit balance on source account. Available: ' . $currency . ' ' . number_format($fromBalanceBefore, 2),
                    'fromBalance' => $fromBalanceBefore,
                ];
            }

            // 💰 Calculate new balances — bcmath (B-071 fix)
            $fromBalanceAfterStr = bcsub($fromBalanceBeforeStr, $amountStr, 2);
            $fromBalanceAfter    = (float)$fromBalanceAfterStr;
            $toBalanceBeforeStr  = number_format((float)$toLocked['balance'], 2, '.', '');
            $toBalanceAfterStr   = bcadd($toBalanceBeforeStr, $amountStr, 2);
            $toBalanceBefore     = (float)$toBalanceBeforeStr;
            $toBalanceAfter      = (float)$toBalanceAfterStr;

            // 🩹 B-071 fix: tblCreditBalances has NO totalWithdrawn/
            // totalDeposited columns — see self::getBalanceRecord().
            // 📝 Update source balance (debit)
            Database::query(
                "UPDATE tblCreditBalances
                 SET balance = ?,
                     lastTransactionAt = NOW(),
                     updatedAt = NOW()
                 WHERE balanceID = ?",
                [$fromBalanceAfter, $fromBalanceID],
                'di'
            );

            // 📝 Update destination balance (credit)
            Database::query(
                "UPDATE tblCreditBalances
                 SET balance = ?,
                     lastTransactionAt = NOW(),
                     updatedAt = NOW()
                 WHERE balanceID = ?",
                [$toBalanceAfter, $toBalanceID],
                'di'
            );

            // 📝 Build shared transfer metadata for cross-referencing
            $transferMeta = array_merge($metadata, [
                'transferType'  => 'inter_account',
                'fromOwnerType' => $fromOwnerType,
                'fromOwnerID'   => $fromOwnerID,
                'toOwnerType'   => $toOwnerType,
                'toOwnerID'     => $toOwnerID,
                'performedBy'   => $performedBy,
            ]);
            $metadataJson = json_encode($transferMeta, JSON_UNESCAPED_UNICODE);

            // 📝 Create the SOURCE-side ledger row.
            // 🩹 B-071 fix (two independent bugs):
            //  1. The real column is `type`, not `transactionType`.
            //  2. The real `type` ENUM (migration 012) does NOT include a
            //     'transfer' member — only deposit/withdrawal/payment/refund/
            //     adjustment/remittance/promotional — so the previous
            //     `type = 'transfer'` INSERT violated the column's ENUM
            //     constraint on every real MySQL server. A transfer IS, at
            //     the ledger level, an ordinary withdrawal from one balance
            //     paired with an ordinary deposit into another — so this now
            //     records exactly that (type='withdrawal'/'deposit'), tagged
            //     `referenceType = 'transfer'` (the default set above) so
            //     the pair remains identifiable/filterable/linkable via
            //     metadata.linkedTransactionID, without touching the schema.
            Database::query(
                "INSERT INTO tblCreditTransactions
                    (balanceID, type, amount, balanceBefore, balanceAfter,
                     currency, description, referenceType, referenceID,
                     performedBy, ipAddress, metadata, createdAt)
                 VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $fromBalanceID,
                    $amount,
                    $fromBalanceBefore,
                    $fromBalanceAfter,
                    $currency,
                    'Transfer out: ' . $description,
                    $referenceType,
                    $referenceID,
                    $performedBy,
                    $ipAddress,
                    $metadataJson,
                ],
                'idddsssiiss'
            );

            $withdrawalTransactionID = Database::getLastInsertId();

            // 📝 Create the DESTINATION-side ledger row (type='deposit').
            // Link to the withdrawal transaction via metadata for traceability
            $transferMeta['linkedTransactionID'] = $withdrawalTransactionID;
            $depositMetaJson = json_encode($transferMeta, JSON_UNESCAPED_UNICODE);

            Database::query(
                "INSERT INTO tblCreditTransactions
                    (balanceID, type, amount, balanceBefore, balanceAfter,
                     currency, description, referenceType, referenceID,
                     performedBy, ipAddress, metadata, createdAt)
                 VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $toBalanceID,
                    $amount,
                    $toBalanceBefore,
                    $toBalanceAfter,
                    $currency,
                    'Transfer in: ' . $description,
                    $referenceType,
                    $referenceID,
                    $performedBy,
                    $ipAddress,
                    $depositMetaJson,
                ],
                'idddsssiiss'
            );

            $depositTransactionID = Database::getLastInsertId();

            // ✅ COMMIT — both sides updated atomically
            Database::commit();

            // 📝 Log the transfer
            ActivityLogger::log(
                $performedBy,
                'credit_transfer',
                'payment',
                'info',
                'Credit transfer: ' . $currency . ' ' . number_format($amount, 2) . ' from ' . $fromOwnerType . ' #' . $fromOwnerID . ' to ' . $toOwnerType . ' #' . $toOwnerID . ' — ' . $description,
                [
                    'withdrawalTransactionID' => $withdrawalTransactionID,
                    'depositTransactionID'    => $depositTransactionID,
                    'fromOwnerType'           => $fromOwnerType,
                    'fromOwnerID'             => $fromOwnerID,
                    'toOwnerType'             => $toOwnerType,
                    'toOwnerID'               => $toOwnerID,
                    'amount'                  => $amount,
                    'currency'                => $currency,
                    'fromBalanceBefore'       => $fromBalanceBefore,
                    'fromBalanceAfter'        => $fromBalanceAfter,
                    'toBalanceBefore'         => $toBalanceBefore,
                    'toBalanceAfter'          => $toBalanceAfter,
                    'performedBy'             => $performedBy,
                ]
            );

            // 🔗 Dispatch webhook
            if (class_exists('WebhookManager')) {
                WebhookManager::dispatch('credit.transferred', [
                    'withdrawalTransactionID' => $withdrawalTransactionID,
                    'depositTransactionID'    => $depositTransactionID,
                    'fromOwnerType'           => $fromOwnerType,
                    'fromOwnerID'             => $fromOwnerID,
                    'toOwnerType'             => $toOwnerType,
                    'toOwnerID'               => $toOwnerID,
                    'amount'                  => $amount,
                    'currency'                => $currency,
                    'performedBy'             => $performedBy,
                ]);
            }

            return [
                'success'                    => true,
                'withdrawalTransactionID'    => $withdrawalTransactionID,
                'depositTransactionID'       => $depositTransactionID,
                'fromNewBalance'             => $fromBalanceAfter,
                'toNewBalance'               => $toBalanceAfter,
            ];
        } catch (\Throwable $e) {
            // 🚨 ROLLBACK
            try {
                Database::rollback();
            } catch (\Throwable $rollbackException) {
                ActivityLogger::log(
                    $performedBy,
                    'credit_rollback_error',
                    'payment',
                    'critical',
                    'Rollback failed during transfer: ' . $rollbackException->getMessage(),
                    ['originalError' => $e->getMessage()]
                );
            }

            ActivityLogger::log(
                $performedBy,
                'credit_transfer_error',
                'payment',
                'error',
                'Credit transfer failed: ' . $e->getMessage(),
                [
                    'fromOwnerType' => $fromOwnerType,
                    'fromOwnerID'   => $fromOwnerID,
                    'toOwnerType'   => $toOwnerType,
                    'toOwnerID'     => $toOwnerID,
                    'amount'        => $amount,
                    'performedBy'   => $performedBy,
                    'error'         => $e->getMessage(),
                ]
            );

            return ['success' => false, 'message' => 'Failed to process credit transfer'];
        }
    }

    // ========================================================================
    // 📊 ADMIN STATISTICS
    // ========================================================================

    /**
     * 📊 Get credit system statistics for the admin dashboard
     *
     * Aggregates key metrics over the specified time window, including
     * total deposits, withdrawals, payments, refunds, adjustments, transfers,
     * active balance count, and total outstanding balance.
     *
     * @param int $days Number of days to look back for transaction stats (default: 30)
     *
     * @return array Associative array of statistics:
     *               - 'totalDeposits'       (float) Sum of all deposits
     *               - 'totalWithdrawals'    (float) Sum of all withdrawals
     *               - 'totalPayments'       (float) Sum of all credit payments
     *               - 'totalRefunds'        (float) Sum of all credit refunds
     *               - 'totalAdjustments'    (float) Sum of all adjustments (net)
     *               - 'totalTransfers'      (float) Sum of all transfer amounts
     *               - 'transactionCount'    (int)   Total number of transactions
     *               - 'depositCount'        (int)   Number of deposit transactions
     *               - 'withdrawalCount'     (int)   Number of withdrawal transactions
     *               - 'paymentCount'        (int)   Number of payment transactions
     *               - 'refundCount'         (int)   Number of refund transactions
     *               - 'adjustmentCount'     (int)   Number of adjustment transactions
     *               - 'transferCount'       (int)   Number of transfer transactions
     *               - 'activeBalances'      (int)   Balances with balance > 0
     *               - 'totalOutstanding'    (float) Sum of all positive balances
     *               - 'uniqueOwners'        (int)   Distinct owners with transactions
     *               - 'avgTransactionAmount' (float) Average transaction amount
     */
    public static function getAdminStats(int $days = 30): array {
        // 📊 Fetch transaction-level statistics for the specified time window.
        // 🩹 B-071 fix: the real column is `type`, not `transactionType`
        // (grep + information_schema-verified against migration 012). The
        // real `type` ENUM also has no 'transfer' member — self::transfer()
        // now records its two ledger rows as an ordinary 'withdrawal' +
        // 'deposit' tagged `referenceType = 'transfer'` (see transfer()'s
        // doc-comment), so transfer volume/count is derived from THAT tag
        // instead — only the destination ('deposit') leg is summed for
        // totalTransfers so a single transfer isn't double-counted.
        $txStats = Database::fetchOne(
            "SELECT
                COUNT(*) AS transactionCount,

                SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) AS totalDeposits,
                SUM(CASE WHEN type = 'deposit' THEN 1 ELSE 0 END) AS depositCount,

                SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) AS totalWithdrawals,
                SUM(CASE WHEN type = 'withdrawal' THEN 1 ELSE 0 END) AS withdrawalCount,

                SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END) AS totalPayments,
                SUM(CASE WHEN type = 'payment' THEN 1 ELSE 0 END) AS paymentCount,

                SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END) AS totalRefunds,
                SUM(CASE WHEN type = 'refund' THEN 1 ELSE 0 END) AS refundCount,

                SUM(CASE WHEN type = 'adjustment' THEN amount ELSE 0 END) AS totalAdjustments,
                SUM(CASE WHEN type = 'adjustment' THEN 1 ELSE 0 END) AS adjustmentCount,

                SUM(CASE WHEN type = 'deposit' AND referenceType = 'transfer' THEN amount ELSE 0 END) AS totalTransfers,
                SUM(CASE WHEN type = 'deposit' AND referenceType = 'transfer' THEN 1 ELSE 0 END) AS transferCount,

                AVG(ABS(amount)) AS avgTransactionAmount
             FROM tblCreditTransactions
             WHERE createdAt >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days],
            'i'
        );

        // 📊 Fetch balance-level statistics (current state, not time-bounded)
        $balanceStats = Database::fetchOne(
            "SELECT
                COUNT(*) AS totalBalanceRecords,
                SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS activeBalances,
                SUM(balance) AS totalOutstanding,
                COUNT(DISTINCT CONCAT(ownerType, '-', ownerID)) AS uniqueOwners
             FROM tblCreditBalances",
            [],
            ''
        );

        // 🔀 Merge both result sets into a single response array
        return [
            // Transaction statistics (time-bounded by $days)
            'transactionCount'    => (int)($txStats['transactionCount'] ?? 0),
            'totalDeposits'       => (float)($txStats['totalDeposits'] ?? 0),
            'depositCount'        => (int)($txStats['depositCount'] ?? 0),
            'totalWithdrawals'    => (float)($txStats['totalWithdrawals'] ?? 0),
            'withdrawalCount'     => (int)($txStats['withdrawalCount'] ?? 0),
            'totalPayments'       => (float)($txStats['totalPayments'] ?? 0),
            'paymentCount'        => (int)($txStats['paymentCount'] ?? 0),
            'totalRefunds'        => (float)($txStats['totalRefunds'] ?? 0),
            'refundCount'         => (int)($txStats['refundCount'] ?? 0),
            'totalAdjustments'    => (float)($txStats['totalAdjustments'] ?? 0),
            'adjustmentCount'     => (int)($txStats['adjustmentCount'] ?? 0),
            'totalTransfers'      => (float)($txStats['totalTransfers'] ?? 0),
            'transferCount'       => (int)($txStats['transferCount'] ?? 0),
            'avgTransactionAmount' => round((float)($txStats['avgTransactionAmount'] ?? 0), 2),

            // Balance statistics (current state)
            'activeBalances'      => (int)($balanceStats['activeBalances'] ?? 0),
            'totalOutstanding'    => (float)($balanceStats['totalOutstanding'] ?? 0),
            'uniqueOwners'        => (int)($balanceStats['uniqueOwners'] ?? 0),

            // Meta
            'periodDays'          => $days,
        ];
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS
    // ========================================================================

    /**
     * 📧 Send credit top-up email notification to a user
     *
     * Fetches the user's email and display name from tblUsers, then sends
     * a 'credit_topup' template email with the deposit details.
     *
     * @param int    $userID      The user's ID
     * @param float  $amount      Amount deposited
     * @param float  $newBalance  Balance after deposit
     * @param string $currency    ISO 4217 currency code
     * @param string $description Description of the deposit
     *
     * @return void
     *
     * @see EmailService::sendTemplateEmail() For the email sending mechanism
     */
    private static function sendCreditTopUpEmail(
        int $userID,
        float $amount,
        float $newBalance,
        string $currency,
        string $description
    ): void {
        try {
            // 🔍 Fetch user's email and display name
            $user = Database::fetchOne(
                "SELECT email, displayName, username FROM tblUsers WHERE userID = ?",
                [$userID],
                'i'
            );

            if (!$user || empty($user['email'])) {
                // ⚠️ No email found — skip notification silently
                return;
            }

            // 📧 Check if credit notification emails are enabled in settings
            $notificationsEnabled = getSetting('credit.email_notifications', '1');
            if ($notificationsEnabled !== '1' && $notificationsEnabled !== true) {
                return;
            }

            // 📧 Send the template email
            // @see EmailService::sendTemplateEmail() for template variable replacement
            if (class_exists('EmailService') && method_exists('EmailService', 'sendTemplateEmail')) {
                EmailService::sendTemplateEmail(
                    $user['email'],
                    'credit_topup',
                    [
                        '{{DISPLAY_NAME}}'  => $user['displayName'] ?? $user['username'] ?? 'Valued User',
                        '{{AMOUNT}}'        => number_format($amount, 2),
                        '{{CURRENCY}}'      => $currency,
                        '{{NEW_BALANCE}}'   => number_format($newBalance, 2),
                        '{{DESCRIPTION}}'   => htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
                        '{{DATE}}'          => date('j F Y \a\t H:i'),
                    ],
                    $userID,
                    5  // Normal priority
                );
            }
        } catch (\Exception $e) {
            // ⚠️ Email failure should NOT prevent the deposit from succeeding
            // Log the error but do not propagate it
            ActivityLogger::log(
                $userID,
                'credit_email_error',
                'payment',
                'warning',
                'Failed to send credit top-up email notification: ' . $e->getMessage(),
                [
                    'userID'   => $userID,
                    'amount'   => $amount,
                    'currency' => $currency,
                    'error'    => $e->getMessage(),
                ]
            );
        }
    }
}

// ✅ CreditManager loaded successfully
