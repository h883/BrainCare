<?php
declare(strict_types=1);

// 利用者本人が、自分宛または全員宛のお知らせを確認するためのAPI。

require_once __DIR__ . '/auth.php';

$me = braincare_require_auth();
$pdo = braincare_db();

$stmt = $pdo->prepare(
    'SELECT m.id, m.body, m.created_at, u.name AS sender_name
     FROM messages m
     INNER JOIN users u ON u.id = m.created_by
     WHERE m.user_id = :uid OR m.user_id IS NULL
     ORDER BY m.created_at DESC
     LIMIT 20'
);
$stmt->execute(['uid' => $me['id']]);

braincare_json_response(['messages' => $stmt->fetchAll()]);
