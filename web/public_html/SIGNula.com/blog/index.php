<?php
/**
 * Blog Listing Page
 * Displays published blog posts with pagination
 * 
 * @package SIGNula
 * @version 1.0.0
 */

// Page configuration
$pageTitle = 'Blog - SIGNula';
$metaDescription = 'Latest news, updates, and insights from the SIGNula team.';
$currentPage = 'blog';

// Include database configuration
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

// Pagination settings
$postsPerPage = 10;
$currentPageNum = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPageNum - 1) * $postsPerPage;

// Category filter
$categoryFilter = isset($_GET['category']) ? trim($_GET['category']) : null;

// Build query
$whereClause = "status = 'published' AND publishedAt <= NOW() AND isDeleted = FALSE";
$params = [];
$types = '';

if ($categoryFilter) {
    $whereClause .= " AND category = ?";
    $params[] = $categoryFilter;
    $types .= 's';
}

// Get total post count for pagination
$countQuery = "SELECT COUNT(*) as total FROM tblBlogPosts WHERE " . $whereClause;
$countStmt = $mysqli->prepare($countQuery);
if ($categoryFilter) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalPosts = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalPosts / $postsPerPage);

// Get posts for current page
$postsQuery = "
    SELECT 
        p.postID,
        p.title,
        p.slug,
        p.excerpt,
        p.featuredImage,
        p.category,
        p.publishedAt,
        p.viewCount,
        u.displayName as authorName,
        u.email as authorEmail
    FROM tblBlogPosts p
    LEFT JOIN tblUsers u ON p.authorID = u.userID
    WHERE " . $whereClause . "
    ORDER BY p.publishedAt DESC
    LIMIT ? OFFSET ?
";

$postsStmt = $mysqli->prepare($postsQuery);
if ($categoryFilter) {
    $types .= 'ii';
    $params[] = $postsPerPage;
    $params[] = $offset;
    $postsStmt->bind_param($types, ...$params);
} else {
    $postsStmt->bind_param('ii', $postsPerPage, $offset);
}

$postsStmt->execute();
$postsResult = $postsStmt->get_result();
$posts = $postsResult->fetch_all(MYSQLI_ASSOC);

// Get categories for filter
$categoriesQuery = "SELECT DISTINCT category FROM tblBlogPosts WHERE status = 'published' ORDER BY category";
$categoriesResult = $mysqli->query($categoriesQuery);
$categories = $categoriesResult->fetch_all(MYSQLI_ASSOC);

// Include public header
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>

