<?php
declare(strict_types=1);

namespace BrainCare;

use BrainCare\Games\CognitiveTestGame;
use BrainCare\Games\GameFactory;
use BrainCare\Games\GameInterface;
use PDO;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * ソロプレイ・認知機能テストの進行をkonto接続単位で管理する。
 * テレビは「みんなで遊ぶ」対戦専用のため、ここでは一切ブロードキャストしない
 * （スマートフォンの画面だけで完結する）。
 */
class SessionManager
{
    private const RESULT_PAUSE_MS = 1800;

    /** @var array<int, array> conn->resourceId => session state */
    private array $sessions = [];

    public function __construct(
        private ConnectionManager $cm,
        private LoopInterface $loop,
        private PDO $pdo
    ) {
    }

    public function start(ConnectionInterface $kontoConn, array $user, string $gameType): void
    {
        $connId = $kontoConn->resourceId;
        $this->cancelTimer($connId);

        try {
            $engine = GameFactory::create($gameType);
        } catch (\InvalidArgumentException $e) {
            $this->cm->send($kontoConn, ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        $this->sessions[$connId] = [
            'engine' => $engine,
            'mode' => $engine instanceof CognitiveTestGame ? 'test' : 'solo',
            'user' => $user,
            'kontoConn' => $kontoConn,
            'score' => 0,
            'correct' => 0,
            'started_at' => time(),
            'timer' => null,
        ];

        $this->askNextQuestion($connId);
    }

    public function handleAnswer(ConnectionInterface $conn, array $payload): void
    {
        $connId = $conn->resourceId;
        if (!isset($this->sessions[$connId])) {
            return;
        }
        $this->resolveRound($connId, $payload);
    }

    public function handleDisconnect(ConnectionInterface $conn): void
    {
        $connId = $conn->resourceId;
        if (isset($this->sessions[$connId])) {
            $this->cancelTimer($connId);
            unset($this->sessions[$connId]);
        }
    }

    private function askNextQuestion(int $connId): void
    {
        $session = $this->sessions[$connId] ?? null;
        if ($session === null) {
            return;
        }
        /** @var GameInterface $engine */
        $engine = $session['engine'];

        if ($engine->isFinished()) {
            $this->finish($connId);
            return;
        }

        $q = $engine->nextQuestion();
        $base = ['type' => 'question', 'game_type' => $engine->type(), 'mode' => $session['mode'], 'time_limit_ms' => $engine->timeLimitMs()];

        $this->cm->send($session['kontoConn'], $base + $q['konto']);

        $this->sessions[$connId]['timer'] = $this->loop->addTimer($engine->timeLimitMs() / 1000, function () use ($connId) {
            $this->resolveRound($connId, []);
        });
    }

    private function resolveRound(int $connId, array $payload): void
    {
        $session = $this->sessions[$connId] ?? null;
        if ($session === null) {
            return;
        }
        $this->cancelTimer($connId);

        /** @var GameInterface $engine */
        $engine = $session['engine'];
        $result = $engine->checkAnswer($payload);

        $this->sessions[$connId]['score'] += $result['points'];
        if ($result['correct']) {
            $this->sessions[$connId]['correct']++;
        }
        $session = $this->sessions[$connId];

        $message = [
            'type' => 'result',
            'mode' => $session['mode'],
            'correct' => $result['correct'],
            'correct_answer' => $result['correctAnswer'],
            'score' => $session['score'],
            'round' => $engine->currentRound(),
            'total_rounds' => $engine->totalRounds(),
        ];
        $this->cm->send($session['kontoConn'], $message);

        $this->sessions[$connId]['timer'] = $this->loop->addTimer(self::RESULT_PAUSE_MS / 1000, function () use ($connId) {
            $this->askNextQuestion($connId);
        });
    }

    private function finish(int $connId): void
    {
        $session = $this->sessions[$connId] ?? null;
        if ($session === null) {
            return;
        }
        /** @var GameInterface $engine */
        $engine = $session['engine'];
        $playTime = max(1, time() - $session['started_at']);

        if ($engine instanceof CognitiveTestGame) {
            // 分野ごとに1件ずつ保存する（既存の「苦手分野グラフ」にそのまま反映されるようにするため）
            $domains = $engine->domainResults();
            $perDomainPlayTime = max(1, intdiv($playTime, max(1, count($domains))));
            $stmt = $this->pdo->prepare(
                'INSERT INTO learning_history (user_id, game_type, source, score, correct, total_rounds, play_time) VALUES (:user_id, :game_type, :source, :score, :correct, :total_rounds, :play_time)'
            );
            foreach ($domains as $domain) {
                $stmt->execute([
                    'user_id' => $session['user']['id'],
                    'game_type' => $domain['game_type'],
                    'source' => 'test',
                    'score' => $domain['score'],
                    'correct' => $domain['correct'],
                    'total_rounds' => $domain['total_rounds'],
                    'play_time' => $perDomainPlayTime,
                ]);
            }
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO learning_history (user_id, game_type, source, score, correct, total_rounds, play_time) VALUES (:user_id, :game_type, :source, :score, :correct, :total_rounds, :play_time)'
            );
            $stmt->execute([
                'user_id' => $session['user']['id'],
                'game_type' => $engine->type(),
                'source' => 'solo',
                'score' => $session['score'],
                'correct' => $session['correct'],
                'total_rounds' => $engine->totalRounds(),
                'play_time' => $playTime,
            ]);
        }

        $message = [
            'type' => 'game_over',
            'mode' => $session['mode'],
            'score' => $session['score'],
            'correct' => $session['correct'],
            'total_rounds' => $engine->totalRounds(),
        ];
        $this->cm->send($session['kontoConn'], $message);

        unset($this->sessions[$connId]);
    }

    private function cancelTimer(int $connId): void
    {
        $timer = $this->sessions[$connId]['timer'] ?? null;
        if ($timer instanceof TimerInterface) {
            $this->loop->cancelTimer($timer);
        }
        if (isset($this->sessions[$connId])) {
            $this->sessions[$connId]['timer'] = null;
        }
    }
}
