<?php
# ============================================================
# ws/api/tablet/sync_mockup.php
# POST { id_agenda, items: [{ id_item, stock_fisico, foto_url }] }
# Mockup controlado — simula sincronización de conteo.
# ============================================================

require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['id_agenda'])) {
    errorResponse('Falta id_agenda');
}

if (empty($input['items']) || !is_array($input['items'])) {
    errorResponse('Falta items o no es array');
}

$resultado = [
    'id_agenda'     => $input['id_agenda'],
    'items_recibidos' => count($input['items']),
    'items_procesados' => 0,
    'items_con_error'  => 0,
    'detalles' => [],
];

foreach ($input['items'] as $item) {
    if (empty($item['id_item'])) {
        $resultado['items_con_error']++;
        $resultado['detalles'][] = [
            'id_item' => null,
            'status'  => 'ERROR',
            'msg'     => 'Falta id_item',
        ];
        continue;
    }

    if (!isset($item['stock_fisico']) || !is_numeric($item['stock_fisico'])) {
        $resultado['items_con_error']++;
        $resultado['detalles'][] = [
            'id_item' => $item['id_item'],
            'status'  => 'ERROR',
            'msg'     => 'stock_fisico invalido',
        ];
        continue;
    }

    $resultado['items_procesados']++;
    $resultado['detalles'][] = [
        'id_item' => $item['id_item'],
        'status'  => 'OK',
        'msg'     => 'Conteo sincronizado',
    ];
}

okResponse($resultado, 'Sincronización mockup completada');
