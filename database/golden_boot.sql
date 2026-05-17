CREATE DATABASE IF NOT EXISTS golden_boot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE golden_boot;

-- USUARIOS
CREATE TABLE users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    email       VARCHAR(100) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    avatar      VARCHAR(255) DEFAULT 'default.png',
    status      ENUM('online','offline','away') DEFAULT 'offline',
    points      INT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- GRUPOS
CREATE TABLE groups_chat (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    avatar      VARCHAR(255) DEFAULT 'group_default.png',
    created_by  INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE group_members (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    group_id    INT NOT NULL,
    user_id     INT NOT NULL,
    role        ENUM('admin','member') DEFAULT 'member',
    joined_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_membership (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES groups_chat(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)       ON DELETE CASCADE
);

-- MENSAJES (privados y grupales)
CREATE TABLE messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sender_id       INT NOT NULL,
    -- Para mensajes privados:
    receiver_id     INT DEFAULT NULL,
    -- Para mensajes grupales:
    group_id        INT DEFAULT NULL,
    content         TEXT,
    file_path       VARCHAR(255) DEFAULT NULL,
    file_type       ENUM('image','audio','video','document','location') DEFAULT NULL,
    is_encrypted    TINYINT(1) DEFAULT 0,
    is_read         TINYINT(1) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id)   REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (group_id)    REFERENCES groups_chat(id) ON DELETE CASCADE
);

-- TAREAS
CREATE TABLE tasks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    group_id    INT NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    assigned_to INT DEFAULT NULL,
    created_by  INT NOT NULL,
    status      ENUM('pending','in_progress','done') DEFAULT 'pending',
    due_date    DATE DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id)    REFERENCES groups_chat(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id)       ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id)       ON DELETE CASCADE
);

-- RECOMPENSAS / BADGES
CREATE TABLE rewards (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    icon        VARCHAR(50)  NOT NULL,
    points_req  INT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_rewards (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    reward_id   INT NOT NULL,
    earned_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reward (user_id, reward_id),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (reward_id) REFERENCES rewards(id) ON DELETE CASCADE
);

-- LLAMADAS / VIDEOLLAMADAS
CREATE TABLE calls (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    caller_id   INT NOT NULL,
    receiver_id INT NOT NULL,
    status      ENUM('calling','active','ended','missed') DEFAULT 'calling',
    started_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at    TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (caller_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

-- NOTIFICACIONES
CREATE TABLE notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    type        ENUM('message','task','reward','call') NOT NULL,
    content     VARCHAR(255),
    is_read     TINYINT(1) DEFAULT 0,
    reference_id INT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Recompensas base
-- Se pueden cambiar los icons con esta página :D
-- https://getemoji.com/#activities
INSERT INTO rewards (name, description, icon, points_req) VALUES
('Primer Mensaje',   'Enviaste tu primer mensaje',              '💬', 1),
('Comunicador',      'Enviaste 50 mensajes',                    '🗣️', 50),
('Líder de equipo',  'Creaste tu primer grupo',                 '👑', 1),
('Tarea completada', 'Completaste tu primera tarea',            '✅', 1),
('Streak 7 días',    'Conectado 7 días seguidos',               '🔥', 7),
('Archivero',        'Compartiste 10 archivos',                 '📁', 10),
('Videollamador',    'Realizaste tu primera videollamada',      '📹', 1),
('MVP',              'Acumulaste 500 puntos',                   '🏆', 500);

ALTER TABLE calls ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER status;
ALTER TABLE calls ADD COLUMN call_duration INT DEFAULT 0 AFTER ended_at;

-- =====================================================
-- SISTEMA DE RECOMPENSAS GOLDEN BOOT - FIFA 2026
-- =====================================================

-- 1. STICKERS (calcomanías para enviar en chats)
CREATE TABLE IF NOT EXISTS stickers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) NOT NULL,
    description TEXT,
    price INT DEFAULT 100,
    city VARCHAR(50) DEFAULT 'todas',
    rarity ENUM('comun', 'raro', 'epico', 'legendario') DEFAULT 'comun',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. INSIGNIAS (badges por logros)
CREATE TABLE IF NOT EXISTS badges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) NOT NULL,
    description TEXT,
    min_points INT DEFAULT 0,
    requirement_type ENUM('points', 'messages', 'images', 'groups_joined', 'tasks_completed') DEFAULT 'points',
    requirement_value INT DEFAULT 0,
    city VARCHAR(50) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. INVENTARIO DE STICKERS DEL USUARIO
CREATE TABLE IF NOT EXISTS user_stickers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    sticker_id INT NOT NULL,
    acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_count INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sticker_id) REFERENCES stickers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_sticker (user_id, sticker_id)
);

-- 4. INSIGNIAS OBTENIDAS POR USUARIOS
CREATE TABLE IF NOT EXISTS user_badges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    badge_id INT NOT NULL,
    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_badge (user_id, badge_id)
);

-- 5. TIENDA DE RECOMPENSAS CANJEABLES
CREATE TABLE IF NOT EXISTS shop_rewards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) NOT NULL,
    description TEXT,
    cost INT NOT NULL,
    reward_type ENUM('sticker', 'badge', 'effect', 'avatar_frame', 'chat_bubble') DEFAULT 'sticker',
    reward_id INT,
    duration_days INT DEFAULT NULL,
    available BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. COMPRAS/HISTORIAL DEL USUARIO
CREATE TABLE IF NOT EXISTS user_purchases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    reward_id INT NOT NULL,
    purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reward_id) REFERENCES shop_rewards(id) ON DELETE CASCADE
);

