<?php

//   backend/websocket/server.php
//   Servidor WebSocket con Ratchet

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/Database.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class GoldenBootChat implements MessageComponentInterface {
    protected array $clients = [];
    protected array $connUsers = [];
    protected PDO $db;

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

        echo "[WS] Mensaje recibido: " . $msg['type'] . "\n";

        switch ($msg['type']) {
            case 'auth':
                $userId = $this->validateSession($msg['session_id'] ?? '');
                if (!$userId) {
                    $from->send(json_encode(['type' => 'error', 'msg' => 'Auth failed']));
                    $from->close();
                    return;
                }
                $this->clients[$userId] = $from;
                $this->connUsers[$from->resourceId] = $userId;
                $this->updateStatus($userId, 'online');
                
                $this->broadcast([
                    'type' => 'status_change',
                    'userId' => $userId,
                    'status' => 'online',
                ]);
                
                $from->send(json_encode(['type' => 'auth_ok', 'userId' => $userId]));
                echo "[WS] Usuario $userId autenticado\n";
                break;

            case 'private_msg':
                $senderId = $this->connUsers[$from->resourceId] ?? null;
                $receiverId = (int)($msg['receiver_id'] ?? 0);
                if (!$senderId || !$receiverId) return;
                
                $content = $msg['content'] ?? '';
                $encrypted = (int)($msg['encrypted'] ?? 0);
                
                $this->db->prepare(
                    'INSERT INTO messages (sender_id, receiver_id, content, is_encrypted)
                     VALUES (?, ?, ?, ?)'
                )->execute([$senderId, $receiverId, $content, $encrypted]);
                
                $payload = [
                    'type' => 'private_msg',
                    'sender_id' => $senderId,
                    'receiver_id' => $receiverId,
                    'content' => $content,
                    'encrypted' => $encrypted,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                
                if (isset($this->clients[$receiverId])) {
                    $this->clients[$receiverId]->send(json_encode($payload));
                    echo "[WS] Mensaje enviado a usuario $receiverId\n";
                }
                $from->send(json_encode($payload));
                break;

            case 'group_msg':
                $senderId = $this->connUsers[$from->resourceId] ?? null;
                $groupId = (int)($msg['group_id'] ?? 0);
                if (!$senderId || !$groupId) return;
                
                $content = $msg['content'] ?? '';
                $this->db->prepare(
                    'INSERT INTO messages (sender_id, group_id, content) VALUES (?, ?, ?)'
                )->execute([$senderId, $groupId, $content]);
                
                $stmt = $this->db->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
                $stmt->execute([$groupId]);
                $members = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $payload = [
                    'type' => 'group_msg',
                    'group_id' => $groupId,
                    'sender_id' => $senderId,
                    'content' => $content,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                
                foreach ($members as $memberId) {
                    if (isset($this->clients[$memberId])) {
                        $this->clients[$memberId]->send(json_encode($payload));
                    }
                }
                break;

            // ═══════ WEBRTC / LLAMADAS - CORREGIDO ═══════
            case 'call_request':
                $fromId = $this->connUsers[$from->resourceId] ?? null;
                $targetId = (int)($msg['target_id'] ?? 0);
                if (!$fromId || !$targetId) {
                    echo "[WS] call_request: faltan IDs\n";
                    return;
                }
                
                echo "[WS] Llamada de $fromId a $targetId\n";
                
                // Registrar en BD
                $this->db->prepare(
                    "INSERT INTO calls (caller_id, receiver_id, status) VALUES (?, ?, 'calling')"
                )->execute([$fromId, $targetId]);
                $callId = $this->db->lastInsertId();
                
                $payload = [
                    'type' => 'call_request',
                    'from_id' => $fromId,
                    'from_name' => $msg['from_name'] ?? $this->getUserName($fromId),
                    'call_id' => $callId,
                ];
                
                if (isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode($payload));
                    echo "[WS] call_request enviado a $targetId\n";
                } else {
                    echo "[WS] Usuario $targetId no está conectado\n";
                    $from->send(json_encode(['type' => 'call_error', 'msg' => 'Usuario no disponible']));
                }
                break;
                
            case 'call_accept':
                $fromId = $this->connUsers[$from->resourceId] ?? null;
                $targetId = (int)($msg['target_id'] ?? 0);
                if (!$fromId || !$targetId) return;
                
                echo "[WS] Llamada aceptada por $fromId a $targetId\n";
                
                // Actualizar estado en BD
                $this->db->prepare("UPDATE calls SET status = 'active' WHERE (caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?)")
                    ->execute([$targetId, $fromId, $fromId, $targetId]);
                
                $payload = ['type' => 'call_accept'];
                if (isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode($payload));
                }
                break;
                
            case 'call_reject':
                $fromId = $this->connUsers[$from->resourceId] ?? null;
                $targetId = (int)($msg['target_id'] ?? 0);
                if (!$fromId || !$targetId) return;
                
                echo "[WS] Llamada rechazada por $fromId\n";
                
                $this->db->prepare("UPDATE calls SET status = 'missed' WHERE (caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?)")
                    ->execute([$targetId, $fromId, $fromId, $targetId]);
                
                $payload = ['type' => 'call_reject'];
                if (isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode($payload));
                }
                break;
                
            case 'call_end':
                $fromId = $this->connUsers[$from->resourceId] ?? null;
                $targetId = (int)($msg['target_id'] ?? 0);
                if (!$fromId || !$targetId) return;
                
                echo "[WS] Llamada terminada por $fromId\n";
                
                $this->db->prepare("UPDATE calls SET status = 'ended', ended_at = NOW() WHERE (caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?)")
                    ->execute([$targetId, $fromId, $fromId, $targetId]);
                
                $payload = ['type' => 'call_end'];
                if (isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode($payload));
                }
                break;
                
            case 'webrtc_offer':
            case 'webrtc_answer':
                $fromId = $this->connUsers[$from->resourceId] ?? null;
                $targetId = (int)($msg['target_id'] ?? 0);
                if (!$fromId || !$targetId) return;
                
                echo "[WS] WebRTC {$msg['type']} de $fromId a $targetId\n";
                
                $payload = [
                    'type' => $msg['type'],
                    'sdp' => $msg['sdp'],
                ];
                if (isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode($payload));
                }
                break;
                
            case 'webrtc_ice':
                $fromId = $this->connUsers[$from->resourceId] ?? null;
                $targetId = (int)($msg['target_id'] ?? 0);
                if (!$fromId || !$targetId) return;
                
                $payload = [
                    'type' => 'webrtc_ice',
                    'candidate' => $msg['candidate'],
                ];
                if (isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode($payload));
                }
                break;

            case 'location':
                $senderId = $this->connUsers[$from->resourceId] ?? null;
                $receiverId = (int)($msg['receiver_id'] ?? 0);
                $groupId = (int)($msg['group_id'] ?? 0);
                $lat = (float)($msg['lat'] ?? 0);
                $lng = (float)($msg['lng'] ?? 0);
                
                if (!$senderId || (!$receiverId && !$groupId)) return;
                
                $mapsUrl = "https://www.google.com/maps?q=$lat,$lng";
                
                $this->db->prepare(
                    'INSERT INTO messages (sender_id, receiver_id, group_id, content, file_type)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $senderId,
                    $receiverId ?: null,
                    $groupId ?: null,
                    $mapsUrl,
                    'location'
                ]);
                
                $payload = [
                    'type' => 'location',
                    'sender_id' => $senderId,
                    'maps_url' => $mapsUrl,
                    'lat' => $lat,
                    'lng' => $lng,
                    'created_at' => date('Y-m-d H:i:s'),
                    'username' => $this->getUserName($senderId),
                ];
                
                if ($receiverId && isset($this->clients[$receiverId])) {
                    $this->clients[$receiverId]->send(json_encode($payload));
                }
                if ($groupId) {
                    $stmt = $this->db->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
                    $stmt->execute([$groupId]);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                        if ($mid != $senderId && isset($this->clients[$mid])) {
                            $this->clients[$mid]->send(json_encode($payload));
                        }
                    }
                }
                break;

            case 'typing':
                $senderId = $this->connUsers[$from->resourceId] ?? null;
                $targetId = (int)($msg['target_id'] ?? 0);
                if ($senderId && $targetId && isset($this->clients[$targetId])) {
                    $this->clients[$targetId]->send(json_encode([
                        'type' => 'typing',
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
                'type' => 'status_change',
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
    
    private function getUserName(int $userId): string {
        $stmt = $this->db->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?: "Usuario#$userId";
    }

    private function validateSession(string $sessionId): ?int {
        session_id($sessionId);
        session_start();
        $userId = $_SESSION['user_id'] ?? null;
        session_write_close();
        return $userId ? (int)$userId : null;
    }
}

$server = IoServer::factory(
    new HttpServer(new WsServer(new GoldenBootChat())),
    WS_PORT,
    WS_HOST
);

echo "[GoldenBoot] WebSocket listo en ws://" . WS_HOST . ":" . WS_PORT . "\n";
$server->run();