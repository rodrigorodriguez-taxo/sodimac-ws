<?php
# ============================================================
# ws/api/tablet/agendas_mockup.php
# GET ?rut_normalizado=175340777
# Mockup controlado — retorna agendas de prueba.
# ============================================================

require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$rutNormalizado = $_GET['rut_normalizado'] ?? '';

if (empty($rutNormalizado)) {
    errorResponse('Falta rut_normalizado');
}

$agendas = [
    [
        'id_agenda'        => 1,
        'fecha'            => '2026-09-15',
        'hora_inicio'      => '09:00',
        'hora_fin'         => '12:00',
        'tienda'           => 'Sodimac Valparaiso',
        'direccion'        => 'Av. España 1234, Valparaiso',
        'estado'           => 'PENDIENTE',
        'auditor_nombre'   => 'Rodrigo Rodriguez',
        'auditor_rut'      => '175340777',
        'total_skus'       => 45,
        'skus_contados'    => 0,
    ],
    [
        'id_agenda'        => 2,
        'fecha'            => '2026-09-15',
        'hora_inicio'      => '14:00',
        'hora_fin'         => '17:00',
        'tienda'           => 'Sodimac Viña del Mar',
        'direccion'        => 'Av. Libertad 5678, Viña del Mar',
        'estado'           => 'EN_CURSO',
        'auditor_nombre'   => 'Rodrigo Rodriguez',
        'auditor_rut'      => '175340777',
        'total_skus'       => 32,
        'skus_contados'    => 15,
    ],
    [
        'id_agenda'        => 3,
        'fecha'            => '2026-09-16',
        'hora_inicio'      => '10:00',
        'hora_fin'         => '13:00',
        'tienda'           => 'Sodimac Quilpue',
        'direccion'        => 'Av. Matta 901, Quilpue',
        'estado'           => 'PENDIENTE',
        'auditor_nombre'   => 'Rodrigo Rodriguez',
        'auditor_rut'      => '175340777',
        'total_skus'       => 28,
        'skus_contados'    => 0,
    ],
];

okResponse([
    'agendas' => $agendas,
], 'Agendas mockup cargadas');
