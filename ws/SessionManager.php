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

/** ソロプレイ（一人プレイ）の進行を screen_id 単位で管理する */
class SessionManager
{
    private const RESULT_PAUSE_MS = 1800;

    /** @var array<string, array> screen_id => session state */
    private array $sessions = [];

    public function __construct(
        private ConnectionManager $cm,
        private LoopInterface $loop,
        private PDO $pdo
    ) {
    }

    public function start(ConnectionInterface $kontoConn, array $user, string $screenId, string $gameType): void
    {
        $this->cancelTimer($screenId);

        try {
            $engine = GameFactory::create($gameType);
        } catch (\InvalidArgumentException $e) {
            $this->cm->send($kontoConn, ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        $this->sessions[$screenId] = [
            'engine' => $engine,
            'mode' => $engine instanceof CognitiveTestGame ? 'test' : 'solo',
            'user' => $user,
            'kontoConn' => $kontoConn,
            'score' => 0,
            'correct' => 0,
            'started_at' => time(),
            'timer' => null,
        ];

        $this->askNextQuestion($screenId);
    }

    public function handleAnswer(string $screenId, array $payload): void
    {
        $session = $this->sessions[$screenId] ?? null;
        if ($session === null) {
            return;
        }
        $this->resolveRound($screenId, $payload);
    }

    public function handleDisconnect(ConnectionInterface $conn, string $screenId): void
    {
        $session = $this->sessions[$screenId] ?? null;
        if ($session !== null && $session['kontoConn'] === $conn) {
            $this->cancelTimer($screenId);
            unset($this->sessions[$screenId]);
        }
    }

    private function askNextQuestion(string $screenId): void
    {
        $session = $this->sessions[$screenId] ?? null;
        if ($session === null) {
            return;
        }
        /** @var GameInterface $engine */
        $engine = $session['engine'];

        if ($engine->isFinished()) {
            $this->finish($screenId);
            return;
        }

        $q = $engine->nextQuestion();
        $base = ['type' => 'question', 'game_type' => $engine->type(), 'mode' => $session['mode'], 'time_limit_ms' => $engine->timeLimitMs()];

        $this->cm->sendMainScreen($screenId, $base + $q['main']);
        $this->cm->send($session['kontoConn'], $base + $q['konto']);

        $this->sessions[$screenId]['timer'] = $this->loop->addTimer($engine->timeLimitMs() / 1000, function () use ($screenId) {
            $this->resolveRound($screenId, []);
        });
    }

    private function resolveRound(string $screenId, array $payload): void
    {
        $session = $this->sessions[$screenId] ?? null;
        if ($session === null) {
            return;
        }
        $this->cancelTimer($screenId);

        /** @var GameInterface $engine */
        $engine = $session['engine'];
        $result = $engine->checkAnswer($payload);

        $this->sessions[$screenId]['score'] += $result['points'];
        if ($result['correct']) {
            $this->sessions[$screenId]['correct']++;
        }
        $session = $this->sessions[$screenId];

        $message = [
            'type' => 'result',
            'mode' => $session['mode'],
            'correct' => $result['correct'],
            'correct_answer' => $result['correctAnswer'],
            'score' => $session['score'],
            'round' => $engine->currentRound(),
            'total_rounds' => $engine->totalRounds(),
        ];
        $this->cm->sendMainScreen($screenId, $message);
        $this->cm->send($session['kontoConn'], $message);

        $this->sessions[$screenId]['timer'] = $this->loop->addTimer(self::RESULT_PAUSE_MS / 1000, function () use ($screenId) {
            $this->askNextQuestion($screenId);
        });
    }

    private function finish(string $screenId): void
    {
        $session = $this->sessions[$screenId] ?? null;
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
        $this->cm->sendMainScreen($screenId, $message);
        $this->cm->send($session['kontoConn'], $message);

        unset($this->sessions[$screenId]);
    }

    private function cancelTimer(string $screenId): void
    {
        $timer = $this->sessions[$screenId]['timer'] ?? null;
        if ($timer instanceof TimerInterface) {
            $this->loop->cancelTimer($timer);
        }
        if (isset($this->sessions[$screenId])) {
            $this->sessions[$screenId]['timer'] = null;
        }
    }
}
