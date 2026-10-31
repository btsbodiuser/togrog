<?php
/*
 * Төгрөг sync API — plain PHP 8 + MySQL, no dependencies (runs on Hostinger shared hosting).
 *
 * The server never sees who the user is or what they spend: accounts are a self-chosen
 * username, and every record arrives already encrypted on the device (AES-GCM, key derived
 * from the password and never sent). "auth" below is a separate PBKDF2-derived secret, not the password.
 *
 * POST api/index.php?a=register|login|logout|sync   body: JSON   header X-Token for logout/sync
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) fail('Сервер тохируулагдаагүй байна (api/config.php)', 500);
$cfg = require $cfgFile;

$in = json_decode(file_get_contents('php://input') ?: '');
if (!is_object($in)) fail('Буруу хүсэлт');
$action = $_GET['a'] ?? '';

try {
    $db = new PDO(
        "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    fail('Өгөгдлийн сантай холбогдож чадсангүй', 503);
}

function migrate(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(64) NOT NULL UNIQUE,
        auth VARCHAR(255) NOT NULL,
        rev BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    $db->exec("CREATE TABLE IF NOT EXISTS tokens (
        hash CHAR(64) PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    // d = encrypted record (opaque base64 string), u = client edit time (ms) for last-write-wins,
    // rev = per-user change counter that clients use as a pull cursor
    $db->exec("CREATE TABLE IF NOT EXISTS records (
        user_id INT UNSIGNED NOT NULL,
        k VARCHAR(100) NOT NULL,
        d MEDIUMTEXT NULL,
        u BIGINT NOT NULL,
        del TINYINT(1) NOT NULL DEFAULT 0,
        rev BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, k),
        INDEX (user_id, rev),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin");
    $db->exec("CREATE TABLE IF NOT EXISTS attempts (
        ip VARCHAR(45) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (ip, kind, at)
    ) ENGINE=InnoDB");
}

function ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45); }
function throttle(PDO $db, string $kind, int $max, int $minutes): void {
    $q = $db->prepare("SELECT COUNT(*) FROM attempts WHERE ip=? AND kind=? AND at > NOW() - INTERVAL $minutes MINUTE");
    $q->execute([ip(), $kind]);
    if ((int)$q->fetchColumn() >= $max) fail('Хэт олон оролдлого. Хэсэг хугацааны дараа дахин оролдоно уу.', 429);
}
function attempt(PDO $db, string $kind): void {
    $db->prepare('INSERT INTO attempts (ip, kind) VALUES (?, ?)')->execute([ip(), $kind]);
}
function credentials(object $in): array {
    $name = mb_strtolower(trim((string)($in->name ?? '')));
    $auth = (string)($in->auth ?? '');
    if (!preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $name)) fail('Нэр 3–32 тэмдэгт, үсэг/тоо/._- байна');
    if (!preg_match('/^[A-Za-z0-9+\/=]{40,64}$/', $auth)) fail('Буруу хүсэлт');
    return [$name, $auth];
}
function issueToken(PDO $db, int $uid, string $name): never {
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO tokens (hash, user_id) VALUES (?, ?)')->execute([hash('sha256', $token), $uid]);
    $db->exec('DELETE FROM tokens WHERE last_used < NOW() - INTERVAL 180 DAY');
    $db->exec('DELETE FROM attempts WHERE at < NOW() - INTERVAL 1 DAY');
    out(['token' => $token, 'user' => ['id' => $uid, 'name' => $name]]);
}
function userFromToken(PDO $db): int {
    $token = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) fail('Нэвтрээгүй байна', 401);
    $h = hash('sha256', $token);
    $q = $db->prepare('SELECT user_id FROM tokens WHERE hash=?');
    $q->execute([$h]);
    $uid = $q->fetchColumn();
    if (!$uid) fail('Нэвтрэлтийн хугацаа дууссан', 401);
    $db->prepare('UPDATE tokens SET last_used=NOW() WHERE hash=? AND last_used < NOW() - INTERVAL 1 DAY')->execute([$h]);
    return (int)$uid;
}

try {
    switch ($action) {
        case 'register': {
            migrate($db);
            [$name, $auth] = credentials($in);
            throttle($db, 'register', 5, 60);
            attempt($db, 'register');
            try {
                $db->prepare('INSERT INTO users (name, auth) VALUES (?, ?)')->execute([$name, password_hash($auth, PASSWORD_DEFAULT)]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') fail('Энэ нэр бүртгэлтэй байна. Өөр нэр сонгоно уу.', 409);
                throw $e;
            }
            issueToken($db, (int)$db->lastInsertId(), $name);
        }
        case 'login': {
            migrate($db);
            [$name, $auth] = credentials($in);
            throttle($db, 'login', 10, 15);
            $q = $db->prepare('SELECT id, auth FROM users WHERE name=?');
            $q->execute([$name]);
            $u = $q->fetch();
            if (!$u || !password_verify($auth, $u['auth'])) {
                attempt($db, 'login');
                fail('Нэр эсвэл нууц үг буруу байна', 401);
            }
            issueToken($db, (int)$u['id'], $name);
        }
        case 'logout': {
            $token = $_SERVER['HTTP_X_TOKEN'] ?? '';
            $db->prepare('DELETE FROM tokens WHERE hash=?')->execute([hash('sha256', $token)]);
            out(['ok' => true]);
        }
        case 'sync': {
            $uid = userFromToken($db);
            $cursor = max(0, (int)($in->cursor ?? 0));
            $changes = is_array($in->changes ?? null) ? array_slice($in->changes, 0, 500) : [];
            $maxU = (int)(microtime(true) * 1000) + 60000; // a phone with a clock set ahead can't win every conflict forever
            $rejected = [];

            if ($changes) {
                $db->beginTransaction();
                $q = $db->prepare('SELECT rev FROM users WHERE id=? FOR UPDATE');
                $q->execute([$uid]);
                $rev = (int)$q->fetchColumn();
                $get = $db->prepare('SELECT u FROM records WHERE user_id=? AND k=?');
                $put = $db->prepare('INSERT INTO records (user_id, k, d, u, del, rev) VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE d=?, u=?, del=?, rev=?');
                foreach ($changes as $c) {
                    if (!is_object($c)) continue;
                    $k = (string)($c->k ?? '');
                    if (!preg_match('#^(acc|cat|tx|rec|goal|bud|meta)/[A-Za-z0-9_\-]{1,80}$#', $k)) continue;
                    $u = min((int)($c->u ?? 0), $maxU);
                    $del = !empty($c->del) ? 1 : 0;
                    $d = $del ? null : ($c->d ?? null);
                    if (!$del && (!is_string($d) || strlen($d) > 200000)) continue;
                    $get->execute([$uid, $k]);
                    $cur = $get->fetchColumn();
                    if ($cur !== false && (int)$cur > $u) { $rejected[] = $k; continue; } // server copy is newer, client will receive it below
                    $rev++;
                    $put->execute([$uid, $k, $d, $u, $del, $rev, $d, $u, $del, $rev]);
                }
                $db->prepare('UPDATE users SET rev=? WHERE id=?')->execute([$rev, $uid]);
                $db->commit();
            }

            $limit = 500;
            $q = $db->prepare("SELECT k, d, u, del, rev FROM records WHERE user_id=? AND rev>? ORDER BY rev LIMIT " . ($limit + 1));
            $q->execute([$uid, $cursor]);
            $rows = $q->fetchAll();
            $more = count($rows) > $limit;
            if ($more) array_pop($rows);
            $seen = [];
            $records = [];
            foreach ($rows as $r) {
                $seen[$r['k']] = true;
                $records[] = ['k' => $r['k'], 'd' => $r['d'], 'u' => (int)$r['u'], 'del' => (int)$r['del']];
                $cursor = (int)$r['rev'];
            }
            $rejected = array_values(array_filter($rejected, fn($k) => !isset($seen[$k])));
            if ($rejected) {
                $in_ = implode(',', array_fill(0, count($rejected), '?'));
                $q = $db->prepare("SELECT k, d, u, del FROM records WHERE user_id=? AND k IN ($in_)");
                $q->execute([$uid, ...$rejected]);
                foreach ($q->fetchAll() as $r) $records[] = ['k' => $r['k'], 'd' => $r['d'], 'u' => (int)$r['u'], 'del' => (int)$r['del']];
            }
            out(['cursor' => $cursor, 'more' => $more, 'records' => $records]);
        }
        default:
            fail('Unknown action', 404);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('togrog api: ' . $e->getMessage());
    fail('Серверийн алдаа', 500);
}
