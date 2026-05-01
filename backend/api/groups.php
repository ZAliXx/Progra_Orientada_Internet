<?php

//   backend/api/groups.php
//   CRUD de grupos y membresías

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

cors_headers();
session_start();

if (empty($_SESSION['user_id'])) json_response(['error' => 'No autenticado'], 401);

$db     = Database::connect();
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

match ($action) {
    'create'       => createGroup($db, $userId),
    'list'         => listGroups($db, $userId),
    'members'      => getMembers($db, $userId),
    'add_member'   => addMember($db, $userId),
    'remove_member'=> removeMember($db, $userId),
    'all_users'    => getAllUsers($db, $userId),
    default        => json_response(['error' => 'Acción no válida'], 400),
};

function createGroup(PDO $db, int $userId): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($data['name'] ?? '');
    if (!$name) json_response(['error' => 'Nombre requerido'], 422);

    $stmt = $db->prepare(
        'INSERT INTO groups_chat (name, description, created_by) VALUES (?, ?, ?)'
    );
    $stmt->execute([$name, $data['description'] ?? '', $userId]);
    $groupId = $db->lastInsertId();

    // Agregar creador como admin
    $db->prepare(
        "INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'admin')"
    )->execute([$groupId, $userId]);

    // Agregar miembros adicionales
    if (!empty($data['members']) && is_array($data['members'])) {
        $ins = $db->prepare(
            "INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)"
        );
        foreach ($data['members'] as $mid) {
            $ins->execute([$groupId, (int)$mid]);
        }
    }

    // Puntos por crear grupo
    $db->prepare('UPDATE users SET points = points + 10 WHERE id = ?')->execute([$userId]);

    json_response(['success' => true, 'group_id' => $groupId], 201);
}

function listGroups(PDO $db, int $userId): void {
    $stmt = $db->prepare(
        'SELECT g.*, COUNT(gm2.user_id) AS member_count
         FROM groups_chat g
         JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
         JOIN group_members gm2 ON gm2.group_id = g.id
         GROUP BY g.id
         ORDER BY g.created_at DESC'
    );
    $stmt->execute([$userId]);
    json_response(['groups' => $stmt->fetchAll()]);
}

function getMembers(PDO $db, int $userId): void {
    $groupId = (int)($_GET['group_id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.avatar, u.status, u.points, gm.role
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ?'
    );
    $stmt->execute([$groupId]);
    json_response(['members' => $stmt->fetchAll()]);
}

function addMember(PDO $db, int $userId): void {
    $data    = json_decode(file_get_contents('php://input'), true) ?? [];
    $groupId = (int)($data['group_id'] ?? 0);
    $newUser = (int)($data['user_id']  ?? 0);

    // Solo admin puede agregar
    $stmt = $db->prepare(
        "SELECT role FROM group_members WHERE group_id = ? AND user_id = ?"
    );
    $stmt->execute([$groupId, $userId]);
    $row = $stmt->fetch();
    if (!$row || $row['role'] !== 'admin') {
        json_response(['error' => 'Solo el administrador puede agregar miembros'], 403);
    }

    $db->prepare("INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)")
       ->execute([$groupId, $newUser]);

    json_response(['success' => true]);
}

function removeMember(PDO $db, int $userId): void {
    $data    = json_decode(file_get_contents('php://input'), true) ?? [];
    $groupId = (int)($data['group_id'] ?? 0);
    $target  = (int)($data['user_id']  ?? 0);

    $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?")
       ->execute([$groupId, $target]);

    json_response(['success' => true]);
}

function getAllUsers(PDO $db, int $userId): void {
    $stmt = $db->prepare(
        'SELECT id, username, avatar, status, points FROM users WHERE id != ? ORDER BY username'
    );
    $stmt->execute([$userId]);
    json_response(['users' => $stmt->fetchAll()]);
}