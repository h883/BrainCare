<?php
declare(strict_types=1);

// 利用者本人が自分の学習履歴・対戦成績・ランキング順位を確認するためのAPI。
// 認証されたトークンのuser_idにのみ限定し、他人のデータは参照できない。

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stats.php';

$me = braincare_require_auth();
$pdo = braincare_db();

$action = $_GET['action'] ?? 'summary';

if ($action !== 'summary') {
    braincare_json_response(['error' => '不明なactionです'], 400);
}

braincare_json_response(braincare_user_summary($pdo, (int) $me['id']));
