CREATE DATABASE poi;
USE poi;

-- Tabla de usuarios
CREATE TABLE usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    monedas INT DEFAULT 0,
    nivel_boost INT DEFAULT 1,
    avatar_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de grupos
CREATE TABLE grupos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    creador_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creador_id) REFERENCES usuarios(id)
);

-- Tabla de miembros de grupo
CREATE TABLE grupo_miembros (
    grupo_id INT,
    usuario_id INT,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (grupo_id, usuario_id),
    FOREIGN KEY (grupo_id) REFERENCES grupos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- Tabla de tareas
CREATE TABLE tareas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    titulo VARCHAR(200) NOT NULL,
    descripcion TEXT,
    completada BOOLEAN DEFAULT FALSE,
    usuario_id INT,
    grupo_id INT,
    especial BOOLEAN DEFAULT FALSE,
    nivel_requerido INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (grupo_id) REFERENCES grupos(id)
);

-- Tabla de mensajes
CREATE TABLE mensajes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contenido TEXT,
    tipo ENUM('texto', 'imagen', 'audio', 'archivo') DEFAULT 'texto',
    archivo_url VARCHAR(255),
    usuario_id INT,
    grupo_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (grupo_id) REFERENCES grupos(id)
);

-- Tabla de recompensas
CREATE TABLE recompensas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT,
    tipo ENUM('boost', 'fondo', 'sticker') NOT NULL,
    nivel INT DEFAULT 1,
    desbloqueado BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- Tabla de transacciones de monedas
CREATE TABLE transacciones_monedas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT,
    cantidad INT NOT NULL,
    tipo ENUM('ganancia', 'gasto') NOT NULL,
    descripcion VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);