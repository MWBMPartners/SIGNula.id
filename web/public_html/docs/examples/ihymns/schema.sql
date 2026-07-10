-- ============================================================================
-- iHymns × SIGNula.id reference integration — schema.sql
-- ============================================================================
-- COPY / adapt this into the iHymns app's OWN database migrations
-- (github.com/MWBMPartners/iHymns) — NOT a SIGNula migration, and NOT
-- executed against SIGNula's database. This is a reference showing the one
-- column iHymns needs on its own `users` table to link a local iHymns
-- account to a verified SIGNula identity — see callback.php's
-- mapSignulaSubjectToIhymnsUser() in this same reference directory for how
-- it's used, and README.md Step 4 for why matching happens on `sub`
-- (not email).
-- ============================================================================

-- -----------------------------------------------------------------------
-- If iHymns already has a `users` table, add just this one column + index:
-- -----------------------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN `signula_sub` VARCHAR(64) NULL
        COMMENT 'SIGNula pairwise (per-client, opaque) subject identifier — the durable link to the user''s SIGNula ID for THIS iHymns client registration only. Not an email address; not comparable across other Relying Parties.'
        AFTER `id`,
    ADD UNIQUE KEY `uq_users_signula_sub` (`signula_sub`);

-- -----------------------------------------------------------------------
-- Illustrative full table, if iHymns is starting from scratch (adapt
-- freely to your real user model — only `signula_sub` and its UNIQUE
-- index are the part that matters for this integration):
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `signula_sub`   VARCHAR(64) NULL
        COMMENT 'SIGNula pairwise subject (sub claim) — set on first SIGNula sign-in; UNIQUE so a given SIGNula identity can only ever map to one iHymns account.',
    `email`         VARCHAR(255) NULL
        COMMENT 'Profile field only — mutable, sourced from SIGNula id_token/UserInfo at each login. NEVER used to look up/re-link an existing account; signula_sub is the durable key.',
    `display_name`  VARCHAR(255) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_signula_sub` (`signula_sub`),
    KEY `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='iHymns local user accounts — signula_sub links to a verified SIGNula.id identity (see /docs/examples/ihymns in the SIGNula repo).';

-- -----------------------------------------------------------------------
-- Why UNIQUE on signula_sub, and why nullable:
--   - UNIQUE: guarantees a given SIGNula identity (for this client
--     registration) can only ever be linked to one iHymns account — the
--     INSERT in mapSignulaSubjectToIhymnsUser() would fail loudly (instead
--     of silently duplicating) if that invariant were ever violated, e.g.
--     by a race between two concurrent first-logins for the same sub.
--   - NULLable: lets iHymns keep supporting accounts that were created
--     BEFORE this integration existed (native email/password accounts
--     that have not yet linked a SIGNula ID) without a backfill migration
--     being a hard blocker — those rows simply have signula_sub = NULL
--     until/unless that user links their SIGNula ID from iHymns' own
--     account settings.
-- -----------------------------------------------------------------------
