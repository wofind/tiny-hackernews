<?php
session_start();

// 数据库配置
try {
    $host = 'localhost';
    $dbname = '';
    $username = '';
    $password = '';
    
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 辅助函数
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    if (!isLoggedIn()) return false;
    global $pdo;
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user && $user['is_admin'];
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// 获取帖子列表
function getPosts($type = 'hot', $limit = 10, $offset = 0) {
    global $pdo;
    try {
        $order_by = $type === 'hot' ? 'p.points DESC, p.created_at DESC' : 'p.created_at DESC';
        
        // 获取总记录数
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM posts");
        $count_stmt->execute();
        $total = $count_stmt->fetchColumn();
        
        // 获取分页数据
        $stmt = $pdo->prepare("
            SELECT p.*, u.username, 
            (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
            FROM posts p
            JOIN users u ON p.user_id = u.id
            ORDER BY $order_by
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $posts = $stmt->fetchAll();
        
        return [
            'posts' => $posts,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ];
    } catch(PDOException $e) {
        return [
            'posts' => [],
            'total' => 0,
            'pages' => 0
        ];
    }
}

// 获取单个帖子
function getPost($id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, u.username
            FROM posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch(PDOException $e) {
        return null;
    }
}

// 获取评论
function getComments($post_id, $parent_id = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, u.username
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.post_id = ? AND c.parent_id " . ($parent_id === null ? "IS NULL" : "= ?") . "
            ORDER BY c.points DESC, c.created_at ASC
        ");
        
        if ($parent_id === null) {
            $stmt->execute([$post_id]);
        } else {
            $stmt->execute([$post_id, $parent_id]);
        }
        
        $comments = $stmt->fetchAll();
        
        // 递归获取子评论
        foreach ($comments as &$comment) {
            $comment['replies'] = getComments($post_id, $comment['id']);
        }
        
        return $comments;
    } catch(PDOException $e) {
        return [];
    }
}

// 渲染评论
function renderComment($comment, $depth = 0) {
    ?>
    <div class="comment" style="margin-left: <?php echo $depth * 20; ?>px;">
        <div class="vote-buttons">
            <?php if (isLoggedIn()): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="vote" value="1">
                    <input type="hidden" name="item_type" value="comment">
                    <input type="hidden" name="item_id" value="<?php echo $comment['id']; ?>">
                    <input type="hidden" name="vote_type" value="up">
                    <button type="submit">▲</button>
                </form>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="vote" value="1">
                    <input type="hidden" name="item_type" value="comment">
                    <input type="hidden" name="item_id" value="<?php echo $comment['id']; ?>">
                    <input type="hidden" name="vote_type" value="down">
                    <button type="submit">▼</button>
                </form>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
                <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this comment?');">
                    <input type="hidden" name="delete" value="1">
                    <input type="hidden" name="item_type" value="comment">
                    <input type="hidden" name="item_id" value="<?php echo $comment['id']; ?>">
                    <button type="submit" style="color: red;">Delete</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="comment-content">
            <div class="comment-text">
                <?php echo nl2br(htmlspecialchars($comment['content'])); ?>
            </div>
            <div class="comment-meta">
                <?php echo $comment['points']; ?> 点赞 
                <a href="/user/<?php echo $comment['user_id']; ?>"><?php echo htmlspecialchars($comment['username']); ?></a>
                <?php if (isLoggedIn()): ?>
                    | <a href="#" onclick="showReplyForm(<?php echo $comment['id']; ?>); return false;">评论</a>
                <?php endif; ?>
            </div>
            <?php if (isLoggedIn()): ?>
                <div id="reply-form-<?php echo $comment['id']; ?>" class="reply-form" style="display: none;">
                    <form method="post">
                        <input type="hidden" name="post_id" value="<?php echo $comment['post_id']; ?>">
                        <input type="hidden" name="parent_id" value="<?php echo $comment['id']; ?>">
                        <div class="form-group">
                            <textarea name="content" required></textarea>
                        </div>
                        <button type="submit" name="submit_comment">提交评论</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    // 递归渲染子评论
    if (isset($comment['replies'])) {
        foreach ($comment['replies'] as $reply) {
            renderComment($reply, $depth + 1);
        }
    }
}

// 处理请求
$action = $_GET['action'] ?? 'index';
$type = $_GET['type'] ?? 'hot';
$post_id = $_GET['id'] ?? null;

// 处理用户页面的 URL
if (preg_match('#^/user/(\d+)$#', $_SERVER['REQUEST_URI'], $matches)) {
    $action = 'user';
    $user_id = $matches[1];
} else {
    $user_id = $_GET['user_id'] ?? null;
}

// 如果是文章页面，提前获取文章信息
if ($action === 'post' && $post_id) {
    $post = getPost($post_id);
    if (!$post) {
        header('Location: /');
        exit;
    }
}

// 如果是用户页面，提前获取用户信息
if ($action === 'user' && $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, created_at FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) {
            header('Location: /');
            exit;
        }
    } catch(PDOException $e) {
        header('Location: /');
        exit;
    }
}

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: /');
        exit;
    }
}