<section class="section-padding bg-light">
    <div class="container">
        <div class="row mb-5">
            <div class="col-lg-8 mx-auto text-center">
                <h1 class="display-4 fw-bold mb-3">Blog</h1>
                <p class="lead text-muted">Insights, updates, and stories from the SIGNula team.</p>
            </div>
        </div>

        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3 mb-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Categories</h5>
                        <div class="list-group">
                            <a href="/blog" class="list-group-item list-group-item-action <?php echo !$categoryFilter ? 'active' : ''; ?>">
                                All Posts
                            </a>
                            <?php foreach ($categories as $cat): ?>
                                <a href="/blog?category=<?php echo urlencode($cat['category']); ?>" 
                                   class="list-group-item list-group-item-action <?php echo $categoryFilter === $cat['category'] ? 'active' : ''; ?>">
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <hr class="my-4">

                        <h5 class="card-title mb-3">Subscribe</h5>
                        <p class="small text-muted">Get the latest posts delivered to your inbox.</p>
                        <form action="/blog/subscribe" method="POST">
                            <div class="mb-2">
                                <input type="email" class="form-control" name="email" placeholder="Your email" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Subscribe</button>
                        </form>

                        <hr class="my-4">

                        <h5 class="card-title mb-3">RSS Feed</h5>
                        <a href="/blog/rss.xml" class="btn btn-outline-primary w-100">
                            <i class="fas fa-rss me-2"></i>Subscribe via RSS
                        </a>
                    </div>
                </div>
            </div>

            <!-- Blog Posts -->
            <div class="col-lg-9">
                <?php if (empty($posts)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No posts found<?php echo $categoryFilter ? ' in this category' : ''; ?>.
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <article class="card shadow-sm mb-4">
                            <div class="row g-0">
                                <?php if ($post['featuredImage']): ?>
                                    <div class="col-md-4">
                                        <img src="<?php echo htmlspecialchars($post['featuredImage']); ?>" 
                                             class="img-fluid rounded-start h-100 object-fit-cover" 
                                             alt="<?php echo htmlspecialchars($post['title']); ?>">
                                    </div>
                                <?php endif; ?>
                                
                                <div class="<?php echo $post['featuredImage'] ? 'col-md-8' : 'col-12'; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($post['category']); ?></span>
                                            <small class="text-muted">
                                                <i class="far fa-calendar me-1"></i>
                                                <?php echo date('F j, Y', strtotime($post['publishedAt'])); ?>
                                            </small>
                                        </div>

                                        <h2 class="h4 card-title mb-3">
                                            <a href="/blog/<?php echo htmlspecialchars($post['slug']); ?>" class="text-decoration-none text-dark">
                                                <?php echo htmlspecialchars($post['title']); ?>
                                            </a>
                                        </h2>

                                        <?php if ($post['excerpt']): ?>
                                            <p class="card-text text-muted">
                                                <?php echo htmlspecialchars($post['excerpt']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between align-items-center mt-3">
                                            <div class="d-flex align-items-center">
                                                <?php
                                                    // 🖼️ Blog list author avatar
                                                    $listAuthorAvatar = (class_exists('AvatarService') && !empty($post['authorID']))
                                                        ? AvatarService::resolve((int)$post['authorID'], null, 48)
                                                        : AvatarService::getGravatarUrl($post['authorEmail'] ?? '', 40);
                                                ?>
                                                <img src="<?php echo htmlspecialchars($listAuthorAvatar ?? ''); ?>"
                                                     class="rounded-circle me-2"
                                                     alt="<?php echo htmlspecialchars($post['authorName']); ?>"
                                                     style="width: 40px; height: 40px; object-fit: cover;">
                                                <div>
                                                    <small class="text-muted d-block">By <?php echo htmlspecialchars($post['authorName']); ?></small>
                                                    <small class="text-muted">
                                                        <i class="far fa-eye me-1"></i><?php echo number_format($post['viewCount']); ?> views
                                                    </small>
                                                </div>
                                            </div>
                                            
                                            <a href="/blog/<?php echo htmlspecialchars($post['slug']); ?>" class="btn btn-outline-primary">
                                                Read More <i class="fas fa-arrow-right ms-1"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Blog pagination">
                            <ul class="pagination justify-content-center">
                                <!-- Previous -->
                                <li class="page-item <?php echo $currentPageNum <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" 
                                       href="?page=<?php echo $currentPageNum - 1; ?><?php echo $categoryFilter ? '&category=' . urlencode($categoryFilter) : ''; ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>

                                <!-- Page Numbers -->
                                <?php
                                $startPage = max(1, $currentPageNum - 2);
                                $endPage = min($totalPages, $currentPageNum + 2);

                                if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1<?php echo $categoryFilter ? '&category=' . urlencode($categoryFilter) : ''; ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?php echo $i === $currentPageNum ? 'active' : ''; ?>">
                                        <a class="page-link" 
                                           href="?page=<?php echo $i; ?><?php echo $categoryFilter ? '&category=' . urlencode($categoryFilter) : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" 
                                           href="?page=<?php echo $totalPages; ?><?php echo $categoryFilter ? '&category=' . urlencode($categoryFilter) : ''; ?>">
                                            <?php echo $totalPages; ?>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <!-- Next -->
                                <li class="page-item <?php echo $currentPageNum >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" 
                                       href="?page=<?php echo $currentPageNum + 1; ?><?php echo $categoryFilter ? '&category=' . urlencode($categoryFilter) : ''; ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
