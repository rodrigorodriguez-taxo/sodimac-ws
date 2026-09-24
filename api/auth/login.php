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
    // debe remplazar por la segurdad de prepare y execute para evitar inyeccion sql
    $sql = "SELECT
        su.login AS login,
        ue.rut AS rut,
        ue.rut_normalizado AS rut_normalizado
    FROM sec_users AS su
    INNER JOIN sod_sec_usuario_ext AS ue
            ON CONVERT(su.login USING utf8mb4)
            COLLATE utf8mb4_unicode_ci = ue.login
    WHERE ue.rut_normalizado = :rut
    AND su.active = 'Y'
    AND ue.fl_activo = 'S'
    AND (
        ue.fecha_inicio_vigencia IS NULL
        OR ue.fecha_inicio_vigencia <= NOW()
        )
    AND (
        ue.fecha_fin_vigencia IS NULL
        OR ue.fecha_fin_vigencia >= NOW()
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

    okResponse([
        'user'  => [
            'correo'          => $user['login'],
            'rut'             => $user['rut'],
            'rut_normalizado' => $user['rut_normalizado'],
        ],
    ], 'Login exitoso');

} catch (PDOException $e) {
    errorResponse($e, 500);
    
}
