<?php
# ============================================================
# ws/api/auth/login_mockup.php
# POST { rut: "12345678-5", password: "123456" }
# Mockup controlado — no toca DB real.
# ============================================================

require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['rut'])) {
    errorResponse('Falta rut');
}

if (empty($input['password'])) {
    errorResponse('Falta password');
}

$rut = preg_replace('/[^0-9kK]/', '', trim($input['rut']));
$rutBody = substr($rut, 0, -1);
$rutDv = strtoupper(substr($rut, -1));

if (strlen($rutBody) < 7 || strlen($rutBody) > 8) {
    errorResponse('RUT invalido');
}

$expected = getDefaultPassword($rut);
if ($input['password'] !== $expected) {
    errorResponse('Contraseña incorrecta');
}

$usuarios = [
    '175340777' => [
        'correo'          => 'rodrigo.rodriguez@sodimac.cl',
        'rut'             => '17534077-7',
        'rut_normalizado' => '175340777',
        'rol'             => 'OPERADOR',
    ],
    '111111111' => [
        'correo'          => 'operador@taxo.cl',
        'rut'             => '11111111-1',
        'rut_normalizado' => '111111111',
        'rol'             => 'OPERADOR',
    ],
    '998000025' => [
        'correo'          => 'seigi.gim@taxo.cl',
        'rut'             => '99800002-5',
        'rut_normalizado' => '998000025',
        'rol'             => 'OPERADOR',
    ],
    '222222222' => [
        'correo'          => 'analista@taxo.cl',
        'rut'             => '22222222-2',
        'rut_normalizado' => '222222222',
        'rol'             => 'ANALISTA_CLIENTE',
    ],
];

if (!isset($usuarios[$rut])) {
    errorResponse('Usuario no permitido en mockup', 401);
}

$user = $usuarios[$rut];

okResponse([
    'user' => [
        'correo'          => $user['correo'],
        'rut'             => $user['rut'],
        'rut_normalizado' => $user['rut_normalizado'],
    ],
], 'Login mockup exitoso');
