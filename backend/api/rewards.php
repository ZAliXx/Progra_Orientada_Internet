<?php
// backend/api/rewards.php (actualizado)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

cors_headers();
session_start();

if (empty($_SESSION['user_id'])) json_response(['error' => 'No autenticado'], 401);

$db = Database::connect();
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

match ($action) {
    'leaderboard' => getLeaderboard($db),
    'my_rewards'  => getMyRewards($db, $userId),
    'all_rewards' => getAllRewards($db),
    // Nuevos endpoints compatibles
    'check_badges' => checkAndAwardBadges($db, $userId),
    default       => json_response(['error' => 'Acción no válida'], 400),
};

function getLeaderboard(PDO $db): void {
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.avatar, u.points, u.status,
                COUNT(ur.reward_id) AS badge_count
         FROM users u
         LEFT JOIN user_rewards ur ON ur.user_id = u.id
         GROUP BY u.id
         ORDER BY u.points DESC
         LIMIT 20'
    );
    $stmt->execute();
    json_response(['leaderboard' => $stmt->fetchAll()]);
}

function getMyRewards(PDO $db, int $userId): void {
    $stmt = $db->prepare(
        'SELECT r.*, ur.earned_at
         FROM user_rewards ur
         JOIN rewards r ON r.id = ur.reward_id
         WHERE ur.user_id = ?
         ORDER BY ur.earned_at DESC'
    );
    $stmt->execute([$userId]);
    json_response(['rewards' => $stmt->fetchAll()]);
}

function getAllRewards(PDO $db): void {
    $stmt = $db->prepare('SELECT * FROM rewards ORDER BY points_req ASC');
    $stmt->execute();
    json_response(['rewards' => $stmt->fetchAll()]);
}

function checkAndAwardBadges(PDO $db, int $userId): void {
    // Verificar insignias por puntos
    $stmt = $db->prepare('SELECT points FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $points = $stmt->fetchColumn();
    
    $stmt = $db->prepare(
        'SELECT b.id FROM badges b
         WHERE b.min_points <= ?
         AND b.id NOT IN (SELECT badge_id FROM user_badges WHERE user_id = ?)'
    );
    $stmt->execute([$points, $userId]);
    $newBadges = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($newBadges as $badgeId) {
        $db->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)')
           ->execute([$userId, $badgeId]);
    }
    
    json_response(['success' => true, 'new_badges' => $newBadges]);
}