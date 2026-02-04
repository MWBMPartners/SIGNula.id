-- ============================================================================
-- SIGNula Database Schema
-- 
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- 
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================
-- Migration: 005_blog_system.sql
-- Description: Creates tables for blog/announcement system
-- Version: 1.0.0
-- Date: 2026-02-03
-- Author: SIGNula Development Team
-- ============================================

USE signula;

-- ============================================
-- Table: tblBlogPosts
-- Purpose: Store blog posts and announcements
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogPosts (
    postID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Post Content
    title VARCHAR(255) NOT NULL COMMENT 'Post title',
    slug VARCHAR(255) NOT NULL COMMENT 'URL-friendly slug',
    excerpt TEXT DEFAULT NULL COMMENT 'Short preview text (auto-generated if null)',
    content LONGTEXT NOT NULL COMMENT 'Full post content (HTML)',
    featuredImage VARCHAR(500) DEFAULT NULL COMMENT 'Path to featured image',

    -- Author Information
    authorID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblUsers.userID',

    -- Categorization
    category VARCHAR(100) DEFAULT 'Uncategorized' COMMENT 'Post category',
    tags TEXT DEFAULT NULL COMMENT 'Comma-separated tags',

    -- Publishing
    status ENUM('draft', 'scheduled', 'published', 'archived') NOT NULL DEFAULT 'draft' COMMENT 'Publication status',
    publishedAt DATETIME DEFAULT NULL COMMENT 'Publication date/time',
    scheduledFor DATETIME DEFAULT NULL COMMENT 'Scheduled publication date',

    -- SEO
    metaTitle VARCHAR(70) DEFAULT NULL COMMENT 'SEO title (defaults to title if null)',
    metaDescription VARCHAR(160) DEFAULT NULL COMMENT 'SEO meta description',
    metaKeywords VARCHAR(255) DEFAULT NULL COMMENT 'SEO keywords',

    -- Engagement
    viewCount BIGINT UNSIGNED DEFAULT 0 COMMENT 'Number of views',
    allowComments BOOLEAN DEFAULT TRUE COMMENT 'Enable/disable comments',

    -- Versioning
    version INT UNSIGNED DEFAULT 1 COMMENT 'Content version number',
    lastEditedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID',
    lastEditedAt DATETIME DEFAULT NULL COMMENT 'Last edit timestamp',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    deletedAt DATETIME DEFAULT NULL COMMENT 'Soft delete timestamp',
    isDeleted BOOLEAN DEFAULT FALSE COMMENT 'Soft delete flag',

    PRIMARY KEY (postID),
    UNIQUE KEY idx_slug (slug),
    KEY idx_author (authorID),
    KEY idx_status (status),
    KEY idx_published (publishedAt),
    KEY idx_category (category),
    KEY idx_created (createdAt),
    KEY idx_deleted (isDeleted, deletedAt),
    FULLTEXT KEY idx_search (title, content, excerpt),

    CONSTRAINT fk_blogposts_author FOREIGN KEY (authorID)
        REFERENCES tblUsers(userID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_blogposts_editor FOREIGN KEY (lastEditedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog posts and announcements';

-- ============================================
-- Table: tblBlogCategories
-- Purpose: Manage blog categories
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogCategories (
    categoryID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    categoryName VARCHAR(100) NOT NULL COMMENT 'Category name',
    categorySlug VARCHAR(100) NOT NULL COMMENT 'URL-friendly slug',
    description TEXT DEFAULT NULL COMMENT 'Category description',
    parentID INT UNSIGNED DEFAULT NULL COMMENT 'Parent category (for nested categories)',
    displayOrder INT DEFAULT 0 COMMENT 'Sort order',
    isActive BOOLEAN DEFAULT TRUE COMMENT 'Active status',
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (categoryID),
    UNIQUE KEY idx_slug (categorySlug),
    KEY idx_parent (parentID),
    KEY idx_active (isActive),

    CONSTRAINT fk_category_parent FOREIGN KEY (parentID)
        REFERENCES tblBlogCategories(categoryID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog post categories';

-- ============================================
-- Table: tblBlogComments
-- Purpose: User comments on blog posts
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogComments (
    commentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    postID BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblBlogPosts.postID',
    userID BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID (null for guests)',
    parentCommentID BIGINT UNSIGNED DEFAULT NULL COMMENT 'Parent comment for nested replies',

    -- Comment Content
    authorName VARCHAR(100) NOT NULL COMMENT 'Commenter name',
    authorEmail VARCHAR(255) DEFAULT NULL COMMENT 'Commenter email (for guests)',
    content TEXT NOT NULL COMMENT 'Comment text',

    -- Moderation
    status ENUM('pending', 'approved', 'spam', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'Moderation status',
    moderatedBy BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to tblUsers.userID',
    moderatedAt DATETIME DEFAULT NULL COMMENT 'Moderation timestamp',
    moderationNote TEXT DEFAULT NULL COMMENT 'Internal moderation note',

    -- Metadata
    ipAddress VARCHAR(45) NOT NULL COMMENT 'Commenter IP (IPv4/IPv6)',
    userAgent TEXT DEFAULT NULL COMMENT 'Browser user agent',

    -- Audit Fields
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deletedAt DATETIME DEFAULT NULL,
    isDeleted BOOLEAN DEFAULT FALSE,

    PRIMARY KEY (commentID),
    KEY idx_post (postID),
    KEY idx_user (userID),
    KEY idx_parent (parentCommentID),
    KEY idx_status (status),
    KEY idx_created (createdAt),
    KEY idx_deleted (isDeleted),

    CONSTRAINT fk_comments_post FOREIGN KEY (postID)
        REFERENCES tblBlogPosts(postID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (userID)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_comments_parent FOREIGN KEY (parentCommentID)
        REFERENCES tblBlogComments(commentID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comments_moderator FOREIGN KEY (moderatedBy)
        REFERENCES tblUsers(userID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog post comments';

-- ============================================
-- Table: tblBlogTags
-- Purpose: Manage blog tags
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogTags (
    tagID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tagName VARCHAR(50) NOT NULL COMMENT 'Tag name',
    tagSlug VARCHAR(50) NOT NULL COMMENT 'URL-friendly slug',
    usageCount INT UNSIGNED DEFAULT 0 COMMENT 'Number of posts using this tag',
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (tagID),
    UNIQUE KEY idx_slug (tagSlug),
    KEY idx_usage (usageCount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Blog post tags';

-- ============================================
-- Table: tblBlogPostTags (Junction Table)
-- Purpose: Many-to-many relationship between posts and tags
-- ============================================
CREATE TABLE IF NOT EXISTS tblBlogPostTags (
    postID BIGINT UNSIGNED NOT NULL,
    tagID INT UNSIGNED NOT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (postID, tagID),
    KEY idx_tag (tagID),

    CONSTRAINT fk_posttags_post FOREIGN KEY (postID)
        REFERENCES tblBlogPosts(postID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_posttags_tag FOREIGN KEY (tagID)
        REFERENCES tblBlogTags(tagID) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Post-Tag relationships';

-- ============================================
-- Insert Default Categories
-- ============================================
INSERT INTO tblBlogCategories (categoryName, categorySlug, description, displayOrder) VALUES
('Announcements', 'announcements', 'Product announcements and company news', 1),
('Updates', 'updates', 'Feature updates and improvements', 2),
('Security', 'security', 'Security updates and best practices', 3),
('Tutorials', 'tutorials', 'How-to guides and tutorials', 4),
('Engineering', 'engineering', 'Technical deep dives and engineering posts', 5),
('Company', 'company', 'Company culture and team updates', 6);

-- ============================================
-- Insert Blog Settings
-- ============================================
INSERT INTO tblSettings (settingKey, settingValue, category, dataType, isEditable, isSensitive, description) VALUES
('blog.postsPerPage', '10', 'Blog', 'integer', TRUE, FALSE, 'Number of posts per page'),
('blog.enableComments', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Enable comments on blog posts'),
('blog.moderateComments', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Require comment moderation'),
('blog.allowGuestComments', 'false', 'Blog', 'boolean', TRUE, FALSE, 'Allow non-authenticated users to comment'),
('blog.showAuthorInfo', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Show author information on posts'),
('blog.enableSocialShare', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Enable social media sharing buttons'),
('blog.rssEnabled', 'true', 'Blog', 'boolean', TRUE, FALSE, 'Enable RSS feed'),
('blog.excerptLength', '200', 'Blog', 'integer', TRUE, FALSE, 'Auto-generated excerpt character limit')
ON DUPLICATE KEY UPDATE updatedAt = CURRENT_TIMESTAMP;

-- ============================================
-- Sample Blog Post (Welcome Post)
-- ============================================
INSERT INTO tblBlogPosts (
    title,
    slug,
    excerpt,
    content,
    authorID,
    category,
    status,
    publishedAt,
    metaTitle,
    metaDescription,
    allowComments
) VALUES (
    'Welcome to SIGNula Blog',
    'welcome-to-signula-blog',
    'We''re excited to launch the official SIGNula blog where we''ll share product updates, security insights, and engineering deep dives.',
    '<h2>Welcome to the SIGNula Blog!</h2>
    <p>We''re thrilled to launch the official SIGNula blog. This is where we''ll share:</p>
    <ul>
        <li><strong>Product Announcements:</strong> New features and updates</li>
        <li><strong>Security Insights:</strong> Best practices and security updates</li>
        <li><strong>Engineering Deep Dives:</strong> Technical articles from our team</li>
        <li><strong>Company News:</strong> What''s happening at SIGNula</li>
    </ul>
    <p>Stay tuned for regular updates as we continue to build the future of universal authentication.</p>
    <p>Have questions or topics you''d like us to cover? <a href="/contact">Let us know!</a></p>',
    1,
    'Announcements',
    'published',
    CURRENT_TIMESTAMP,
    'Welcome to SIGNula Blog',
    'Discover product updates, security insights, and engineering deep dives from the SIGNula team.',
    TRUE
);

-- ============================================
-- Migration Complete
-- ============================================
