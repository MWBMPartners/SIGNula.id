-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 📧 SIGNula Contact Submissions Migration
-- ============================================================================
-- Creates table for storing contact form submissions from SIGNula.com
--
-- Migration: 004
-- Date: 2026-02-03
-- Version: 2.0.2
-- ============================================================================

USE signula;

-- ============================================================================
-- Table: tblContactSubmissions
-- ============================================================================
-- Stores all contact form submissions from the marketing website
--
-- Purpose:
-- - Store contact form data for follow-up
-- - Track inquiry types and status
-- - Maintain communication history
-- - Spam detection and filtering
--
-- Security:
-- - IP address logging for abuse prevention
-- - User agent tracking
-- - Status tracking for workflow management
-- ============================================================================

CREATE TABLE IF NOT EXISTS tblContactSubmissions (
    -- Primary Key
    submissionID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    COMMENT 'Unique identifier for each contact submission',

    -- Contact Information
    name VARCHAR(100) NOT NULL,
    COMMENT 'Full name of person submitting',

    email VARCHAR(255) NOT NULL,
    COMMENT 'Email address for response',

    -- Inquiry Details
    inquiryType ENUM('general', 'support', 'sales', 'partnership', 'feedback') NOT NULL DEFAULT 'general',
    COMMENT 'Type of inquiry to route to appropriate team',

    subject VARCHAR(200) NOT NULL,
    COMMENT 'Subject line of the inquiry',

    message TEXT NOT NULL,
    COMMENT 'Full message content (up to 2000 characters in form)',

    -- Status Tracking
    status ENUM('new', 'in_progress', 'responded', 'resolved', 'spam') NOT NULL DEFAULT 'new',
    COMMENT 'Current status of the submission',

    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    COMMENT 'Priority level assigned by staff',

    -- Assignment & Follow-up
    assignedTo BIGINT UNSIGNED NULL DEFAULT NULL,
    COMMENT 'userID of staff member assigned to this inquiry',

    responseNotes TEXT NULL,
    COMMENT 'Internal notes about the response',

    respondedAt DATETIME NULL DEFAULT NULL,
    COMMENT 'When response was sent',

    respondedBy BIGINT UNSIGNED NULL DEFAULT NULL,
    COMMENT 'userID of staff member who responded',

    -- Security & Tracking
    ipAddress VARCHAR(45) NOT NULL,
    COMMENT 'IPv4 or IPv6 address of submitter',

    userAgent VARCHAR(500) NULL,
    COMMENT 'Browser user agent string',

    referrer VARCHAR(500) NULL,
    COMMENT 'Page that referred to contact form',

    -- Spam Detection
    spamScore DECIMAL(3, 2) NULL DEFAULT NULL,
    COMMENT 'Spam probability score (0.00 to 1.00)',

    isSpam BOOLEAN NOT NULL DEFAULT FALSE,
    COMMENT 'Flagged as spam',

    -- Timestamps
    submittedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    COMMENT 'When form was submitted',

    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    COMMENT 'Last update timestamp',

    -- Indexes for performance
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_inquiry_type (inquiryType),
    INDEX idx_submitted_at (submittedAt),
    INDEX idx_assigned_to (assignedTo),
    INDEX idx_is_spam (isSpam),

    -- Foreign key constraints (if staff management exists)
    CONSTRAINT fk_contact_assigned_to FOREIGN KEY (assignedTo)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_contact_responded_by FOREIGN KEY (respondedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Contact form submissions from SIGNula.com marketing website';

-- ============================================================================
-- Initial Data / Settings
-- ============================================================================

-- Add setting for contact form email notifications
INSERT INTO tblSettings (settingKey, settingValue, settingType, isSensitive, description, settingCategory)
VALUES
    ('contact.notification.enabled', '1', 'boolean', 0, 'Enable email notifications for new contact submissions', 'Contact Form'),
    ('contact.notification.email', 'support@signula.com', 'string', 0, 'Email address to receive contact form notifications', 'Contact Form'),
    ('contact.spam.threshold', '0.70', 'decimal', 0, 'Spam score threshold (0.00-1.00) to auto-flag as spam', 'Contact Form'),
    ('contact.rate_limit.per_email', '5', 'integer', 0, 'Maximum submissions per email per hour', 'Contact Form'),
    ('contact.rate_limit.per_ip', '10', 'integer', 0, 'Maximum submissions per IP per hour', 'Contact Form')
ON DUPLICATE KEY UPDATE
    settingValue = VALUES(settingValue),
    description = VALUES(description);

-- ============================================================================
-- Migration Complete
-- ============================================================================

-- Verify table creation
SELECT
    'Migration 004 Complete' AS status,
    COUNT(*) AS table_exists
FROM information_schema.tables
WHERE table_schema = 'signula'
    AND table_name = 'tblContactSubmissions';

-- Show table structure
DESCRIBE tblContactSubmissions;
