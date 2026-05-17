<?php

//   backend/websocket/server.php
//   Servidor WebSocket con Ratchet - VERSIÓN CORREGIDA

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/Database.php';

if (ob_get_level()) ob_end_clean();

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class GoldenBootChat implements MessageComponentInterface {
    protected array $clients = [];      // userId => Connection
    protected array $connUsers = [];    // connId => userId
    protected array $pendingCalls = []; // targetId => callData
    protected PDO $db;

    public function __construct() {
        $this->db = Database::connect();
        echo "[GoldenBoot WS] Servidor iniciado en " . WS_HOST . ":" . WS_PORT . "\n";
    }

    public function onOpen(ConnectionInterface $conn): void {
        echo "[WS] Nueva conexión #{$conn->resourceId} desde {$conn->remoteAddress}\n";
    }

    public function onMessage(ConnectionInterface $from, $rawMsg): void {
        $msg = json_decode($rawMsg, true);
        if (!$msg || !isset($msg['type'])) {
            echo "[WS] Mensaje inválido: $rawMsg\n";
            return;
        }

        echo "[WS] 📨 Mensaje recibido: " . $msg['type'] . "\n";

        switch ($msg['type']) {
            case 'auth':
                $this->handleAuth($from, $msg);
                break;
            case 'private_msg':
                $this->handlePrivateMessage($from, $msg);
                break;
            case 'group_msg':
                $this->handleGroupMessage($from, $msg);
                break;
            case 'call_request':
                $this->handleCallRequest($from, $msg);
                break;
            case 'call_accept':
                $this->handleCallAccept($from, $msg);
                break;
            case 'call_reject':
                $this->handleCallReject($from, $msg);
                break;
            case 'call_end':
                $this->handleCallEnd($from, $msg);
                break;
            case 'webrtc_offer':
            case 'webrtc_answer':
                $this->handleWebRTCSignaling($from, $msg);
                break;
            case 'webrtc_ice':
                $this->handleWebRTCIce($from, $msg);
                break;
            case 'location':
                $this->handleLocation($from, $msg);
                break;
            case 'typing':
                $this->handleTyping($from, $msg);
                break;
            default:
                echo "[WS] Tipo de mensaje desconocido: {$msg['type']}\n";
        }
    }

    private function handleAuth(ConnectionInterface $from, array $msg): void {
    $sessionId = $msg['session_id'] ?? '';
    
    if (empty($sessionId)) {
        $from->send(json_encode(['type' => 'error', 'msg' => 'No session ID provided']));
        $from->close();
        return;
    }
    
    $userId = $this->validateSession($sessionId);
    
    if (!$userId) {
        $from->send(json_encode(['type' => 'error', 'msg' => 'Auth failed - invalid session']));
        $from->close();
        echo "[WS] ❌ Autenticación fallida para session: $sessionId\n";
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
    echo "[WS] ✅ Usuario $userId autenticado correctamente\n";
}

    private function handleCallRequest(ConnectionInterface $from, array $msg): void {
        $fromId = $this->connUsers[$from->resourceId] ?? null;
        $targetId = (int)($msg['target_id'] ?? 0);
        
        if (!$fromId || !$targetId) {
            echo "[WS] ❌ call_request: faltan IDs (fromId=$fromId, targetId=$targetId)\n";
            return;
        }
        
        echo "[WS] 📞 LLAMADA: Usuario $fromId está llamando a $targetId\n";
        
        // Verificar si el destinatario está conectado
        if (!isset($this->clients[$targetId])) {
            echo "[WS] ❌ Usuario $targetId NO está conectado\n";
            $from->send(json_encode(['type' => 'call_error', 'msg' => 'El usuario no está disponible']));
            return;
        }
        
        // Guardar en base de datos
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO calls (caller_id, receiver_id, status, started_at) VALUES (?, ?, 'calling', NOW())"
            );
            $stmt->execute([$fromId, $targetId]);
            $callId = $this->db->lastInsertId();
            echo "[WS] 📝 Llamada guardada en BD con ID: $callId\n";
            
            // Enviar solicitud al destinatario
            $payload = [
                'type' => 'call_request',
                'from_id' => $fromId,
                'from_name' => $this->getUserName($fromId),
                'call_id' => $callId,
                'target_id' => $targetId,
            ];
            
            $this->clients[$targetId]->send(json_encode($payload));
            echo "[WS] 📞 Solicitud de llamada ENVIADA a usuario $targetId\n";
            
            // Guardar en pending calls para tracking
            $this->pendingCalls[$targetId] = [
                'call_id' => $callId,
                'from_id' => $fromId,
                'from_conn' => $from
            ];
            
        } catch (Exception $e) {
            echo "[WS] ❌ Error al guardar llamada: " . $e->getMessage() . "\n";
            $from->send(json_encode(['type' => 'call_error', 'msg' => 'Error interno']));
        }
    }

    private function handleCallAccept(ConnectionInterface $from, array $msg): void {
        $fromId = $this->connUsers[$from->resourceId] ?? null;
        $targetId = (int)($msg['target_id'] ?? 0);
        
        if (!$fromId || !$targetId) {
            echo "[WS] ❌ call_accept: faltan IDs\n";
            return;
        }
        
        echo "[WS] ✅ LLAMADA ACEPTADA: Usuario $fromId aceptó llamada de $targetId\n";
        
        // Actualizar base de datos
        try {
            $this->db->prepare(
                "UPDATE calls SET status = 'active' WHERE (caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?)"
            )->execute([$targetId, $fromId, $fromId, $targetId]);
            echo "[WS] 📝 Estado de llamada actualizado a 'active'\n";
        } catch (Exception $e) {
            echo "[WS] ❌ Error al actualizar llamada: " . $e->getMessage() . "\n";
        }
        
        // Enviar confirmación al que inició la llamada
        if (isset($this->clients[$targetId])) {
            $this->clients[$targetId]->send(json_encode([
                'type' => 'call_accept',
                'from_id' => $fromId
            ]));
            echo "[WS] 📞 Confirmación de aceptación ENVIADA a $targetId\n";
        }
        
        // Limpiar pending calls
        unset($this->pendingCalls[$fromId]);
    }

    private function handleCallReject(ConnectionInterface $from, array $msg): void {
        $fromId = $this->connUsers[$from->resourceId] ?? null;
        $targetId = (int)($msg['target_id'] ?? 0);
        
        if (!$fromId || !$targetId) return;
        
        echo "[WS] ❌ LLAMADA RECHAZADA: Usuario $fromId rechazó llamada de $targetId\n";
        
        // Actualizar base de datos
        $this->db->prepare(
            "UPDATE calls SET status = 'missed', ended_at = NOW() WHERE (caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?)"
        )->execute([$targetId, $fromId, $fromId, $targetId]);
        
        // Notificar al que llamó
        if (isset($this->clients[$targetId])) {
            $this->clients[$targetId]->send(json_encode([
                'type' => 'call_reject',
                'from_id' => $fromId
            ]));
        }
        
        unset($this->pendingCalls[$fromId]);
    }

    private function handleCallEnd(ConnectionInterface $from, array $msg): void {
        $fromId = $this->connUsers[$from->resourceId] ?? null;
        $targetId = (int)($msg['target_id'] ?? 0);
        
        if (!$fromId || !$targetId) return;
        
        echo "[WS] 🏁 LLAMADA TERMINADA: Usuario $fromId terminó llamada con $targetId\n";
        
        // Calcular duración
        $stmt = $this->db->prepare(
            "SELECT started_at FROM calls WHERE (caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?) ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$fromId, $targetId, $targetId, $fromId]);
        $call = $stmt->fetch();
        
        if ($call) {
            $started = new DateTime($call['started_at']);
            $ended = new DateTime();
            $duration = $ended->getTimestamp() - $started->getTimestamp();
            
            $this->db->prepare(
                "UPDATE calls SET status = 'ended', ended_at = NOW(), call_duration = ? WHERE (caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?)"
            )->execute([$duration, $fromId, $targetId, $targetId, $fromId]);
        }
        
        // Notificar al otro participante
        if (isset($this->clients[$targetId])) {
            $this->clients[$targetId]->send(json_encode(['type' => 'call_end']));
        }
    }

    private function handleWebRTCSignaling(ConnectionInterface $from, array $msg): void {
        $fromId = $this->connUsers[$from->resourceId] ?? null;
        $targetId = (int)($msg['target_id'] ?? 0);
        
        if (!$fromId || !$targetId) return;
        
        echo "[WS] 🔄 WebRTC {$msg['type']} de $fromId a $targetId\n";
        
        if (isset($this->clients[$targetId])) {
            $this->clients[$targetId]->send(json_encode([
                'type' => $msg['type'],
                'sdp' => $msg['sdp'],
                'from_id' => $fromId
            ]));
        }
    }

    private function handleWebRTCIce(ConnectionInterface $from, array $msg): void {
        $fromId = $this->connUsers[$from->resourceId] ?? null;
        $targetId = (int)($msg['target_id'] ?? 0);
        
        if (!$fromId || !$targetId) return;
        
        if (isset($this->clients[$targetId])) {
            $this->clients[$targetId]->send(json_encode([
                'type' => 'webrtc_ice',
                'candidate' => $msg['candidate'],
                'from_id' => $fromId
            ]));
        }
    }

    private function handlePrivateMessage(ConnectionInterface $from, array $msg): void {
        $senderId = $this->connUsers[$from->resourceId] ?? null;
        $receiverId = (int)($msg['receiver_id'] ?? 0);
        if (!$senderId || !$receiverId) return;
        
        $content = $msg['content'] ?? '';
        $encrypted = (int)($msg['encrypted'] ?? 0);
        
        $this->db->prepare(
            'INSERT INTO messages (sender_id, receiver_id, content, is_encrypted) VALUES (?, ?, ?, ?)'
        )->execute([$senderId, $receiverId, $content, $encrypted]);
        
        $payload = [
            'type' => 'private_msg',
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'content' => $content,
            'encrypted' => $encrypted,
            'created_at' => date('Y-m-d H:i:s'),
            'username' => $this->getUserName($senderId)
        ];
        
        if (isset($this->clients[$receiverId])) {
            $this->clients[$receiverId]->send(json_encode($payload));
        }
        // NO hacer echo al emisor — el frontend lo muestra localmente
    }

    private function handleGroupMessage(ConnectionInterface $from, array $msg): void {
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
            'username' => $this->getUserName($senderId)
        ];
        
        foreach ($members as $memberId) {
            // NO mandar al emisor — el frontend lo muestra localmente
            if ($memberId == $senderId) continue;
            if (isset($this->clients[$memberId])) {
                $this->clients[$memberId]->send(json_encode($payload));
            }
        }
    }

    private function handleLocation(ConnectionInterface $from, array $msg): void {
        $senderId = $this->connUsers[$from->resourceId] ?? null;
        $receiverId = (int)($msg['receiver_id'] ?? 0);
        $groupId = (int)($msg['group_id'] ?? 0);
        $lat = (float)($msg['lat'] ?? 0);
        $lng = (float)($msg['lng'] ?? 0);
        
        if (!$senderId || (!$receiverId && !$groupId)) return;
        
        $mapsUrl = "https://www.google.com/maps?q=$lat,$lng";
        
        $this->db->prepare(
            'INSERT INTO messages (sender_id, receiver_id, group_id, content, file_type) VALUES (?, ?, ?, ?, ?)'
        )->execute([$senderId, $receiverId ?: null, $groupId ?: null, $mapsUrl, 'location']);
        
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
    }

    private function handleTyping(ConnectionInterface $from, array $msg): void {
        $senderId = $this->connUsers[$from->resourceId] ?? null;
        $targetId = (int)($msg['target_id'] ?? 0);
        if ($senderId && $targetId && isset($this->clients[$targetId])) {
            $this->clients[$targetId]->send(json_encode([
                'type' => 'typing',
                'sender_id' => $senderId,
            ]));
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $userId = $this->connUsers[$conn->resourceId] ?? null;
        if ($userId) {
            unset($this->clients[$userId]);
            unset($this->pendingCalls[$userId]);
            $this->updateStatus($userId, 'offline');
            $this->broadcast([
                'type' => 'status_change',
                'userId' => $userId,
                'status' => 'offline',
            ]);
            echo "[WS] 👋 Usuario $userId desconectado\n";
        }
        unset($this->connUsers[$conn->resourceId]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[WS] ❌ Error: {$e->getMessage()}\n";
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
    $sessionId = preg_replace('/[^a-zA-Z0-9,-]/', '', $sessionId);
    if (empty($sessionId)) return null;
    
    $savePath = ini_get('session.save_path') ?: sys_get_temp_dir();
    $sessionFile = rtrim($savePath, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
    
    if (!file_exists($sessionFile)) {
        echo "[WS] ⚠️  Sesión no encontrada: $sessionFile\n";
        return null;
    }
    
    $data = file_get_contents($sessionFile);
    if ($data === false) return null;
    
    $sessionData = $this->unserializeSession($data);
    $userId = $sessionData['user_id'] ?? null;
    echo "[WS] 🔑 Sesión válida, userId: " . ($userId ?? 'null') . "\n";
    return $userId ? (int)$userId : null;
}

    private function unserializeSession(string $data): array {
    $result = [];
    $offset = 0;
    $len = strlen($data);
    while ($offset < $len) {
        $pipe = strpos($data, '|', $offset);
        if ($pipe === false) break;
        $key = substr($data, $offset, $pipe - $offset);
        $offset = $pipe + 1;
        try {
            $value = unserialize(substr($data, $offset));
            $result[$key] = $value;
            $offset += strlen(serialize($value));
        } catch (\Throwable $e) { break; }
    }
    return $result;
}
}

// Iniciar servidor
$server = IoServer::factory(
    new HttpServer(new WsServer(new GoldenBootChat())),
    WS_PORT,
    WS_HOST
);

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  🥇 Golden Boot - Servidor WebSocket\n";
echo "  📡 Host: " . WS_HOST . "\n";
echo "  🔌 Puerto: " . WS_PORT . "\n";
echo "  ✅ Servidor listo para recibir conexiones\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$server->run();