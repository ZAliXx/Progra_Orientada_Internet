<?php

//   backend/api/tasks.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

cors_headers();
session_start();

if (empty($_SESSION['user_id'])) json_response(['error' => 'No autenticado'], 401);

$db     = Database::connect();
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

match ($action) {
    'create'   => createTask($db, $userId),
    'list'     => listTasks($db, $userId),
    'complete' => completeTask($db, $userId),
    'delete'   => deleteTask($db, $userId),
    default    => json_response(['error' => 'Acción no válida'], 400),
};

function createTask(PDO $db, int $userId): void {
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $groupId    = (int)($data['group_id']    ?? 0);
    $title      = trim($data['title']        ?? '');
    $assignedTo = (int)($data['assigned_to'] ?? 0);
    $dueDate    = $data['due_date'] ?? null;

    if (!$groupId || !$title) json_response(['error' => 'Grupo y título requeridos'], 422);

    $stmt = $db->prepare(
        'INSERT INTO tasks (group_id, title, description, assigned_to, created_by, due_date)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $groupId, $title,
        $data['description'] ?? '',
        $assignedTo ?: null,
        $userId,
        $dueDate ?: null,
    ]);

    json_response(['success' => true, 'task_id' => $db->lastInsertId()], 201);
}

function listTasks(PDO $db, int $userId): void {
    $groupId = (int)($_GET['group_id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT t.*, 
                u1.username AS created_by_name,
                u2.username AS assigned_to_name
         FROM tasks t
         JOIN users u1 ON u1.id = t.created_by
         LEFT JOIN users u2 ON u2.id = t.assigned_to
         WHERE t.group_id = ?
         ORDER BY t.created_at DESC'
    );
    $stmt->execute([$groupId]);
    json_response(['tasks' => $stmt->fetchAll()]);
}

function completeTask(PDO $db, int $userId): void {
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $taskId = (int)($data['task_id'] ?? 0);

    $db->prepare(
        "UPDATE tasks SET status = 'done', updated_at = NOW() WHERE id = ?"
    )->execute([$taskId]);

    // +20 puntos al usuario que completó
    $db->prepare('UPDATE users SET points = points + 20 WHERE id = ?')
       ->execute([$userId]);

    json_response(['success' => true]);
}

function deleteTask(PDO $db, int $userId): void {
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $taskId = (int)($data['task_id'] ?? 0);

    $db->prepare('DELETE FROM tasks WHERE id = ? AND created_by = ?')
       ->execute([$taskId, $userId]);

    json_response(['success' => true]);
}