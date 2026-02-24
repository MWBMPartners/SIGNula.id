<?php
/**
 * Admin Blog Editor
 * Create and edit blog posts with WYSIWYG editor
 * 
 * @package SIGNula
 * @version 1.0.0
 */

// Start session and check authentication
session_start();
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'check-admin.php';

// Page configuration
$pageTitle = 'Blog Editor - SIGNula Admin';
$currentPage = 'admin';

// Get post ID if editing
$postID = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEditing = $postID > 0;

// Initialize post data
$post = [
    'postID' => 0,
    'title' => '',
    'slug' => '',
    'excerpt' => '',
    'content' => '',
    'featuredImage' => '',
    'category' => 'Uncategorized',
    'tags' => '',
    'status' => 'draft',
    'publishedAt' => '',
    'scheduledFor' => '',
    'metaTitle' => '',
    'metaDescription' => '',
    'metaKeywords' => '',
    'allowComments' => true
];

// Fetch existing post if editing
if ($isEditing) {
    $postQuery = "SELECT * FROM tblBlogPosts WHERE postID = ? AND isDeleted = FALSE";
    $postStmt = $mysqli->prepare($postQuery);
    $postStmt->bind_param('i', $postID);
    $postStmt->execute();
    $postResult = $postStmt->get_result();
    
    if ($postResult->num_rows > 0) {
        $post = $postResult->fetch_assoc();
    } else {
        $_SESSION['error_message'] = 'Post not found.';
        header('Location: /admin/blog-list');
        exit;
    }
}

