<?php
// backend/api/rewards_system.php
// Sistema completo de recompensas: stickers, badges, tienda, eventos, bonos diarios

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

cors_headers();
session_start();

if (empty($_SESSION['user_id'])) {
    json_response(['error' => 'No autenticado'], 401);
}

$db = Database::connect();
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

match ($action) {
    // Stickers
    'get_stickers' => getAvailableStickers($db, $userId),
    'get_my_stickers' => getMyStickers($db, $userId),
    'buy_sticker' => buySticker($db, $userId),
    'use_sticker' => useSticker($db, $userId),
    
    // Badges / Insignias
    'get_badges' => getAllBadges($db),
    'get_my_badges' => getMyBadges($db, $userId),
    
    // Tienda de recompensas
    'get_shop_items' => getShopItems($db, $userId),
    'buy_reward' => buyReward($db, $userId),
    'get_my_purchases' => getMyPurchases($db, $userId),
    
    // Eventos por ciudad
    'get_events' => getCityEvents($db),
    'get_my_events_progress' => getMyEventsProgress($db, $userId),
    'complete_event_task' => completeEventTask($db, $userId),
    
    // Bonos diarios
    'claim_daily_bonus' => claimDailyBonus($db, $userId),
    'get_daily_bonus_info' => getDailyBonusInfo($db, $userId),
    
    default => json_response(['error' => 'Acción no válida'], 400),
};

// ==================== STICKERS ====================

