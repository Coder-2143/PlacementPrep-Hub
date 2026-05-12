<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        send_json(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }
    return $decoded;
}

function str_val($v): string {
    return trim((string)($v ?? ''));
}

function require_auth(): array {
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        send_json(401, ['ok' => false, 'error' => 'Authentication required']);
    }
    return $_SESSION['user'];
}

function require_admin(array $user): void {
    if (strtolower((string)($user['role'] ?? 'user')) !== 'admin') {
        send_json(403, ['ok' => false, 'error' => 'Admin access required']);
    }
}

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'placementprep';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);

$mysqli = null;
try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    send_json(500, ['ok' => false, 'error' => 'Database connection failed']);
}
if (!$mysqli || $mysqli->connect_errno) {
    send_json(500, ['ok' => false, 'error' => 'Database connection failed']);
}
$mysqli->set_charset('utf8mb4');

$endpoint = trim((string)($_GET['endpoint'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = read_json_body();

if ($endpoint === 'register' || $endpoint === 'users/register') {
    if ($method !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }

    $fullName = str_val($body['fullName'] ?? '');
    $email = strtolower(str_val($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $bio = str_val($body['bio'] ?? '');
    $expertise = str_val($body['expertise'] ?? '');

    if ($fullName === '' || $email === '' || $password === '' || $bio === '' || $expertise === '') {
        send_json(400, ['ok' => false, 'error' => 'All fields are required']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json(400, ['ok' => false, 'error' => 'Invalid email']);
    }
    if (strlen($password) < 6) {
        send_json(400, ['ok' => false, 'error' => 'Password must be at least 6 characters']);
    }

    $check = $mysqli->prepare('SELECT email FROM users WHERE email = ? LIMIT 1');
    $check->bind_param('s', $email);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        send_json(409, ['ok' => false, 'error' => 'User already exists']);
    }
    $check->close();

    $createdAt = (int)round(microtime(true) * 1000);
    $role = 'user';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $mysqli->prepare('INSERT INTO users (email, fullName, password, bio, expertise, role, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->bind_param('ssssssi', $email, $fullName, $hash, $bio, $expertise, $role, $createdAt);
    if (!$insert->execute()) {
        send_json(500, ['ok' => false, 'error' => 'Failed to create user', 'info' => $insert->error]);
    }
    $insert->close();

    send_json(201, ['ok' => true, 'message' => 'Registered']);
}

if ($endpoint === 'login' || $endpoint === 'users/login') {
    if ($method !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }

    $email = strtolower(str_val($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($email === '' || $password === '') {
        send_json(400, ['ok' => false, 'error' => 'Email and password are required']);
    }

    $stmt = $mysqli->prepare('SELECT email, fullName, password, bio, expertise, role FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$user) {
        send_json(401, ['ok' => false, 'error' => 'Invalid credentials']);
    }

    $stored = (string)($user['password'] ?? '');
    $valid = password_verify($password, $stored);
    if (!$valid) {
        send_json(401, ['ok' => false, 'error' => 'Invalid credentials']);
    }

    $sessionUser = [
        'email' => (string)$user['email'],
        'fullName' => (string)$user['fullName'],
        'bio' => (string)($user['bio'] ?? ''),
        'expertise' => (string)($user['expertise'] ?? ''),
        'role' => strtolower((string)($user['role'] ?? 'user')) === 'admin' ? 'admin' : 'user'
    ];
    $_SESSION['user'] = $sessionUser;

    send_json(200, ['ok' => true, 'user' => $sessionUser]);
}

if ($endpoint === 'logout' || $endpoint === 'users/logout') {
    if ($method !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    send_json(200, ['ok' => true, 'message' => 'Logged out']);
}

if ($endpoint === 'session' || $endpoint === 'users/session') {
    if ($method !== 'GET') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }
    send_json(200, ['ok' => true, 'user' => $_SESSION['user'] ?? null]);
}

if ($endpoint === 'users') {
    if ($method !== 'GET') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }
    $users = [];
    $query = $mysqli->query('SELECT email, fullName, bio, expertise, role, createdAt FROM users ORDER BY createdAt ASC');
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $users[] = [
                'email' => (string)$row['email'],
                'fullName' => (string)$row['fullName'],
                'bio' => (string)($row['bio'] ?? ''),
                'expertise' => (string)($row['expertise'] ?? ''),
                'role' => strtolower((string)$row['role']) === 'admin' ? 'admin' : 'user',
                'createdAt' => (int)($row['createdAt'] ?? 0)
            ];
        }
    }
    send_json(200, ['ok' => true, 'users' => $users]);
}

if ($endpoint === 'role' || $endpoint === 'users/role') {
    $current = require_auth();
    require_admin($current);
    if ($method !== 'PUT') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }
    $email = strtolower(str_val($body['email'] ?? ''));
    $role = strtolower(str_val($body['role'] ?? 'user'));
    $role = $role === 'admin' ? 'admin' : 'user';
    if ($email === '') {
        send_json(400, ['ok' => false, 'error' => 'Email is required']);
    }

    $stmt = $mysqli->prepare('UPDATE users SET role = ? WHERE email = ?');
    $stmt->bind_param('ss', $role, $email);
    $stmt->execute();
    $stmt->close();

    if (strtolower((string)$current['email']) === $email) {
        $_SESSION['user']['role'] = $role;
    }
    send_json(200, ['ok' => true, 'email' => $email, 'role' => $role]);
}

if ($endpoint === 'posts') {
    if ($method === 'GET') {
        $posts = [];
        $query = $mysqli->query("SELECT id, title, category, content, excerpt, coverImage, videoUrl, youtubeUrl, author, authorEmail, authorBio, expertise, date, status, createdAt, likes FROM posts WHERE status='published' ORDER BY createdAt DESC");
        if ($query) {
            while ($row = $query->fetch_assoc()) {
                $row['createdAt'] = (int)($row['createdAt'] ?? 0);
                $row['likes'] = (int)($row['likes'] ?? 0);
                $posts[] = $row;
            }
        }
        send_json(200, ['ok' => true, 'posts' => $posts]);
    }
    if ($method === 'POST') {
        $current = require_auth();
        $id = str_val($body['id'] ?? '');
        $title = str_val($body['title'] ?? '');
        $category = str_val($body['category'] ?? '');
        $content = str_val($body['content'] ?? '');
        if ($id === '' || $title === '' || $category === '' || $content === '') {
            send_json(400, ['ok' => false, 'error' => 'Missing required post fields']);
        }

        $excerpt = str_val($body['excerpt'] ?? '');
        $coverImage = str_val($body['coverImage'] ?? '');
        $videoUrl = str_val($body['videoUrl'] ?? '');
        $youtubeUrl = str_val($body['youtubeUrl'] ?? '');
        $author = str_val($body['author'] ?? $current['fullName']);
        $authorEmail = strtolower(str_val($body['authorEmail'] ?? $current['email']));
        $authorBio = str_val($body['authorBio'] ?? $current['bio']);
        $expertise = str_val($body['expertise'] ?? $current['expertise']);
        $date = str_val($body['date'] ?? '');
        $status = str_val($body['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $createdAt = (int)($body['createdAt'] ?? round(microtime(true) * 1000));
        $likes = (int)($body['likes'] ?? 0);

        $stmt = $mysqli->prepare('INSERT INTO posts (id, title, category, content, excerpt, coverImage, videoUrl, youtubeUrl, author, authorEmail, authorBio, expertise, date, status, createdAt, likes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), category=VALUES(category), content=VALUES(content), excerpt=VALUES(excerpt), coverImage=VALUES(coverImage), videoUrl=VALUES(videoUrl), youtubeUrl=VALUES(youtubeUrl), author=VALUES(author), authorEmail=VALUES(authorEmail), authorBio=VALUES(authorBio), expertise=VALUES(expertise), date=VALUES(date), status=VALUES(status), createdAt=VALUES(createdAt), likes=VALUES(likes)');
        $stmt->bind_param('ssssssssssssssii', $id, $title, $category, $content, $excerpt, $coverImage, $videoUrl, $youtubeUrl, $author, $authorEmail, $authorBio, $expertise, $date, $status, $createdAt, $likes);
        if (!$stmt->execute()) {
            send_json(500, ['ok' => false, 'error' => 'Failed to save post', 'info' => $stmt->error]);
        }
        $stmt->close();

        send_json(200, ['ok' => true, 'id' => $id]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'post') {
    $id = str_val($_GET['id'] ?? '');
    if ($id === '') {
        send_json(400, ['ok' => false, 'error' => 'Missing post id']);
    }
    if ($method === 'GET') {
        $stmt = $mysqli->prepare('SELECT id, title, category, content, excerpt, coverImage, videoUrl, youtubeUrl, author, authorEmail, authorBio, expertise, date, status, createdAt, likes FROM posts WHERE id = ? LIMIT 1');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $post = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$post) {
            send_json(404, ['ok' => false, 'error' => 'Post not found']);
        }
        $post['createdAt'] = (int)($post['createdAt'] ?? 0);
        $post['likes'] = (int)($post['likes'] ?? 0);
        send_json(200, ['ok' => true, 'post' => $post]);
    }
    if ($method === 'DELETE') {
        $current = require_auth();
        $stmt = $mysqli->prepare('SELECT authorEmail FROM posts WHERE id = ? LIMIT 1');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $post = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$post) {
            send_json(404, ['ok' => false, 'error' => 'Post not found']);
        }
        $isAdmin = strtolower((string)$current['role']) === 'admin';
        if (!$isAdmin && strtolower((string)$current['email']) !== strtolower((string)$post['authorEmail'])) {
            send_json(403, ['ok' => false, 'error' => 'Not allowed to delete this post']);
        }

        $stmt = $mysqli->prepare('DELETE FROM posts WHERE id = ?');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt->close();
        send_json(200, ['ok' => true, 'id' => $id]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'comments') {
    $postId = str_val($_GET['postId'] ?? '');
    if ($postId === '') {
        send_json(400, ['ok' => false, 'error' => 'Missing postId']);
    }
    if ($method === 'GET') {
        $comments = [];
        $stmt = $mysqli->prepare('SELECT id, postId, name, email, comment, date FROM comments WHERE postId = ? ORDER BY id ASC');
        $stmt->bind_param('s', $postId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['replies'] = [];
            $comments[$row['id']] = $row;
        }
        $stmt->close();

        foreach (array_keys($comments) as $cid) {
            $replyStmt = $mysqli->prepare('SELECT id, name, text, date FROM replies WHERE commentId = ? ORDER BY id ASC');
            $replyStmt->bind_param('s', $cid);
            $replyStmt->execute();
            $rr = $replyStmt->get_result();
            while ($reply = $rr->fetch_assoc()) {
                $comments[$cid]['replies'][] = [
                    'id' => (string)$reply['id'],
                    'name' => (string)$reply['name'],
                    'text' => (string)$reply['text'],
                    'date' => (string)$reply['date']
                ];
            }
            $replyStmt->close();
        }

        send_json(200, ['ok' => true, 'comments' => array_values($comments)]);
    }
    if ($method === 'POST') {
        require_auth();
        $id = str_val($body['id'] ?? '');
        $name = str_val($body['name'] ?? '');
        $email = str_val($body['email'] ?? '');
        $comment = str_val($body['comment'] ?? '');
        $date = str_val($body['date'] ?? '');
        if ($id === '' || $name === '' || $comment === '') {
            send_json(400, ['ok' => false, 'error' => 'Invalid comment']);
        }
        $stmt = $mysqli->prepare('INSERT INTO comments (id, postId, name, email, comment, date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssss', $id, $postId, $name, $email, $comment, $date);
        if (!$stmt->execute()) {
            send_json(500, ['ok' => false, 'error' => 'Failed to save comment', 'info' => $stmt->error]);
        }
        $stmt->close();
        send_json(201, ['ok' => true, 'id' => $id]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'replies') {
    require_auth();
    if ($method !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }
    $commentId = str_val($_GET['commentId'] ?? '');
    $id = str_val($body['id'] ?? '');
    $name = str_val($body['name'] ?? '');
    $text = str_val($body['text'] ?? '');
    $date = str_val($body['date'] ?? '');
    if ($commentId === '' || $id === '' || $name === '' || $text === '') {
        send_json(400, ['ok' => false, 'error' => 'Invalid reply']);
    }
    $stmt = $mysqli->prepare('INSERT INTO replies (id, commentId, name, text, date) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssss', $id, $commentId, $name, $text, $date);
    if (!$stmt->execute()) {
        send_json(500, ['ok' => false, 'error' => 'Failed to save reply', 'info' => $stmt->error]);
    }
    $stmt->close();
    send_json(201, ['ok' => true, 'id' => $id]);
}

if ($endpoint === 'likes') {
    if ($method === 'GET') {
        $postId = str_val($_GET['postId'] ?? '');
        if ($postId === '') {
            send_json(400, ['ok' => false, 'error' => 'Missing postId']);
        }
        $actors = [];
        $stmt = $mysqli->prepare('SELECT actorId FROM likes WHERE postId = ?');
        $stmt->bind_param('s', $postId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $actors[] = (string)$row['actorId'];
        }
        $stmt->close();
        send_json(200, ['ok' => true, 'postId' => $postId, 'actors' => $actors]);
    }
    if ($method === 'POST') {
        $postId = str_val($body['postId'] ?? '');
        $actorId = str_val($body['actorId'] ?? '');
        if ($postId === '' || $actorId === '') {
            send_json(400, ['ok' => false, 'error' => 'postId and actorId are required']);
        }

        $check = $mysqli->prepare('SELECT 1 FROM likes WHERE postId = ? AND actorId = ? LIMIT 1');
        $check->bind_param('ss', $postId, $actorId);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();

        if ($exists) {
            $del = $mysqli->prepare('DELETE FROM likes WHERE postId = ? AND actorId = ?');
            $del->bind_param('ss', $postId, $actorId);
            $del->execute();
            $del->close();
            send_json(200, ['ok' => true, 'liked' => false]);
        }

        $ins = $mysqli->prepare('INSERT INTO likes (postId, actorId) VALUES (?, ?)');
        $ins->bind_param('ss', $postId, $actorId);
        $ins->execute();
        $ins->close();
        send_json(200, ['ok' => true, 'liked' => true]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'saved') {
    if ($method === 'GET') {
        $userKey = str_val($_GET['userKey'] ?? '');
        if ($userKey === '') {
            send_json(400, ['ok' => false, 'error' => 'Missing userKey']);
        }
        $postIds = [];
        $stmt = $mysqli->prepare('SELECT postId FROM saved_posts WHERE userKey = ?');
        $stmt->bind_param('s', $userKey);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $postIds[] = (string)$row['postId'];
        }
        $stmt->close();
        send_json(200, ['ok' => true, 'postIds' => $postIds]);
    }
    if ($method === 'POST') {
        $userKey = str_val($body['userKey'] ?? '');
        $postId = str_val($body['postId'] ?? '');
        if ($userKey === '' || $postId === '') {
            send_json(400, ['ok' => false, 'error' => 'userKey and postId are required']);
        }

        if ($userKey !== 'savedPosts:guest') {
            $current = require_auth();
            if (strtolower((string)$current['email']) !== strtolower($userKey)) {
                send_json(403, ['ok' => false, 'error' => "Cannot modify another user's saved posts"]);
            }
        }

        $check = $mysqli->prepare('SELECT 1 FROM saved_posts WHERE userKey = ? AND postId = ? LIMIT 1');
        $check->bind_param('ss', $userKey, $postId);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();

        if ($exists) {
            $stmt = $mysqli->prepare('DELETE FROM saved_posts WHERE userKey = ? AND postId = ?');
            $stmt->bind_param('ss', $userKey, $postId);
            $stmt->execute();
            $stmt->close();
            send_json(200, ['ok' => true, 'saved' => false]);
        }

        $stmt = $mysqli->prepare('INSERT INTO saved_posts (userKey, postId) VALUES (?, ?)');
        $stmt->bind_param('ss', $userKey, $postId);
        $stmt->execute();
        $stmt->close();
        send_json(200, ['ok' => true, 'saved' => true]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'report' || $endpoint === 'reports') {
    if ($method === 'GET') {
        $current = require_auth();
        require_admin($current);
        $reports = [];
        $query = $mysqli->query('SELECT id, postId, reason, note, date FROM reports ORDER BY id DESC');
        if ($query) {
            while ($row = $query->fetch_assoc()) {
                $reports[] = $row;
            }
        }
        send_json(200, ['ok' => true, 'reports' => $reports]);
    }
    if ($method === 'POST') {
        require_auth();
        $id = str_val($body['id'] ?? '');
        $postId = str_val($body['postId'] ?? '');
        $reason = str_val($body['reason'] ?? '');
        $note = str_val($body['note'] ?? '');
        $date = str_val($body['date'] ?? '');
        if ($id === '' || $postId === '' || $reason === '') {
            send_json(400, ['ok' => false, 'error' => 'Invalid report payload']);
        }
        $stmt = $mysqli->prepare('INSERT INTO reports (id, postId, reason, note, date) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $id, $postId, $reason, $note, $date);
        if (!$stmt->execute()) {
            send_json(500, ['ok' => false, 'error' => 'Failed to save report', 'info' => $stmt->error]);
        }
        $stmt->close();
        send_json(201, ['ok' => true, 'id' => $id]);
    }
    if ($method === 'DELETE') {
        $current = require_auth();
        require_admin($current);
        $id = str_val($_GET['id'] ?? '');
        if ($id === '') {
            send_json(400, ['ok' => false, 'error' => 'Missing report id']);
        }
        $stmt = $mysqli->prepare('DELETE FROM reports WHERE id = ?');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt->close();
        send_json(200, ['ok' => true, 'id' => $id]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'contact') {
    if ($method === 'POST') {
        $id = str_val($body['id'] ?? '');
        $name = str_val($body['name'] ?? '');
        $email = str_val($body['email'] ?? '');
        $message = str_val($body['message'] ?? '');
        $date = str_val($body['date'] ?? '');
        if ($id === '' || $name === '' || $email === '' || $message === '') {
            send_json(400, ['ok' => false, 'error' => 'All fields are required']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            send_json(400, ['ok' => false, 'error' => 'Invalid email']);
        }
        $stmt = $mysqli->prepare('INSERT INTO contact_messages (id, name, email, message, date) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $id, $name, $email, $message, $date);
        if (!$stmt->execute()) {
            send_json(500, ['ok' => false, 'error' => 'Failed to save message', 'info' => $stmt->error]);
        }
        $stmt->close();
        send_json(201, ['ok' => true, 'message' => 'Message sent']);
    }
    if ($method === 'GET') {
        $current = require_auth();
        require_admin($current);
        $messages = [];
        $query = $mysqli->query('SELECT id, name, email, message, date FROM contact_messages ORDER BY id DESC');
        if ($query) {
            while ($row = $query->fetch_assoc()) {
                $messages[] = $row;
            }
        }
        send_json(200, ['ok' => true, 'messages' => $messages]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'analytics') {
    $postId = str_val($_GET['postId'] ?? $body['postId'] ?? '');
    if ($postId === '') {
        send_json(400, ['ok' => false, 'error' => 'Missing postId']);
    }
    if ($method === 'GET') {
        $stmt = $mysqli->prepare('SELECT views FROM analytics WHERE postId = ? LIMIT 1');
        $stmt->bind_param('s', $postId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        send_json(200, ['ok' => true, 'postId' => $postId, 'views' => (int)($row['views'] ?? 0)]);
    }
    if ($method === 'POST') {
        $stmt = $mysqli->prepare('INSERT INTO analytics (postId, views) VALUES (?, 1) ON DUPLICATE KEY UPDATE views = views + 1');
        $stmt->bind_param('s', $postId);
        if (!$stmt->execute()) {
            send_json(500, ['ok' => false, 'error' => 'Failed to track analytics', 'info' => $stmt->error]);
        }
        $stmt->close();
        send_json(200, ['ok' => true, 'postId' => $postId]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'drafts') {
    $current = require_auth();
    $email = strtolower(str_val($_GET['email'] ?? ''));
    if ($email === '') {
        send_json(400, ['ok' => false, 'error' => 'Missing email']);
    }
    if (strtolower((string)$current['email']) !== $email) {
        send_json(403, ['ok' => false, 'error' => "Cannot access another user's draft"]);
    }
    if ($method === 'GET') {
        $stmt = $mysqli->prepare('SELECT userEmail, title, category, content, coverImage, videoUrl, youtubeUrl, status, lastUpdated FROM drafts WHERE userEmail = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $draft = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        send_json(200, ['ok' => true, 'draft' => $draft ?: null]);
    }
    if ($method === 'PUT') {
        $title = str_val($body['title'] ?? '');
        $category = str_val($body['category'] ?? '');
        $content = str_val($body['content'] ?? '');
        $coverImage = str_val($body['coverImage'] ?? '');
        $videoUrl = str_val($body['videoUrl'] ?? '');
        $youtubeUrl = str_val($body['youtubeUrl'] ?? '');
        $status = str_val($body['status'] ?? 'draft');
        $lastUpdated = str_val($body['lastUpdated'] ?? '');
        $stmt = $mysqli->prepare('INSERT INTO drafts (userEmail, title, category, content, coverImage, videoUrl, youtubeUrl, status, lastUpdated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), category=VALUES(category), content=VALUES(content), coverImage=VALUES(coverImage), videoUrl=VALUES(videoUrl), youtubeUrl=VALUES(youtubeUrl), status=VALUES(status), lastUpdated=VALUES(lastUpdated)');
        $stmt->bind_param('sssssssss', $email, $title, $category, $content, $coverImage, $videoUrl, $youtubeUrl, $status, $lastUpdated);
        if (!$stmt->execute()) {
            send_json(500, ['ok' => false, 'error' => 'Failed to save draft', 'info' => $stmt->error]);
        }
        $stmt->close();
        send_json(200, ['ok' => true]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'followers') {
    if ($method === 'GET') {
        $email = strtolower(str_val($_GET['email'] ?? ''));
        if ($email === '') {
            send_json(400, ['ok' => false, 'error' => 'Missing author email']);
        }
        $followers = [];
        $stmt = $mysqli->prepare('SELECT followerEmail FROM followers WHERE authorEmail = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $followers[] = (string)$row['followerEmail'];
        }
        $stmt->close();
        send_json(200, ['ok' => true, 'followers' => $followers]);
    }
    if ($method === 'POST') {
        $current = require_auth();
        $authorEmail = strtolower(str_val($body['authorEmail'] ?? ''));
        $followerEmail = strtolower(str_val($body['followerEmail'] ?? $current['email']));
        if ($authorEmail === '' || $followerEmail === '') {
            send_json(400, ['ok' => false, 'error' => 'authorEmail and followerEmail are required']);
        }
        if (strtolower((string)$current['email']) !== $followerEmail) {
            send_json(403, ['ok' => false, 'error' => 'Cannot follow as another user']);
        }
        if ($authorEmail === $followerEmail) {
            send_json(400, ['ok' => false, 'error' => 'You cannot follow yourself']);
        }
        $check = $mysqli->prepare('SELECT 1 FROM followers WHERE authorEmail = ? AND followerEmail = ? LIMIT 1');
        $check->bind_param('ss', $authorEmail, $followerEmail);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();
        if ($exists) {
            $stmt = $mysqli->prepare('DELETE FROM followers WHERE authorEmail = ? AND followerEmail = ?');
            $stmt->bind_param('ss', $authorEmail, $followerEmail);
            $stmt->execute();
            $stmt->close();
            send_json(200, ['ok' => true, 'following' => false]);
        }
        $stmt = $mysqli->prepare('INSERT INTO followers (authorEmail, followerEmail) VALUES (?, ?)');
        $stmt->bind_param('ss', $authorEmail, $followerEmail);
        $stmt->execute();
        $stmt->close();
        send_json(200, ['ok' => true, 'following' => true]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if ($endpoint === 'user_kv') {
    $current = require_auth();
    $email = strtolower((string)$current['email']);
    $key = str_val($_GET['key'] ?? $body['key'] ?? '');
    if ($key === '') {
        send_json(400, ['ok' => false, 'error' => 'Missing key']);
    }
    if ($method === 'GET') {
        $stmt = $mysqli->prepare('SELECT kv_val FROM user_kv WHERE user_email = ? AND kv_key = ? LIMIT 1');
        $stmt->bind_param('ss', $email, $key);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $value = $row ? json_decode((string)$row['kv_val'], true) : null;
        send_json(200, ['ok' => true, 'key' => $key, 'value' => $value]);
    }
    if ($method === 'PUT') {
        $value = $body['value'] ?? null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $stmt = $mysqli->prepare('INSERT INTO user_kv (user_email, kv_key, kv_val) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE kv_val = VALUES(kv_val)');
        $stmt->bind_param('sss', $email, $key, $json);
        if (!$stmt->execute()) {
            send_json(500, ['ok' => false, 'error' => 'Failed to save user_kv', 'info' => $stmt->error]);
        }
        $stmt->close();
        send_json(200, ['ok' => true, 'key' => $key]);
    }
    if ($method === 'DELETE') {
        $stmt = $mysqli->prepare('DELETE FROM user_kv WHERE user_email = ? AND kv_key = ?');
        $stmt->bind_param('ss', $email, $key);
        $stmt->execute();
        $stmt->close();
        send_json(200, ['ok' => true, 'key' => $key]);
    }
    send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

send_json(404, ['ok' => false, 'error' => 'Unknown endpoint', 'endpoint' => $endpoint]);