// 处理注册
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if ($password === $confirm_password) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashed_password]);
            header('Location: /?action=login');
            exit;
        } catch(PDOException $e) {
            $error = "Registration failed, username or email already exists";
        }
    } else {
        $error = "Passwords do not match";
    }
}

// 处理登出
if ($action === 'logout') {
    session_destroy();
    header('Location: /');
    exit;
}

// 处理投票
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vote'])) {
    if (!isLoggedIn()) {
        header('Location: /?action=login');
        exit;
    }
    
    $vote_type = $_POST['vote_type'] ?? '';
    $item_type = $_POST['item_type'] ?? '';
    $item_id = $_POST['item_id'] ?? null;
    
    if ($item_id && $vote_type && $item_type) {
        try {
            $id_field = $item_type . '_id';
            $table = $item_type === 'post' ? 'posts' : 'comments';
            
            // 检查是否已经投票
            $stmt = $pdo->prepare("SELECT * FROM votes WHERE user_id = ? AND $id_field = ?");
            $stmt->execute([$_SESSION['user_id'], $item_id]);
            $existing_vote = $stmt->fetch();
            
            if ($existing_vote) {
                if ($existing_vote['vote_type'] === $vote_type) {
                    // 删除投票
                    $stmt = $pdo->prepare("DELETE FROM votes WHERE id = ?");
                    $stmt->execute([$existing_vote['id']]);
                } else {
                    // 更新投票
                    $stmt = $pdo->prepare("UPDATE votes SET vote_type = ? WHERE id = ?");
                    $stmt->execute([$vote_type, $existing_vote['id']]);
                }
            } else {
                // 创建新投票
                $stmt = $pdo->prepare("INSERT INTO votes (user_id, $id_field, vote_type) VALUES (?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $item_id, $vote_type]);
            }
            
            // 更新积分
            $stmt = $pdo->prepare("
                UPDATE $table 
                SET points = (
                    SELECT COUNT(*) FROM votes 
                    WHERE $id_field = ? AND vote_type = 'up'
                ) - (
                    SELECT COUNT(*) FROM votes 
                    WHERE $id_field = ? AND vote_type = 'down'
                )
                WHERE id = ?
            ");
            $stmt->execute([$item_id, $item_id, $item_id]);
        } catch(PDOException $e) {
            // 处理错误
        }
    }
    
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

// 处理发帖
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    if (!isLoggedIn()) {
        header('Location: /?action=login');
        exit;
    }
    
    $title = $_POST['title'] ?? '';
    $url = $_POST['url'] ?? '';
    $content = $_POST['content'] ?? '';
    
    if (!empty($title) && (!empty($url) || !empty($content))) {
        // 自动将内容转为<p>段落+<br>换行的HTML
        $safe_content = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $paragraphs = preg_split('/\r?\n\r?\n/', $safe_content);
        $content_html = '';
        foreach ($paragraphs as $para) {
            $content_html .= '<p>' . nl2br(trim($para)) . '</p>';
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO posts (user_id, title, url, content) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $title, $url, $content_html]);
            header('Location: /');
            exit;
        } catch(PDOException $e) {
            $error = "发帖失败";
        }
    }
}

