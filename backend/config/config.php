<?php

//   backend/config/config.php
//   Configuración central del proyecto

// Base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'golden_boot');
define('DB_USER', 'root');
define('DB_PASS', ''); 
define('DB_CHARSET', 'utf8mb4');

// Aplicación
define('APP_NAME', 'Golden Boot');
define('APP_URL',  'http://localhost/Progra_Orientada_Internet/frontend');
define('UPLOAD_DIR', __DIR__ . '/../../frontend/uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024);

// Sesiones
define('JWT_SECRET', 'gb_secret_2024_change_in_production');
define('SESSION_LIFETIME', 86400); // 24h en segundos

// WebSocket
define('WS_HOST', '0.0.0.0');
define('WS_PORT', 8080);

// Email (PHPMailer / SMTP) NO MOVER, NO TOCAR HASTA AÚN EN PRUEBA 
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'aqua.azul10@gmail.com');
define('MAIL_PASSWORD', 'plppqdxsppmgguxf');
define('MAIL_FROM',     'aqua.azul10@gmail.com');
define('MAIL_FROM_NAME', APP_NAME);

// CORS (para ngrok)
$allowed_origins = [
    'http://localhost',
    'http://localhost:3000',
];

// Helpers globales 
function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}