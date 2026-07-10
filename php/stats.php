<?php
declare(strict_types=1);

// 利用者1人分の学習履歴・対戦成績・ランキング順位・バッジ・今日の目標を集計する共通処理。
// php/me.php（本人向け）とphp/admin.php（管理者による閲覧）の両方から使う。

function braincare_user_summary(PDO $pdo, int $userId): array
{
    $totals = $pdo->prepare(
        'SELECT COUNT(*) AS plays, COALESCE(AVG(score), 0) AS avg_score,
                COALESCE(AVG(correct), 0) AS avg_correct, COALESCE(MAX(score), 0) AS best_score,
                COALESCE(SUM(correct), 0) AS total_correct
         FROM learning_history WHERE user_id = :uid'
    );
    $totals->execute(['uid' => $userId]);
    $totalsRow = $totals->fetch();

    // 対戦(みんなで遊ぶ)は出題数を可変にできるため、正答率は単純平均ではなく
    // SUM(correct)/SUM(total_rounds)の加重平均で計算する。
    // 正答率が低いゲーム種別ほど「苦手分野」として画面に表示する。
    $byGameType = $pdo->prepare(
        'SELECT game_type, COUNT(*) AS plays, AVG(score) AS avg_score, AVG(correct) AS avg_correct,
                SUM(correct) / SUM(total_rounds) * 100 AS accuracy_percent
         FROM learning_history WHERE user_id = :uid GROUP BY game_type'
    );
    $byGameType->execute(['uid' => $userId]);
    $byGameTypeRows = array_map(function (array $row): array {
        $row['avg_score'] = round((float) $row['avg_score'], 1);
        $row['avg_correct'] = round((float) $row['avg_correct'], 1);
        $row['accuracy_percent'] = round((float) $row['accuracy_percent'], 1);
        $row['plays'] = (int) $row['plays'];
        return $row;
    }, $byGameType->fetchAll());
    usort($byGameTypeRows, fn ($a, $b) => $a['accuracy_percent'] <=> $b['accuracy_percent']);

    $history = $pdo->prepare(
        'SELECT game_type, source, score, correct, total_rounds, play_time, created_at
         FROM learning_history WHERE user_id = :uid
         ORDER BY created_at DESC LIMIT 20'
    );
    $history->execute(['uid' => $userId]);

    $ranking = $pdo->prepare('SELECT point, win, lose FROM ranking WHERE user_id = :uid LIMIT 1');
    $ranking->execute(['uid' => $userId]);
    $rankingRow = $ranking->fetch();
    $hasRanking = $rankingRow !== false;
    $rankingRow = $hasRanking ? $rankingRow : ['point' => 0, 'win' => 0, 'lose' => 0];

    $rankPosition = null;
    if ($hasRanking) {
        $posStmt = $pdo->prepare(
            'SELECT COUNT(*) + 1 AS pos FROM ranking WHERE point > (SELECT point FROM ranking WHERE user_id = :uid)'
        );
        $posStmt->execute(['uid' => $userId]);
        $rankPosition = (int) $posStmt->fetch()['pos'];
    }

    $totalRanked = (int) $pdo->query('SELECT COUNT(*) AS c FROM ranking')->fetch()['c'];

    $rankingSummary = [
        'point' => (int) $rankingRow['point'],
        'win' => (int) $rankingRow['win'],
        'lose' => (int) $rankingRow['lose'],
        'position' => $rankPosition,
        'total_ranked' => $totalRanked,
    ];

    $playDates = braincare_play_dates($pdo, $userId);
    $streakDays = braincare_calc_streak($playDates);

    return [
        'totals' => [
            'plays' => (int) $totalsRow['plays'],
            'avg_score' => round((float) $totalsRow['avg_score'], 1),
            'avg_correct' => round((float) $totalsRow['avg_correct'], 1),
            'best_score' => (int) $totalsRow['best_score'],
            'total_correct' => (int) $totalsRow['total_correct'],
        ],
        'by_game_type' => $byGameTypeRows,
        'history' => $history->fetchAll(),
        'ranking' => $rankingSummary,
        'streak_days' => $streakDays,
        'play_dates' => $playDates,
        'badges' => braincare_user_badges((int) $totalsRow['plays'], (int) $totalsRow['total_correct'], $rankingSummary['win'], $streakDays),
        'daily_goals' => braincare_daily_goals($pdo, $userId),
    ];
}

