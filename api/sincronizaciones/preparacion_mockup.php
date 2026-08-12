<?php
# ============================================================
# ws/api/sincronizaciones/preparacion_mockup.php
# POST { correo: "...", rut: "..." }
# Mockup controlado — no toca DB real.
# ============================================================

require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

$correo = trim($input['correo'] ?? '');
$rut    = trim($input['rut'] ?? '');

if ($correo === '') {
    errorResponse('Falta correo');
}

if ($rut === '') {
    errorResponse('Falta rut');
}

$usuarios = [
    'rodrigo.rodriguez@sodimac.cl' => [
        'login'            => 'rodrigo.rodriguez@sodimac.cl',
        'rut'              => '17534077-7',
        'rut_normalizado'  => '175340777',
        'nombre_completo'  => 'Rodrigo Rodriguez',
        'nombres'          => 'Rodrigo',
        'apellido_paterno' => 'Rodriguez',
        'apellido_materno' => '',
        'cargo'            => 'Operador',
        'tipo_usuario'     => 'operador',
        'usuario_cliente'  => 'N',
        'autenticado'      => true,
    ],
    'operador@taxo.cl' => [
        'login'            => 'operador@taxo.cl',
        'rut'              => '11111111-1',
        'rut_normalizado'  => '111111111',
        'nombre_completo'  => 'Operador Taxo',
        'nombres'          => 'Operador',
        'apellido_paterno' => 'Taxo',
        'apellido_materno' => '',
        'cargo'            => 'Operador',
        'tipo_usuario'     => 'operador',
        'usuario_cliente'  => 'N',
        'autenticado'      => true,
    ],
    'seigi.gim@taxo.cl' => [
        'login'            => 'seigi.gim@taxo.cl',
        'rut'              => '99800002-5',
        'rut_normalizado'  => '998000025',
        'nombre_completo'  => 'Seigi Gim',
        'nombres'          => 'Seigi',
        'apellido_paterno' => 'Gim',
        'apellido_materno' => '',
        'cargo'            => 'Operador',
        'tipo_usuario'     => 'operador',
        'usuario_cliente'  => 'N',
        'autenticado'      => true,
    ],
];

if (!isset($usuarios[$correo])) {
    errorResponse('Usuario no permitido en mockup', 401);
}

$usuario = $usuarios[$correo];

if ($usuario['rut_normalizado'] !== preg_replace('/[^0-9]/', '', $rut)) {
    errorResponse('RUT no coincide con el correo', 401);
}

$data = [
    'usuario' => $usuario,

    'tiendas' => [
        'id_tienda'      => 900001,
        'codigo_tienda'  => 'MOCK001',
        'nombre_tienda'  => 'Sodimac Mockup',
        'zona_operativa' => 'Mockup',
    ],

    'muestras' => [
        'id_muestra'            => 900001,
        'codigo_muestra'        => 'MOCK-MUESTRA-DEV',
        'nombre_muestra'        => 'Muestra mockup APK',
        'fecha_inicio_vigencia' => date('Y-m-d'),
        'fecha_fin_vigencia'    => date('Y-m-d', strtotime('+30 days')),
    ],

    'eventos' => [
        'sucursal_id'      => 900001,
        'fecha_programada' => date('d-m-Y'),
        'estado'           => 'ABIERTO',
    ],

    'productos' => [
        ['sku' => 'AF000037001'],
        ['sku' => 'AF000037002'],
        ['sku' => 'AF000037003'],
        ['sku' => 'AF000037004'],
        ['sku' => 'AF000037005'],
    ],

    'zonas_tienda' => [
        ['VENTA',     'Piso de venta y gancheras'],
        ['ALTILLO',   'Altillos y storage superior'],
        ['BODEGA',    'Bodega y trastienda'],
        ['RECEPCION', 'Zona de recepcion'],
    ],
];

okResponse($data, 'Datos mockup preparados correctamente');
