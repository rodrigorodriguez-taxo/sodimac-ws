<?php
# ============================================================
# ws/api/auth/login.php
# POST { rut: "12345678-5", password: "123456" }
# Contrato alineado a main (correo, rut, rut_normalizado).
# Query sin sec_users por ahora — ver Finding JWT (pendiente).
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
        rut_normalizado
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
