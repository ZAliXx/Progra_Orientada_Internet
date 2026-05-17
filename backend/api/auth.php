<?php

//   backend/api/auth.php
//   Endpoints: register / login / logout / me

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

cors_headers();
session_start();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = Database::connect();

match ($action) {
    'register' => handleRegister($db),
    'login'    => handleLogin($db),
    'logout'   => handleLogout($db),
    'me'       => handleMe($db),
    default    => json_response(['error' => 'Acción no válida'], 400),
};

// REGISTRO
function handleRegister(PDO $db): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'Método no permitido'], 405);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($data['username'] ?? '');
    $email    = trim($data['email']    ?? '');
    $password =       $data['password'] ?? '';

    // Validaciones básicas
    if (!$username || !$email || !$password) {
        json_response(['error' => 'Todos los campos son requeridos'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['error' => 'Email inválido'], 422);
    }
    if (strlen($password) < 6) {
        json_response(['error' => 'La contraseña debe tener al menos 6 caracteres'], 422);
    }

    // Verificar duplicados
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) {
        json_response(['error' => 'El email o nombre de usuario ya existe'], 409);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare(
        'INSERT INTO users (username, email, password) VALUES (?, ?, ?)'
    );
    $stmt->execute([$username, $email, $hash]);
    $userId = $db->lastInsertId();

    // Marcar online y crear sesión
    updateStatus($db, $userId, 'online');
    $_SESSION['user_id'] = $userId;

    json_response([
        'success' => true,
        'message' => '¡Cuenta creada!',
        'user'    => fetchUser($db, $userId),
    ], 201);
}

// LOGIN
function handleLogin(PDO $db): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'Método no permitido'], 405);
    }

    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = trim($data['email']    ?? '');
    $password =       $data['password'] ?? '';

    if (!$email || !$password) {
        json_response(['error' => 'Email y contraseña requeridos'], 422);
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_response(['error' => 'Usuario y/o contraseña incorrectas'], 401);
    }

    updateStatus($db, $user['id'], 'online');
    $_SESSION['user_id'] = $user['id'];

    // Agregar puntos por login diario (si no lo hizo hoy)
    awardDailyPoints($db, $user['id']);

    json_response([
        'success' => true,
        'user'    => fetchUser($db, $user['id']),
    ]);
}

// LOGOUT
function handleLogout(PDO $db): void {
    if (!empty($_SESSION['user_id'])) {
        updateStatus($db, $_SESSION['user_id'], 'offline');
    }
    session_destroy();
    json_response(['success' => true, 'message' => 'Sesión cerrada']);
}

// ME (usuario actual)
function handleMe(PDO $db): void {
    if (empty($_SESSION['user_id'])) {
        json_response(['error' => 'No autenticado'], 401);
    }
    json_response(['user' => fetchUser($db, $_SESSION['user_id'])]);
}

// Helpers
function fetchUser(PDO $db, int $id): array {
    $stmt = $db->prepare(
        'SELECT id, username, email, avatar, status, points, created_at, last_seen
         FROM users WHERE id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

function updateStatus(PDO $db, int $userId, string $status): void {
    $db->prepare('UPDATE users SET status = ? WHERE id = ?')
       ->execute([$status, $userId]);
}

function awardDailyPoints(PDO $db, int $userId): void {
    // Verificar si ya reclamó bono hoy usando la nueva tabla
    $stmt = $db->prepare(
        "SELECT id FROM daily_bonus 
         WHERE user_id = ? AND bonus_date = CURDATE() AND claimed = 1"
    );
    $stmt->execute([$userId]);
    if ($stmt->fetch()) return;

    // Dar puntos base por login
    $db->prepare('UPDATE users SET points = points + 5 WHERE id = ?')
       ->execute([$userId]);

    checkAndAwardBadges($db, $userId);
}

function checkAndAwardBadges(PDO $db, int $userId): void {
    $stmt = $db->prepare('SELECT points FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $points = (int)($stmt->fetchColumn() ?? 0);

    // Obtener badges disponibles por puntos
    $stmt = $db->prepare(
        'SELECT r.id FROM rewards r
         WHERE r.points_req <= ?
         AND r.id NOT IN (SELECT reward_id FROM user_rewards WHERE user_id = ?)'
    );
    $stmt->execute([$points, $userId]);
    $newBadges = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($newBadges as $rewardId) {
        $db->prepare('INSERT IGNORE INTO user_rewards (user_id, reward_id) VALUES (?,?)')
           ->execute([$userId, $rewardId]);

        $db->prepare(
            "INSERT INTO notifications (user_id, type, content, reference_id)
             VALUES (?, 'reward', '¡Ganaste una nueva recompensa!', ?)"
        )->execute([$userId, $rewardId]);
    }
}