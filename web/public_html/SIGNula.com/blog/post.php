<?php
/**
 * Individual Blog Post View
 * Displays full blog post content with comments
 * 
 * @package SIGNula
 * @version 1.0.0
 */

// Include database configuration
require_once __DIR__ . '/../../../private_html/config/database.php';

// Get post slug from URL
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('Location: /blog');
    exit;
}

// Fetch post data
$postQuery = "
    SELECT 
        p.postID,
        p.title,
        p.slug,
        p.content,
        p.featuredImage,
        p.category,
        p.tags,
        p.publishedAt,
        p.viewCount,
        p.allowComments,
        p.metaTitle,
        p.metaDescription,
        p.metaKeywords,
        u.userID as authorID,
        u.displayName as authorName,
        u.email as authorEmail,
        u.bio as authorBio
    FROM tblBlogPosts p
    LEFT JOIN tblUsers u ON p.authorID = u.userID
    WHERE p.slug = ? 
    AND p.status = 'published' 
    AND p.publishedAt <= NOW() 
    AND p.isDeleted = FALSE
    LIMIT 1
";

$postStmt = $mysqli->prepare($postQuery);
$postStmt->bind_param('s', $slug);
$postStmt->execute();
$postResult = $postStmt->get_result();
$post = $postResult->fetch_assoc();

// 404 if post not found
if (!$post) {
    http_response_code(404);
    header('Location: /blog');
    exit;
}

// Increment view count
$updateViewQuery = "UPDATE tblBlogPosts SET viewCount = viewCount + 1 WHERE postID = ?";
$updateViewStmt = $mysqli->prepare($updateViewQuery);
$updateViewStmt->bind_param('i', $post['postID']);
$updateViewStmt->execute();

// Get related posts (same category, excluding current)
$relatedQuery = "
    SELECT postID, title, slug, excerpt, featuredImage, publishedAt
    FROM tblBlogPosts
    WHERE category = ? 
    AND postID != ? 
    AND status = 'published' 
    AND isDeleted = FALSE
    ORDER BY publishedAt DESC
    LIMIT 3
";
$relatedStmt = $mysqli->prepare($relatedQuery);
$relatedStmt->bind_param('si', $post['category'], $post['postID']);
$relatedStmt->execute();
$relatedResult = $relatedStmt->get_result();
$relatedPosts = $relatedResult->fetch_all(MYSQLI_ASSOC);

// Get comments if enabled
$comments = [];
if ($post['allowComments']) {
    $commentsQuery = "
        SELECT 
            c.commentID,
            c.authorName,
            c.content,
            c.createdAt,
            u.displayName as userName,
            u.email as userEmail
        FROM tblBlogComments c
        LEFT JOIN tblUsers u ON c.userID = u.userID
        WHERE c.postID = ? 
        AND c.status = 'approved' 
        AND c.isDeleted = FALSE
        ORDER BY c.createdAt DESC
    ";
    $commentsStmt = $mysqli->prepare($commentsQuery);
    $commentsStmt->bind_param('i', $post['postID']);
    $commentsStmt->execute();
    $commentsResult = $commentsStmt->get_result();
    $comments = $commentsResult->fetch_all(MYSQLI_ASSOC);
}

// Page configuration
$pageTitle = $post['metaTitle'] ?? $post['title'] . ' - SIGNula Blog';
$metaDescription = $post['metaDescription'] ?? substr(strip_tags($post['content']), 0, 160);
$currentPage = 'blog';

// Include public header
require_once __DIR__ . '/../../../private_html/layout/public-header.php';
?>

