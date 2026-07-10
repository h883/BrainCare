<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    braincare_json_response(['error' => 'POSTメソッドのみ許可されています'], 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

$pdo = braincare_db();
$userId = $body['user_id'] ?? null;

if ($userId !== null) {
    // 利用者本人による名前選択ログイン（パスワードなし）。role=userのみ許可する。
    $stmt = $pdo->prepare('SELECT id, name, role, birthday FROM users WHERE id = :id AND role = :role LIMIT 1');
    $stmt->execute(['id' => (int) $userId, 'role' => 'user']);
    $user = $stmt->fetch();

    if ($user === false) {
        braincare_json_response(['error' => '利用者が見つかりません'], 404);
    }

    respond_with_token($user);
}

// 名前+パスワードによるログイン（介護スタッフ/管理者向け）
$name = trim((string) ($body['name'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($name === '' || $password === '') {
    braincare_json_response(['error' => '名前とパスワードを入力してください'], 400);
}

$stmt = $pdo->prepare('SELECT id, name, password, role, birthday FROM users WHERE name = :name LIMIT 1');
$stmt->execute(['name' => $name]);
$user = $stmt->fetch();

if ($user === false || $user['password'] === null || !password_verify($password, $user['password'])) {
    // ユーザー有無を区別しないメッセージでユーザー列挙を防ぐ
    braincare_json_response(['error' => '名前またはパスワードが正しくありません'], 401);
}

respond_with_token($user);

function respond_with_token(array $user): never
{
    $token = braincare_issue_token((int) $user['id']);

    braincare_json_response([
        'token' => $token,
        'user' => [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'birthday' => $user['birthday'],
        ],
    ]);
}