// Get categories
$categoriesQuery = "SELECT categoryName, categorySlug FROM tblBlogCategories WHERE isActive = TRUE ORDER BY displayOrder, categoryName";
$categoriesResult = $mysqli->query($categoriesQuery);
$categories = $categoriesResult->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'Invalid security token.';
        header('Location: ' . $_SERVER['PHP_SELF'] . ($isEditing ? '?id=' . $postID : ''));
        exit;
    }

    // Sanitize and validate input
    $title = trim($_POST['title']);
    $slug = trim($_POST['slug']);
    $excerpt = trim($_POST['excerpt']);
    $content = $_POST['content']; // TinyMCE handles sanitization
    $featuredImage = trim($_POST['featuredImage']);
    $category = trim($_POST['category']);
    $tags = trim($_POST['tags']);
    $status = $_POST['status'];
    $scheduledFor = !empty($_POST['scheduledFor']) ? $_POST['scheduledFor'] : null;
    $metaTitle = trim($_POST['metaTitle']);
    $metaDescription = trim($_POST['metaDescription']);
    $metaKeywords = trim($_POST['metaKeywords']);
    $allowComments = isset($_POST['allowComments']) ? 1 : 0;

    // Auto-generate slug if empty
    if (empty($slug)) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = trim($slug, '-');
    }

    // Auto-generate excerpt if empty
    if (empty($excerpt)) {
        $excerpt = substr(strip_tags($content), 0, 200);
    }

    // Set publishedAt based on status
    $publishedAt = null;
    if ($status === 'published' && empty($post['publishedAt'])) {
        $publishedAt = date('Y-m-d H:i:s');
    } elseif ($status === 'published' && !empty($post['publishedAt'])) {
        $publishedAt = $post['publishedAt'];
    } elseif ($status === 'scheduled' && !empty($scheduledFor)) {
        $publishedAt = null; // Will be set when scheduled task runs
    }

    // Validation
    $errors = [];
    if (strlen($title) < 5) {
        $errors[] = 'Title must be at least 5 characters.';
    }
    if (strlen($slug) < 3) {
        $errors[] = 'Slug must be at least 3 characters.';
    }
    if (strlen($content) < 50) {
        $errors[] = 'Content must be at least 50 characters.';
    }

    // Check slug uniqueness
    $slugCheckQuery = "SELECT postID FROM tblBlogPosts WHERE slug = ? AND postID != ?";
    $slugCheckStmt = $mysqli->prepare($slugCheckQuery);
    $slugCheckStmt->bind_param('si', $slug, $postID);
    $slugCheckStmt->execute();
    if ($slugCheckStmt->get_result()->num_rows > 0) {
        $errors[] = 'Slug already exists. Please choose a unique slug.';
    }

    if (empty($errors)) {
        if ($isEditing) {
            // Update existing post
            $updateQuery = "
                UPDATE tblBlogPosts SET
                    title = ?,
                    slug = ?,
                    excerpt = ?,
                    content = ?,
                    featuredImage = ?,
                    category = ?,
                    tags = ?,
                    status = ?,
                    publishedAt = ?,
                    scheduledFor = ?,
                    metaTitle = ?,
                    metaDescription = ?,
                    metaKeywords = ?,
                    allowComments = ?,
                    lastEditedBy = ?,
                    lastEditedAt = NOW(),
                    version = version + 1
                WHERE postID = ?
            ";
            $updateStmt = $mysqli->prepare($updateQuery);
            $updateStmt->bind_param(
                'ssssssssssssiii',
                $title, $slug, $excerpt, $content, $featuredImage, $category, $tags,
                $status, $publishedAt, $scheduledFor, $metaTitle, $metaDescription,
                $metaKeywords, $allowComments, $_SESSION['user_id'], $postID
            );
            
            if ($updateStmt->execute()) {
                $_SESSION['success_message'] = 'Post updated successfully!';
                header('Location: /admin/blog-list');
                exit;
            } else {
                $errors[] = 'Failed to update post: ' . $mysqli->error;
            }
        } else {
            // Insert new post
            $insertQuery = "
                INSERT INTO tblBlogPosts (
                    title, slug, excerpt, content, featuredImage, category, tags,
                    status, publishedAt, scheduledFor, metaTitle, metaDescription,
                    metaKeywords, allowComments, authorID
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $insertStmt = $mysqli->prepare($insertQuery);
            $insertStmt->bind_param(
                'sssssssssssssii',
                $title, $slug, $excerpt, $content, $featuredImage, $category, $tags,
                $status, $publishedAt, $scheduledFor, $metaTitle, $metaDescription,
                $metaKeywords, $allowComments, $_SESSION['user_id']
            );
            
            if ($insertStmt->execute()) {
                $_SESSION['success_message'] = 'Post created successfully!';
                header('Location: /admin/blog-list');
                exit;
            } else {
                $errors[] = 'Failed to create post: ' . $mysqli->error;
            }
        }
    }

    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['error_message'] = implode('<br>', $errors);
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    
    <!-- TinyMCE -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    
    <style>
        .admin-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
        }
        
        .form-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .form-section h3 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: #667eea;
        }
        
        .char-counter {
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Admin Header -->
    <header class="admin-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 mb-0">
                    <i class="fas fa-pen-to-square me-2"></i>
                    <?php echo $isEditing ? 'Edit Post' : 'Create New Post'; ?>
                </h1>
                <a href="/admin/blog-list" class="btn btn-light">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
            </div>
        </div>
    </header>

    <div class="container pb-5">
        <!-- Messages -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="blogForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Main Content Column -->
                <div class="col-lg-8">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-file-alt me-2"></i>Basic Information</h3>
                        
                        <div class="mb-3">
                            <label for="title" class="form-label">Title *</label>
                            <input type="text" class="form-control" id="title" name="title" 
                                   value="<?php echo htmlspecialchars($post['title']); ?>" 
                                   required maxlength="255">
                        </div>

                        <div class="mb-3">
                            <label for="slug" class="form-label">Slug (URL)</label>
                            <input type="text" class="form-control" id="slug" name="slug" 
                                   value="<?php echo htmlspecialchars($post['slug']); ?>" 
                                   maxlength="255">
                            <small class="text-muted">Leave empty to auto-generate from title</small>
                        </div>

                        <div class="mb-3">
                            <label for="excerpt" class="form-label">Excerpt</label>
                            <textarea class="form-control" id="excerpt" name="excerpt" rows="3" maxlength="500"><?php echo htmlspecialchars($post['excerpt']); ?></textarea>
                            <small class="text-muted">Leave empty to auto-generate from content</small>
                        </div>
                    </div>

                    <!-- Content Editor -->
                    <div class="form-section">
                        <h3><i class="fas fa-paragraph me-2"></i>Content *</h3>
                        <textarea id="content" name="content"><?php echo htmlspecialchars($post['content']); ?></textarea>
                    </div>

                    <!-- SEO Settings -->
                    <div class="form-section">
                        <h3><i class="fas fa-search me-2"></i>SEO Settings</h3>
                        
                        <div class="mb-3">
                            <label for="metaTitle" class="form-label">Meta Title</label>
                            <input type="text" class="form-control" id="metaTitle" name="metaTitle" 
                                   value="<?php echo htmlspecialchars($post['metaTitle']); ?>" 
                                   maxlength="70">
                            <small class="text-muted char-counter"><span id="metaTitleCount">0</span>/70 characters</small>
                        </div>

                        <div class="mb-3">
                            <label for="metaDescription" class="form-label">Meta Description</label>
                            <textarea class="form-control" id="metaDescription" name="metaDescription" 
                                      rows="2" maxlength="160"><?php echo htmlspecialchars($post['metaDescription']); ?></textarea>
                            <small class="text-muted char-counter"><span id="metaDescCount">0</span>/160 characters</small>
                        </div>

                        <div class="mb-3">
                            <label for="metaKeywords" class="form-label">Meta Keywords</label>
                            <input type="text" class="form-control" id="metaKeywords" name="metaKeywords" 
                                   value="<?php echo htmlspecialchars($post['metaKeywords']); ?>" 
                                   maxlength="255">
                            <small class="text-muted">Comma-separated keywords</small>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Column -->
                <div class="col-lg-4">
                    <!-- Publish Settings -->
                    <div class="form-section">
                        <h3><i class="fas fa-rocket me-2"></i>Publish</h3>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="draft" <?php echo $post['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="published" <?php echo $post['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="scheduled" <?php echo $post['status'] === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="archived" <?php echo $post['status'] === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>

                        <div class="mb-3" id="scheduledForGroup" style="display: none;">
                            <label for="scheduledFor" class="form-label">Schedule For</label>
                            <input type="datetime-local" class="form-control" id="scheduledFor" name="scheduledFor" 
                                   value="<?php echo $post['scheduledFor'] ? date('Y-m-d\TH:i', strtotime($post['scheduledFor'])) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="allowComments" name="allowComments" 
                                       <?php echo $post['allowComments'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="allowComments">Allow Comments</label>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i><?php echo $isEditing ? 'Update Post' : 'Create Post'; ?>
                            </button>
                            <?php if ($isEditing && $post['status'] === 'published'): ?>
                                <a href="/blog/<?php echo htmlspecialchars($post['slug']); ?>" target="_blank" class="btn btn-outline-primary">
                                    <i class="fas fa-eye me-2"></i>View Post
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Category & Tags -->
                    <div class="form-section">
                        <h3><i class="fas fa-tags me-2"></i>Organization</h3>
                        
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['categoryName']); ?>" 
                                            <?php echo $post['category'] === $cat['categoryName'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['categoryName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="tags" class="form-label">Tags</label>
                            <input type="text" class="form-control" id="tags" name="tags" 
                                   value="<?php echo htmlspecialchars($post['tags']); ?>">
                            <small class="text-muted">Comma-separated tags</small>
                        </div>
                    </div>

                    <!-- Featured Image -->
                    <div class="form-section">
                        <h3><i class="fas fa-image me-2"></i>Featured Image</h3>
                        
                        <div class="mb-3">
                            <input type="url" class="form-control" id="featuredImage" name="featuredImage" 
                                   value="<?php echo htmlspecialchars($post['featuredImage']); ?>" 
                                   placeholder="https://example.com/image.jpg">
                        </div>

                        <?php if ($post['featuredImage']): ?>
                            <img src="<?php echo htmlspecialchars($post['featuredImage']); ?>" 
                                 class="img-fluid rounded" 
                                 alt="Featured Image Preview">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    
    <!-- Custom JS -->
    <script>
        // Initialize TinyMCE
        tinymce.init({
            selector: '#content',
            height: 500,
            menubar: true,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount'
            ],
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
                'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | ' +
                'link image media | removeformat code | help',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 16px; }',
            relative_urls: false,
            remove_script_host: false
        });

        // Auto-generate slug from title
        document.getElementById('title').addEventListener('input', function() {
            const slugField = document.getElementById('slug');
            if (!slugField.value || slugField.dataset.autogenerated) {
                const slug = this.value
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/gi, '-')
                    .replace(/^-+|-+$/g, '');
                slugField.value = slug;
                slugField.dataset.autogenerated = 'true';
            }
        });

        document.getElementById('slug').addEventListener('input', function() {
            if (this.value) {
                this.dataset.autogenerated = 'false';
            }
        });

        // Character counters
        function updateCharCount(inputId, counterId) {
            const input = document.getElementById(inputId);
            const counter = document.getElementById(counterId);
            counter.textContent = input.value.length;
        }

        document.getElementById('metaTitle').addEventListener('input', function() {
            updateCharCount('metaTitle', 'metaTitleCount');
        });

        document.getElementById('metaDescription').addEventListener('input', function() {
            updateCharCount('metaDescription', 'metaDescCount');
        });

        // Initialize counters
        updateCharCount('metaTitle', 'metaTitleCount');
        updateCharCount('metaDescription', 'metaDescCount');

        // Show/hide scheduled date field
        document.getElementById('status').addEventListener('change', function() {
            const scheduledGroup = document.getElementById('scheduledForGroup');
            scheduledGroup.style.display = this.value === 'scheduled' ? 'block' : 'none';
        });

        // Initialize on page load
        if (document.getElementById('status').value === 'scheduled') {
            document.getElementById('scheduledForGroup').style.display = 'block';
        }

        // Form validation
        document.getElementById('blogForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const content = tinymce.get('content').getContent();

            if (title.length < 5) {
                e.preventDefault();
                alert('Title must be at least 5 characters long.');
                return false;
            }

            if (content.length < 50) {
                e.preventDefault();
                alert('Content must be at least 50 characters long.');
                return false;
            }
        });
    </script>
</body>
</html>
