-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================
-- Migration: 006_support_system.sql
-- Description: Creates tables for support ticket system
-- Version: 1.0.0
-- Date: 2026-02-03
-- Author: SIGNula Development Team
-- ============================================

USE signula;

-- ============================================
-- Table: tblSupportTickets
-- Purpose: Store customer support tickets
-- ============================================
CREATE TABLE IF NOT EXISTS tblSupportTickets (
    ticketID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Ticket Identification
    ticketNumber VARCHAR(20) NOT NULL COMMENT 'Human-readable ticket number (e.g., SIGN-2024-00001)',

    -- User Information
    userID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (null for guest submissions)',
    email VARCHAR(255) NOT NULL COMMENT 'Contact email',
    name VARCHAR(100) NOT NULL COMMENT 'Contact name',

    -- Ticket Details
    subject VARCHAR(255) NOT NULL COMMENT 'Ticket subject/title',
    description TEXT NOT NULL COMMENT 'Detailed description of issue',
    category ENUM(
        'Technical Support',
        'Billing',
        'Account',
        'API Integration',
        'Security',
        'Feature Request',
        'Bug Report',
        'General Inquiry',
        'Other'
    ) NOT NULL DEFAULT 'General Inquiry' COMMENT 'Support category',

    priority ENUM('Low', 'Normal', 'High', 'Urgent') NOT NULL DEFAULT 'Normal' COMMENT 'Ticket priority',

    status ENUM(
        'New',
        'Open',
        'In Progress',
        'Waiting on Customer',
        'Waiting on Internal',
        'Resolved',
        'Closed',
        'Cancelled'
    ) NOT NULL DEFAULT 'New' COMMENT 'Ticket status',

    -- Assignment
    assignedTo BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (assigned staff member)',
    assignedAt DATETIME DEFAULT NULL COMMENT 'Assignment timestamp',

    -- Product/Service Context
    relatedProduct VARCHAR(100) DEFAULT NULL COMMENT 'Related product or service',
    relatedURL VARCHAR(500) DEFAULT NULL COMMENT 'URL where issue occurred',
    browserInfo TEXT DEFAULT NULL COMMENT 'Browser/device information',
    errorMessage TEXT DEFAULT NULL COMMENT 'Any error messages',

    -- Resolution
    resolution TEXT DEFAULT NULL COMMENT 'Resolution details',
    resolvedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (staff who resolved)',
    resolvedAt DATETIME DEFAULT NULL COMMENT 'Resolution timestamp',
    closedAt DATETIME DEFAULT NULL COMMENT 'Closure timestamp',

    -- Satisfaction
    satisfactionRating TINYINT UNSIGNED DEFAULT NULL COMMENT 'Customer satisfaction (1-5 stars)',
    satisfactionComment TEXT DEFAULT NULL COMMENT 'Customer feedback',

    -- Metadata
    ipAddress VARCHAR(45) NOT NULL COMMENT 'Submitter IP address (IPv4/IPv6)',
    userAgent TEXT DEFAULT NULL COMMENT 'Browser user agent',
    referrer VARCHAR(500) DEFAULT NULL COMMENT 'Referrer URL',

    -- SLA Tracking
    firstResponseAt DATETIME DEFAULT NULL COMMENT 'Timestamp of first staff response',
    slaDeadline DATETIME DEFAULT NULL COMMENT 'SLA deadline based on priority',
    slaViolated BOOLEAN DEFAULT FALSE COMMENT 'Whether SLA was violated',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Ticket creation timestamp',
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    deletedAt DATETIME DEFAULT NULL COMMENT 'Soft delete timestamp',
    isDeleted BOOLEAN DEFAULT FALSE COMMENT 'Soft delete flag',

    PRIMARY KEY (ticketID),
    UNIQUE KEY idx_ticket_number (ticketNumber),
    KEY idx_user (userID),
    KEY idx_email (email),
    KEY idx_status (status),
    KEY idx_priority (priority),
    KEY idx_category (category),
    KEY idx_assigned (assignedTo),
    KEY idx_created (createdAt),
    KEY idx_sla (slaDeadline, slaViolated),
    KEY idx_deleted (isDeleted, deletedAt),
    FULLTEXT KEY idx_search (subject, description),

    CONSTRAINT fk_tickets_user FOREIGN KEY (userID)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_assigned FOREIGN KEY (assignedTo)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_resolved FOREIGN KEY (resolvedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer support tickets';

-- ============================================
-- Table: tblSupportReplies
-- Purpose: Ticket replies/updates
-- ============================================
CREATE TABLE IF NOT EXISTS tblSupportReplies (
    replyID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticketID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblSupportTickets.ticketID',

    -- Author Information
    userID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (null for system messages)',
    authorName VARCHAR(100) NOT NULL COMMENT 'Author name',
    authorEmail VARCHAR(255) DEFAULT NULL COMMENT 'Author email',
    isStaff BOOLEAN DEFAULT FALSE COMMENT 'Whether reply is from staff',
    isInternal BOOLEAN DEFAULT FALSE COMMENT 'Internal note (not visible to customer)',

    -- Reply Content
    message TEXT NOT NULL COMMENT 'Reply message',
    messageHTML TEXT DEFAULT NULL COMMENT 'HTML version of message',

    -- Attachments
    hasAttachments BOOLEAN DEFAULT FALSE COMMENT 'Whether reply has attachments',
    attachmentCount INT UNSIGNED DEFAULT 0 COMMENT 'Number of attachments',

    -- Status Changes
    statusChanged BOOLEAN DEFAULT FALSE COMMENT 'Whether this reply changed ticket status',
    oldStatus VARCHAR(50) DEFAULT NULL COMMENT 'Previous status if changed',
    newStatus VARCHAR(50) DEFAULT NULL COMMENT 'New status if changed',

    -- Metadata
    ipAddress VARCHAR(45) DEFAULT NULL COMMENT 'Author IP address',
    userAgent TEXT DEFAULT NULL COMMENT 'Browser user agent',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Reply timestamp',
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deletedAt DATETIME DEFAULT NULL,
    isDeleted BOOLEAN DEFAULT FALSE,

    PRIMARY KEY (replyID),
    KEY idx_ticket (ticketID),
    KEY idx_user (userID),
    KEY idx_staff (isStaff),
    KEY idx_created (createdAt),
    KEY idx_deleted (isDeleted),

    CONSTRAINT fk_replies_ticket FOREIGN KEY (ticketID)
        REFERENCES tblSupportTickets(ticketID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_replies_user FOREIGN KEY (userID)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Support ticket replies';

-- ============================================
-- Table: tblSupportAttachments
-- Purpose: File attachments for tickets
-- ============================================
CREATE TABLE IF NOT EXISTS tblSupportAttachments (
    attachmentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticketID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblSupportTickets.ticketID',
    replyID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblSupportReplies.replyID',

    -- File Information
    fileName VARCHAR(255) NOT NULL COMMENT 'Original filename',
    storedFileName VARCHAR(255) NOT NULL COMMENT 'Stored filename (hashed)',
    filePath VARCHAR(500) NOT NULL COMMENT 'Storage path',
    fileSize BIGINT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    mimeType VARCHAR(100) NOT NULL COMMENT 'MIME type',

    -- Upload Info
    uploadedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID',
    uploadedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Upload timestamp',

    -- Security
    scanStatus ENUM('Pending', 'Clean', 'Infected', 'Failed') DEFAULT 'Pending' COMMENT 'Virus scan status',
    scanDetails TEXT DEFAULT NULL COMMENT 'Scan details if infected',

    -- Audit Fields
    deletedAt DATETIME DEFAULT NULL,
    isDeleted BOOLEAN DEFAULT FALSE,

    PRIMARY KEY (attachmentID),
    KEY idx_ticket (ticketID),
    KEY idx_reply (replyID),
    KEY idx_uploaded_by (uploadedBy),
    KEY idx_deleted (isDeleted),

    CONSTRAINT fk_attachments_ticket FOREIGN KEY (ticketID)
        REFERENCES tblSupportTickets(ticketID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attachments_reply FOREIGN KEY (replyID)
        REFERENCES tblSupportReplies(replyID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attachments_user FOREIGN KEY (uploadedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Support ticket attachments';

-- ============================================
-- Table: tblSupportCategories
-- Purpose: Support knowledge base categories
-- ============================================
CREATE TABLE IF NOT EXISTS tblSupportCategories (
    categoryID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    categoryName VARCHAR(100) NOT NULL COMMENT 'Category name',
    categorySlug VARCHAR(100) NOT NULL COMMENT 'URL-friendly slug',
    description TEXT DEFAULT NULL COMMENT 'Category description',
    icon VARCHAR(50) DEFAULT NULL COMMENT 'Font Awesome icon class',
    displayOrder INT DEFAULT 0 COMMENT 'Sort order',
    isActive BOOLEAN DEFAULT TRUE COMMENT 'Active status',
    articleCount INT UNSIGNED DEFAULT 0 COMMENT 'Number of articles in category',

    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (categoryID),
    UNIQUE KEY idx_slug (categorySlug),
    KEY idx_active (isActive),
    KEY idx_order (displayOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Support knowledge base categories';

-- ============================================
-- Insert Default Support Categories
-- ============================================
INSERT INTO tblSupportCategories (categoryName, categorySlug, description, icon, displayOrder) VALUES
('Getting Started', 'getting-started', 'New to SIGNula? Start here!', 'fa-rocket', 1),
('Account & Billing', 'account-billing', 'Manage your account and billing', 'fa-user-circle', 2),
('API Integration', 'api-integration', 'Integrate SIGNula with your app', 'fa-code', 3),
('Security & Privacy', 'security-privacy', 'Security features and privacy settings', 'fa-shield-alt', 4),
('Troubleshooting', 'troubleshooting', 'Common issues and solutions', 'fa-wrench', 5),
('Mobile Apps', 'mobile-apps', 'iOS and Android app support', 'fa-mobile-alt', 6);

-- ============================================
-- Insert Support Settings
-- ============================================
INSERT INTO tblSettings (settingKey, settingValue, category, dataType, isEditable, isSensitive, description) VALUES
('support.enableGuestTickets', 'false', 'Support', 'boolean', TRUE, FALSE, 'Allow non-authenticated users to submit tickets'),
('support.requireEmailVerification', 'true', 'Support', 'boolean', TRUE, FALSE, 'Require email verification for guest tickets'),
('support.autoAssignTickets', 'true', 'Support', 'boolean', TRUE, FALSE, 'Automatically assign tickets to available staff'),
('support.slaHoursLow', '72', 'Support', 'integer', TRUE, FALSE, 'SLA hours for Low priority tickets'),
('support.slaHoursNormal', '48', 'Support', 'integer', TRUE, FALSE, 'SLA hours for Normal priority tickets'),
('support.slaHoursHigh', '24', 'Support', 'integer', TRUE, FALSE, 'SLA hours for High priority tickets'),
('support.slaHoursUrgent', '4', 'Support', 'integer', TRUE, FALSE, 'SLA hours for Urgent priority tickets'),
('support.maxAttachmentSize', '10485760', 'Support', 'integer', TRUE, FALSE, 'Max attachment size in bytes (10MB default)'),
('support.allowedAttachmentTypes', 'jpg,jpeg,png,gif,pdf,doc,docx,txt,zip', 'Support', 'string', TRUE, FALSE, 'Allowed file extensions'),
('support.notifyStaffEmail', 'support@signula.com', 'Support', 'string', TRUE, FALSE, 'Email to notify for new tickets'),
('support.autoCloseAfterDays', '30', 'Support', 'integer', TRUE, FALSE, 'Auto-close resolved tickets after N days')
ON DUPLICATE KEY UPDATE updatedAt = CURRENT_TIMESTAMP;

-- ============================================
-- Stored Procedure: Generate Ticket Number
-- ============================================
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_GenerateTicketNumber(OUT newTicketNumber VARCHAR(20))
BEGIN
    DECLARE nextNumber INT;
    DECLARE yearPart VARCHAR(4);

    SET yearPart = YEAR(NOW());

    -- Get the next ticket number for current year
    SELECT COALESCE(MAX(CAST(SUBSTRING(ticketNumber, -5) AS UNSIGNED)), 0) + 1
    INTO nextNumber
    FROM tblSupportTickets
    WHERE ticketNumber LIKE CONCAT('SIGN-', yearPart, '-%');

    -- Format: SIGN-2026-00001
    SET newTicketNumber = CONCAT('SIGN-', yearPart, '-', LPAD(nextNumber, 5, '0'));
END //

DELIMITER ;

-- ============================================
-- Trigger: Auto-generate ticket number
-- ============================================
DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_tickets_before_insert
BEFORE INSERT ON tblSupportTickets
FOR EACH ROW
BEGIN
    IF NEW.ticketNumber IS NULL OR NEW.ticketNumber = '' THEN
        CALL sp_GenerateTicketNumber(@ticketNum);
        SET NEW.ticketNumber = @ticketNum;
    END IF;

    -- Set SLA deadline based on priority
    IF NEW.slaDeadline IS NULL THEN
        SET NEW.slaDeadline = CASE NEW.priority
            WHEN 'Urgent' THEN DATE_ADD(NOW(), INTERVAL 4 HOUR)
            WHEN 'High' THEN DATE_ADD(NOW(), INTERVAL 24 HOUR)
            WHEN 'Normal' THEN DATE_ADD(NOW(), INTERVAL 48 HOUR)
            WHEN 'Low' THEN DATE_ADD(NOW(), INTERVAL 72 HOUR)
            ELSE DATE_ADD(NOW(), INTERVAL 48 HOUR)
        END;
    END IF;
END //

DELIMITER ;

-- ============================================
-- View: Open Tickets Summary
-- ============================================
CREATE OR REPLACE VIEW vw_OpenTickets AS
SELECT
    t.ticketID,
    t.ticketNumber,
    t.subject,
    t.category,
    t.priority,
    t.status,
    t.userID,
    t.email,
    t.name,
    t.assignedTo,
    u.displayName as assignedToName,
    t.createdAt,
    t.slaDeadline,
    t.slaViolated,
    TIMESTAMPDIFF(HOUR, t.createdAt, NOW()) as ageInHours,
    (SELECT COUNT(*) FROM tblSupportReplies WHERE ticketID = t.ticketID AND isDeleted = FALSE) as replyCount
FROM tblSupportTickets t
LEFT JOIN tblUsers u ON t.assignedTo = u.userID
WHERE t.status NOT IN ('Resolved', 'Closed', 'Cancelled')
AND t.isDeleted = FALSE
ORDER BY
    FIELD(t.priority, 'Urgent', 'High', 'Normal', 'Low'),
    t.createdAt ASC;

-- ============================================
-- Migration Complete
-- ============================================
