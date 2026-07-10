<?php
declare(strict_types=1);

namespace BrainCare;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/auth.php';

/**
 * WebSocketメッセージのルーティングを担当する。
 * 進行ロジック自体はSessionManager（ソロ）/BattleManager（対戦）に委譲する。
 */
class BrainCareServer implements MessageComponentInterface
{
    public function __construct(
        private SessionManager $sessionManager,
        private BattleManager $battleManager,
        private ConnectionManager $cm
    ) {
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        // helloメッセージが届くまでは何もしない
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode((string) $msg, true);
        if (!is_array($data) || !isset($data['type'])) {
            $this->cm->send($from, ['type' => 'error', 'message' => '不正なメッセージ形式です']);
            return;
        }

        try {
            match ($data['type']) {
                'hello' => $this->handleHello($from, $data),
                'start_solo' => $this->handleStartSolo($from, $data),
                'host_create_room' => $this->handleHostCreateRoom($from, $data),
                'battle_room_join' => $this->handleBattleRoomJoin($from, $data),
                'answer' => $this->handleAnswer($from, $data),
                default => $this->cm->send($from, ['type' => 'error', 'message' => '不明なメッセージタイプです']),
            };
        } catch (\Throwable $e) {
            error_log('[BrainCareServer] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->cm->send($from, ['type' => 'error', 'message' => '処理中にエラーが発生しました']);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $meta = $this->cm->meta($conn);
        $this->battleManager->handleDisconnect($conn);
        if ($meta !== null) {
            $this->sessionManager->handleDisconnect($conn, $meta['screen_id']);
        }
        $this->cm->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    private function handleHello(ConnectionInterface $conn, array $data): void
    {
        $role = $data['role'] ?? '';
        $screenId = (string) ($data['screen_id'] ?? '1');

        if ($role === 'main') {
            $this->cm->attachMain($conn, $screenId);
            $this->cm->send($conn, ['type' => 'hello_ack', 'role' => 'main', 'screen_id' => $screenId]);
            return;
        }

        if ($role === 'konto') {
            $user = braincare_authenticate_token($data['token'] ?? null);
            if ($user === null) {
                $this->cm->send($conn, ['type' => 'error', 'message' => '認証に失敗しました。再度ログインしてください']);
                $conn->close();
                return;
            }
            $this->cm->attachKonto($conn, $user, $screenId);
            $this->cm->send($conn, [
                'type' => 'hello_ack',
                'role' => 'konto',
                'screen_id' => $screenId,
                'user' => ['id' => $user['id'], 'name' => $user['name']],
            ]);
            return;
        }

        $this->cm->send($conn, ['type' => 'error', 'message' => 'roleはmainかkontoを指定してください']);
    }

    private function requireKonto(ConnectionInterface $conn): ?array
    {
        $meta = $this->cm->meta($conn);
        if ($meta === null || $meta['role'] !== 'konto' || $meta['user'] === null) {
            $this->cm->send($conn, ['type' => 'error', 'message' => '先にログイン(hello)してください']);
            return null;
        }
        return $meta;
    }

    private function requireMain(ConnectionInterface $conn): ?array
    {
        $meta = $this->cm->meta($conn);
        if ($meta === null || $meta['role'] !== 'main') {
            $this->cm->send($conn, ['type' => 'error', 'message' => 'main画面からのみ実行できます']);
            return null;
        }
        return $meta;
    }

    private function handleStartSolo(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->requireKonto($conn);
        if ($meta === null) {
            return;
        }
        $gameType = (string) ($data['game_type'] ?? '');
        $this->sessionManager->start($conn, $meta['user'], $meta['screen_id'], $gameType);
    }

    private function handleHostCreateRoom(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->requireMain($conn);
        if ($meta === null) {
            return;
        }
        $gameType = (string) ($data['game_type'] ?? '');
        $rounds = (int) ($data['rounds'] ?? 5);
        $this->battleManager->hostCreateRoom($conn, $meta['screen_id'], $gameType, $rounds);
    }

    private function handleBattleRoomJoin(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->requireKonto($conn);
        if ($meta === null) {
            return;
        }
        $code = (string) ($data['code'] ?? '');
        $this->battleManager->joinRoom($conn, $meta['user'], $code);
    }

    private function handleAnswer(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->requireKonto($conn);
        if ($meta === null) {
            return;
        }
        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        if ($this->battleManager->isInBattle($conn)) {
            $this->battleManager->handleAnswer($conn, $payload);
        } else {
            $this->sessionManager->handleAnswer($meta['screen_id'], $payload);
        }
    }
}
