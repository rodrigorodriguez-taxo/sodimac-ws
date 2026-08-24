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
        'cargo'            => 'Operador de Inventario',
        'tipo_usuario'     => 'OPERADOR',
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
        'cargo'            => 'Operador de Inventario',
        'tipo_usuario'     => 'OPERADOR',
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
        'cargo'            => 'Operador de Inventario',
        'tipo_usuario'     => 'OPERADOR',
        'usuario_cliente'  => 'N',
        'autenticado'      => true,
    ],
    'analista@taxo.cl' => [
        'login'            => 'analista@taxo.cl',
        'rut'              => '22222222-2',
        'rut_normalizado'  => '222222222',
        'nombre_completo'  => 'Analista Sodimac',
        'nombres'          => 'Analista',
        'apellido_paterno' => 'Sodimac',
        'apellido_materno' => '',
        'cargo'            => 'Analista Cliente',
        'tipo_usuario'     => 'ANALISTA_CLIENTE',
        'usuario_cliente'  => 'S',
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

$data = prepararSincronizacion($usuario, $correo);

okResponse($data, 'Datos mockup preparados correctamente');

# ============================================================
# Funciones internas
# ============================================================

function prepararSincronizacion(array $usuario, string $correo): array {
    $base = prepararSincronizacionBase($usuario);

    if ($usuario['tipo_usuario'] === 'ANALISTA_CLIENTE') {
        return prepararSincronizacionAnalista($base, $correo);
    }

    return prepararSincronizacionOperador($base);
}

function prepararSincronizacionBase(array $usuario): array {
    return [
        'usuario' => $usuario,
        'tiendas' => [
            'id_tienda'      => 900001,
            'codigo_tienda'  => 'MOCK001',
            'nombre_tienda'  => 'Sodimac Mockup',
            'zona_operativa' => 'Mockup',
        ],
        'eventos' => [
            'sucursal_id'      => 900001,
            'fecha_programada' => date('d-m-Y'),
            'estado'           => 'ABIERTO',
        ],
    ];
}

function prepararSincronizacionOperador(array $base): array {
    $base['muestras'] = [
        'id_muestra'            => 900001,
        'codigo_muestra'        => 'MOCK-MUESTRA-DEV',
        'nombre_muestra'        => 'Muestra mockup APK',
        'fecha_inicio_vigencia' => date('Y-m-d'),
        'fecha_fin_vigencia'    => date('Y-m-d', strtotime('+30 days')),
    ];

    $base['productos'] = mockProductos();
    $base['zonas_tienda'] = mockZonas();

    return $base;
}

function prepararSincronizacionAnalista(array $base, string $correo): array {
    $codigoTienda  = $base['tiendas']['codigo_tienda'];
    $nombreTienda  = $base['tiendas']['nombre_tienda'];
    $codigoMuestra = 'M-' . date('Ymd') . '-' . $codigoTienda;
    $numeroAgenda  = 'AG-' . date('Ymd') . '-' . $codigoTienda . '-01';
    $idMuestra     = 900002;
    $idAgenda      = 910002;

    $base['muestras'] = [
        'id_muestra'            => $idMuestra,
        'id_agenda'             => $idAgenda,
        'codigo_muestra'        => $codigoMuestra,
        'nombre_muestra'        => 'Revisión Inventario Nacional',
        'numero_agenda'         => $numeroAgenda,
        'fecha_inicio_vigencia' => date('Y-m-d'),
        'fecha_fin_vigencia'    => date('Y-m-d', strtotime('+30 days')),
    ];

    $productos = mockProductosAnalista();
    $base['productos'] = $productos;

    $base['zonas_tienda'] = mockZonasAnalista();

    $filas = [];

    foreach ($productos as $p) {
        $sku       = $p['sku'];
        $desc      = $p['descripcion'];
        $stock     = $p['stock_sistema'];
        $contado   = $p['cantidad_contada'];
        $difUnid   = $contado - $stock;
        $precio    = $p['precio_unitario'];
        $difValor  = abs($difUnid) * $precio;

        $prioridad = 'BAJA';
        if (abs($difUnid) > 0 && abs($difUnid) <= 5) {
            $prioridad = 'MEDIA';
        } elseif (abs($difUnid) > 5) {
            $prioridad = 'ALTA';
        }

        $estado = 'SIN_DIFERENCIA';
        if ($difUnid !== 0) {
            $estado = abs($difUnid) > 5 ? 'CRITICA' : 'PENDIENTE';
        }

        $filas[] = [
            'sku'                => $sku,
            'descripcion'        => $desc,
            'codigo_barras'      => $p['codigo_barras'] ?? '',
            'zona'               => $p['zona'],
            'tag'                => $p['tag'],
            'stock_sistema'      => $stock,
            'cantidad_contada'   => $contado,
            'diferencia_unidades'=> $difUnid,
            'diferencia_valor'   => $difValor,
            'precio_unitario'    => $precio,
            'prioridad'          => $prioridad,
            'estado'             => $estado,
            'tags'               => $p['tags'] ?? [],
        ];
    }

    usort($filas, function ($a, $b) {
        $ordenPrioridad = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2];
        $pa = $ordenPrioridad[$a['prioridad']] ?? 3;
        $pb = $ordenPrioridad[$b['prioridad']] ?? 3;
        if ($pa !== $pb) return $pa - $pb;
        return abs($b['diferencia_unidades']) - abs($a['diferencia_unidades']);
    });

    $totalFilas = count($filas);
    $pendientes = 0;
    $criticas   = 0;
    $resueltas  = 0;
    $persisten  = 0;
    $valorTotal = 0;

    foreach ($filas as $f) {
        if ($f['diferencia_unidades'] !== 0) {
            $pendientes++;
            $valorTotal += $f['diferencia_valor'];
        }
        if ($f['estado'] === 'CRITICA') $criticas++;
        if ($f['estado'] === 'SIN_DIFERENCIA') $resueltas++;
        if ($f['estado'] === 'PENDIENTE') $persisten++;
    }

    $base['analista'] = [
        'contexto' => [
            'codigo_tienda'  => $codigoTienda,
            'nombre_tienda'  => $nombreTienda,
            'id_agenda'       => $idAgenda,
            'numero_agenda'   => $numeroAgenda,
            'codigo_muestra'  => $codigoMuestra,
            'nombre_muestra'  => 'Revisión Inventario Nacional',
            'fecha_jornada'   => date('Y-m-d'),
        ],
        'kpis' => [
            'diferencias_pendientes'  => $pendientes,
            'valor_diferencias'       => $valorTotal,
            'diferencias_criticas'    => $criticas,
            'reconteos_realizados'    => 0,
            'diferencias_resueltas'   => $resueltas,
            'persisten_con_diferencia'=> $persisten,
            'total_productos'         => $totalFilas,
        ],
        'filas' => $filas,
    ];

    return $base;
}

