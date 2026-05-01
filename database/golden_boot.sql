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

-- Usuario demo (password: 123456  → en producción usar password_hash)
INSERT INTO users (username, email, password, points) VALUES
('demo_user', 'demo@goldenboot.app', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uJDL53pJ2', 0);