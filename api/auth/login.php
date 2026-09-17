<?php
# ============================================================
# ws/api/auth/login.php
# POST { rut: "12345678-5", password: "123456" }
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['rut'])) {
    errorResponse('Falta rut');
}

$rut = preg_replace('/[^0-9kK]/', '', trim($input['rut']));
$rutBody = substr($rut, 0, -1);
$rutDv = strtoupper(substr($rut, -1));

if (strlen($rutBody) < 7 || strlen($rutBody) > 8) {
    errorResponse('RUT invalido');
}

if (empty($input['password'])) {
    errorResponse('Falta password');
}

$expected = getDefaultPassword($rut);
if ($input['password'] !== $expected) {
    errorResponse('Contraseña incorrecta');
}

try {
    $sql = "SELECT
        login,
        rut,
        rut_normalizado,
        nombres,
        apellido_paterno,
        apellido_materno,
        tipo_usuario,
        email
    FROM sod_sec_usuario_ext
    WHERE rut_normalizado = :rut
    AND fl_activo = 'S'
    AND (
        fecha_inicio_vigencia IS NULL
        OR fecha_inicio_vigencia <= NOW()
        )
    AND (
        fecha_fin_vigencia IS NULL
        OR fecha_fin_vigencia >= NOW()
        )
    LIMIT 1";

    $params = [
        ':rut' => $rut,
    ];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // $queryFinal = debugQuery($sql, $params);
    // echo '<pre>';var_dump($queryFinal);exit;
    
    $user = $stmt->fetch();
    

    if (!$user) {
        errorResponse('Usuario no existe o inactivo', 401);
    }

    $nombre = trim(($user['nombres'] ?? '') . ' ' . ($user['apellido_paterno'] ?? ''));

    okResponse([
        'user'  => [
            'correo'          => $user['email'] ?? $user['login'],
            'rut'             => $user['rut'],
            'rut_normalizado' => $user['rut_normalizado'],
            'nombre'          => $nombre,
            'perfil'          => $user['tipo_usuario'],
        ],
    ], 'Login exitoso');

} catch (PDOException $e) {
    errorResponse($e, 500);
    
}
