<?php

//   backend/api/messages.php
//   Endpoints para chat privado y grupal

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

cors_headers();
session_start();

if (empty($_SESSION['user_id'])) {
    json_response(['error' => 'No autenticado'], 401);
}

$db     = Database::connect();
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

match ($action) {
    'send'         => sendMessage($db, $userId),
    'history'      => getHistory($db, $userId),
    'upload'       => uploadFile($db, $userId),
    'unread'       => getUnread($db, $userId),
    'mark_read'    => markRead($db, $userId),
    'send_location' => sendLocation($db, $userId),
    default        => json_response(['error' => 'Acción no válida'], 400),
};

// ENVIAR MENSAJE
function sendMessage(PDO $db, int $senderId): void {
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $content    = trim($data['content']      ?? '');
    $receiverId = (int)($data['receiver_id'] ?? 0);
    $groupId    = (int)($data['group_id']    ?? 0);
    $encrypted  = (int)($data['encrypted']   ?? 0);

    if (!$content && !$groupId && !$receiverId) {
        json_response(['error' => 'Mensaje vacío'], 422);
    }
    if (!$receiverId && !$groupId) {
        json_response(['error' => 'Destino requerido'], 422);
    }

    $stmt = $db->prepare(
        'INSERT INTO messages (sender_id, receiver_id, group_id, content, is_encrypted)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $senderId,
        $receiverId ?: null,
        $groupId    ?: null,
        $content,
        $encrypted,
    ]);
    $msgId = $db->lastInsertId();

    // Sumar puntos al sender (+1 por mensaje)
    $db->prepare('UPDATE users SET points = points + 1 WHERE id = ?')
       ->execute([$senderId]);

    // Notificación al receptor (solo privado)
    if ($receiverId) {
        $db->prepare(
            "INSERT INTO notifications (user_id, type, content, reference_id)
             VALUES (?, 'message', ?, ?)"
        )->execute([$receiverId, "Nuevo mensaje de usuario #$senderId", $msgId]);
    }

    // Devolver mensaje completo
    $stmt = $db->prepare(
        'SELECT m.*, u.username, u.avatar
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.id = ?'
    );
    $stmt->execute([$msgId]);

    json_response(['success' => true, 'message' => $stmt->fetch()], 201);
}

// HISTORIAL
function getHistory(PDO $db, int $userId): void {
    $receiverId = (int)($_GET['receiver_id'] ?? 0);
    $groupId    = (int)($_GET['group_id']    ?? 0);
    $limit      = min((int)($_GET['limit']   ?? 50), 100);

    if ($groupId) {
        // Mensajes grupales
        $stmt = $db->prepare(
            'SELECT m.*, u.username, u.avatar
             FROM messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.group_id = ?
             ORDER BY m.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$groupId, $limit]);
    } elseif ($receiverId) {
        // Mensajes privados (ambas direcciones)
        $stmt = $db->prepare(
            'SELECT m.*, u.username, u.avatar
             FROM messages m
             JOIN users u ON u.id = m.sender_id
             WHERE (m.sender_id = ? AND m.receiver_id = ?)
                OR (m.sender_id = ? AND m.receiver_id = ?)
             ORDER BY m.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$userId, $receiverId, $receiverId, $userId, $limit]);
    } else {
        json_response(['error' => 'Parámetros inválidos'], 422);
    }

    $messages = array_reverse($stmt->fetchAll());
    json_response(['messages' => $messages]);
}

// SUBIDA DE ARCHIVOS
function uploadFile(PDO $db, int $senderId): void {
    if (!isset($_FILES['file'])) {
        json_response(['error' => 'No se recibió archivo'], 422);
    }

    $file       = $_FILES['file'];
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $groupId    = (int)($_POST['group_id']    ?? 0);

    if ($file['size'] > MAX_FILE_SIZE) {
        json_response(['error' => 'Archivo demasiado grande (máx. 10 MB)'], 422);
    }

    // Determinar tipo
    $mime = mime_content_type($file['tmp_name']);
    $type = match(true) {
        str_starts_with($mime, 'image/') => 'image',
        str_starts_with($mime, 'audio/') => 'audio',
        str_starts_with($mime, 'video/') => 'video',
        default                          => 'document',
    };

    // Nombre único y moverlo
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid("gb_{$senderId}_", true) . '.' . strtolower($ext);
    $dest     = UPLOAD_DIR . $filename;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_response(['error' => 'Error al guardar archivo'], 500);
    }

    // Guardar mensaje con archivo
    $stmt = $db->prepare(
        'INSERT INTO messages (sender_id, receiver_id, group_id, file_path, file_type)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $senderId,
        $receiverId ?: null,
        $groupId    ?: null,
        $filename,
        $type,
    ]);

    json_response([
        'success'   => true,
        'file_url'  => UPLOAD_URL . $filename,
        'file_type' => $type,
        'msg_id'    => $db->lastInsertId(),
    ], 201);
}

// MENSAJES NO LEÍDOS
function getUnread(PDO $db, int $userId): void {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total FROM messages
         WHERE receiver_id = ? AND is_read = 0'
    );
    $stmt->execute([$userId]);
    json_response($stmt->fetch());
}

// MARCAR COMO LEÍDOS
function markRead(PDO $db, int $userId): void {
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $senderId   = (int)($data['sender_id'] ?? 0);

    $db->prepare(
        'UPDATE messages SET is_read = 1
         WHERE receiver_id = ? AND sender_id = ?'
    )->execute([$userId, $senderId]);

    json_response(['success' => true]);
}

function sendLocation(PDO $db, int $senderId): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $lat = (float)($data['lat'] ?? 0);
    $lng = (float)($data['lng'] ?? 0);
    $mapsUrl = $data['maps_url'] ?? "https://www.google.com/maps?q=$lat,$lng";
    $receiverId = (int)($data['receiver_id'] ?? 0);
    $groupId = (int)($data['group_id'] ?? 0);
    
    if (!$lat || !$lng) {
        json_response(['error' => 'Coordenadas inválidas'], 422);
    }
    
    $stmt = $db->prepare(
        'INSERT INTO messages (sender_id, receiver_id, group_id, content, file_type)
         VALUES (?, ?, ?, ?, ?)'
    );
    // Guardar "lat,lng" como content para poder reconstruir el mapa en el historial
    $coordsContent = "$lat,$lng";
    $stmt->execute([
        $senderId,
        $receiverId ?: null,
        $groupId ?: null,
        $coordsContent,
        'location'
    ]);
    
    $msgId = $db->lastInsertId();
    
    // Obtener el mensaje completo
    $stmt = $db->prepare(
        'SELECT m.*, u.username, u.avatar
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.id = ?'
    );
    $stmt->execute([$msgId]);
    $message = $stmt->fetch();
    
    // Guardar también lat/lng en metadata (opcional??? checar rubrica
    // Por ahora solo guardamos el link
    
    json_response(['success' => true, 'message' => $message], 201);
}