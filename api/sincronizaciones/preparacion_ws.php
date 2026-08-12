<?php

/**
 * preparacion_ws.php — Endpoint de preparación para modo develop_ws.
 *
 * Reutiliza la lógica real de usuario y tienda, pero devuelve productos,
 * muestras y eventos mock para que la APK pueda trabajar aunque la vista
 * vw_sod_tienda_muestra_efectiva no tenga datos.
 */

require_once '../../config/database.php';
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

try {
    $data = prepararSincronizacionMock($pdo, $correo, $rut);
    okResponse($data, 'Datos preparados correctamente (mock)');
} catch (PDOException $e) {
    errorResponse('Error: ' . $e->getMessage(), 500);
}

function prepararSincronizacionMock(PDO $pdo, string $correo, string $rut): array
{
    $usuario = obtenerUsuario($pdo, $correo, $rut);
    if (!$usuario) {
        errorResponse('Usuario no existe o inactivo', 401);
    }

    $tiendas = obtenerTiendas($pdo, $correo);
    if (!$tiendas) {
        errorResponse('No existen tiendas asignadas', 401);
    }

    $tienda = current($tiendas);

    $productos = array_map(function ($i) {
        $num = str_pad(37001 + $i, 5, '0', STR_PAD_LEFT);
        return [
            'id_muestra_det' => 900001 + $i,
            'id_producto'    => 900001 + $i,
            'sku'            => "AF0000{$num}",
            'codigo_barras'  => "780000{$num}",
            'descripcion'    => "Producto mock " . ($i + 1),
            'stock_sistema'  => ($i + 1) * 5,
        ];
    }, range(0, 4));

    return [
        'usuario'    => $usuario,
        'tiendas'    => $tienda,
        'muestras'   => [
            'id_muestra'             => 900001,
            'codigo_muestra'         => 'MOCK-MUESTRA-DEV',
            'nombre_muestra'         => 'Muestra mock develop_ws',
            'fecha_inicio_vigencia'  => date('Y-m-d'),
            'fecha_fin_vigencia'     => date('Y-m-d', strtotime('+30 days')),
        ],
        'eventos'    => [
            'sucursal_id'       => $tienda['id_tienda'],
            'fecha_programada'  => date('d-m-Y'),
            'estado'            => 'ABIERTO',
        ],
        'productos'  => $productos,
        'zonas_tienda' => [
            ['VENTA',     'Piso de venta y gancheras'],
            ['ALTILLO',   'Altillos y storage superior'],
            ['BODEGA',    'Bodega y trastienda'],
            ['RECEPCION', 'Zona de recepcion'],
        ],
    ];
}

function obtenerUsuario(PDO $pdo, string $correo, string $rut): ?array
{
    $stmt = $pdo->prepare("SELECT
        su.login                        AS login,
        ue.rut                          AS rut,
        ue.rut_normalizado              AS rut_normalizado,
        CONCAT_WS(' ',
            NULLIF(TRIM(ue.nombres), ''),
            NULLIF(TRIM(ue.apellido_paterno), ''),
            NULLIF(TRIM(ue.apellido_materno), '')
        )                               AS nombre_completo,
        ue.nombres                      AS nombres,
        ue.apellido_paterno             AS apellido_paterno,
        ue.apellido_materno             AS apellido_materno,
        ue.cargo                        AS cargo,
        ue.tipo_usuario                 AS tipo_usuario,
        ue.fl_usuario_cliente           AS usuario_cliente
    FROM sec_users AS su
    INNER JOIN sod_sec_usuario_ext AS ue
            ON CONVERT(su.login USING utf8mb4) COLLATE utf8mb4_unicode_ci = ue.login
    WHERE (
            CONVERT(TRIM(su.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(:login_1) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR
            CONVERT(TRIM(su.email) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(:login_2) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        )
    AND ue.rut_normalizado = :rut
    AND su.active = 'Y'
    AND ue.fl_activo = 'S'
    AND (ue.fecha_inicio_vigencia IS NULL OR ue.fecha_inicio_vigencia <= NOW())
    AND (ue.fecha_fin_vigencia IS NULL OR ue.fecha_fin_vigencia >= NOW())
    LIMIT 1");

    $stmt->execute([':login_1' => $correo, ':login_2' => $correo, ':rut' => $rut]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return null;

    return [
        'login'            => $user['login'],
        'rut'              => $user['rut'],
        'rut_normalizado'  => $user['rut_normalizado'],
        'nombre_completo'  => $user['nombre_completo'],
        'nombres'          => $user['nombres'],
        'apellido_paterno' => $user['apellido_paterno'],
        'apellido_materno' => $user['apellido_materno'],
        'cargo'            => $user['cargo'],
        'tipo_usuario'     => $user['tipo_usuario'],
        'usuario_cliente'  => $user['usuario_cliente'],
        'autenticado'      => true,
    ];
}

function obtenerTiendas(PDO $pdo, string $correo): ?array
{
    $stmt = $pdo->prepare("SELECT
        id_tienda, codigo_tienda, nombre_tienda,
        id_zona_operativa, codigo_zona, nombre_zona
    FROM vw_sod_usuario_tienda_resumen
    WHERE login = :login
        AND estado_operativo = 'VIGENTE'
        AND fl_usuario_vigente = 'S'
    ORDER BY fl_principal DESC");

    $stmt->execute([':login' => $correo]);
    $tiendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$tiendas) return null;

    return array_map(function ($t) {
        return [
            'id_tienda'      => $t['id_tienda'],
            'codigo_tienda'  => $t['codigo_tienda'],
            'nombre_tienda'  => $t['nombre_tienda'],
            'zona_operativa' => $t['nombre_zona'],
        ];
    }, $tiendas);
}