# ============================================================
# Datos mock: productos y zonas
# ============================================================

function mockProductos(): array {
    return [
        [
            'sku'           => 'AF000037001',
            'descripcion'   => 'Producto mockup 1',
            'stock_sistema' => 10,
            'codigos' => [
                ['codigo_lectura' => 'AF000037001', 'tipo_codigo' => 'SKU'],
                ['codigo_lectura' => '78000037001', 'tipo_codigo' => 'BARRA'],
            ],
        ],
        [
            'sku'           => 'AF000037002',
            'descripcion'   => 'Producto mockup 2',
            'stock_sistema' => 15,
            'codigos' => [
                ['codigo_lectura' => 'AF000037002', 'tipo_codigo' => 'SKU'],
                ['codigo_lectura' => '78000037002', 'tipo_codigo' => 'BARRA'],
            ],
        ],
        [
            'sku'           => 'AF000037003',
            'descripcion'   => 'Producto mockup 3',
            'stock_sistema' => 20,
            'codigos' => [
                ['codigo_lectura' => 'AF000037003', 'tipo_codigo' => 'SKU'],
                ['codigo_lectura' => '78000037003', 'tipo_codigo' => 'BARRA'],
            ],
        ],
        [
            'sku'           => 'AF000037004',
            'descripcion'   => 'Producto mockup 4',
            'stock_sistema' => 5,
            'codigos' => [
                ['codigo_lectura' => 'AF000037004', 'tipo_codigo' => 'SKU'],
                ['codigo_lectura' => '78000037004', 'tipo_codigo' => 'BARRA'],
            ],
        ],
        [
            'sku'           => 'AF000037005',
            'descripcion'   => 'Producto mockup 5',
            'stock_sistema' => 8,
            'codigos' => [
                ['codigo_lectura' => 'AF000037005', 'tipo_codigo' => 'SKU'],
                ['codigo_lectura' => '78000037005', 'tipo_codigo' => 'BARRA'],
            ],
        ],
    ];
}

