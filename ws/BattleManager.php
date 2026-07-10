<?php
declare(strict_types=1);

namespace BrainCare;

use BrainCare\Games\GameFactory;
use BrainCare\Games\GameInterface;
use PDO;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * 対戦（TVが選んだゲームに、スマホが参加コードを入力して参加する方式）の進行を管理する。
 * 参加コードはTV(main.html)側がゲーム種別を選んで発行し、スマホ2台がそのコードを入力して
 * 参加することで対戦が開始する（1台のTVを2人の利用者が共同で見る運用を想定）。
 */
class BattleManager
{
    private const RESULT_PAUSE_MS = 2200;

    /** @var array<string, array> code => ['screenId','gameType','mainConn','players'=>[0..1件]] */
    private array $rooms = [];

    /** @var array<string, array> battleId => battle state */
    private array $battles = [];

    /** @var array<int, string> conn->resourceId => battleId （対戦中の接続の逆引き） */
    private array $connToBattle = [];

    private int $nextBattleId = 1;

    public function __construct(
        private ConnectionManager $cm,
        private LoopInterface $loop,
        private PDO $pdo
    ) {
    }

    private const MIN_ROUNDS = 3;
    private const MAX_ROUNDS = 30;
    private const DEFAULT_ROUNDS = 5;

