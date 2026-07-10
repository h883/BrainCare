<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stats.php';

$currentAdmin = braincare_require_admin();

$pdo = braincare_db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'users':
        admin_action_list_users($pdo);
        break;
    case 'create_user':
        admin_action_create_user($pdo);
        break;
    case 'delete_user':
        admin_action_delete_user($pdo, $currentAdmin);
        break;
    case 'update_user':
        admin_action_update_user($pdo);
        break;
    case 'history':
        admin_action_history($pdo);
        break;
    case 'battles':
        admin_action_battles($pdo);
        break;
    case 'ranking':
        admin_action_ranking($pdo);
        break;
    case 'stats':
        admin_action_stats($pdo);
        break;
    case 'user_summary':
        admin_action_user_summary($pdo);
        break;
    case 'send_message':
        admin_action_send_message($pdo, $currentAdmin);
        break;
    case 'sent_messages':
        admin_action_sent_messages($pdo);
        break;
    case 'delete_message':
        admin_action_delete_message($pdo);
        break;
    case 'export_history_csv':
        admin_action_export_history_csv($pdo);
        break;
    case 'import_users':
        admin_action_import_users($pdo);
        break;
    default:
        braincare_json_response(['error' => '不明なactionです'], 400);
}

function admin_action_send_message(PDO $pdo, array $currentAdmin): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        braincare_json_response(['error' => 'POSTメソッドのみ許可されています'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $userId = $body['user_id'] ?? null;
    $text = trim((string) ($body['body'] ?? ''));

    if ($text === '' || mb_strlen($text) > 500) {
        braincare_json_response(['error' => 'メッセージは1〜500文字で入力してください'], 400);
    }

    $targetUserId = null;
    if ($userId !== null && $userId !== '') {
        $targetUserId = (int) $userId;
        $check = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $check->execute(['id' => $targetUserId]);
        if ($check->fetch() === false) {
            braincare_json_response(['error' => '宛先の利用者が見つかりません'], 404);
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO messages (user_id, body, created_by) VALUES (:user_id, :body, :created_by)'
    );
    $stmt->execute([
        'user_id' => $targetUserId,
        'body' => $text,
        'created_by' => $currentAdmin['id'],
    ]);

    braincare_json_response(['id' => (int) $pdo->lastInsertId()], 201);
}

function admin_action_sent_messages(PDO $pdo): never
{
    $stmt = $pdo->query(
        'SELECT m.id, m.body, m.created_at, m.user_id, u.name AS target_name, s.name AS sender_name
         FROM messages m
         LEFT JOIN users u ON u.id = m.user_id
         INNER JOIN users s ON s.id = m.created_by
         ORDER BY m.created_at DESC
         LIMIT 100'
    );
    braincare_json_response(['messages' => $stmt->fetchAll()]);
}

function admin_action_delete_message(PDO $pdo): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        braincare_json_response(['error' => 'POSTメソッドのみ許可されています'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $messageId = (int) ($body['id'] ?? 0);
    if ($messageId <= 0) {
        braincare_json_response(['error' => 'idが必要です'], 400);
    }

    $stmt = $pdo->prepare('SELECT id FROM messages WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $messageId]);
    if ($stmt->fetch() === false) {
        braincare_json_response(['error' => 'メッセージが見つかりません'], 404);
    }

    $del = $pdo->prepare('DELETE FROM messages WHERE id = :id');
    $del->execute(['id' => $messageId]);

    braincare_json_response(['deleted' => true]);
}

function admin_action_import_users(PDO $pdo): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        braincare_json_response(['error' => 'POSTメソッドのみ許可されています'], 405);
    }
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        braincare_json_response(['error' => 'CSVファイルを選択してください'], 400);
    }

    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    if ($handle === false) {
        braincare_json_response(['error' => 'ファイルを読み込めませんでした'], 400);
    }

    // ExcelのUTF-8 BOM対策（先頭3バイトを確認し、BOMでなければ読み込み位置を戻す）
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $insert = $pdo->prepare('INSERT INTO users (name, password, birthday, role) VALUES (:name, NULL, :birthday, \'user\')');
    $dupCheck = $pdo->prepare('SELECT id FROM users WHERE name = :name LIMIT 1');

    $created = [];
    $skipped = [];
    $seenNames = [];
    $rowNum = 0;

    while (($cols = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count($cols) === 1 && trim((string) $cols[0]) === '') {
            continue; // 空行はスキップ
        }

        $name = trim((string) ($cols[0] ?? ''));
        $birthday = trim((string) ($cols[1] ?? ''));
        $roleRaw = trim((string) ($cols[2] ?? ''));

        if ($rowNum === 1 && $name === '名前') {
            continue; // ヘッダー行はスキップ
        }

        if ($name === '' || mb_strlen($name) > 100) {
            $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => '名前が未入力、または100文字を超えています'];
            continue;
        }

        if (in_array($roleRaw, ['admin', '管理者'], true)) {
            $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => '管理者アカウントは一括登録できません（個別に登録してください）'];
            continue;
        }

        $birthdayValue = null;
        if ($birthday !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $birthday);
            if (!$d || $d->format('Y-m-d') !== $birthday) {
                $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => '生年月日はYYYY-MM-DD形式で入力してください'];
                continue;
            }
            $birthdayValue = $birthday;
        }

        if (isset($seenNames[$name])) {
            $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'CSV内で名前が重複しています'];
            continue;
        }

        $dupCheck->execute(['name' => $name]);
        if ($dupCheck->fetch() !== false) {
            $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'その名前は既に登録されています'];
            continue;
        }

        $insert->execute(['name' => $name, 'birthday' => $birthdayValue]);
        $seenNames[$name] = true;
        $created[] = ['row' => $rowNum, 'name' => $name];
    }
    fclose($handle);

    braincare_json_response(['created' => $created, 'skipped' => $skipped]);
}