/** 学習履歴のあった日付(Y-m-d)を新しい順に返す。カレンダー表示と連続日数の計算に使う。 */
function braincare_play_dates(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT DATE(created_at) AS d FROM learning_history WHERE user_id = :uid ORDER BY d DESC LIMIT 400'
    );
    $stmt->execute(['uid' => $userId]);
    return array_column($stmt->fetchAll(), 'd');
}

/**
 * 学習履歴のあった日を新しい順にたどり、今日または昨日から始まる連続プレイ日数を数える。
 * （今日まだプレイしていなくても、昨日までの連続記録は維持されているとみなす）
 */
function braincare_calc_streak(array $dates): int
{
    if (empty($dates)) {
        return 0;
    }

    $today = new DateTimeImmutable('today');
    $yesterday = $today->modify('-1 day');
    $mostRecent = new DateTimeImmutable($dates[0]);

    if ($mostRecent != $today && $mostRecent != $yesterday) {
        return 0;
    }

    $cursor = $mostRecent;
    $streak = 0;
    foreach ($dates as $dateStr) {
        if ($dateStr === $cursor->format('Y-m-d')) {
            $streak++;
            $cursor = $cursor->modify('-1 day');
        } else {
            break;
        }
    }
    return $streak;
}

/** @return array<int, array{id: string, emoji: string, label: string, earned: bool}> */
function braincare_user_badges(int $totalPlays, int $totalCorrect, int $battleWins, int $streakDays): array
{
    return [
        ['id' => 'first_play', 'emoji' => '🥇', 'label' => '初プレイ', 'earned' => $totalPlays >= 1],
        ['id' => '100_correct', 'emoji' => '🥈', 'label' => '100問正解', 'earned' => $totalCorrect >= 100],
        ['id' => '7day_streak', 'emoji' => '🏆', 'label' => '7日連続プレイ', 'earned' => $streakDays >= 7],
        ['id' => '10_wins', 'emoji' => '🎉', 'label' => '対戦10勝', 'earned' => $battleWins >= 10],
    ];
}

/** その日のうちに達成すべき3つの目標の達成状況を返す */
function braincare_daily_goals(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT game_type, source FROM learning_history WHERE user_id = :uid AND DATE(created_at) = CURDATE()'
    );
    $stmt->execute(['uid' => $userId]);
    $todayRows = $stmt->fetchAll();

    $soloPlaysToday = count(array_filter($todayRows, fn ($r) => $r['source'] === 'solo'));
    $calcPlayedToday = count(array_filter($todayRows, fn ($r) => $r['game_type'] === 'calc')) > 0;
    $testTakenToday = count(array_filter($todayRows, fn ($r) => $r['source'] === 'test')) > 0;

    $goals = [
        ['id' => 'play_3_games', 'label' => '3ゲーム遊ぶ', 'done' => $soloPlaysToday >= 3],
        ['id' => 'play_calc', 'label' => '計算問題を1回行う', 'done' => $calcPlayedToday],
        ['id' => 'take_test', 'label' => '認知機能テストを受ける', 'done' => $testTakenToday],
    ];
    $doneCount = count(array_filter($goals, fn ($g) => $g['done']));

    return [
        'goals' => $goals,
        'achieved_count' => $doneCount,
        'total' => count($goals),
        'percent' => (int) round($doneCount / count($goals) * 100),
    ];
}