    /** TV(main)がゲーム種別・出題数を選んで参加コードを発行する */
    public function hostCreateRoom(ConnectionInterface $mainConn, string $screenId, string $gameType, int $rounds = self::DEFAULT_ROUNDS): void
    {
        if (!in_array($gameType, \BrainCare\Games\GameFactory::types(), true)) {
            $this->cm->send($mainConn, ['type' => 'error', 'message' => '不明なgame_typeです']);
            return;
        }
        $rounds = max(self::MIN_ROUNDS, min(self::MAX_ROUNDS, $rounds));

        do {
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (isset($this->rooms[$code]));

        $this->rooms[$code] = [
            'screenId' => $screenId,
            'gameType' => $gameType,
            'rounds' => $rounds,
            'mainConn' => $mainConn,
            'players' => [],
        ];
        $this->cm->send($mainConn, ['type' => 'room_created', 'code' => $code, 'game_type' => $gameType, 'rounds' => $rounds]);
    }

    /** スマホが参加コードを入力して参加する */
    public function joinRoom(ConnectionInterface $conn, array $user, string $code): void
    {
        $room = $this->rooms[$code] ?? null;
        if ($room === null) {
            $this->cm->send($conn, ['type' => 'error', 'message' => 'その参加コードは見つかりません']);
            return;
        }
        foreach ($room['players'] as $p) {
            if ($p['user']['id'] === $user['id']) {
                $this->cm->send($conn, ['type' => 'error', 'message' => 'すでに参加しています']);
                return;
            }
        }

        $room['players'][] = ['conn' => $conn, 'user' => $user];
        $this->rooms[$code] = $room;

        if (count($room['players']) < 2) {
            $this->cm->send($conn, ['type' => 'battle_waiting', 'mode' => 'code']);
            $this->cm->send($room['mainConn'], ['type' => 'host_player_joined', 'name' => $user['name']]);
            return;
        }

        unset($this->rooms[$code]);
        $this->startBattle($room['players'][0], $room['players'][1], $room['gameType'], $room['screenId'], $room['rounds']);
    }

    public function isInBattle(ConnectionInterface $conn): bool
    {
        return isset($this->connToBattle[$conn->resourceId]);
    }

    public function handleAnswer(ConnectionInterface $conn, array $payload): void
    {
        $battleId = $this->connToBattle[$conn->resourceId] ?? null;
        if ($battleId === null || !isset($this->battles[$battleId])) {
            return;
        }
        $battle = $this->battles[$battleId];
        $index = $battle['players'][0]['conn'] === $conn ? 0 : 1;
        $this->recordAnswer($battleId, $index, $payload);
    }

    public function handleDisconnect(ConnectionInterface $conn): void
    {
        // 参加コード待ち状態からの離脱
        foreach ($this->rooms as $code => $room) {
            if ($room['mainConn'] === $conn) {
                // TV側が切断した場合はコードごと無効化する
                foreach ($room['players'] as $p) {
                    $this->cm->send($p['conn'], ['type' => 'error', 'message' => 'テレビとの接続が切れたため対戦を中止しました']);
                }
                unset($this->rooms[$code]);
                continue;
            }
            $remaining = array_values(array_filter($room['players'], fn ($p) => $p['conn'] !== $conn));
            if (count($remaining) !== count($room['players'])) {
                $room['players'] = $remaining;
                $this->rooms[$code] = $room;
            }
        }

        // 対戦中の切断
        $battleId = $this->connToBattle[$conn->resourceId] ?? null;
        if ($battleId === null || !isset($this->battles[$battleId])) {
            return;
        }
        $battle = $this->battles[$battleId];
        $remainingIndex = $battle['players'][0]['conn'] === $conn ? 1 : 0;
        $this->endBattle($battleId, 'disconnected', $remainingIndex);
    }

    private function startBattle(array $p1, array $p2, string $gameType, string $screenId, int $rounds = self::DEFAULT_ROUNDS): void
    {
        $battleId = (string) $this->nextBattleId++;
        $engine = GameFactory::create($gameType);
        $engine->setTotalRounds($rounds);

        $this->battles[$battleId] = [
            'engine' => $engine,
            'gameType' => $gameType,
            'screenId' => $screenId,
            'startedAt' => time(),
            'players' => [
                ['conn' => $p1['conn'], 'user' => $p1['user'], 'score' => 0, 'correct' => 0, 'answered' => false, 'result' => null],
                ['conn' => $p2['conn'], 'user' => $p2['user'], 'score' => 0, 'correct' => 0, 'answered' => false, 'result' => null],
            ],
            'timer' => null,
        ];
        $this->connToBattle[$p1['conn']->resourceId] = $battleId;
        $this->connToBattle[$p2['conn']->resourceId] = $battleId;

        // 参加した2台のスマホをともにTVのscreen_idへ紐付ける（1台のTVで2人分の進行を表示するため）
        $this->cm->setScreen($p1['conn'], $screenId);
        $this->cm->setScreen($p2['conn'], $screenId);

        $matchedMsg = fn (array $me, array $opp) => [
            'type' => 'battle_matched',
            'opponent_name' => $opp['user']['name'],
            'game_type' => $gameType,
        ];
        $this->cm->send($p1['conn'], $matchedMsg($p1, $p2));
        $this->cm->send($p2['conn'], $matchedMsg($p2, $p1));

        $this->askNextQuestion($battleId);
    }

    private function askNextQuestion(string $battleId): void
    {
        $battle = $this->battles[$battleId] ?? null;
        if ($battle === null) {
            return;
        }
        /** @var GameInterface $engine */
        $engine = $battle['engine'];

        if ($engine->isFinished()) {
            $this->endBattle($battleId, 'finished');
            return;
        }

        $q = $engine->nextQuestion();
        $this->battles[$battleId]['players'][0]['answered'] = false;
        $this->battles[$battleId]['players'][0]['result'] = null;
        $this->battles[$battleId]['players'][1]['answered'] = false;
        $this->battles[$battleId]['players'][1]['result'] = null;
        $this->battles[$battleId]['revealed'] = false;

        $base = ['type' => 'question', 'game_type' => $engine->type(), 'mode' => 'battle', 'time_limit_ms' => $engine->timeLimitMs()];
        $this->cm->sendMainScreen($battle['screenId'], $base + $q['main'] + [
            'scores' => $this->scoreSummary($battleId),
        ]);
        foreach ($battle['players'] as $p) {
            $this->cm->send($p['conn'], $base + $q['konto']);
        }

        $this->battles[$battleId]['timer'] = $this->loop->addTimer($engine->timeLimitMs() / 1000, function () use ($battleId) {
            $this->forceRevealOnTimeout($battleId);
        });
    }

    private function recordAnswer(string $battleId, int $index, array $payload): void
    {
        $battle = $this->battles[$battleId];
        if ($battle['players'][$index]['answered']) {
            return;
        }
        /** @var GameInterface $engine */
        $engine = $battle['engine'];
        $result = $engine->checkAnswer($payload);

        $this->battles[$battleId]['players'][$index]['answered'] = true;
        $this->battles[$battleId]['players'][$index]['result'] = $result;
        $this->battles[$battleId]['players'][$index]['score'] += $result['points'];
        if ($result['correct']) {
            $this->battles[$battleId]['players'][$index]['correct']++;
        }

        $both = $this->battles[$battleId]['players'][0]['answered'] && $this->battles[$battleId]['players'][1]['answered'];
        if ($both) {
            $this->cancelTimer($battleId);
            $this->revealRound($battleId);
        }
    }

    private function forceRevealOnTimeout(string $battleId): void
    {
        $battle = $this->battles[$battleId] ?? null;
        if ($battle === null) {
            return;
        }
        foreach ([0, 1] as $index) {
            if (!$battle['players'][$index]['answered']) {
                $this->recordAnswer($battleId, $index, []);
            }
        }
        // recordAnswer側でその場でreveal済みの場合はrevealRound内のガードで二重実行を防ぐ
        $this->revealRound($battleId);
    }

    private function revealRound(string $battleId): void
    {
        $battle = $this->battles[$battleId] ?? null;
        if ($battle === null || ($battle['revealed'] ?? false)) {
            return;
        }
        $this->battles[$battleId]['revealed'] = true;
        /** @var GameInterface $engine */
        $engine = $battle['engine'];

        $message = [
            'type' => 'result',
            'mode' => 'battle',
            'round' => $engine->currentRound(),
            'total_rounds' => $engine->totalRounds(),
            'correct_answer' => $battle['players'][0]['result']['correctAnswer'] ?? null,
            'scores' => $this->scoreSummary($battleId),
        ];
        $this->cm->sendMainScreen($battle['screenId'], $message);
        foreach ($battle['players'] as $p) {
            $this->cm->send($p['conn'], $message + ['correct' => $p['result']['correct'] ?? false]);
        }

        $this->battles[$battleId]['timer'] = $this->loop->addTimer(self::RESULT_PAUSE_MS / 1000, function () use ($battleId) {
            $this->askNextQuestion($battleId);
        });
    }

    private function scoreSummary(string $battleId): array
    {
        $battle = $this->battles[$battleId];
        return [
            ['name' => $battle['players'][0]['user']['name'], 'score' => $battle['players'][0]['score']],
            ['name' => $battle['players'][1]['user']['name'], 'score' => $battle['players'][1]['score']],
        ];
    }

    private function endBattle(string $battleId, string $reason, ?int $forcedWinnerIndex = null): void
    {
        $battle = $this->battles[$battleId] ?? null;
        if ($battle === null) {
            return;
        }
        $this->cancelTimer($battleId);

        $p1 = $battle['players'][0];
        $p2 = $battle['players'][1];

        if ($forcedWinnerIndex !== null) {
            $winnerIndex = $forcedWinnerIndex;
        } elseif ($p1['score'] === $p2['score']) {
            $winnerIndex = null;
        } else {
            $winnerIndex = $p1['score'] > $p2['score'] ? 0 : 1;
        }
        $winnerUserId = $winnerIndex === null ? null : $battle['players'][$winnerIndex]['user']['id'];

        $stmt = $this->pdo->prepare(
            'INSERT INTO battle_history (player1, player2, winner, score1, score2, game_type)
             VALUES (:p1, :p2, :winner, :s1, :s2, :game_type)'
        );
        $stmt->execute([
            'p1' => $p1['user']['id'],
            'p2' => $p2['user']['id'],
            'winner' => $winnerUserId,
            's1' => $p1['score'],
            's2' => $p2['score'],
            'game_type' => $battle['gameType'],
        ]);

        $this->applyRanking($p1['user']['id'], $winnerIndex === null ? null : ($winnerIndex === 0));
        $this->applyRanking($p2['user']['id'], $winnerIndex === null ? null : ($winnerIndex === 1));

        // 対戦の結果も本人の学習記録（苦手分野グラフ・プレイ履歴）に反映する
        /** @var GameInterface $engine */
        $engine = $battle['engine'];
        $playTime = max(1, time() - $battle['startedAt']);
        $historyStmt = $this->pdo->prepare(
            'INSERT INTO learning_history (user_id, game_type, source, score, correct, total_rounds, play_time) VALUES (:user_id, :game_type, :source, :score, :correct, :total_rounds, :play_time)'
        );
        foreach ([$p1, $p2] as $p) {
            $historyStmt->execute([
                'user_id' => $p['user']['id'],
                'game_type' => $battle['gameType'],
                'source' => 'battle',
                'score' => $p['score'],
                'correct' => $p['correct'],
                'total_rounds' => $engine->totalRounds(),
                'play_time' => $playTime,
            ]);
        }

        $message = [
            'type' => 'game_over',
            'mode' => 'battle',
            'reason' => $reason,
            'scores' => $this->scoreSummary($battleId),
            'winner_name' => $winnerUserId !== null ? $battle['players'][$winnerIndex]['user']['name'] : null,
        ];
        $this->cm->sendMainScreen($battle['screenId'], $message);
        foreach ($battle['players'] as $p) {
            $this->cm->send($p['conn'], $message);
        }

        unset($this->connToBattle[$p1['conn']->resourceId], $this->connToBattle[$p2['conn']->resourceId]);
        unset($this->battles[$battleId]);
    }

    private function applyRanking(int $userId, ?bool $won): void
    {
        if ($won === null) {
            $point = 8; $win = 0; $lose = 0;
        } elseif ($won) {
            $point = 15; $win = 1; $lose = 0;
        } else {
            $point = 5; $win = 0; $lose = 1;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ranking (user_id, point, win, lose) VALUES (:user_id, :point, :win, :lose)
             ON DUPLICATE KEY UPDATE point = point + VALUES(point), win = win + VALUES(win), lose = lose + VALUES(lose)'
        );
        $stmt->execute(['user_id' => $userId, 'point' => $point, 'win' => $win, 'lose' => $lose]);
    }

    private function cancelTimer(string $battleId): void
    {
        $timer = $this->battles[$battleId]['timer'] ?? null;
        if ($timer instanceof TimerInterface) {
            $this->loop->cancelTimer($timer);
        }
        if (isset($this->battles[$battleId])) {
            $this->battles[$battleId]['timer'] = null;
        }
    }
}
