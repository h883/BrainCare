<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function braincare_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function braincare_generate_token(): string
{
    return bin2hex(random_bytes(32)); // 64文字
}

/**
 * ログイン成功時にトークンを発行してDBへ保存する。
 * WebSocketデーモン(Ratchet)とPHP-FPMはプロセスが別なため$_SESSIONを共有できず、
 * トークンをhelloメッセージ/Authorizationヘッダで受け渡して認証する。
 */
function braincare_issue_token(int $userId): string
{
    $pdo = braincare_db();
    $ttl = braincare_config()['auth']['token_ttl_seconds'];
    $token = braincare_generate_token();

    $stmt = $pdo->prepare(
        'INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
    );
    $stmt->execute([
        'user_id' => $userId,
        'token' => $token,
        'ttl' => $ttl,
    ]);

    return $token;
}

/**
 * トークンを検証しユーザー情報を返す。無効・期限切れの場合はnull。
 */
function braincare_authenticate_token(?string $token): ?array
{
    if ($token === null || $token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $pdo = braincare_db();
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.role, u.birthday
         FROM auth_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token = :token AND t.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function braincare_bearer_token_from_request(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? '';
    }
    if (preg_match('/Bearer\s+([a-f0-9]{64})/i', $header, $m)) {
        return $m[1];
    }
    // フォールバック: クエリ/POSTパラメータ
    return $_REQUEST['token'] ?? null;
}

/**
 * 認証必須エンドポイントの先頭で呼ぶ。未認証なら401 JSONで即終了する。
 */
function braincare_require_auth(): array
{
    $user = braincare_authenticate_token(braincare_bearer_token_from_request());
    if ($user === null) {
        braincare_json_response(['error' => '認証が必要です'], 401);
    }
    return $user;
}

/**
 * 管理者専用エンドポイントの先頭で呼ぶ。管理者以外は403 JSONで即終了する。
 * クライアント自己申告のroleは信用せず、都度DBの最新roleを確認する。
 */
function braincare_require_admin(): array
{
    $user = braincare_require_auth();
    if ($user['role'] !== 'admin') {
        braincare_json_response(['error' => '管理者権限が必要です'], 403);
    }
    return $user;
}