// 处理评论
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    if (!isLoggedIn()) {
        header('Location: /?action=login');
        exit;
    }
    
    $content = $_POST['content'] ?? '';
    $post_id = $_POST['post_id'] ?? null;
    $parent_id = $_POST['parent_id'] ?? null;
    
    if (!empty($content) && $post_id) {
        try {
            $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, parent_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$post_id, $_SESSION['user_id'], $content, $parent_id]);
            header('Location: /?action=post&id=' . $post_id);
            exit;
        } catch(PDOException $e) {
            $error = "Failed to submit comment";
        }
    }
}

// 处理删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!isLoggedIn() || !isAdmin()) {
        header('Location: /');
        exit;
    }
    
    $item_type = $_POST['item_type'] ?? '';
    $item_id = $_POST['item_id'] ?? null;
    
    if ($item_id && $item_type) {
        try {
            $pdo->beginTransaction();
            
            if ($item_type === 'post') {
                // 删除帖子的所有评论
                $stmt = $pdo->prepare("DELETE FROM comments WHERE post_id = ?");
                $stmt->execute([$item_id]);
                
                // 删除帖子的所有投票
                $stmt = $pdo->prepare("DELETE FROM votes WHERE post_id = ?");
                $stmt->execute([$item_id]);
                
                // 删除帖子
                $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
                $stmt->execute([$item_id]);
            } else {
                // 删除评论的所有投票
                $stmt = $pdo->prepare("DELETE FROM votes WHERE comment_id = ?");
                $stmt->execute([$item_id]);
                
                // 删除评论
                $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
                $stmt->execute([$item_id]);
            }
            
            $pdo->commit();
        } catch(PDOException $e) {
            $pdo->rollBack();
        }
    }
    
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

// 页面头部
function renderHeader() {
    ?>
    <div class="header">
        <a href="/">首页</a>
        <a href="/hot" class="<?php echo $GLOBALS['type'] === 'hot' ? 'active' : ''; ?>">热门</a>
        <a href="/latest" class="<?php echo $GLOBALS['type'] === 'latest' ? 'active' : ''; ?>">最新</a>
        <?php if (isLoggedIn()): ?>
            <a href="/submit">发布</a>
            <a href="/logout">退出</a>
        <?php else: ?>
            <a href="/login">登录</a>
            <a href="/register">注册</a>
        <?php endif; ?>
    </div>
    <?php
}

// 渲染页面
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php 
        switch ($action) {
            case 'post':
                if (isset($post)) {
                    echo htmlspecialchars($post['title']) . ' - 查神';
                } else {
                    echo '查神';
                }
                break;
            case 'user':
                if (isset($user) && isset($user['username'])) {
                    echo htmlspecialchars($user['username']) . ' - 查神';
                } else {
                    echo '查神';
                }
                break;
            case 'login':
                echo '登录 - 查神';
                break;
            case 'register':
                echo '注册 - 查神';
                break;
            case 'submit':
                echo '发布 - 查神';
                break;
            default:
                if ($type == 'hot') {
                    echo '查神';
                } else if ($type == 'latest') {
                    echo '最新 - 查神';
                } else {
                    echo '查神';
                }
        }
    ?></title>