<article class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <!-- Post Header -->
                <header class="mb-5">
                    <div class="mb-3">
                        <a href="/blog?category=<?php echo urlencode($post['category']); ?>" class="badge bg-primary text-decoration-none">
                            <?php echo htmlspecialchars($post['category']); ?>
                        </a>
                    </div>
                    
                    <h1 class="display-4 fw-bold mb-4"><?php echo htmlspecialchars($post['title']); ?></h1>

                    <div class="d-flex align-items-center mb-4">
                        <?php
                            // 🖼️ Use AvatarService if available, fallback to Gravatar
                            $postAuthorAvatar = (class_exists('AvatarService') && !empty($post['authorID']))
                                ? AvatarService::resolve((int)$post['authorID'], null, 64)
                                : AvatarService::getGravatarUrl($post['authorEmail'] ?? '', 64);
                        ?>
                        <img src="<?php echo htmlspecialchars($postAuthorAvatar ?? ''); ?>"
                             class="rounded-circle me-3"
                             alt="<?php echo htmlspecialchars($post['authorName']); ?>"
                             style="width: 64px; height: 64px; object-fit: cover;">
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($post['authorName']); ?></div>
                            <small class="text-muted">
                                <i class="far fa-calendar me-1"></i>
                                <?php echo date('F j, Y', strtotime($post['publishedAt'])); ?>
                                <span class="mx-2">•</span>
                                <i class="far fa-eye me-1"></i>
                                <?php echo number_format($post['viewCount'] + 1); ?> views
                            </small>
                        </div>
                    </div>

                    <?php if ($post['featuredImage']): ?>
                        <img src="<?php echo htmlspecialchars($post['featuredImage']); ?>" 
                             class="img-fluid rounded shadow-sm mb-4" 
                             alt="<?php echo htmlspecialchars($post['title']); ?>">
                    <?php endif; ?>
                </header>

                <!-- Post Content -->
                <div class="post-content mb-5">
                    <?php echo $post['content']; ?>
                </div>

                <!-- Tags -->
                <?php if ($post['tags']): 
                    $tags = explode(',', $post['tags']);
                ?>
                    <div class="mb-4">
                        <strong class="me-2">Tags:</strong>
                        <?php foreach ($tags as $tag): ?>
                            <a href="/blog?tag=<?php echo urlencode(trim($tag)); ?>" class="badge bg-light text-dark text-decoration-none me-1">
                                #<?php echo htmlspecialchars(trim($tag)); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Social Share -->
                <div class="card bg-light mb-5">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Share this post</h5>
                        <div class="d-flex gap-2">
                            <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('https://signula.com/blog/' . $post['slug']); ?>&text=<?php echo urlencode($post['title']); ?>" 
                               target="_blank" 
                               class="btn btn-outline-primary">
                                <i class="fab fa-twitter me-2"></i>Twitter
                            </a>
                            <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode('https://signula.com/blog/' . $post['slug']); ?>&title=<?php echo urlencode($post['title']); ?>" 
                               target="_blank" 
                               class="btn btn-outline-primary">
                                <i class="fab fa-linkedin me-2"></i>LinkedIn
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('https://signula.com/blog/' . $post['slug']); ?>" 
                               target="_blank" 
                               class="btn btn-outline-primary">
                                <i class="fab fa-facebook me-2"></i>Facebook
                            </a>
                            <button type="button" class="btn btn-outline-primary" onclick="navigator.clipboard.writeText(window.location.href); alert('Link copied!');">
                                <i class="fas fa-link me-2"></i>Copy Link
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Author Bio -->
                <?php if ($post['authorBio']): ?>
                    <div class="card mb-5">
                        <div class="card-body">
                            <div class="d-flex">
                                <?php
                                    // 🖼️ Author avatar in "About the Author" section
                                    $aboutAuthorAvatar = (class_exists('AvatarService') && !empty($post['authorID']))
                                        ? AvatarService::resolve((int)$post['authorID'], null, 96)
                                        : AvatarService::getGravatarUrl($post['authorEmail'] ?? '', 80);
                                ?>
                                <img src="<?php echo htmlspecialchars($aboutAuthorAvatar ?? ''); ?>"
                                     class="rounded-circle me-3"
                                     alt="<?php echo htmlspecialchars($post['authorName']); ?>"
                                     style="width: 80px; height: 80px; object-fit: cover;">
                                <div>
                                    <h5 class="mb-2">About <?php echo htmlspecialchars($post['authorName']); ?></h5>
                                    <p class="text-muted mb-0"><?php echo htmlspecialchars($post['authorBio']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Comments Section -->
                <?php if ($post['allowComments']): ?>
                    <div class="comments-section mb-5">
                        <h3 class="mb-4">Comments (<?php echo count($comments); ?>)</h3>

                        <!-- Comment Form -->
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <div class="card mb-4">
                                <div class="card-body">
                                    <form action="/blog/comment" method="POST">
                                        <input type="hidden" name="postID" value="<?php echo $post['postID']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                        
                                        <div class="mb-3">
                                            <textarea name="content" class="form-control" rows="4" placeholder="Share your thoughts..." required></textarea>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-comment me-2"></i>Post Comment
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <a href="https://signula.id/login" class="alert-link">Sign in</a> to leave a comment.
                            </div>
                        <?php endif; ?>

                        <!-- Comments List -->
                        <?php if (!empty($comments)): ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <div class="d-flex mb-3">
                                            <?php
                                                // 🖼️ Comment author avatar
                                                $commentAvatar = (class_exists('AvatarService') && !empty($comment['userID']))
                                                    ? AvatarService::resolve((int)$comment['userID'], null, 48)
                                                    : AvatarService::getGravatarUrl($comment['userEmail'] ?? $comment['authorName'] ?? '', 48);
                                            ?>
                                            <img src="<?php echo htmlspecialchars($commentAvatar ?? ''); ?>"
                                                 class="rounded-circle me-3"
                                                 alt="<?php echo htmlspecialchars($comment['userName'] ?? $comment['authorName']); ?>"
                                                 style="width: 48px; height: 48px; object-fit: cover;">
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($comment['userName'] ?? $comment['authorName']); ?></div>
                                                <small class="text-muted"><?php echo date('F j, Y \a\t g:i A', strtotime($comment['createdAt'])); ?></small>
                                            </div>
                                        </div>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">No comments yet. Be the first to share your thoughts!</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Related Posts -->
                <?php if (!empty($relatedPosts)): ?>
                    <div class="related-posts">
                        <h3 class="mb-4">Related Posts</h3>
                        <div class="row">
                            <?php foreach ($relatedPosts as $relatedPost): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100 shadow-sm">
                                        <?php if ($relatedPost['featuredImage']): ?>
                                            <img src="<?php echo htmlspecialchars($relatedPost['featuredImage']); ?>" 
                                                 class="card-img-top" 
                                                 alt="<?php echo htmlspecialchars($relatedPost['title']); ?>">
                                        <?php endif; ?>
                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <a href="/blog/<?php echo htmlspecialchars($relatedPost['slug']); ?>" class="text-decoration-none text-dark">
                                                    <?php echo htmlspecialchars($relatedPost['title']); ?>
                                                </a>
                                            </h5>
                                            <?php if ($relatedPost['excerpt']): ?>
                                                <p class="card-text text-muted small"><?php echo htmlspecialchars(substr($relatedPost['excerpt'], 0, 100)) . '...'; ?></p>
                                            <?php endif; ?>
                                            <a href="/blog/<?php echo htmlspecialchars($relatedPost['slug']); ?>" class="btn btn-sm btn-outline-primary">
                                                Read More
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Back to Blog -->
                <div class="text-center mt-5">
                    <a href="/blog" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Blog
                    </a>
                </div>
            </div>
        </div>
    </div>
</article>

<?php require_once __DIR__ . '/../../../private_html/layout/public-footer.php'; ?>
