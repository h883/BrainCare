-- BrainCare データベーススキーマ
-- 文字コード: utf8mb4

CREATE DATABASE IF NOT EXISTS braincare
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE braincare;

-- 利用者
-- passwordはNULL許容: role='user'（利用者本人）は名前選択のみでログインしパスワードを持たない。
-- role='admin'（介護スタッフ）のみパスワードログインが必須。
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    password VARCHAR(255) NULL,
    birthday DATE NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WebSocket認証用トークン（PHP-FPMとRatchet常駐プロセス間でセッションを共有できないため）
CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token (token),
    KEY idx_user_id (user_id),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 学習履歴（ソロプレイ・認知機能テスト・対戦）
-- source: 'solo'=通常のソロプレイ, 'test'=認知機能テストの1分野分, 'battle'=対戦(みんなで遊ぶ)。
-- 「今日の目標」（3ゲーム遊ぶ／テストを受ける）の判定に使う。
-- total_rounds: そのプレイの出題数。対戦は出題数を可変にできるため、
-- 正答率は AVG(correct) ではなく SUM(correct)/SUM(total_rounds) で計算する。
CREATE TABLE IF NOT EXISTS learning_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    game_type VARCHAR(50) NOT NULL,
    source ENUM('solo', 'test', 'battle') NOT NULL DEFAULT 'solo',
    score INT NOT NULL DEFAULT 0,
    correct INT NOT NULL DEFAULT 0,
    total_rounds INT NOT NULL DEFAULT 5,
    play_time INT NOT NULL DEFAULT 0 COMMENT '秒数',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id),
    KEY idx_game_type (game_type),
    CONSTRAINT fk_learning_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 対戦履歴
CREATE TABLE IF NOT EXISTS battle_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    player1 INT UNSIGNED NOT NULL,
    player2 INT UNSIGNED NOT NULL,
    winner INT UNSIGNED NULL,
    score1 INT NOT NULL DEFAULT 0,
    score2 INT NOT NULL DEFAULT 0,
    game_type VARCHAR(50) NOT NULL DEFAULT 'calc',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_player1 (player1),
    KEY idx_player2 (player2),
    CONSTRAINT fk_battle_history_player1 FOREIGN KEY (player1) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_battle_history_player2 FOREIGN KEY (player2) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ランキング（1利用者1レコード、対戦のたびに加減算する）
CREATE TABLE IF NOT EXISTS ranking (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    point INT NOT NULL DEFAULT 0,
    win INT NOT NULL DEFAULT 0,
    lose INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_id (user_id),
    CONSTRAINT fk_ranking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 介護スタッフから利用者へのメッセージ・お知らせ
-- user_id が NULL の場合は全利用者への一斉送信メッセージ。
CREATE TABLE IF NOT EXISTS messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    body VARCHAR(500) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id),
    CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初期管理者アカウント (name: admin / password: admin1234 ※導入後は必ず変更すること)
INSERT INTO users (name, password, birthday, role)
VALUES ('admin', '$2b$10$eA/OMsXqNH.17KaPZgtjbOv5UegrafPORyBD0v/iB2wuWUH7m0fji', NULL, 'admin')
ON DUPLICATE KEY UPDATE name = name;