<link rel='stylesheet' href='//www.chashen.me/style.css' type='text/css' media='all' />
</head>
<body>
    <?php renderHeader(); ?>

    <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php
    switch ($action) {
        case 'login':
            if (isLoggedIn()) {
                header('Location: /');
                exit;
            }
            ?>
            <div class="form-container">
                <h2>登录</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="username">用户名:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">密码:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit" name="login">登录</button>
                </form>
                <p>没有账号? <a href="/register">注册</a></p>
            </div>
            <?php
            break;

        case 'register':
            if (isLoggedIn()) {
                header('Location: /');
                exit;
            }
            ?>
            <div class="form-container">
                <h2>注册</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="username">用户名:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="email">邮箱:</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">密码:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">确认密码:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" name="register">注册</button>
                </form>
                <p>已有账号? <a href="/login">登录</a></p>
            </div>
            <?php
            break;

        case 'submit':
            if (!isLoggedIn()) {
                header('Location: /?action=login');
                exit;
            }
            ?>
            <div class="form-container">
                <h2>提交帖子</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="title">标题:</label>
                        <input type="text" id="title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="url">链接:</label>
                        <input type="text" id="url" name="url">
                    </div>
                    <div class="form-group">
                        <label for="content">内容:</label>
                        <textarea id="content" name="content"></textarea>
                    </div>
                    <button type="submit" name="submit_post">提交</button>
                </form>
            </div>
            <?php
            break;

        case 'post':
            if (!$post_id) {
                header('Location: /');
                exit;
            }
            
            $post = getPost($post_id);
            if (!$post) {
                header('Location: /');
                exit;
            }
            
            $comments = getComments($post_id);
            ?>
            <div class="post">
                <div class="vote-buttons">
                    <?php if (isLoggedIn()): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="vote" value="1">
                            <input type="hidden" name="item_type" value="post">
                            <input type="hidden" name="item_id" value="<?php echo $post['id']; ?>">
                            <input type="hidden" name="vote_type" value="up">
                            <button type="submit">▲</button>
                        </form>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="vote" value="1">
                            <input type="hidden" name="item_type" value="post">
                            <input type="hidden" name="item_id" value="<?php echo $post['id']; ?>">
                            <input type="hidden" name="vote_type" value="down">
                            <button type="submit">▼</button>
                        </form>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                        <form method="post" style="display: inline;" onsubmit="return confirm('确定要删除这个帖子吗？');">
                            <input type="hidden" name="delete" value="1">
                            <input type="hidden" name="item_type" value="post">
                            <input type="hidden" name="item_id" value="<?php echo $post['id']; ?>">
                            <button type="submit" style="color: red;">删除</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="post-content">
                    <div class="post-title">
                        <?php if (!empty($post['url'])): ?>
                            <a href="<?php echo htmlspecialchars($post['url']); ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                            <span class="domain">(<?php echo parse_url($post['url'], PHP_URL_HOST); ?>)</span>
                        <?php else: ?>
                            <a href="/post/<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="post-meta">
                        <?php echo $post['points']; ?> 点赞  
                        <a href="/user/<?php echo $post['user_id']; ?>"><?php echo htmlspecialchars($post['username']); ?></a>
                    </div>
                    <?php if (!empty($post['content'])): ?>
                        <div class="post-text">
                            <?php echo $post['content']; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isLoggedIn()): ?>
                <div class="form-container">
                    <h3>添加评论</h3>
                    <form method="post">
                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                        <div class="form-group">
                            <textarea name="content" required></textarea>
                        </div>
                        <button type="submit" name="submit_comment">提交</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="comments">
                <h3>评论</h3>
                <?php foreach ($comments as $comment): ?>
                    <?php renderComment($comment); ?>
                <?php endforeach; ?>
            </div>
            <?php
            break;

        case 'user':
            if (isset($user) && isset($user['username'])) {
                echo '<div class="form-container">';
                echo '<h2>用户：' . htmlspecialchars($user['username']) . '</h2>';
                echo '</div>';

                // 查询该用户的所有帖子，包含积分、评论数、用户名
                $stmt = $pdo->prepare("
                    SELECT p.*, 
                           u.username, 
                           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count
                    FROM posts p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.user_id = ?
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$user_id]);
                $posts = $stmt->fetchAll();

                if (empty($posts)) {
                    echo '<div class="no-posts"><p>暂无帖子</p></div>';
                } else {
                    foreach ($posts as $post) {
                        echo '<div class="post">';
                        echo '<div class="vote-buttons"></div>';
                        echo '<div class="post-content">';
                        echo '<div class="post-title">';
                        if (!empty($post['url'])) {
                            echo '<a href="' . htmlspecialchars($post['url']) . '">' . htmlspecialchars($post['title']) . '</a>';
                            echo '<span class="domain">(' . parse_url($post['url'], PHP_URL_HOST) . ')</span>';
                        } else {
                            echo '<a href="/post/' . $post['id'] . '">' . htmlspecialchars($post['title']) . '</a>';
                        }
                        echo '</div>';
                        echo '<div class="post-meta">';
                        echo $post['points'] . ' 点赞 ';
                        echo '<a href="/user/' . $post['user_id'] . '">' . htmlspecialchars($post['username']) . '</a>';
                        echo ' | <a href="/post/' . $post['id'] . '">' . $post['comment_count'] . ' 评论</a>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                    }
                }
            }
            break;

        default:
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = 10;
            $offset = ($page - 1) * $limit;
            
            $result = getPosts($type, $limit, $offset);
            $posts = $result['posts'];
            $total_pages = $result['pages'];
            
            if (empty($posts)): ?>
                <div class="no-posts">
                    <p>暂无帖子</p>
                    <?php if (isLoggedIn()): ?>
                        <p><a href="/submit">提交第一个帖子</a></p>
                    <?php else: ?>
                        <p><a href="/login">登录</a> 以提交帖子</p>
                    <?php endif; ?>
                </div>
            <?php else:
                foreach ($posts as $post): ?>
                    <div class="post">
                        <div class="vote-buttons">
                            <?php if (isLoggedIn()): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="vote" value="1">
                                    <input type="hidden" name="item_type" value="post">
                                    <input type="hidden" name="item_id" value="<?php echo $post['id']; ?>">
                                    <input type="hidden" name="vote_type" value="up">
                                    <button type="submit">▲</button>
                                </form>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="vote" value="1">
                                    <input type="hidden" name="item_type" value="post">
                                    <input type="hidden" name="item_id" value="<?php echo $post['id']; ?>">
                                    <input type="hidden" name="vote_type" value="down">
                                    <button type="submit">▼</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="post-content">
                            <div class="post-title">
                                <?php if (!empty($post['url'])): ?>
                                    <a href="<?php echo htmlspecialchars($post['url']); ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                                    <span class="domain">(<?php echo parse_url($post['url'], PHP_URL_HOST); ?>)</span>
                                <?php else: ?>
                                    <a href="/post/<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                                <?php endif; ?>
                            </div>
                            <div class="post-meta">
                                <?php echo $post['points']; ?> 点赞 
                                <a href="/user/<?php echo $post['user_id']; ?>"><?php echo htmlspecialchars($post['username']); ?></a>
                                | <a href="/post/<?php echo $post['id']; ?>"><?php echo $post['comment_count']; ?> 评论</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="/<?php echo $type; ?>/page/<?php echo $page - 1; ?>" class="page-link">&laquo; 上一页</a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="/<?php echo $type; ?>/page/<?php echo $i; ?>" 
                               class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="/<?php echo $type; ?>/page/<?php echo $page + 1; ?>" class="page-link">下一页 &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif;
            break;
    }
    ?>
    <script>
    function showReplyForm(commentId) {
        const form = document.getElementById('reply-form-' + commentId);
        if (form.style.display === 'none') {
            form.style.display = 'block';
        } else {
            form.style.display = 'none';
        }
    }
    </script>
</body>
</html> 
