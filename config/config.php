<?php
// BrainCare 共通設定
// 実運用時は環境変数(getenv)からの読み込みに切り替えることを推奨する

return [
    'db' => [
        'host' => getenv('BRAINCARE_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('BRAINCARE_DB_PORT') ?: '3306',
        'name' => getenv('BRAINCARE_DB_NAME') ?: 'braincare',
        'user' => getenv('BRAINCARE_DB_USER') ?: 'braincare',
        'pass' => getenv('BRAINCARE_DB_PASS') ?: 'braincare',
        'charset' => 'utf8mb4',
    ],
    'ws' => [
        'host' => getenv('BRAINCARE_WS_HOST') ?: '0.0.0.0',
        'port' => (int) (getenv('BRAINCARE_WS_PORT') ?: 8080),
        // ブラウザ側がWSに接続する際のURL（Nginxでリバースプロキシする場合はwss://facility-host/ws等に変更）
        'public_url' => getenv('BRAINCARE_WS_PUBLIC_URL') ?: 'ws://localhost:8080',
    ],
    'auth' => [
        'token_ttl_seconds' => 60 * 60 * 12, // 12時間
    ],
];