function getAvailableStickers(PDO $db, int $userId): void {
    // Obtener stickers que el usuario NO tiene
    $stmt = $db->prepare("
        SELECT s.* FROM stickers s
        WHERE s.is_active = 1
        AND s.id NOT IN (SELECT sticker_id FROM user_stickers WHERE user_id = ?)
        ORDER BY s.price ASC
    ");
    $stmt->execute([$userId]);
    json_response(['stickers' => $stmt->fetchAll()]);
}

function getMyStickers(PDO $db, int $userId): void {
    $stmt = $db->prepare("
        SELECT s.*, us.used_count, us.acquired_at 
        FROM user_stickers us
        JOIN stickers s ON s.id = us.sticker_id
        WHERE us.user_id = ?
        ORDER BY us.acquired_at DESC
    ");
    $stmt->execute([$userId]);
    json_response(['stickers' => $stmt->fetchAll()]);
}

function buySticker(PDO $db, int $userId): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $stickerId = (int)($data['sticker_id'] ?? 0);
    
    // Verificar sticker existe
    $stmt = $db->prepare("SELECT * FROM stickers WHERE id = ? AND is_active = 1");
    $stmt->execute([$stickerId]);
    $sticker = $stmt->fetch();
    if (!$sticker) {
        json_response(['error' => 'Sticker no disponible'], 404);
    }
    
    // Verificar puntos del usuario
    $stmt = $db->prepare("SELECT points FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userPoints = $stmt->fetchColumn();
    
    if ($userPoints < $sticker['price']) {
        json_response(['error' => 'No tienes suficientes puntos'], 400);
    }
    
    // Descontar puntos y agregar sticker
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE users SET points = points - ? WHERE id = ?")
           ->execute([$sticker['price'], $userId]);
        
        $db->prepare("INSERT INTO user_stickers (user_id, sticker_id) VALUES (?, ?)")
           ->execute([$userId, $stickerId]);
        
        // Registrar en notificaciones
        $db->prepare("
            INSERT INTO notifications (user_id, type, content, reference_id)
            VALUES (?, 'reward', ?, ?)
        ")->execute([$userId, "¡Compraste el sticker '{$sticker['name']}'!", $stickerId]);
        
        $db->commit();
        json_response(['success' => true, 'message' => 'Sticker comprado con éxito']);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Error al comprar sticker'], 500);
    }
}

function useSticker(PDO $db, int $userId): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $stickerId = (int)($data['sticker_id'] ?? 0);
    $chatId = (int)($data['chat_id'] ?? 0);
    $chatType = $data['chat_type'] ?? 'private';
    
    // Verificar que el usuario tiene el sticker
    $stmt = $db->prepare("
        SELECT * FROM user_stickers 
        WHERE user_id = ? AND sticker_id = ?
    ");
    $stmt->execute([$userId, $stickerId]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'No tienes este sticker'], 403);
    }
    
    // Incrementar contador de uso
    $db->prepare("
        UPDATE user_stickers SET used_count = used_count + 1 
        WHERE user_id = ? AND sticker_id = ?
    ")->execute([$userId, $stickerId]);
    
    json_response([
        'success' => true, 
        'message' => 'Sticker enviado',
        'sticker_id' => $stickerId
    ]);
}

// ==================== BADGES / INSIGNIAS ====================

function getAllBadges(PDO $db): void {
    $stmt = $db->prepare("SELECT * FROM badges WHERE is_active = 1 ORDER BY min_points ASC");
    $stmt->execute();
    json_response(['badges' => $stmt->fetchAll()]);
}

function getMyBadges(PDO $db, int $userId): void {
    $stmt = $db->prepare("
        SELECT b.*, ub.earned_at 
        FROM user_badges ub
        JOIN badges b ON b.id = ub.badge_id
        WHERE ub.user_id = ?
        ORDER BY ub.earned_at DESC
    ");
    $stmt->execute([$userId]);
    json_response(['badges' => $stmt->fetchAll()]);
}

// ==================== TIENDA DE RECOMPENSAS ====================

function getShopItems(PDO $db, int $userId): void {
    // Obtener items de la tienda que el usuario NO ha comprado o que han expirado
    $stmt = $db->prepare("
        SELECT sr.*, 
               CASE WHEN up.id IS NOT NULL AND (up.expires_at IS NULL OR up.expires_at > NOW()) 
                    THEN 1 ELSE 0 END as already_owned
        FROM shop_rewards sr
        LEFT JOIN user_purchases up ON up.reward_id = sr.id AND up.user_id = ?
        WHERE sr.available = 1
        GROUP BY sr.id
        ORDER BY sr.cost ASC
    ");
    $stmt->execute([$userId]);
    json_response(['shop_items' => $stmt->fetchAll()]);
}

function buyReward(PDO $db, int $userId): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $rewardId = (int)($data['reward_id'] ?? 0);
    
    // Verificar recompensa existe
    $stmt = $db->prepare("SELECT * FROM shop_rewards WHERE id = ? AND available = 1");
    $stmt->execute([$rewardId]);
    $reward = $stmt->fetch();
    if (!$reward) {
        json_response(['error' => 'Recompensa no disponible'], 404);
    }
    
    // Verificar puntos
    $stmt = $db->prepare("SELECT points FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userPoints = $stmt->fetchColumn();
    
    if ($userPoints < $reward['cost']) {
        json_response(['error' => 'No tienes suficientes puntos'], 400);
    }
    
    // Verificar si ya tiene la recompensa (si es permanente)
    if ($reward['duration_days'] === null) {
        $stmt = $db->prepare("
            SELECT id FROM user_purchases 
            WHERE user_id = ? AND reward_id = ? AND expires_at IS NULL
        ");
        $stmt->execute([$userId, $rewardId]);
        if ($stmt->fetch()) {
            json_response(['error' => 'Ya tienes esta recompensa'], 400);
        }
    }
    
    $db->beginTransaction();
    try {
        // Descontar puntos
        $db->prepare("UPDATE users SET points = points - ? WHERE id = ?")
           ->execute([$reward['cost'], $userId]);
        
        // Calcular fecha de expiración
        $expiresAt = null;
        if ($reward['duration_days']) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$reward['duration_days']} days"));
        }
        
        // Registrar compra
        $db->prepare("
            INSERT INTO user_purchases (user_id, reward_id, expires_at) 
            VALUES (?, ?, ?)
        ")->execute([$userId, $rewardId, $expiresAt]);
        
        // Otorgar sticker o badge según corresponda
        if ($reward['reward_type'] === 'sticker' && $reward['reward_id']) {
            $db->prepare("
                INSERT IGNORE INTO user_stickers (user_id, sticker_id) 
                VALUES (?, ?)
            ")->execute([$userId, $reward['reward_id']]);
        } elseif ($reward['reward_type'] === 'badge' && $reward['reward_id']) {
            $db->prepare("
                INSERT IGNORE INTO user_badges (user_id, badge_id) 
                VALUES (?, ?)
            ")->execute([$userId, $reward['reward_id']]);
        }
        
        $db->commit();
        json_response(['success' => true, 'message' => '¡Recompensa canjeada con éxito!']);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Error al canjear recompensa'], 500);
    }
}

function getMyPurchases(PDO $db, int $userId): void {
    $stmt = $db->prepare("
        SELECT sr.*, up.purchased_at, up.expires_at
        FROM user_purchases up
        JOIN shop_rewards sr ON sr.id = up.reward_id
        WHERE up.user_id = ? AND (up.expires_at IS NULL OR up.expires_at > NOW())
        ORDER BY up.purchased_at DESC
    ");
    $stmt->execute([$userId]);
    json_response(['purchases' => $stmt->fetchAll()]);
}

// ==================== EVENTOS POR CIUDAD ====================

function getCityEvents(PDO $db): void {
    $stmt = $db->prepare("
        SELECT ce.*, b.name as reward_badge_name, b.icon as reward_badge_icon
        FROM city_events ce
        LEFT JOIN badges b ON b.id = ce.reward_badge_id
        WHERE ce.is_active = 1 AND (ce.end_date IS NULL OR ce.end_date >= CURDATE())
        ORDER BY ce.city
    ");
    $stmt->execute();
    json_response(['events' => $stmt->fetchAll()]);
}

function getMyEventsProgress(PDO $db, int $userId): void {
    $stmt = $db->prepare("
        SELECT ce.*, uep.tasks_completed, uep.completed_at,
               b.name as reward_badge_name, b.icon as reward_badge_icon
        FROM city_events ce
        LEFT JOIN user_event_progress uep ON uep.event_id = ce.id AND uep.user_id = ?
        LEFT JOIN badges b ON b.id = ce.reward_badge_id
        WHERE ce.is_active = 1
        ORDER BY ce.city
    ");
    $stmt->execute([$userId]);
    $events = $stmt->fetchAll();
    
    foreach ($events as &$event) {
        $requiredTasks = json_decode($event['required_tasks'] ?? '[]', true);
        $completedTasks = json_decode($event['tasks_completed'] ?? '[]', true);
        $event['total_tasks'] = count($requiredTasks);
        $event['completed_count'] = count($completedTasks);
        $event['is_completed'] = !empty($event['completed_at']);
    }
    
    json_response(['events' => $events]);
}

function completeEventTask(PDO $db, int $userId): void {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $eventId = (int)($data['event_id'] ?? 0);
    $taskIndex = (int)($data['task_index'] ?? 0);
    
    // Verificar evento
    $stmt = $db->prepare("SELECT * FROM city_events WHERE id = ? AND is_active = 1");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        json_response(['error' => 'Evento no disponible'], 404);
    }
    
    // Obtener o crear progreso
    $stmt = $db->prepare("
        SELECT * FROM user_event_progress 
        WHERE user_id = ? AND event_id = ?
    ");
    $stmt->execute([$userId, $eventId]);
    $progress = $stmt->fetch();
    
    $completedTasks = $progress ? json_decode($progress['tasks_completed'] ?? '[]', true) : [];
    
    // Verificar si ya completó esta tarea
    if (in_array($taskIndex, $completedTasks)) {
        json_response(['error' => 'Tarea ya completada'], 400);
    }
    
    // Verificar si ya completó el evento
    if ($progress && $progress['completed_at']) {
        json_response(['error' => 'Evento ya completado'], 400);
    }
    
    $requiredTasks = json_decode($event['required_tasks'] ?? '[]', true);
    if ($taskIndex >= count($requiredTasks)) {
        json_response(['error' => 'Tarea no válida'], 400);
    }
    
    $completedTasks[] = $taskIndex;
    
    $db->beginTransaction();
    try {
        if ($progress) {
            $db->prepare("
                UPDATE user_event_progress 
                SET tasks_completed = ? 
                WHERE user_id = ? AND event_id = ?
            ")->execute([json_encode($completedTasks), $userId, $eventId]);
        } else {
            $db->prepare("
                INSERT INTO user_event_progress (user_id, event_id, tasks_completed) 
                VALUES (?, ?, ?)
            ")->execute([$userId, $eventId, json_encode($completedTasks)]);
        }
        
        // Verificar si completó todas las tareas
        if (count($completedTasks) === count($requiredTasks)) {
            // Marcar como completado y dar recompensa
            $db->prepare("
                UPDATE user_event_progress 
                SET completed_at = NOW() 
                WHERE user_id = ? AND event_id = ?
            ")->execute([$userId, $eventId]);
            
            // Dar puntos de recompensa
            $db->prepare("UPDATE users SET points = points + ? WHERE id = ?")
               ->execute([$event['reward_points'], $userId]);
            
            // Otorgar badge si existe
            if ($event['reward_badge_id']) {
                $db->prepare("
                    INSERT IGNORE INTO user_badges (user_id, badge_id) 
                    VALUES (?, ?)
                ")->execute([$userId, $event['reward_badge_id']]);
            }
            
            // Notificación
            $db->prepare("
                INSERT INTO notifications (user_id, type, content, reference_id)
                VALUES (?, 'reward', ?, ?)
            ")->execute([$userId, "¡Completaste el evento '{$event['name']}'! +{$event['reward_points']} pts", $eventId]);
        }
        
        $db->commit();
        json_response(['success' => true, 'message' => 'Tarea completada']);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Error al completar tarea'], 500);
    }
}

// ==================== BONOS DIARIOS ====================

function getDailyBonusInfo(PDO $db, int $userId): void {
    $bonusMap = [1 => 50, 2 => 75, 3 => 100, 4 => 150, 5 => 200, 6 => 300, 7 => 500];
    
    // Obtener último bono reclamado
    $stmt = $db->prepare("
        SELECT day_streak, bonus_date 
        FROM daily_bonus 
        WHERE user_id = ? 
        ORDER BY bonus_date DESC 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $lastBonus = $stmt->fetch();
    
    // Verificar si ya reclamó hoy
    $stmt = $db->prepare("
        SELECT id FROM daily_bonus 
        WHERE user_id = ? AND bonus_date = CURDATE()
    ");
    $stmt->execute([$userId]);
    $claimedToday = $stmt->fetch();
    
    $streak = 1;
    if ($lastBonus) {
        $lastDate = new DateTime($lastBonus['bonus_date']);
        $today = new DateTime();
        $diff = $today->diff($lastDate)->days;
        
        if ($diff == 1) {
            $streak = min($lastBonus['day_streak'] + 1, 7);
        } elseif ($diff > 1) {
            $streak = 1;
        }
    }
    
    json_response([
        'current_streak' => $streak,
        'today_bonus' => $bonusMap[$streak],
        'claimed_today' => !empty($claimedToday),
        'bonus_map' => $bonusMap
    ]);
}

function claimDailyBonus(PDO $db, int $userId): void {
    $bonusMap = [1 => 50, 2 => 75, 3 => 100, 4 => 150, 5 => 200, 6 => 300, 7 => 500];
    
    // Verificar si ya reclamó hoy
    $stmt = $db->prepare("
        SELECT id FROM daily_bonus 
        WHERE user_id = ? AND bonus_date = CURDATE()
    ");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Ya reclamaste el bono de hoy'], 400);
    }
    
    // Obtener racha actual
    $stmt = $db->prepare("
        SELECT day_streak 
        FROM daily_bonus 
        WHERE user_id = ? 
        ORDER BY bonus_date DESC 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $lastBonus = $stmt->fetch();
    
    $streak = 1;
    if ($lastBonus) {
        $lastDate = $db->query("SELECT DATE(MAX(bonus_date)) as last_date FROM daily_bonus WHERE user_id = $userId")->fetch();
        if ($lastDate) {
            $lastDateObj = new DateTime($lastDate['last_date']);
            $today = new DateTime();
            $diff = $today->diff($lastDateObj)->days;
            
            if ($diff == 1) {
                $streak = min($lastBonus['day_streak'] + 1, 7);
            }
        }
    }
    
    $pointsToAward = $bonusMap[$streak];
    
    $db->beginTransaction();
    try {
        // Registrar bono
        $db->prepare("
            INSERT INTO daily_bonus (user_id, bonus_date, day_streak, points_awarded, claimed)
            VALUES (?, CURDATE(), ?, ?, 1)
        ")->execute([$userId, $streak, $pointsToAward]);
        
        // Sumar puntos
        $db->prepare("UPDATE users SET points = points + ? WHERE id = ?")
           ->execute([$pointsToAward, $userId]);
        
        // Notificación
        $db->prepare("
            INSERT INTO notifications (user_id, type, content)
            VALUES (?, 'reward', ?)
        ")->execute([$userId, "¡Bono diario! +{$pointsToAward} pts (Racha: {$streak} días)"]);
        
        $db->commit();
        json_response([
            'success' => true,
            'points_awarded' => $pointsToAward,
            'streak' => $streak,
            'message' => "¡Reclamaste {$pointsToAward} puntos! Racha: {$streak} días"
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Error al reclamar bono'], 500);
    }
}