-- 7. EVENTOS POR CIUDAD (FIFA 2026)
CREATE TABLE IF NOT EXISTS city_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    city VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    required_tasks TEXT,
    reward_badge_id INT,
    reward_points INT DEFAULT 100,
    start_date DATE,
    end_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (reward_badge_id) REFERENCES badges(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. PROGRESO DE EVENTOS POR USUARIO
CREATE TABLE IF NOT EXISTS user_event_progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    event_id INT NOT NULL,
    tasks_completed TEXT,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES city_events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_event (user_id, event_id)
);

-- 9. BONOS DIARIOS
CREATE TABLE IF NOT EXISTS daily_bonus (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    bonus_date DATE NOT NULL,
    day_streak INT DEFAULT 1,
    points_awarded INT NOT NULL,
    claimed BOOLEAN DEFAULT FALSE,
    UNIQUE KEY unique_user_date (user_id, bonus_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =====================================================
-- DATOS INICIALES (Stickers y Badges con temática FIFA 2026)
-- =====================================================

-- Insertar STICKERS
INSERT INTO stickers (name, icon, description, price, city, rarity) VALUES
('Estadio BBVA', '🏟️', 'Monterrey - Rayados', 150, 'mty', 'raro'),
('Estadio Akron', '🏟️', 'Guadalajara - Chivas', 150, 'gdl', 'raro'),
('Estadio Azteca', '🏟️', 'CDMX - La casa del fútbol', 150, 'cdmx', 'raro'),
('Águila Azteca', '🦅', 'Orgullo mexicano', 100, 'cdmx', 'epico'),
('Charro Regio', '🤠', 'Monterrey tradición', 80, 'mty', 'comun'),
('Mariachi Tapatío', '🎺', 'Guadalajara tradición', 80, 'gdl', 'comun'),
('Taco Gol', '🌮', 'Gol de taquito', 50, 'todas', 'comun'),
('Piñata Mundialista', '🎉', 'Celebra a lo mexicano', 120, 'todas', 'raro'),
('Chile Picante', '🌶️', 'Jugador caliente', 60, 'todas', 'comun'),
('Cabra Legendaria', '🐐', 'Greatest Of All Time', 200, 'todas', 'legendario');

-- Insertar BADGES / INSIGNIAS
INSERT INTO badges (name, icon, description, min_points, requirement_type, requirement_value, city) VALUES
('Aficionado', '🥉', 'Comienza tu aventura', 0, 'points', 0, NULL),
('Jugador Destacado', '🥈', '¡Eres todo un crack!', 500, 'points', 500, NULL),
('Figura Mundialista', '🥇', 'Estrella del FIFA 2026', 1500, 'points', 1500, NULL),
('Leyenda del FIFA 2026', '🏆', 'Solo para los mejores', 3500, 'points', 3500, NULL),
('Charro Regio', '🐎', 'Monterrey en tu corazón', 0, 'city_mty', 1, 'mty'),
('Tapatío de Corazón', '🌮', 'Guadalajara te abraza', 0, 'city_gdl', 1, 'gdl'),
('Azteca de Oro', '🦅', 'Orgullo de la CDMX', 0, 'city_cdmx', 1, 'cdmx'),
('GoLEADOR', '⚽', '+500 mensajes enviados', 0, 'messages', 500, NULL),
('Rey de Reyes', '👑', 'Top 1 del ranking', 0, 'points', 5000, NULL);

-- Insertar EVENTOS POR CIUDAD
INSERT INTO city_events (city, name, description, icon, required_tasks, reward_badge_id, reward_points) VALUES
('mty', 'Ruta Regia', 'Conquista Monterrey', '🐎', 
 '["Visitar el Cerro de la Silla", "Comer una orden de carnitas", "Tomar una cervecita en Barrio Antiguo", "Asistir a un partido en el BBVA"]',
 (SELECT id FROM badges WHERE name = 'Charro Regio'), 200),
 
('gdl', 'Ruta Tapatía', 'Descubre Guadalajara', '🌮',
 '["Comer una torta ahogada", "Visitar el Hospicio Cabañas", "Ir al Tequila Express", "Cantar con mariachis en Plaza de los Mariachis"]',
 (SELECT id FROM badges WHERE name = 'Tapatío de Corazón'), 200),
 
('cdmx', 'Ruta Azteca', 'Explora la CDMX', '🦅',
 '["Subir al Ángel de la Independencia", "Comer unos churros en El Moro", "Ir a Xochimilco", "Asistir al Estadio Azteca"]',
 (SELECT id FROM badges WHERE name = 'Azteca de Oro'), 200);

-- Insertar TIENDA DE RECOMPENSAS
INSERT INTO shop_rewards (name, icon, description, cost, reward_type, reward_id, duration_days) VALUES
('✨ Nombre Dorado', '✨', 'Tu nombre brillará en dorado por 7 días', 300, 'effect', NULL, 7),
('🇲🇽 Avatar FIFA 2026', '🇲🇽', 'Marco especial de selección mexicana por 7 días', 500, 'avatar_frame', NULL, 7),
('💬 Burbuja Estadio', '🏟️', 'Burbuja de chat con diseño de estadio por 7 días', 400, 'chat_bubble', NULL, 7),
('🦅 Insignia Selección', '🦅', 'Badge permanente de la selección mexicana', 1000, 'badge', (SELECT id FROM badges WHERE name = 'Azteca de Oro'), NULL);