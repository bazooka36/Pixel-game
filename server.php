<?php
$host = '0.0.0.0';
$port = 8080;

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socket, $host, $port);
socket_listen($socket);

$clients = [];
$players = [];
$bullets = [];
$items = [];
$explosions = [];

$walls = [
    ['x' => 300, 'y' => 200, 'w' => 60, 'h' => 300],
    ['x' => 900, 'y' => 200, 'w' => 60, 'h' => 300],
    ['x' => 550, 'y' => 320, 'w' => 160, 'h' => 60]
];

echo "2D Shooter WebSocket Сервер запущен на port $port...\n";

$lastItemSpawn = microtime(true);

while (true) {
    $read = array_merge([$socket], array_column($clients, 'socket'));
    $write = null; $except = null;

    if (socket_select($read, $write, $except, 0, 16666) > 0) {
        if (in_array($socket, $read)) {
            $newSocket = socket_accept($socket);
            $header = socket_read($newSocket, 1024);
            performHandshake($header, $newSocket, $host, $port);

            $id = uniqid();
            $clients[$id] = ['id' => $id, 'socket' => $newSocket];
            
            $players[$id] = [
                'id' => $id,
                'x' => rand(100, 400),
                'y' => rand(100, 400),
                'angle' => 0,
                'hp' => 100,
                'shield' => 0,
                'kills' => 0,
                'deaths' => 0,
                'color' => sprintf('#%06X', mt_rand(0x444444, 0xFFFFFF)),
                'lastShoot' => 0
            ];

            sendToClient($newSocket, ['type' => 'init', 'id' => $id, 'walls' => $walls]);

            $key = array_search($socket, $read);
            unset($read[$key]);
        }

        foreach ($clients as $id => $client) {
            if (in_array($client['socket'], $read)) {
                $data = socket_read($client['socket'], 2048);
                if ($data === false || strlen($data) === 0) {
                    unset($clients[$id]);
                    unset($players[$id]);
                    socket_close($client['socket']);
                    continue;
                }

                $msg = decode($data);
                if ($msg) {
                    $json = json_decode($msg, true);
                    if ($json && isset($players[$id])) {
                        handlePlayerInput($id, $json);
                    }
                }
            }
        }
    }

    // Спавн бонусов (строго вне стен)
    if (microtime(true) - $lastItemSpawn > 4.0 && count($items) < 8) {
        $lastItemSpawn = microtime(true);
        $types = ['heal', 'shield', 'bomb'];
        
        // Подбираем координаты, которые не пересекаются со стенами
        $attempts = 0;
        do {
            $ix = rand(50, 1200);
            $iy = rand(50, 650);
            $attempts++;
        } while (checkWallCollision($ix, $iy, 20, $walls) && $attempts < 50);

        if ($attempts < 50) {
            $items[] = [
                'id' => uniqid(),
                'type' => $types[array_rand($types)],
                'x' => $ix,
                'y' => $iy
            ];
        }
    }

    updateGameState();

    $stateMsg = encode(json_encode([
        'type' => 'state',
        'players' => array_values($players),
        'bullets' => $bullets,
        'items' => array_values($items),
        'explosions' => $explosions
    ]));

    $explosions = [];

    foreach ($clients as $client) {
        @socket_write($client['socket'], $stateMsg, strlen($stateMsg));
    }

    usleep(10000);
}

function sendToClient($socket, $data) {
    $msg = encode(json_encode($data));
    @socket_write($socket, $msg, strlen($msg));
}

function handlePlayerInput($id, $data) {
    global $players, $bullets, $walls;
    $p = &$players[$id];

    if ($data['type'] === 'input') {
        $speed = 5;
        $dx = 0; $dy = 0;

        if (!empty($data['keys']['w'])) $dy -= 1;
        if (!empty($data['keys']['s'])) $dy += 1;
        if (!empty($data['keys']['a'])) $dx -= 1;
        if (!empty($data['keys']['d'])) $dx += 1;

        // Поворот по направлению ходьбы (если идем)
        if ($dx !== 0 || $dy !== 0) {
            $p['angle'] = atan2($dy, $dx);

            $newX = $p['x'] + $dx * $speed;
            $newY = $p['y'] + $dy * $speed;

            $newX = max(20, min(1260, $newX));
            $newY = max(20, min(700, $newY));

            if (!checkWallCollision($newX, $newY, 18, $walls)) {
                $p['x'] = $newX;
                $p['y'] = $newY;
            }
        }

        // Стрельба ТОЛЬКО при нажатии ЛКМ (shooting === true)
        if (!empty($data['shooting'])) {
            $now = microtime(true);
            if ($now - $p['lastShoot'] >= 0.18) {
                $p['lastShoot'] = $now;

                // АВТОНАВОДКА: Ищем ближайшего живого врага
                $closestEnemy = null;
                $minDist = 999999;

                foreach ($players as $otherId => $target) {
                    if ($otherId !== $id && $target['hp'] > 0) {
                        $d = sqrt(($target['x'] - $p['x'])**2 + ($target['y'] - $p['y'])**2);
                        if ($d < $minDist) {
                            $minDist = $d;
                            $closestEnemy = $target;
                        }
                    }
                }

                // Если есть враг — наводимся на него, иначе стреляем в сторону взгляда
                $shootAngle = $p['angle'];
                if ($closestEnemy !== null) {
                    $shootAngle = atan2($closestEnemy['y'] - $p['y'], $closestEnemy['x'] - $p['x']);
                    $p['angle'] = $shootAngle; // Дополнительно поворачиваем игрока
                }

                $bulletSpeed = 14;
                $bullets[] = [
                    'id' => uniqid(),
                    'owner' => $id,
                    'x' => $p['x'] + cos($shootAngle) * 20,
                    'y' => $p['y'] + sin($shootAngle) * 20,
                    'vx' => cos($shootAngle) * $bulletSpeed,
                    'vy' => sin($shootAngle) * $bulletSpeed,
                    'dist' => 0
                ];
            }
        }
    }
}