function admin_action_export_history_csv(PDO $pdo): never
{
    $userId = $_GET['user_id'] ?? null;
    $sql = "SELECT h.created_at, u.name AS user_name, h.game_type, h.source, h.score, h.correct, h.total_rounds, h.play_time
            FROM learning_history h
            INNER JOIN users u ON u.id = h.user_id";
    $params = [];
    if ($userId !== null && $userId !== '') {
        $sql .= ' WHERE h.user_id = :user_id';
        $params['user_id'] = (int) $userId;
    }
    $sql .= ' ORDER BY h.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learning_history.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // ExcelでのUTF-8文字化け対策のBOM
    fputcsv($out, ['日時', '利用者名', 'ゲーム種別', '種別', 'スコア', '正解数', '出題数', 'プレイ時間(秒)']);
    $sourceLabels = ['solo' => 'ソロ', 'test' => '認知機能テスト', 'battle' => '対戦'];
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'],
            $row['user_name'],
            $row['game_type'],
            $sourceLabels[$row['source']] ?? $row['source'],
            $row['score'],
            $row['correct'],
            $row['total_rounds'],
            $row['play_time'],
        ]);
    }
    fclose($out);
    exit;
}

function admin_action_delete_user(PDO $pdo, array $currentAdmin): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        braincare_json_response(['error' => 'POSTメソッドのみ許可されています'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $userId = (int) ($body['user_id'] ?? 0);
    if ($userId <= 0) {
        braincare_json_response(['error' => 'user_idが必要です'], 400);
    }
    if ($userId === (int) $currentAdmin['id']) {
        braincare_json_response(['error' => '自分自身のアカウントは削除できません'], 400);
    }

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $target = $stmt->fetch();
    if ($target === false) {
        braincare_json_response(['error' => '利用者が見つかりません'], 404);
    }

    if ($target['role'] === 'admin') {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch()['c'];
        if ($adminCount <= 1) {
            braincare_json_response(['error' => '最後の管理者アカウントは削除できません'], 400);
        }
    }

    // 学習履歴・対戦履歴・ランキング・認証トークンはON DELETE CASCADEで連鎖削除される
    $del = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $del->execute(['id' => $userId]);

    braincare_json_response(['deleted' => true]);
}

function admin_action_update_user(PDO $pdo): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        braincare_json_response(['error' => 'POSTメソッドのみ許可されています'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $userId = (int) ($body['user_id'] ?? 0);
    if ($userId <= 0) {
        braincare_json_response(['error' => 'user_idが必要です'], 400);
    }

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $target = $stmt->fetch();
    if ($target === false) {
        braincare_json_response(['error' => '利用者が見つかりません'], 404);
    }

    $name = trim((string) ($body['name'] ?? ''));
    $birthday = trim((string) ($body['birthday'] ?? ''));
    $role = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $password = (string) ($body['password'] ?? '');

    if ($name === '' || mb_strlen($name) > 100) {
        braincare_json_response(['error' => '名前を正しく入力してください'], 400);
    }

    $dup = $pdo->prepare('SELECT id FROM users WHERE name = :name AND id != :id LIMIT 1');
    $dup->execute(['name' => $name, 'id' => $userId]);
    if ($dup->fetch() !== false) {
        braincare_json_response(['error' => 'その名前は既に使用されています'], 409);
    }

    $birthdayValue = null;
    if ($birthday !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $birthday);
        if (!$d || $d->format('Y-m-d') !== $birthday) {
            braincare_json_response(['error' => '生年月日はYYYY-MM-DD形式で入力してください'], 400);
        }
        $birthdayValue = $birthday;
    }

    // 最後の管理者を利用者へ降格させない
    if ($target['role'] === 'admin' && $role !== 'admin') {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch()['c'];
        if ($adminCount <= 1) {
            braincare_json_response(['error' => '最後の管理者アカウントの権限は変更できません'], 400);
        }
    }

    $setParts = ['name = :name', 'birthday = :birthday', 'role = :role'];
    $params = ['id' => $userId, 'name' => $name, 'birthday' => $birthdayValue, 'role' => $role];

    if ($role === 'admin') {
        if ($password !== '') {
            if (mb_strlen($password) < 4) {
                braincare_json_response(['error' => 'パスワードは4文字以上にしてください'], 400);
            }
            $setParts[] = 'password = :password';
            $params['password'] = password_hash($password, PASSWORD_DEFAULT);
        } elseif ($target['role'] !== 'admin') {
            braincare_json_response(['error' => '管理者にする場合はパスワードを設定してください'], 400);
        }
        // 既に管理者でパスワード未入力の場合は既存のパスワードを維持する
    } else {
        // 利用者化する場合はパスワードを不要にする(NULLへ)
        $setParts[] = 'password = :password';
        $params['password'] = null;
    }

    $sql = 'UPDATE users SET ' . implode(', ', $setParts) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    braincare_json_response(['updated' => true]);
}

function admin_action_user_summary(PDO $pdo): never
{
    $userId = (int) ($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        braincare_json_response(['error' => 'user_idが必要です'], 400);
    }

    $stmt = $pdo->prepare('SELECT id, name, birthday, role, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if ($user === false) {
        braincare_json_response(['error' => '利用者が見つかりません'], 404);
    }

    braincare_json_response(['user' => $user] + braincare_user_summary($pdo, $userId));
}

function admin_action_list_users(PDO $pdo): never
{
    $stmt = $pdo->query(
        'SELECT id, name, birthday, role, created_at FROM users ORDER BY created_at DESC'
    );
    braincare_json_response(['users' => $stmt->fetchAll()]);
}

function admin_action_create_user(PDO $pdo): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        braincare_json_response(['error' => 'POSTメソッドのみ許可されています'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $name = trim((string) ($body['name'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $birthday = trim((string) ($body['birthday'] ?? ''));
    $role = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

    if ($name === '' || mb_strlen($name) > 100) {
        braincare_json_response(['error' => '名前を正しく入力してください'], 400);
    }
    // 利用者(role=user)は名前選択のみでログインするためパスワード不要。管理者は必須。
    $passwordHash = null;
    if ($role === 'admin') {
        if (mb_strlen($password) < 4) {
            braincare_json_response(['error' => '管理者はパスワードを4文字以上で設定してください'], 400);
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    }
    $birthdayValue = null;
    if ($birthday !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $birthday);
        if (!$d || $d->format('Y-m-d') !== $birthday) {
            braincare_json_response(['error' => '生年月日はYYYY-MM-DD形式で入力してください'], 400);
        }
        $birthdayValue = $birthday;
    }

    $dup = $pdo->prepare('SELECT id FROM users WHERE name = :name LIMIT 1');
    $dup->execute(['name' => $name]);
    if ($dup->fetch() !== false) {
        braincare_json_response(['error' => 'その名前は既に使用されています'], 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, password, birthday, role) VALUES (:name, :password, :birthday, :role)'
    );
    $stmt->execute([
        'name' => $name,
        'password' => $passwordHash,
        'birthday' => $birthdayValue,
        'role' => $role,
    ]);

    braincare_json_response(['id' => (int) $pdo->lastInsertId()], 201);
}

function admin_action_history(PDO $pdo): never
{
    $userId = $_GET['user_id'] ?? null;
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));

    $sql = 'SELECT h.id, h.user_id, u.name AS user_name, h.game_type, h.source, h.score, h.correct, h.total_rounds, h.play_time, h.created_at
            FROM learning_history h
            INNER JOIN users u ON u.id = h.user_id';
    $params = [];
    if ($userId !== null && $userId !== '') {
        $sql .= ' WHERE h.user_id = :user_id';
        $params['user_id'] = (int) $userId;
    }
    $sql .= ' ORDER BY h.created_at DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    braincare_json_response(['history' => $stmt->fetchAll()]);
}

function admin_action_battles(PDO $pdo): never
{
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
    $sql = 'SELECT b.id, b.player1, p1.name AS player1_name, b.player2, p2.name AS player2_name,
                   b.winner, w.name AS winner_name, b.score1, b.score2, b.game_type, b.created_at
            FROM battle_history b
            INNER JOIN users p1 ON p1.id = b.player1
            INNER JOIN users p2 ON p2.id = b.player2
            LEFT JOIN users w ON w.id = b.winner
            ORDER BY b.created_at DESC LIMIT ' . $limit;
    $stmt = $pdo->query($sql);
    braincare_json_response(['battles' => $stmt->fetchAll()]);
}

function admin_action_ranking(PDO $pdo): never
{
    $stmt = $pdo->query(
        'SELECT r.user_id, u.name, r.point, r.win, r.lose
         FROM ranking r
         INNER JOIN users u ON u.id = r.user_id
         ORDER BY r.point DESC, r.win DESC
         LIMIT 100'
    );
    braincare_json_response(['ranking' => $stmt->fetchAll()]);
}

function admin_action_stats(PDO $pdo): never
{
    $totalUsers = (int) $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    $totalPlays = (int) $pdo->query('SELECT COUNT(*) AS c FROM learning_history')->fetch()['c'];
    $totalBattles = (int) $pdo->query('SELECT COUNT(*) AS c FROM battle_history')->fetch()['c'];

    $byGameType = $pdo->query(
        'SELECT game_type, COUNT(*) AS plays, AVG(score) AS avg_score, AVG(correct) AS avg_correct
         FROM learning_history GROUP BY game_type'
    )->fetchAll();

    $byDay = $pdo->query(
        'SELECT DATE(created_at) AS day, COUNT(*) AS plays
         FROM learning_history
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(created_at)
         ORDER BY day ASC'
    )->fetchAll();

    braincare_json_response([
        'total_users' => $totalUsers,
        'total_plays' => $totalPlays,
        'total_battles' => $totalBattles,
        'by_game_type' => $byGameType,
        'by_day' => $byDay,
    ]);
}
