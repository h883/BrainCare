<?php
declare(strict_types=1);

namespace BrainCare;

use Ratchet\ConnectionInterface;

/**
 * WebSocket接続のメタデータ（role/screen_id/user）を管理する。
 * 「screen」は施設内のTV(main.html)単位の識別子で、同じscreen_idに紐づく
 * konto.html接続（1台=ソロプレイ、2台=対戦）がそのTVに表示される。
 */
class ConnectionManager
{
    /** @var array<int, array{role:string, screen_id:string, user:?array, conn:ConnectionInterface}> */
    private array $meta = [];

    /** @var array<string, array<int, ConnectionInterface>> screen_id => [resourceId => conn] */
    private array $mainByScreen = [];

    public function attachMain(ConnectionInterface $conn, string $screenId): void
    {
        $this->meta[$conn->resourceId] = [
            'role' => 'main',
            'screen_id' => $screenId,
            'user' => null,
            'conn' => $conn,
        ];
        $this->mainByScreen[$screenId][$conn->resourceId] = $conn;
    }

    public function attachKonto(ConnectionInterface $conn, array $user, string $screenId): void
    {
        $this->meta[$conn->resourceId] = [
            'role' => 'konto',
            'screen_id' => $screenId,
            'user' => $user,
            'conn' => $conn,
        ];
    }

    public function setScreen(ConnectionInterface $conn, string $screenId): void
    {
        if (!isset($this->meta[$conn->resourceId])) {
            return;
        }
        $old = $this->meta[$conn->resourceId]['screen_id'];
        if ($this->meta[$conn->resourceId]['role'] === 'main') {
            unset($this->mainByScreen[$old][$conn->resourceId]);
            $this->mainByScreen[$screenId][$conn->resourceId] = $conn;
        }
        $this->meta[$conn->resourceId]['screen_id'] = $screenId;
    }

    public function detach(ConnectionInterface $conn): ?array
    {
        $info = $this->meta[$conn->resourceId] ?? null;
        if ($info === null) {
            return null;
        }
        if ($info['role'] === 'main') {
            unset($this->mainByScreen[$info['screen_id']][$conn->resourceId]);
        }
        unset($this->meta[$conn->resourceId]);
        return $info;
    }

    public function meta(ConnectionInterface $conn): ?array
    {
        return $this->meta[$conn->resourceId] ?? null;
    }

    public function send(ConnectionInterface $conn, array $data): void
    {
        $conn->send(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function sendMainScreen(string $screenId, array $data): void
    {
        foreach ($this->mainByScreen[$screenId] ?? [] as $conn) {
            $this->send($conn, $data);
        }
    }
}