function updateGameState() {
    global $bullets, $players, $walls, $items, $explosions;

    // 1. Подбор вещей
    foreach ($items as $iIndex => $item) {
        foreach ($players as $pId => &$p) {
            $dist = sqrt(($item['x'] - $p['x'])**2 + ($item['y'] - $p['y'])**2);
            if ($dist < 25) {
                if ($item['type'] === 'heal') {
                    $p['hp'] = min(100, $p['hp'] + 50);
                } elseif ($item['type'] === 'shield') {
                    $p['shield'] = min(100, $p['shield'] + 50);
                } elseif ($item['type'] === 'bomb') {
                    $explosions[] = ['x' => $p['x'], 'y' => $p['y'], 'r' => 120];
                    foreach ($players as $otherId => &$target) {
                        if ($otherId !== $pId) {
                            $eDist = sqrt(($target['x'] - $p['x'])**2 + ($target['y'] - $p['y'])**2);
                            if ($eDist < 120) {
                                applyDamage($target, 60, $p, $players);
                            }
                        }
                    }
                }
                unset($items[$iIndex]);
                break;
            }
        }
    }

    // 2. Пули
    foreach ($bullets as $bIndex => &$b) {
        $b['x'] += $b['vx'];
        $b['y'] += $b['vy'];
        $b['dist'] += sqrt($b['vx']*$b['vx'] + $b['vy']*$b['vy']);

        if ($b['dist'] > 1200 || $b['x'] < 0 || $b['x'] > 1280 || $b['y'] < 0 || $b['y'] > 720) {
            unset($bullets[$bIndex]);
            continue;
        }

        if (checkWallCollision($b['x'], $b['y'], 2, $walls)) {
            unset($bullets[$bIndex]);
            continue;
        }

        foreach ($players as $pId => &$p) {
            if ($pId !== $b['owner'] && $p['hp'] > 0) {
                $dist = sqrt(($b['x'] - $p['x'])**2 + ($b['y'] - $p['y'])**2);
                if ($dist < 18) {
                    if (isset($players[$b['owner']])) {
                        applyDamage($p, 25, $players[$b['owner']], $players);
                    }
                    unset($bullets[$bIndex]);
                    break;
                }
            }
        }
    }
    $bullets = array_values($bullets);
}

function applyDamage(&$target, $dmg, &$attacker, &$allPlayers) {
    if ($target['shield'] > 0) {
        $target['shield'] -= $dmg;
        if ($target['shield'] < 0) {
            $target['hp'] += $target['shield'];
            $target['shield'] = 0;
        }
    } else {
        $target['hp'] -= $dmg;
    }

    if ($target['hp'] <= 0) {
        $target['deaths']++;
        $attacker['kills']++;
        $target['hp'] = 100;
        $target['shield'] = 0;
        
        // Респавн игрока строго вне стен
        global $walls;
        do {
            $rx = rand(100, 1100);
            $ry = rand(100, 600);
        } while (checkWallCollision($rx, $ry, 20, $walls));

        $target['x'] = $rx;
        $target['y'] = $ry;
    }
}

function checkWallCollision($x, $y, $radius, $walls) {
    foreach ($walls as $w) {
        if ($x + $radius > $w['x'] && $x - $radius < $w['x'] + $w['w'] &&
            $y + $radius > $w['y'] && $y - $radius < $w['y'] + $w['h']) {
            return true;
        }
    }
    return false;
}

function performHandshake($header, $client, $host, $port) {
    $headers = [];
    $lines = preg_split("/\r\n/", $header);
    foreach ($lines as $line) {
        $line = chop($line);
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $headers[$matches[1]] = $matches[2];
        }
    }
    $secKey = $headers['Sec-WebSocket-Key'] ?? '';
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $buffer = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
              "Upgrade: websocket\r\n" .
              "Connection: Upgrade\r\n" .
              "WebSocket-Origin: $host\r\n" .
              "WebSocket-Location: ws://$host:$port\r\n" .
              "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
    socket_write($client, $buffer, strlen($buffer));
}

function decode($text) {
    $length = ord($text[1]) & 127;
    if ($length === 126) { $masks = substr($text, 4, 4); $data = substr($text, 8); }
    elseif ($length === 127) { $masks = substr($text, 10, 4); $data = substr($text, 14); }
    else { $masks = substr($text, 2, 4); $data = substr($text, 6); }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) { $text .= $data[$i] ^ $masks[$i % 4]; }
    return $text;
}

function encode($text) {
    $b1 = 0x80 | (0x1 & 0x0f);
    $length = strlen($text);
    if ($length <= 125) $header = pack('CC', $b1, $length);
    elseif ($length > 125 && $length < 65536) $header = pack('CCn', $b1, 126, $length);
    else { $header = pack('CCN', $b1, 127, $length); }
    return $header . $text;
}
