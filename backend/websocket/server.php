<?php

//   backend/websocket/server.php
//   Servidor WebSocket con Ratchet

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/Database.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class GoldenBootChat implements MessageComponentInterface {
    // Conexiones activas: userId => Connection
    protected array $clients   = [];
    protected array $connUsers = []; // connId => userId
    protected PDO   $db;

    public function __construct() {
        $this->db = Database::connect();
        echo "[GoldenBoot WS] Servidor iniciado en " . WS_HOST . ":" . WS_PORT . "\n";
    }

    public function onOpen(ConnectionInterface $conn): void {
        echo "[WS] Nueva conexión #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $rawMsg): void {
        $msg = json_decode($rawMsg, true);
        if (!$msg || !isset($msg['type'])) return;

        switch ($msg['type']) {

            // Autenticación
            case 'auth':
                $userId = $this->validateSession($msg['session_id'] ?? '');
                if (!$userId) {
                    $from->send(json_encode(['type' => 'error', 'msg' => 'Auth failed']));
                    $from->close();
                    return;
                }
                $this->clients[$userId]           = $from;
                $this->connUsers[$from->resourceId] = $userId;
                $this->updateStatus($userId, 'online');

                // Notificar estado a todos
                $this->broadcast([
                    'type'   => 'status_change',
                    'userId' => $userId,
                    'status' => 'online',
                ]);

                $from->send(json_encode(['type' => 'auth_ok', 'userId' => $userId]));
                echo "[WS] Usuario $userId autenticado\n";
                break;

            // Mensaje privado
            case 'private_msg':
                $senderId   = $this->connUsers[$from->resourceId] ?? null;
                $receiverId = (int)($msg['receiver_id'] ?? 0);
                if (!$senderId || !$receiverId) return;

                $content   = $msg['content'] ?? '';
                $encrypted = (int)($msg['encrypted'] ?? 0);

                // Guardar en BD
                $this->db->prepare(
                    'INSERT INTO messages (sender_id, receiver_id, content, is_encrypted)
                     VALUES (?, ?, ?, ?)'
                )->execute([$senderId, $receiverId, $content, $encrypted]);

                $payload = [
                    'type'        => 'private_msg',
                    'sender_id'   => $senderId,
                    'receiver_id' => $receiverId,
                    'content'     => $content,
                    'encrypted'   => $encrypted,
                    'created_at'  => date('Y-m-d H:i:s'),
                ];

                // Enviar al receptor si está conectado
                if (isset($this->clients[$receiverId])) {
                    $this->clients[$receiverId]->send(json_encode($payload));
                }
                // Confirmación al emisor
                $from->send(json_encode($payload));
                break;

            // Mensaje grupal
            case 'group_msg':
                $senderId = $this->connUsers[$from->resourceId] ?? null;
                $groupId  = (int)($msg['group_id'] ?? 0);
                if (!$senderId || !$groupId) return;

                $content = $msg['content'] ?? '';
                $this->db->prepare(
                    'INSERT INTO messages (sender_id, group_id, content) VALUES (?, ?, ?)'
                )->execute([$senderId, $groupId, $content]);

                // Obtener miembros del grupo
                $stmt = $this->db->prepare(
                    'SELECT user_id FROM group_members WHERE group_id = ?'
                );
                $stmt->execute([$groupId]);
                $members = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $payload = [
                    'type'       => 'group_msg',
                    'group_id'   => $groupId,
                    'sender_id'  => $senderId,
                    'content'    => $content,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                foreach ($members as $memberId) {
                    if (isset($this->clients[$memberId])) {
                        $this->clients[$memberId]->send(json_encode($payload));
                    }
                }
                break;

            // Señalización WebRTC
            case 'webrtc_offer':
            case 'webrtc_answer':
            case 'webrtc_ice':
            case 'call_request':
            case 'call_accept':
            case 'call_reject':
            case 'call_end':
                $targetId = (int)($msg['target_id'] ?? 0);
                if (isset($this->clients[$targetId])) {
                    $msg['from_id'] = $this->connUsers[$from->resourceId] ?? null;
                    $this->clients[$targetId]->send(json_encode($msg));
                }
                break;

            // Compartir ubicación
            case 'location':
                $senderId   = $this->connUsers[$from->resourceId] ?? null;
                $receiverId = (int)($msg['receiver_id'] ?? 0);
                $groupId    = (int)($msg['group_id']    ?? 0);
                $lat        = (float)($msg['lat'] ?? 0);
                $lng        = (float)($msg['lng'] ?? 0);

                $mapsUrl = "https://www.google.com/maps?q=$lat,$lng";

                // Guardar como mensaje con link
                $this->db->prepare(
                    'INSERT INTO messages (sender_id, receiver_id, group_id, content, file_type)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $senderId,
                    $receiverId ?: null,
                    $groupId    ?: null,
                    $mapsUrl,
                    'location',
                ]);

                $payload = [
                    'type'       => 'location',
                    'sender_id'  => $senderId,
                    'maps_url'   => $mapsUrl,
                    'lat'        => $lat,
                    'lng'        => $lng,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                if ($receiverId && isset($this->clients[$receiverId])) {
                    $this->clients[$receiverId]->send(json_encode($payload));
                }
                if ($groupId) {
                    $stmt = $this->db->prepare(
                        'SELECT user_id FROM group_members WHERE group_id = ?'
                    );
                    $stmt->execute([$groupId]);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                        if (isset($this->clients[$mid])) {
                            $this->clients[$mid]->send(json_encode($payload));
                        }
                    }
                }
                break;

            // Typing indicator
            case 'typing':
                $targetId = (int)($msg['target_id'] ?? 0);
                $senderId = $this->connUsers[$from->resourceId] ?? null;
                if ($targetId && isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode([
                        'type'      => 'typing',
                        'sender_id' => $senderId,
                    ]));
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $userId = $this->connUsers[$conn->resourceId] ?? null;
        if ($userId) {
            unset($this->clients[$userId]);
            $this->updateStatus($userId, 'offline');
            $this->broadcast([
                'type'   => 'status_change',
                'userId' => $userId,
                'status' => 'offline',
            ]);
            echo "[WS] Usuario $userId desconectado\n";
        }
        unset($this->connUsers[$conn->resourceId]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[WS] Error: {$e->getMessage()}\n";
        $conn->close();
    }

    // Helpers
    private function broadcast(array $data): void {
        $json = json_encode($data);
        foreach ($this->clients as $client) {
            $client->send($json);
        }
    }

    private function updateStatus(int $userId, string $status): void {
        $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')
                 ->execute([$status, $userId]);
    }

    private function validateSession(string $sessionId): ?int {
        // Leemos la sesión PHP del usuario desde el ID de sesión
        session_id($sessionId);
        session_start();
        $userId = $_SESSION['user_id'] ?? null;
        session_write_close();
        return $userId ? (int)$userId : null;
    }
}

// Iniciar servidor
$server = IoServer::factory(
    new HttpServer(new WsServer(new GoldenBootChat())),
    WS_PORT,
    WS_HOST
);

echo "[GoldenBoot] WebSocket listo. Puerto: " . WS_PORT . "\n";
$server->run();