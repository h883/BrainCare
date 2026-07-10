<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../php/db.php';

use BrainCare\BattleManager;
use BrainCare\BrainCareServer;
use BrainCare\ConnectionManager;
use BrainCare\SessionManager;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

$config = braincare_config();
$pdo = braincare_db();

$loop = Loop::get();
$cm = new ConnectionManager();
$sessionManager = new SessionManager($cm, $loop, $pdo);
$battleManager = new BattleManager($cm, $loop, $pdo);
$app = new BrainCareServer($sessionManager, $battleManager, $cm);

$host = $config['ws']['host'];
$port = $config['ws']['port'];

$socket = new SocketServer("{$host}:{$port}", [], $loop);
$server = new IoServer(new HttpServer(new WsServer($app)), $socket, $loop);

echo "BrainCare WebSocketサーバを起動しました: ws://{$host}:{$port}" . PHP_EOL;

$loop->run();
