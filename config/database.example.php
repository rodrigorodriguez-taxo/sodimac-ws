<?php
# ============================================================
# ws/config/database.example.php
# Copia este archivo a database.php y completa tus credenciales
# Conexion PDO a la BD remota
# ============================================================

$host = 'TU_HOST';
$db   = 'TU_BD';
$user = 'TU_USUARIO';
$pass = 'TU_PASSWORD';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['status' => 'ERROR', 'msg' => 'Error de conexion a BD']));
}