function mockProductosAnalista(): array {
    $productos = [];
    $items = [
        ['sku' => '845921', 'desc' => 'Taladro inalámbrico 20V',   'barra' => '78000845921', 'stock' => 14, 'contado' => 2,  'precio' => 104000, 'zona' => 'Exhibición',        'tag' => 'R-EXH-0041', 'tags' => [['tag_codigo' => '41', 'ubicacion_codigo' => 'R-EXH-0041', 'zona_nombre' => 'EXHIBICION', 'zona_descripcion' => 'Exhibición', 'cantidad_operador' => 2], ['tag_codigo' => '42', 'ubicacion_codigo' => 'R-EXH-0042', 'zona_nombre' => 'EXHIBICION', 'zona_descripcion' => 'Exhibición', 'cantidad_operador' => 0]]],
        ['sku' => '778214', 'desc' => 'Juego comedor 6 sillas',     'barra' => '78000778214', 'stock' => 5,  'contado' => 1,  'precio' => 199990, 'zona' => 'Pasillo Hogar',     'tag' => 'TAG-044-021', 'tags' => [['tag_codigo' => '44021', 'ubicacion_codigo' => 'TAG-044-021', 'zona_nombre' => 'PUNTO_VENTA', 'zona_descripcion' => 'Pasillo 04 · Hogar', 'cantidad_operador' => 1], ['tag_codigo' => '44022', 'ubicacion_codigo' => 'TAG-044-022', 'zona_nombre' => 'PUNTO_VENTA', 'zona_descripcion' => 'Pasillo 04 · Hogar', 'cantidad_operador' => 0]]],
        ['sku' => '662180', 'desc' => 'Cerámica gris 60x60',        'barra' => '78000662180', 'stock' => 44, 'contado' => 28, 'precio' => 22990,  'zona' => 'Patio Constructor', 'tag' => 'TAG-PC-118', 'tags' => [['tag_codigo' => '118', 'ubicacion_codigo' => 'TAG-PC-118', 'zona_nombre' => 'OTRO', 'zona_descripcion' => 'Patio Constructor', 'cantidad_operador' => 28]]],
        ['sku' => '451902', 'desc' => 'Repisa mural 80 cm',         'barra' => '78000451902', 'stock' => 17, 'contado' => 9,  'precio' => 19990,  'zona' => 'Pasillo Hogar',     'tag' => 'TAG-044-035', 'tags' => [['tag_codigo' => '44035', 'ubicacion_codigo' => 'TAG-044-035', 'zona_nombre' => 'PUNTO_VENTA', 'zona_descripcion' => 'Pasillo 04 · Hogar', 'cantidad_operador' => 9]]],
        ['sku' => '310475', 'desc' => 'Lámpara colgante negra',     'barra' => '78000310475', 'stock' => 6,  'contado' => 6,  'precio' => 89990,  'zona' => 'Exhibición',        'tag' => 'R-EXH-0032', 'tags' => [['tag_codigo' => '32', 'ubicacion_codigo' => 'R-EXH-0032', 'zona_nombre' => 'EXHIBICION', 'zona_descripcion' => 'Exhibición', 'cantidad_operador' => 6]]],
        ['sku' => '520118', 'desc' => 'Pintura blanca 20L',         'barra' => '78000520118', 'stock' => 30, 'contado' => 22, 'precio' => 42990,  'zona' => 'Patio Constructor', 'tag' => 'TAG-PC-055', 'tags' => [['tag_codigo' => '55', 'ubicacion_codigo' => 'TAG-PC-055', 'zona_nombre' => 'OTRO', 'zona_descripcion' => 'Patio Constructor', 'cantidad_operador' => 22]]],
        ['sku' => '910334', 'desc' => 'Martillo 450g fibra',        'barra' => '78000910334', 'stock' => 12, 'contado' => 12, 'precio' => 12990,  'zona' => 'Herramientas',      'tag' => 'TAG-HERR-009', 'tags' => [['tag_codigo' => '9', 'ubicacion_codigo' => 'TAG-HERR-009', 'zona_nombre' => 'ALTILLO', 'zona_descripcion' => 'Herramientas', 'cantidad_operador' => 12]]],
        ['sku' => '680221', 'desc' => 'Cierre magnético 2 pack',    'barra' => '78000680221', 'stock' => 8,  'contado' => 3,  'precio' => 5990,   'zona' => 'Pasillo Hogar',     'tag' => 'TAG-044-102', 'tags' => [['tag_codigo' => '44102', 'ubicacion_codigo' => 'TAG-044-102', 'zona_nombre' => 'PUNTO_VENTA', 'zona_descripcion' => 'Pasillo 04 · Hogar', 'cantidad_operador' => 3]]],
        ['sku' => '410556', 'desc' => 'Lija grano 120 x10',         'barra' => '78000410556', 'stock' => 50, 'contado' => 48, 'precio' => 2990,   'zona' => 'Patio Constructor', 'tag' => 'TAG-PC-201', 'tags' => [['tag_codigo' => '201', 'ubicacion_codigo' => 'TAG-PC-201', 'zona_nombre' => 'OTRO', 'zona_descripcion' => 'Patio Constructor', 'cantidad_operador' => 48]]],
        ['sku' => '330777', 'desc' => 'Desmalezadora 2 tiempos',    'barra' => '78000330777', 'stock' => 3,  'contado' => 1,  'precio' => 289990, 'zona' => 'Jardín',            'tag' => 'TAG-JARD-003', 'tags' => [['tag_codigo' => '3', 'ubicacion_codigo' => 'TAG-JARD-003', 'zona_nombre' => 'OTRO', 'zona_descripcion' => 'Jardín', 'cantidad_operador' => 1]]],
        ['sku' => '750442', 'desc' => 'Manguera 3/4" x 30m',        'barra' => '78000750442', 'stock' => 7,  'contado' => 7,  'precio' => 34990,  'zona' => 'Jardín',            'tag' => 'TAG-JARD-010', 'tags' => [['tag_codigo' => '10', 'ubicacion_codigo' => 'TAG-JARD-010', 'zona_nombre' => 'OTRO', 'zona_descripcion' => 'Jardín', 'cantidad_operador' => 7]]],
        ['sku' => '220665', 'desc' => 'Tornillo M4 x 40 x100',     'barra' => '78000220665', 'stock' => 25, 'contado' => 20, 'precio' => 8990,   'zona' => 'Herramientas',      'tag' => 'TAG-HERR-044', 'tags' => [['tag_codigo' => '44', 'ubicacion_codigo' => 'TAG-HERR-044', 'zona_nombre' => 'ALTILLO', 'zona_descripcion' => 'Herramientas', 'cantidad_operador' => 20]]],
        ['sku' => '880119', 'desc' => 'Foco LED 9W pack x6',        'barra' => '78000880119', 'stock' => 15, 'contado' => 15, 'precio' => 11990,  'zona' => 'Pasillo Hogar',     'tag' => 'TAG-044-078', 'tags' => [['tag_codigo' => '44078', 'ubicacion_codigo' => 'TAG-044-078', 'zona_nombre' => 'PUNTO_VENTA', 'zona_descripcion' => 'Pasillo 04 · Hogar', 'cantidad_operador' => 15]]],
        ['sku' => '560883', 'desc' => 'Cinta metrica 5m',           'barra' => '78000560883', 'stock' => 20, 'contado' => 18, 'precio' => 4990,   'zona' => 'Herramientas',      'tag' => 'TAG-HERR-017', 'tags' => [['tag_codigo' => '17', 'ubicacion_codigo' => 'TAG-HERR-017', 'zona_nombre' => 'ALTILLO', 'zona_descripcion' => 'Herramientas', 'cantidad_operador' => 18]]],
        ['sku' => '140992', 'desc' => 'Taladro atornillador 12V',   'barra' => '78000140992', 'stock' => 6,  'contado' => 2,  'precio' => 79990,  'zona' => 'Herramientas',      'tag' => 'TAG-HERR-031', 'tags' => [['tag_codigo' => '31', 'ubicacion_codigo' => 'TAG-HERR-031', 'zona_nombre' => 'ALTILLO', 'zona_descripcion' => 'Herramientas', 'cantidad_operador' => 2]]],
    ];

    foreach ($items as $i) {
        $productos[] = [
            'sku'              => $i['sku'],
            'codigo_barras'    => $i['barra'],
            'descripcion'      => $i['desc'],
            'stock_sistema'    => $i['stock'],
            'cantidad_contada' => $i['contado'],
            'precio_unitario'  => $i['precio'],
            'zona'             => $i['zona'],
            'tag'              => $i['tag'],
            'tags'             => $i['tags'],
            'codigos' => [
                ['codigo_lectura' => $i['sku'], 'tipo_codigo' => 'SKU', 'codigo_barras' => $i['barra']],
            ],
        ];
    }

    return $productos;
}

function mockZonas(): array {
    return [
        ['ALTILLO',          'Altillo',                                  1000, 2999],
        ['PUNTO_VENTA',      'Punto de venta',                           3000, 4999],
        ['BODEGA',           'Zonas de remate / bodegas / trastienda',  5000, 5999],
        ['EXHIBICION',       'Exhibiciones',                             6000, 6999],
        ['OTRO',             'Pto. vta. otros',                          7000, 9999],
    ];
}

function mockZonasAnalista(): array {
    return [
        ['EXHIBICION',       'Exhibiciones',             6000, 6999],
        ['PASILLO_HOGAR',    'Pasillo Hogar',            3000, 3999],
        ['PATIO_CONSTRUCTOR','Patio Constructor',        4000, 4999],
        ['HERRAMIENTAS',     'Herramientas',             5000, 5499],
        ['JARDIN',           'Jardín',                   5500, 5999],
    ];
}
