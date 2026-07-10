<?php
declare(strict_types=1);

// main.html（TV表示・ログイン不要）向けの公開ランキング取得API。
// 氏名と得点/勝敗数のみを返す（パスワード等の機微情報は含まない）。

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = braincare_db();
$stmt = $pdo->query(
    'SELECT u.name, r.point, r.win, r.lose
     FROM ranking r
     INNER JOIN users u ON u.id = r.user_id
     ORDER BY r.point DESC, r.win DESC
     LIMIT 10'
);

echo json_encode(['ranking' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
