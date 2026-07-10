<?php
declare(strict_types=1);

// konto.html のログイン画面（名前選択）向け公開API。
// パスワードなしログイン対象となる利用者(role=user)の氏名一覧のみを返す。

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = braincare_db();
$stmt = $pdo->query(
    "SELECT id, name FROM users WHERE role = 'user' ORDER BY name ASC"
);

echo json_encode(['users' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
