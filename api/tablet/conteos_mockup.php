<?php
# ============================================================
# ws/api/tablet/conteos_mockup.php
# GET ?id_agenda=1
# Mockup controlado — retorna items de conteo de prueba.
# ============================================================

require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$idAgenda = $_GET['id_agenda'] ?? '';

if (empty($idAgenda)) {
    errorResponse('Falta id_agenda');
}

$conteos = [
    '1' => [
        'agenda' => [
            'id_agenda'   => 1,
            'tienda'      => 'Sodimac Valparaiso',
            'fecha'       => '2026-09-15',
            'estado'      => 'PENDIENTE',
        ],
        'items' => [
            [
                'id_item'       => 1,
                'codigo_sku'    => 'SKU-001',
                'nombre'        => 'Martillo 16oz',
                'categoria'     => 'Herramientas',
                'ubicacion'     => 'Pasillo 3 - Anaquel 2',
                'stock_sistema' => 15,
                'stock_fisico'  => null,
                'estado'        => 'PENDIENTE',
            ],
            [
                'id_item'       => 2,
                'codigo_sku'    => 'SKU-002',
                'nombre'        => 'Destornillador Phillips',
                'categoria'     => 'Herramientas',
                'ubicacion'     => 'Pasillo 3 - Anaquel 3',
                'stock_sistema' => 23,
                'stock_fisico'  => null,
                'estado'        => 'PENDIENTE',
            ],
            [
                'id_item'       => 3,
                'codigo_sku'    => 'SKU-003',
                'nombre'        => 'Cinta Métrica 5m',
                'categoria'     => 'Herramientas',
                'ubicacion'     => 'Pasillo 3 - Anaquel 1',
                'stock_sistema' => 30,
                'stock_fisico'  => null,
                'estado'        => 'PENDIENTE',
            ],
        ],
    ],
    '2' => [
        'agenda' => [
            'id_agenda'   => 2,
            'tienda'      => 'Sodimac Viña del Mar',
            'fecha'       => '2026-09-15',
            'estado'      => 'EN_CURSO',
        ],
        'items' => [
            [
                'id_item'       => 4,
                'codigo_sku'    => 'SKU-010',
                'nombre'        => 'Pintura Blanca 1L',
                'categoria'     => 'Pinturas',
                'ubicacion'     => 'Pasillo 7 - Anaquel 1',
                'stock_sistema' => 40,
                'stock_fisico'  => 38,
                'estado'        => 'CONTADO',
            ],
            [
                'id_item'       => 5,
                'codigo_sku'    => 'SKU-011',
                'nombre'        => 'Rodillo 23cm',
                'categoria'     => 'Pinturas',
                'ubicacion'     => 'Pasillo 7 - Anaquel 2',
                'stock_sistema' => 12,
                'stock_fisico'  => 12,
                'estado'        => 'CONTADO',
            ],
        ],
    ],
];

if (!isset($conteos[$idAgenda])) {
    errorResponse('Agenda no encontrada', 404);
}

okResponse($conteos[$idAgenda], 'Conteos mockup cargados');
