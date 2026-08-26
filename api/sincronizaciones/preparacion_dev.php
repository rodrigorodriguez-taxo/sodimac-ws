<?php

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
    $data = prepararSincronizacion($pdo, $correo, $rut);
    okResponse($data, 'Datos preparados correctamente');
} catch (PDOException $e) {
    errorResponse('Error: ' . $e->getMessage(), 500);
}

/* ============================================================================
   Flujo V3 — Contrato Sincronización Auditor V6
   ============================================================================
   1. validar usuario          -> obtenerUsuario()
   2. tiendas autorizadas      -> obtenerTiendas()          (maestro)
   3. agenda lista del dia     -> obtenerAgendas()          (V3.0)
   4. productos/codigos        -> obtenerCodigosAgenda()    (V4.0 optimizado)
      4a. resolverAgendaMuestra()  (Paso A — autorizar + resolver id_muestra)
      4b. obtenerCodigosMuestra()  (Paso B — descarga por id_muestra)
   5. agrupar por producto     -> agruparProductosConCodigos()
   6. responder contrato APK
   ============================================================================ */

function prepararSincronizacion(PDO $pdo, string $correo, string $rut): array
{
    /* --- 1. validar usuario ------------------------------------------------- */
    $usuario = obtenerUsuario($pdo, $correo, $rut);
    if (!$usuario) {
        errorResponse('Usuario no existe o inactivo', 401);
    }

    $login = $usuario['login'];

    /* --- 2. tiendas autorizadas (maestro) ----------------------------------- */
    $tiendas = obtenerTiendas($pdo, $login);
    if (!$tiendas) {
        errorResponse('No existen tiendas asignadas', 401);
    }

    /* --- 3. agenda lista del dia -------------------------------------------- */
    $fecha = date('Y-m-d');
    $agendas = obtenerAgendas($pdo, $login, $fecha);
    if (!$agendas) {
        $agendas = obtenerAgendasFallbackPrueba($pdo, $login, $fecha);
    }
    if (!$agendas) {
        errorResponse('No existen agendas listas para hoy', 401);
    }

    $agendaSeleccionada = current($agendas);

    /* --- 4. separar por perfil ---------------------------------------------- */
    if (($usuario['tipo_usuario'] ?? '') === 'ANALISTA_CLIENTE') {
        return prepararSincronizacionAnalistaDev($pdo, $usuario, $tiendas, $agendaSeleccionada);
    }

    return prepararSincronizacionOperadorDev($pdo, $usuario, $tiendas, $agendaSeleccionada);
}

/* ============================================================================
   Flujo OPERADOR — extraído de prepararSincronizacion()
   Contrato APK invariado: usuario, tiendas, muestras, eventos, productos,
   zonas_tienda.
   ============================================================================ */

function prepararSincronizacionOperadorDev(PDO $pdo, array $usuario, array $tiendas, array $agendaSeleccionada): array
{
    $login = $usuario['login'];

    /* --- 4. productos/codigos de la agenda ---------------------------------- */
    $filasCodigos = obtenerCodigosAgenda($pdo, (int) $agendaSeleccionada['id_agenda'], $login);
    if (!$filasCodigos) {
        errorResponse('No existen productos para la agenda seleccionada', 401);
    }

    /* --- 5. agrupar productos ----------------------------------------------- */
    $productos = agruparProductosConCodigos($filasCodigos);

    /* --- 6. construir muestra y evento desde agenda ------------------------- */
    $muestras = construirMuestraDesdeAgenda($agendaSeleccionada);
    $eventos  = construirEventoDesdeAgenda($agendaSeleccionada, current($tiendas));

    /* --- 7. responder contrato APK ------------------------------------------ */
    return [
        'usuario'       => $usuario,
        'tiendas'       => $tiendas,
        'muestras'      => $muestras,
        'eventos'       => $eventos,
        'productos'     => $productos,
        'zonas_tienda'  => obtenerZonasTiendaDefault(),
    ];
}

/* ============================================================================
   Flujo ANALISTA_CLIENTE — contrato liviano A0.5
   No descarga muestras ni productos completos. Devuelve solo contexto,
   conteos y validación operacional (Altillos/PDV/etc).
   ============================================================================ */

function prepararSincronizacionAnalistaDev(PDO $pdo, array $usuario, array $tiendas, array $agendaSeleccionada): array
{
    /* --- 4. construir evento desde agenda ------------------------------------ */
    $eventos = construirEventoDesdeAgenda($agendaSeleccionada, current($tiendas));

    /* --- 5. resolver conteos ------------------------------------------------- */
    $idAgenda = (int) $agendaSeleccionada['id_agenda'];
    $conteos  = obtenerConteosAnalistaDev($pdo, $idAgenda);

    /* --- 6. altillos lectura ------------------------------------------------- */
    $altillos = obtenerAltillosAnalistaDev($pdo, $idAgenda, $conteos['id_conteo_1'], $conteos['id_conteo_2']);

    /* --- 7. punto de venta lectura ------------------------------------------- */
    $puntoVenta = obtenerPuntoVentaAnalistaDev($pdo, $idAgenda, $conteos['id_conteo_1'], $conteos['id_conteo_2']);

    /* --- 8. pre variance lectura --------------------------------------------- */
    $idKardex = $agendaSeleccionada['id_kardex'] !== null ? (int) $agendaSeleccionada['id_kardex'] : null;
    $preVariance = obtenerPreVarianceAnalistaDev($pdo, $idAgenda, $idKardex, $conteos['id_conteo_1'], $conteos['id_conteo_2']);

    /* --- 9. recuento lectura ------------------------------------------------- */
    $recuento = obtenerRecuentoAnalistaDev($pdo, $idAgenda, $idKardex, $conteos['id_conteo_1'], $conteos['id_conteo_2'], $conteos['id_conteo_3']);

    /* --- 10. contexto analista ----------------------------------------------- */
    $tiendaPrincipal = current($tiendas);

    return [
        'usuario'       => $usuario,
        'tiendas'       => $tiendas,
        'muestras'      => null,
        'eventos'       => $eventos,
        'productos'     => [],
        'zonas_tienda'  => obtenerZonasTiendaDefault(),

        'analista' => [
            'contexto' => [
                'codigo_tienda'  => $agendaSeleccionada['codigo_tienda'] ?? $tiendaPrincipal['codigo_tienda'] ?? '',
                'nombre_tienda'  => $agendaSeleccionada['nombre_tienda'] ?? $tiendaPrincipal['nombre_tienda'] ?? '',
                'id_agenda'      => $idAgenda,
                'numero_agenda'  => $agendaSeleccionada['numero_agenda'] ?? '',
                'codigo_muestra' => $agendaSeleccionada['codigo_muestra'] ?? '',
                'nombre_muestra' => $agendaSeleccionada['nombre_muestra'] ?? '',
                'fecha_jornada'  => $agendaSeleccionada['fecha_agenda'] ?? date('Y-m-d'),
                'id_kardex'      => $idKardex,
            ],
            'kpis' => [
                'diferencias_pendientes'   => 0,
                'valor_diferencias'        => 0,
                'diferencias_criticas'     => 0,
                'reconteos_realizados'     => 0,
                'diferencias_resueltas'    => 0,
                'persisten_con_diferencia' => 0,
                'total_productos'          => 0,
            ],
            'filas' => [],

            'conteos' => $conteos,

            'validacion_operacional' => [
                'altillos'      => $altillos,
                'punto_venta'   => $puntoVenta,
                'pre_variance'  => $preVariance,
                'recuento'      => $recuento,
            ],
        ],
    ];
}

/* ============================================================================
   Helper: zonas TAG default (mismo array que siempre devuelve la preparación)
   ============================================================================ */

function obtenerZonasTiendaDefault(): array
{
    return array(
        0 => array('ALTILLO',      'Altillo',                            1000, 2999),
        1 => array('PUNTO_VENTA',  'Punto de venta',                     3000, 4999),
        2 => array('BODEGA',       'Zonas de remate / bodegas / trastienda', 5000, 5999),
        3 => array('EXHIBICION',   'Exhibiciones',                       6000, 6999),
        4 => array('OTRO',         'Pto. vta. otros',                    7000, 9999),
    );
}

/* ============================================================================
   Q03 — Resolver Conteo 1, Conteo 2 y Conteo 3
   ============================================================================ */

function obtenerConteosAnalistaDev(PDO $pdo, int $idAgenda): array
{
    $sql = "SELECT
        MAX(
            CASE
                WHEN numero_iteracion = 1
                 AND tipo_conteo = 'INICIAL'
                THEN id_conteo
            END
        ) AS id_conteo_1,
        MAX(
            CASE
                WHEN numero_iteracion = 2
                 AND tipo_conteo = 'VALIDACION'
                THEN id_conteo
            END
        ) AS id_conteo_2,
        MAX(
            CASE
                WHEN numero_iteracion = 3
                 AND tipo_conteo = 'RECONTEO'
                THEN id_conteo
            END
        ) AS id_conteo_3
    FROM sod_inv_conteo
    WHERE id_agenda = :id_agenda
      AND fl_activo = 'S'
      AND estado_conteo <> 'ANULADO'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_agenda' => $idAgenda]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'id_conteo_1' => $row['id_conteo_1'] !== null ? (int) $row['id_conteo_1'] : null,
        'id_conteo_2' => $row['id_conteo_2'] !== null ? (int) $row['id_conteo_2'] : null,
        'id_conteo_3' => $row['id_conteo_3'] !== null ? (int) $row['id_conteo_3'] : null,
    ];
}

/* ============================================================================
   Validación por zona — punto de entrada genérico
   ============================================================================ */

function obtenerAltillosAnalistaDev(PDO $pdo, int $idAgenda, ?int $idConteo1, ?int $idConteo2): array
{
    return obtenerValidacionZonaAnalistaDev($pdo, $idAgenda, $idConteo1, $idConteo2, 'ALTILLO', 'Altillo', 100);
}

function obtenerPuntoVentaAnalistaDev(PDO $pdo, int $idAgenda, ?int $idConteo1, ?int $idConteo2): array
{
    return obtenerValidacionZonaAnalistaDev($pdo, $idAgenda, $idConteo1, $idConteo2, 'PUNTO_VENTA', 'Punto de venta', 30);
}

function obtenerValidacionZonaAnalistaDev(PDO $pdo, int $idAgenda, ?int $idConteo1, ?int $idConteo2, string $codigoZona, string $nombreZona, int $objetivoPorcentaje): array
{
    if ($idConteo1 === null) {
        return obtenerValidacionZonaVacioAnalistaDev($codigoZona, $nombreZona, $objetivoPorcentaje);
    }

    $c1 = $idConteo1;
    $c2 = $idConteo2 ?? 0;

    $dataset = obtenerDatasetValidacionZonaAnalistaDev($pdo, $idAgenda, $c1, $c2, $codigoZona);
    $avance  = obtenerAvanceValidacionZonaAnalistaDev($pdo, $idAgenda, $c1, $c2, $codigoZona);

    return construirValidacionZonaAnalistaDev($dataset, $avance, $codigoZona, $nombreZona, $objetivoPorcentaje);
}

function obtenerValidacionZonaVacioAnalistaDev(string $codigoZona, string $nombreZona, int $objetivoPorcentaje): array
{
    return [
        'resumen' => [
            'codigo_zona'         => $codigoZona,
            'nombre_zona'         => $nombreZona,
            'objetivo_porcentaje' => $objetivoPorcentaje,
            'tags_usados'         => 0,
            'tags_confirmados'    => 0,
            'tags_pendientes'     => 0,
            'porcentaje'          => 0,
            'cumple'              => false,
        ],
        'tags'      => [],
        'productos' => [],
    ];
}

/* ============================================================================
   Q11 — Dataset operacional por zona (filtro parametrizable)
   ============================================================================ */

function obtenerDatasetValidacionZonaAnalistaDev(PDO $pdo, int $idAgenda, int $idConteo1, int $idConteo2, string $codigoZona): array
{
    $sql = "SELECT
        t.id_tag,
        t.numero_tag,
        t.id_tipo_ubicacion,
        COALESCE(rz.codigo_zona_reporte, tu.codigo_tipo_ubicacion, '') AS codigo_zona,
        COALESCE(rz.nombre_zona_reporte, tu.nombre_tipo_ubicacion, 'Sin zona') AS nombre_zona,
        p.id_producto,
        p.sku,
        p.descripcion_producto,
        COALESCE(op.cantidad_c2, c1.cantidad_c1, 0) AS cantidad_inventariada,
        CASE
            WHEN op.cantidad_c2 IS NULL THEN 'PENDIENTE'
            ELSE 'CONFIRMADO'
        END AS estado_validacion,
        CASE
            WHEN c1.id_producto IS NULL THEN 'S'
            ELSE 'N'
        END AS fl_incorporado
    FROM (
        SELECT id_tag, id_producto
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_conteo_1
          AND estado_registro = 'VIGENTE'
        UNION
        SELECT id_tag, id_producto
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_conteo_2
          AND id_reconteo IS NULL
          AND origen = 'SGO_ANALISTA'
          AND estado_registro = 'VIGENTE'
    ) AS u
    INNER JOIN sod_inv_tag AS t
            ON t.id_tag = u.id_tag
           AND t.id_agenda = :id_agenda_tag
    INNER JOIN sod_cfg_producto AS p
            ON p.id_producto = u.id_producto
           AND p.fl_activo = 'S'
    INNER JOIN sod_inv_agenda_muestra AS am
            ON am.id_agenda = :id_agenda_muestra
           AND am.fl_activo = 'S'
    INNER JOIN sod_inv_muestra_det AS md
            ON md.id_muestra = am.id_muestra
           AND md.id_producto = p.id_producto
           AND md.fl_activo = 'S'
    LEFT JOIN sod_cfg_tipo_ubicacion AS tu
           ON tu.id_tipo_ubicacion = t.id_tipo_ubicacion
    LEFT JOIN sod_rep_zona_tag_regla AS rz
           ON rz.fl_activo = 'S'
          AND t.numero_tag BETWEEN rz.tag_desde AND rz.tag_hasta
    LEFT JOIN (
        SELECT id_tag, id_producto, SUM(cantidad) AS cantidad_c1
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_conteo_1_c1
          AND estado_registro = 'VIGENTE'
        GROUP BY id_tag, id_producto
    ) AS c1
           ON c1.id_tag = u.id_tag
          AND c1.id_producto = u.id_producto
    LEFT JOIN (
        SELECT id_tag, id_producto, SUM(cantidad) AS cantidad_c2
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_conteo_2_op
          AND id_reconteo IS NULL
          AND origen = 'SGO_ANALISTA'
          AND estado_registro = 'VIGENTE'
        GROUP BY id_tag, id_producto
    ) AS op
           ON op.id_tag = u.id_tag
          AND op.id_producto = u.id_producto
    WHERE (
        UPPER(COALESCE(rz.codigo_zona_reporte, tu.codigo_tipo_ubicacion, '')) LIKE :zona_filtro_1
        OR UPPER(COALESCE(rz.nombre_zona_reporte, tu.nombre_tipo_ubicacion, 'Sin zona')) LIKE :zona_filtro_2
    )
    ORDER BY COALESCE(rz.orden_visual, tu.orden_visual, 999), t.numero_tag, p.sku";

    $zonaFiltro = '%' . strtoupper($codigoZona) . '%';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_conteo_1'       => $idConteo1,
        ':id_conteo_2'       => $idConteo2,
        ':id_agenda_tag'     => $idAgenda,
        ':id_agenda_muestra' => $idAgenda,
        ':id_conteo_1_c1'    => $idConteo1,
        ':id_conteo_2_op'    => $idConteo2,
        ':zona_filtro_1'     => $zonaFiltro,
        ':zona_filtro_2'     => $zonaFiltro,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ============================================================================
   Q12 — Avance por zona (filtro parametrizable)
   ============================================================================ */

function obtenerAvanceValidacionZonaAnalistaDev(PDO $pdo, int $idAgenda, int $idConteo1, int $idConteo2, string $codigoZona): array
{
    $sql = "SELECT
        x.codigo_zona,
        x.nombre_zona,
        COUNT(*) AS tags_usados,
        SUM(x.tag_confirmado) AS tags_confirmados,
        COUNT(*) - SUM(x.tag_confirmado) AS tags_pendientes,
        ROUND(SUM(x.tag_confirmado) * 100 / NULLIF(COUNT(*), 0), 0) AS porcentaje
    FROM (
        SELECT
            t.id_tag,
            COALESCE(rz.codigo_zona_reporte, tu.codigo_tipo_ubicacion, '') AS codigo_zona,
            COALESCE(rz.nombre_zona_reporte, tu.nombre_tipo_ubicacion, 'Sin zona') AS nombre_zona,
            CASE
                WHEN SUM(CASE WHEN op.id_producto IS NULL THEN 0 ELSE 1 END) = COUNT(*)
                THEN 1
                ELSE 0
            END AS tag_confirmado
        FROM (
            SELECT DISTINCT id_tag, id_producto
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_1
              AND estado_registro = 'VIGENTE'
        ) AS u
        INNER JOIN sod_inv_tag AS t
                ON t.id_tag = u.id_tag
               AND t.id_agenda = :id_agenda
        LEFT JOIN sod_cfg_tipo_ubicacion AS tu
               ON tu.id_tipo_ubicacion = t.id_tipo_ubicacion
        LEFT JOIN sod_rep_zona_tag_regla AS rz
               ON rz.fl_activo = 'S'
              AND t.numero_tag BETWEEN rz.tag_desde AND rz.tag_hasta
        LEFT JOIN (
            SELECT DISTINCT id_tag, id_producto
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_2
              AND id_reconteo IS NULL
              AND origen = 'SGO_ANALISTA'
              AND estado_registro = 'VIGENTE'
        ) AS op
               ON op.id_tag = u.id_tag
              AND op.id_producto = u.id_producto
        WHERE (
            UPPER(COALESCE(rz.codigo_zona_reporte, tu.codigo_tipo_ubicacion, '')) LIKE :zona_filtro_1
            OR UPPER(COALESCE(rz.nombre_zona_reporte, tu.nombre_tipo_ubicacion, 'Sin zona')) LIKE :zona_filtro_2
        )
        GROUP BY
            t.id_tag,
            COALESCE(rz.codigo_zona_reporte, tu.codigo_tipo_ubicacion, ''),
            COALESCE(rz.nombre_zona_reporte, tu.nombre_tipo_ubicacion, 'Sin zona')
    ) AS x
    GROUP BY x.codigo_zona, x.nombre_zona
    ORDER BY x.codigo_zona";

    $zonaFiltro = '%' . strtoupper($codigoZona) . '%';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_conteo_1'  => $idConteo1,
        ':id_conteo_2'  => $idConteo2,
        ':id_agenda'    => $idAgenda,
        ':zona_filtro_1' => $zonaFiltro,
        ':zona_filtro_2' => $zonaFiltro,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [];
    }

    return [
        'codigo_zona'      => $row['codigo_zona'],
        'nombre_zona'      => $row['nombre_zona'],
        'tags_usados'      => (int) $row['tags_usados'],
        'tags_confirmados' => (int) $row['tags_confirmados'],
        'tags_pendientes'  => (int) $row['tags_pendientes'],
        'porcentaje'       => (int) $row['porcentaje'],
    ];
}

/* ============================================================================
   Construir estructura de validación desde dataset + avance
   ============================================================================ */

function construirValidacionZonaAnalistaDev(array $dataset, array $avance, string $codigoZona, string $nombreZona, int $objetivoPorcentaje): array
{
    $resultado = obtenerValidacionZonaVacioAnalistaDev($codigoZona, $nombreZona, $objetivoPorcentaje);

    /* --- resumen desde avance ------------------------------------------------ */
    if (!empty($avance)) {
        $resultado['resumen']['tags_usados']      = $avance['tags_usados'];
        $resultado['resumen']['tags_confirmados']  = $avance['tags_confirmados'];
        $resultado['resumen']['tags_pendientes']   = $avance['tags_pendientes'];
        $resultado['resumen']['porcentaje']        = $avance['porcentaje'];
        $resultado['resumen']['cumple']            = $avance['tags_usados'] > 0
            && $avance['porcentaje'] >= $objetivoPorcentaje;
    }

    if (empty($dataset)) {
        return $resultado;
    }

    /* --- productos ----------------------------------------------------------- */
    $productos = [];
    foreach ($dataset as $fila) {
        $productos[] = [
            'id_tag'               => (int) $fila['id_tag'],
            'numero_tag'           => (int) $fila['numero_tag'],
            'id_tipo_ubicacion'    => $fila['id_tipo_ubicacion'] ?? null,
            'codigo_zona'          => $fila['codigo_zona'] ?? '',
            'nombre_zona'          => $fila['nombre_zona'] ?? '',
            'id_producto'          => (int) $fila['id_producto'],
            'sku'                  => $fila['sku'] ?? '',
            'descripcion_producto' => $fila['descripcion_producto'] ?? '',
            'cantidad_inventariada' => (float) $fila['cantidad_inventariada'],
            'estado_validacion'    => $fila['estado_validacion'] ?? 'PENDIENTE',
            'fl_incorporado'       => $fila['fl_incorporado'] ?? 'N',
        ];
    }
    $resultado['productos'] = $productos;

    /* --- tags agrupados ------------------------------------------------------ */
    $tagsMap = [];
    foreach ($productos as $p) {
        $tagId = $p['id_tag'];
        if (!isset($tagsMap[$tagId])) {
            $tagsMap[$tagId] = [
                'id_tag'               => $p['id_tag'],
                'numero_tag'           => $p['numero_tag'],
                'codigo_zona'          => $p['codigo_zona'],
                'nombre_zona'          => $p['nombre_zona'],
                'productos_total'      => 0,
                'productos_confirmados' => 0,
                'productos_pendientes' => 0,
                'estado'               => 'PENDIENTE',
            ];
        }
        $tagsMap[$tagId]['productos_total']++;
        if ($p['estado_validacion'] === 'CONFIRMADO') {
            $tagsMap[$tagId]['productos_confirmados']++;
        } else {
            $tagsMap[$tagId]['productos_pendientes']++;
        }
    }

    foreach ($tagsMap as &$tag) {
        if ($tag['productos_total'] > 0 && $tag['productos_confirmados'] === $tag['productos_total']) {
            $tag['estado'] = 'CONFIRMADO';
        }
    }
    unset($tag);

    $resultado['tags'] = array_values($tagsMap);

    return $resultado;
}

/* ============================================================================
   Pre Variance — punto de entrada
   ============================================================================ */

function obtenerPreVarianceAnalistaDev(PDO $pdo, int $idAgenda, ?int $idKardex, ?int $idConteo1, ?int $idConteo2): array
{
    if ($idKardex === null || $idConteo1 === null) {
        return obtenerPreVarianceVacioAnalistaDev();
    }

    $c1 = $idConteo1;
    $c2 = $idConteo2 ?? 0;

    $listado = obtenerListadoPreVarianceDev($pdo, $idAgenda, $idKardex, $c1, $c2);

    // Obtener ubicaciones de todos los SKUs en una sola query
    $todosLosSkuIds = array_column($listado, 'id_producto');
    $todasLasUbicaciones = [];
    if (!empty($todosLosSkuIds)) {
        $todasLasUbicaciones = obtenerUbicacionesMultiplesPreVarianceDev($pdo, $idAgenda, $todosLosSkuIds, $c1, $c2);
    }

    // Agrupar ubicaciones por SKU
    $ubicacionesPorSku = [];
    foreach ($todasLasUbicaciones as $u) {
        $skuId = $u['id_producto'];
        if (!isset($ubicacionesPorSku[$skuId])) {
            $ubicacionesPorSku[$skuId] = [];
        }
        $ubicacionesPorSku[$skuId][] = [
            'id_tag_backend'        => $u['id_tag'],
            'numero_tag'            => $u['numero_tag'],
            'zona'                  => $u['zona'],
            'cantidad_inventariada' => (float) $u['cantidad_inventariada'],
            'cantidad_pre_variance' => $u['cantidad_pre_variance'] !== null ? (float) $u['cantidad_pre_variance'] : null,
        ];
    }

    // Construir productos con sus ubicaciones
    $productos = [];
    foreach ($listado as $fila) {
        $skuId = (int) $fila['id_producto'];
        $productos[] = [
            'id_producto_backend'          => $skuId,
            'sku'                          => $fila['sku'] ?? '',
            'descripcion'                  => $fila['descripcion_producto'] ?? null,
            'stock_teorico'                => (float) ($fila['stock_teorico'] ?? 0),
            'valor_unitario'               => (float) ($fila['valor_unitario'] ?? 0),
            'inventariado_antes_pre_variance' => (float) ($fila['inventariado_antes_pre_variance'] ?? 0),
            'fisico_vigente'               => (float) ($fila['fisico_vigente'] ?? 0),
            'diferencia_unidades'          => (float) ($fila['diferencia_unidades'] ?? 0),
            'diferencia_en_costo'          => (float) ($fila['diferencia_en_costo'] ?? 0),
            'estado_pre_variance'          => $fila['estado_pre_variance'] ?? 'PENDIENTE',
            'ubicaciones'                  => $ubicacionesPorSku[$skuId] ?? [],
        ];
    }

    // Resumen
    $totalSku = count($productos);
    $skuPendientes = 0;
    $skuRevisados = 0;
    $diferenciaTotal = 0.0;
    $skuConMayorDiferencia = null;
    $mayorDiferencia = 0.0;

    foreach ($productos as $p) {
        if ($p['estado_pre_variance'] === 'REVISADO') {
            $skuRevisados++;
        } else {
            $skuPendientes++;
        }
        $diferenciaTotal += $p['diferencia_en_costo'];
        if (abs($p['diferencia_en_costo']) > abs($mayorDiferencia)) {
            $mayorDiferencia = $p['diferencia_en_costo'];
            $skuConMayorDiferencia = $p;
        }
    }

    return [
        'resumen' => [
            'sku_total'               => $totalSku,
            'sku_pendientes'          => $skuPendientes,
            'sku_revisados'           => $skuRevisados,
            'diferencia_total'        => $diferenciaTotal,
            'mayor_diferencia_valor'  => $mayorDiferencia,
            'mayor_diferencia_sku'    => $skuConMayorDiferencia ? $skuConMayorDiferencia['sku'] : null,
            'mayor_diferencia_descripcion' => $skuConMayorDiferencia ? $skuConMayorDiferencia['descripcion'] : null,
        ],
        'productos' => $productos,
    ];
}

function obtenerPreVarianceVacioAnalistaDev(): array
{
    return [
        'resumen' => [
            'sku_total'               => 0,
            'sku_pendientes'          => 0,
            'sku_revisados'           => 0,
            'diferencia_total'        => 0,
            'mayor_diferencia_valor'  => 0,
            'mayor_diferencia_sku'    => null,
            'mayor_diferencia_descripcion' => null,
        ],
        'productos' => [],
    ];
}

/* ============================================================================
   Q15 — Listado Pre Variance (diferencia valorizada absoluta > 500000)
   ============================================================================ */

function obtenerListadoPreVarianceDev(PDO $pdo, int $idAgenda, int $idKardex, int $idConteo1, int $idConteo2): array
{
    $sql = "SELECT
        p.id_producto,
        p.sku,
        p.descripcion_producto,
        kd.stock_teorico,
        kd.valor_unitario,
        COALESCE(base.fisico_operacional, 0) AS inventariado_antes_pre_variance,
        COALESCE(pv.cantidad_pre_variance, base.fisico_operacional, 0) AS fisico_vigente,
        (
            COALESCE(pv.cantidad_pre_variance, base.fisico_operacional, 0)
            - COALESCE(kd.stock_teorico, 0)
        ) AS diferencia_unidades,
        (
            (
                COALESCE(pv.cantidad_pre_variance, base.fisico_operacional, 0)
                - COALESCE(kd.stock_teorico, 0)
            )
            * COALESCE(kd.valor_unitario, 0)
        ) AS diferencia_en_costo,
        CASE
            WHEN pv.id_producto IS NULL THEN 'PENDIENTE'
            ELSE 'REVISADO'
        END AS estado_pre_variance
    FROM sod_inv_agenda_muestra AS am
    INNER JOIN sod_inv_muestra_det AS md
            ON md.id_muestra = am.id_muestra
           AND md.fl_activo = 'S'
    INNER JOIN sod_cfg_producto AS p
            ON p.id_producto = md.id_producto
           AND p.fl_activo = 'S'
    INNER JOIN sod_inv_kardex_det AS kd
            ON kd.id_kardex = :id_kardex
           AND kd.id_producto = p.id_producto
    LEFT JOIN (
        SELECT
            u.id_producto,
            SUM(COALESCE(op.cantidad_c2, c1.cantidad_c1, 0)) AS fisico_operacional
        FROM (
            SELECT id_producto, id_tag
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_1
              AND estado_registro = 'VIGENTE'
            UNION
            SELECT id_producto, id_tag
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_2
              AND id_reconteo IS NULL
              AND origen = 'SGO_ANALISTA'
              AND estado_registro = 'VIGENTE'
        ) AS u
        LEFT JOIN (
            SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c1
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_1_c1
              AND estado_registro = 'VIGENTE'
            GROUP BY id_producto, id_tag
        ) AS c1 ON c1.id_producto = u.id_producto AND c1.id_tag = u.id_tag
        LEFT JOIN (
            SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c2
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_2_op
              AND id_reconteo IS NULL
              AND origen = 'SGO_ANALISTA'
              AND estado_registro = 'VIGENTE'
            GROUP BY id_producto, id_tag
        ) AS op ON op.id_producto = u.id_producto AND op.id_tag = u.id_tag
        GROUP BY u.id_producto
    ) AS base ON base.id_producto = p.id_producto
    LEFT JOIN (
        SELECT id_producto, SUM(cantidad) AS cantidad_pre_variance
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_conteo_2_pv
          AND id_reconteo IS NULL
          AND origen = 'SGO_PREVARIANCE'
          AND estado_registro = 'VIGENTE'
        GROUP BY id_producto
    ) AS pv ON pv.id_producto = p.id_producto
    WHERE am.id_agenda = :id_agenda
      AND am.fl_activo = 'S'
      AND (
            ABS(
                (COALESCE(base.fisico_operacional, 0) - COALESCE(kd.stock_teorico, 0))
                * COALESCE(kd.valor_unitario, 0)
            ) > 500000
            OR pv.id_producto IS NOT NULL
      )
    ORDER BY
        ABS(
            (COALESCE(pv.cantidad_pre_variance, base.fisico_operacional, 0) - COALESCE(kd.stock_teorico, 0))
            * COALESCE(kd.valor_unitario, 0)
        ) DESC,
        p.sku";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_agenda'      => $idAgenda,
        ':id_kardex'      => $idKardex,
        ':id_conteo_1'    => $idConteo1,
        ':id_conteo_2'    => $idConteo2,
        ':id_conteo_1_c1' => $idConteo1,
        ':id_conteo_2_op' => $idConteo2,
        ':id_conteo_2_pv' => $idConteo2,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ============================================================================
   Q16 — Ubicaciones de múltiples SKUs en Pre Variance
   ============================================================================ */

function obtenerUbicacionesMultiplesPreVarianceDev(PDO $pdo, int $idAgenda, array $skuIds, int $idConteo1, int $idConteo2): array
{
    if (empty($skuIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($skuIds), '?'));

    $sql = "SELECT
        u.id_producto,
        t.id_tag,
        t.numero_tag,
        COALESCE(rz.nombre_zona_reporte, tu.nombre_tipo_ubicacion, 'Sin zona') AS zona,
        COALESCE(op.cantidad_c2, c1.cantidad_c1, 0) AS cantidad_inventariada,
        pv.cantidad_pre_variance
    FROM (
        SELECT id_producto, id_tag
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND id_producto IN ($placeholders)
          AND estado_registro = 'VIGENTE'
        UNION
        SELECT id_producto, id_tag
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND id_producto IN ($placeholders)
          AND id_reconteo IS NULL
          AND origen = 'SGO_ANALISTA'
          AND estado_registro = 'VIGENTE'
    ) AS u
    INNER JOIN sod_inv_tag AS t
            ON t.id_tag = u.id_tag
           AND t.id_agenda = ?
    LEFT JOIN sod_cfg_tipo_ubicacion AS tu
           ON tu.id_tipo_ubicacion = t.id_tipo_ubicacion
    LEFT JOIN sod_rep_zona_tag_regla AS rz
           ON rz.fl_activo = 'S'
          AND t.numero_tag BETWEEN rz.tag_desde AND rz.tag_hasta
    LEFT JOIN (
        SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c1
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND estado_registro = 'VIGENTE'
        GROUP BY id_producto, id_tag
    ) AS c1 ON c1.id_producto = u.id_producto AND c1.id_tag = u.id_tag
    LEFT JOIN (
        SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c2
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND id_reconteo IS NULL
          AND origen = 'SGO_ANALISTA'
          AND estado_registro = 'VIGENTE'
        GROUP BY id_producto, id_tag
    ) AS op ON op.id_producto = u.id_producto AND op.id_tag = u.id_tag
    LEFT JOIN (
        SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_pre_variance
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND id_reconteo IS NULL
          AND origen = 'SGO_PREVARIANCE'
          AND estado_registro = 'VIGENTE'
        GROUP BY id_producto, id_tag
    ) AS pv ON pv.id_producto = u.id_producto AND pv.id_tag = u.id_tag
    ORDER BY u.id_producto, t.numero_tag";

    // Build params: C1, SKU1..SKUn, C2, SKU1..SKUn, agenda, C1_ubic, C2_ubic, C2_pv
    $params = [];
    $params[] = $idConteo1;                     // id_conteo_1 (UNION 1)
    foreach ($skuIds as $id) { $params[] = $id; }
    $params[] = $idConteo2;                     // id_conteo_2 (UNION 2)
    foreach ($skuIds as $id) { $params[] = $id; }
    $params[] = $idAgenda;                      // t.id_agenda
    $params[] = $idConteo1;                     // c1 subquery
    $params[] = $idConteo2;                     // op subquery
    $params[] = $idConteo2;                     // pv subquery

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ============================================================================
   Recuento — punto de entrada
   ============================================================================ */

function obtenerRecuentoAnalistaDev(PDO $pdo, int $idAgenda, ?int $idKardex, ?int $idConteo1, ?int $idConteo2, ?int $idConteo3): array
{
    if ($idKardex === null || $idConteo1 === null) {
        return obtenerRecuentoVacioAnalistaDev();
    }

    $c1 = $idConteo1;
    $c2 = $idConteo2 ?? 0;
    $c3 = $idConteo3 ?? 0;

    $listado = obtenerListadoRecuentoDev($pdo, $idAgenda, $idKardex, $c1, $c2, $c3);

    // Obtener ubicaciones de todos los SKUs en una sola query
    $todosLosSkuIds = array_column($listado, 'id_producto');
    $todasLasUbicaciones = [];
    if (!empty($todosLosSkuIds)) {
        $todasLasUbicaciones = obtenerUbicacionesMultiplesRecuentoDev($pdo, $idAgenda, $todosLosSkuIds, $c1, $c2, $c3);
    }

    // Agrupar ubicaciones por SKU
    $ubicacionesPorSku = [];
    foreach ($todasLasUbicaciones as $u) {
        $skuId = $u['id_producto'];
        if (!isset($ubicacionesPorSku[$skuId])) {
            $ubicacionesPorSku[$skuId] = [];
        }
        $ubicacionesPorSku[$skuId][] = [
            'id_tag_backend'         => $u['id_tag'],
            'numero_tag'             => $u['numero_tag'],
            'zona'                   => $u['zona'],
            'cantidad_inventariada'  => (float) $u['cantidad_inventariada'],
            'cantidad_recuento'      => $u['cantidad_recuento'] !== null ? (float) $u['cantidad_recuento'] : null,
        ];
    }

    // Construir productos con sus ubicaciones
    $productos = [];
    foreach ($listado as $fila) {
        $skuId = (int) $fila['id_producto'];
        $productos[] = [
            'id_producto_backend'   => $skuId,
            'sku'                   => $fila['sku'] ?? '',
            'descripcion'           => $fila['descripcion_producto'] ?? null,
            'stock_teorico'         => (float) ($fila['teorico'] ?? 0),
            'valor_unitario'        => (float) ($fila['costo_unitario'] ?? 0),
            'fisico_actual'         => (float) ($fila['fisico_actual'] ?? 0),
            'diferencia_unidades'   => (float) ($fila['diferencia_unidades'] ?? 0),
            'diferencia_en_costo'   => (float) ($fila['diferencia_en_costo'] ?? 0),
            'es_pre_variance'       => (int) ($fila['es_pre_variance'] ?? 0) === 1,
            'estado_recuento'       => $fila['estado_recuento'] ?? 'PENDIENTE',
            'ubicaciones'           => $ubicacionesPorSku[$skuId] ?? [],
        ];
    }

    // Resumen
    $totalSku = count($productos);
    $skuPendientes = 0;
    $skuRecontados = 0;
    $diferenciaTotal = 0.0;
    $skuConMayorDiferencia = null;
    $mayorDiferencia = 0.0;

    foreach ($productos as $p) {
        if ($p['estado_recuento'] === 'RECONTADO') {
            $skuRecontados++;
        } else {
            $skuPendientes++;
        }
        $diferenciaTotal += $p['diferencia_en_costo'];
        if (abs($p['diferencia_en_costo']) > abs($mayorDiferencia)) {
            $mayorDiferencia = $p['diferencia_en_costo'];
            $skuConMayorDiferencia = $p;
        }
    }

    return [
        'resumen' => [
            'sku_total'                     => $totalSku,
            'sku_pendientes'                => $skuPendientes,
            'sku_recontados'                => $skuRecontados,
            'diferencia_total'              => $diferenciaTotal,
            'mayor_diferencia_valor'        => $mayorDiferencia,
            'mayor_diferencia_sku'          => $skuConMayorDiferencia ? $skuConMayorDiferencia['sku'] : null,
            'mayor_diferencia_descripcion'  => $skuConMayorDiferencia ? $skuConMayorDiferencia['descripcion'] : null,
        ],
        'productos' => $productos,
    ];
}

function obtenerRecuentoVacioAnalistaDev(): array
{
    return [
        'resumen' => [
            'sku_total'                     => 0,
            'sku_pendientes'                => 0,
            'sku_recontados'                => 0,
            'diferencia_total'              => 0,
            'mayor_diferencia_valor'        => 0,
            'mayor_diferencia_sku'          => null,
            'mayor_diferencia_descripcion'  => null,
        ],
        'productos' => [],
    ];
}

/* ============================================================================
   Q20 — Listado Recuento (diferencia contra Kárdex, excluye PV por defecto)
   ============================================================================ */

function obtenerListadoRecuentoDev(PDO $pdo, int $idAgenda, int $idKardex, int $idConteo1, int $idConteo2, int $idConteo3): array
{
    $sql = "SELECT
        p.id_producto,
        p.sku,
        p.descripcion_producto,
        kd.stock_teorico AS teorico,
        kd.valor_unitario AS costo_unitario,
        SUM(v.cantidad_vigente) AS fisico_actual,
        SUM(v.cantidad_vigente) - kd.stock_teorico AS diferencia_unidades,
        (SUM(v.cantidad_vigente) - kd.stock_teorico) * kd.valor_unitario AS diferencia_en_costo,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM sod_inv_conteo_det AS r
                WHERE r.id_conteo = :id_conteo_3_check
                  AND r.id_producto = p.id_producto
                  AND r.id_reconteo IS NULL
                  AND r.origen = 'SGO_RECUENTO'
                  AND r.estado_registro = 'VIGENTE'
            )
            THEN 1
            ELSE 0
        END AS tiene_c3,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM sod_inv_conteo_det AS r
                WHERE r.id_conteo = :id_conteo_2_pv
                  AND r.id_producto = p.id_producto
                  AND r.id_reconteo IS NULL
                  AND r.origen = 'SGO_PREVARIANCE'
                  AND r.estado_registro = 'VIGENTE'
            )
            THEN 1
            ELSE 0
        END AS es_pre_variance
    FROM (
        SELECT u.id_producto, u.id_tag,
            CASE
                WHEN rc.cantidad_c3 IS NOT NULL THEN rc.cantidad_c3
                WHEN pv.cantidad_pre_variance IS NOT NULL THEN pv.cantidad_pre_variance
                WHEN op.cantidad_operacional IS NOT NULL THEN op.cantidad_operacional
                WHEN c1.cantidad_c1 IS NOT NULL THEN c1.cantidad_c1
                ELSE 0
            END AS cantidad_vigente
        FROM (
            SELECT id_producto, id_tag
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_1
              AND estado_registro = 'VIGENTE'
            UNION
            SELECT id_producto, id_tag
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_2
              AND id_reconteo IS NULL
              AND origen IN ('SGO_ANALISTA','SGO_PREVARIANCE')
              AND estado_registro = 'VIGENTE'
            UNION
            SELECT id_producto, id_tag
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_3_u
              AND id_reconteo IS NULL
              AND origen = 'SGO_RECUENTO'
              AND estado_registro = 'VIGENTE'
        ) AS u
        LEFT JOIN (
            SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c1
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_1_c1
              AND estado_registro = 'VIGENTE'
            GROUP BY id_producto, id_tag
        ) AS c1 ON c1.id_producto = u.id_producto AND c1.id_tag = u.id_tag
        LEFT JOIN (
            SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_operacional
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_2_op
              AND id_reconteo IS NULL
              AND origen = 'SGO_ANALISTA'
              AND estado_registro = 'VIGENTE'
            GROUP BY id_producto, id_tag
        ) AS op ON op.id_producto = u.id_producto AND op.id_tag = u.id_tag
        LEFT JOIN (
            SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_pre_variance
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_2_pv_u
              AND id_reconteo IS NULL
              AND origen = 'SGO_PREVARIANCE'
              AND estado_registro = 'VIGENTE'
            GROUP BY id_producto, id_tag
        ) AS pv ON pv.id_producto = u.id_producto AND pv.id_tag = u.id_tag
        LEFT JOIN (
            SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c3
            FROM sod_inv_conteo_det
            WHERE id_conteo = :id_conteo_3_rc
              AND id_reconteo IS NULL
              AND origen = 'SGO_RECUENTO'
              AND estado_registro = 'VIGENTE'
            GROUP BY id_producto, id_tag
        ) AS rc ON rc.id_producto = u.id_producto AND rc.id_tag = u.id_tag
    ) AS v
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = v.id_producto
    INNER JOIN sod_inv_kardex_det AS kd ON kd.id_kardex = :id_kardex AND kd.id_producto = p.id_producto
    GROUP BY p.id_producto, p.sku, p.descripcion_producto, kd.stock_teorico, kd.valor_unitario
    HAVING (
            ABS(SUM(v.cantidad_vigente) - kd.stock_teorico) > 0.0005
            OR tiene_c3 = 1
        )
        AND es_pre_variance = 0
    ORDER BY ABS((SUM(v.cantidad_vigente) - kd.stock_teorico) * kd.valor_unitario) DESC, p.sku";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_kardex'       => $idKardex,
        ':id_conteo_1'     => $idConteo1,
        ':id_conteo_2'     => $idConteo2,
        ':id_conteo_3_u'   => $idConteo3,
        ':id_conteo_3_check' => $idConteo3,
        ':id_conteo_2_pv'  => $idConteo2,
        ':id_conteo_1_c1'  => $idConteo1,
        ':id_conteo_2_op'  => $idConteo2,
        ':id_conteo_2_pv_u' => $idConteo2,
        ':id_conteo_3_rc'  => $idConteo3,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ============================================================================
   Q21/Q22 — Ubicaciones de múltiples SKUs en Recuento
   ============================================================================ */

function obtenerUbicacionesMultiplesRecuentoDev(PDO $pdo, int $idAgenda, array $skuIds, int $idConteo1, int $idConteo2, int $idConteo3): array
{
    if (empty($skuIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($skuIds), '?'));

    $sql = "SELECT
        u.id_producto,
        t.id_tag,
        t.numero_tag,
        COALESCE(rz.nombre_zona_reporte, tu.nombre_tipo_ubicacion, 'Sin zona') AS zona,
        CASE
            WHEN rc.cantidad_c3 IS NOT NULL THEN rc.cantidad_c3
            WHEN pv.cantidad_pre_variance IS NOT NULL THEN pv.cantidad_pre_variance
            WHEN op.cantidad_operacional IS NOT NULL THEN op.cantidad_operacional
            WHEN c1.cantidad_c1 IS NOT NULL THEN c1.cantidad_c1
            ELSE 0
        END AS cantidad_inventariada,
        rc.cantidad_c3 AS cantidad_recuento
    FROM (
        SELECT id_producto, id_tag
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND id_producto IN ($placeholders)
          AND estado_registro = 'VIGENTE'
        UNION
        SELECT id_producto, id_tag
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND id_producto IN ($placeholders)
          AND id_reconteo IS NULL
          AND origen IN ('SGO_ANALISTA','SGO_PREVARIANCE')
          AND estado_registro = 'VIGENTE'
        UNION
        SELECT id_producto, id_tag
        FROM sod_inv_conteo_det
        WHERE id_conteo = ?
          AND id_producto IN ($placeholders)
          AND id_reconteo IS NULL
          AND origen = 'SGO_RECUENTO'
          AND estado_registro = 'VIGENTE'
    ) AS u
    INNER JOIN sod_inv_tag AS t ON t.id_tag = u.id_tag AND t.id_agenda = ?
    LEFT JOIN sod_cfg_tipo_ubicacion AS tu ON tu.id_tipo_ubicacion = t.id_tipo_ubicacion
    LEFT JOIN sod_rep_zona_tag_regla AS rz ON rz.fl_activo = 'S' AND t.numero_tag BETWEEN rz.tag_desde AND rz.tag_hasta
    LEFT JOIN (
        SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c1
        FROM sod_inv_conteo_det WHERE id_conteo = ? AND estado_registro = 'VIGENTE'
        GROUP BY id_producto, id_tag
    ) AS c1 ON c1.id_producto = u.id_producto AND c1.id_tag = u.id_tag
    LEFT JOIN (
        SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_operacional
        FROM sod_inv_conteo_det WHERE id_conteo = ? AND id_reconteo IS NULL AND origen = 'SGO_ANALISTA' AND estado_registro = 'VIGENTE'
        GROUP BY id_producto, id_tag
    ) AS op ON op.id_producto = u.id_producto AND op.id_tag = u.id_tag
    LEFT JOIN (
        SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_pre_variance
        FROM sod_inv_conteo_det WHERE id_conteo = ? AND id_reconteo IS NULL AND origen = 'SGO_PREVARIANCE' AND estado_registro = 'VIGENTE'
        GROUP BY id_producto, id_tag
    ) AS pv ON pv.id_producto = u.id_producto AND pv.id_tag = u.id_tag
    LEFT JOIN (
        SELECT id_producto, id_tag, SUM(cantidad) AS cantidad_c3
        FROM sod_inv_conteo_det WHERE id_conteo = ? AND id_reconteo IS NULL AND origen = 'SGO_RECUENTO' AND estado_registro = 'VIGENTE'
        GROUP BY id_producto, id_tag
    ) AS rc ON rc.id_producto = u.id_producto AND rc.id_tag = u.id_tag
    ORDER BY u.id_producto, t.numero_tag";

    $params = [];
    // UNION 1
    $params[] = $idConteo1;
    foreach ($skuIds as $id) { $params[] = $id; }
    // UNION 2
    $params[] = $idConteo2;
    foreach ($skuIds as $id) { $params[] = $id; }
    // UNION 3
    $params[] = $idConteo3;
    foreach ($skuIds as $id) { $params[] = $id; }
    // JOINs
    $params[] = $idAgenda;
    $params[] = $idConteo1;  // c1
    $params[] = $idConteo2;  // op
    $params[] = $idConteo2;  // pv
    $params[] = $idConteo3;  // rc

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ============================================================================
   1. obtenerUsuario  (V6 — identidad funcional + RUT + vigencia)
   Parametros: :login_1 (login), :login_2 (email), :rut_normalizado
   ============================================================================ */

function obtenerUsuario(PDO $pdo, string $correo, string $rut): ?array
{
    $stmt = $pdo->prepare("SELECT
        su.login                        AS login,
        ue.rut                          AS rut,
        ue.rut_normalizado              AS rut_normalizado,

        CONCAT_WS(
            ' ',
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
            ON CONVERT(TRIM(su.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
               =
               CONVERT(TRIM(ue.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci

    WHERE (
            CONVERT(TRIM(su.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(TRIM(:login_1) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR
            CONVERT(TRIM(su.email) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(TRIM(:login_2) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        )
    AND ue.rut_normalizado = :rut_normalizado
    AND su.active = 'Y'
    AND ue.fl_activo = 'S'
    AND (ue.fecha_inicio_vigencia IS NULL OR ue.fecha_inicio_vigencia <= NOW())
    AND (ue.fecha_fin_vigencia IS NULL OR ue.fecha_fin_vigencia >= NOW())
    LIMIT 1");

    $stmt->execute([
        ':login_1'         => $correo,
        ':login_2'         => $correo,
        ':rut_normalizado' => $rut,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

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

/* ============================================================================
   2. obtenerTiendas  (maestro de tiendas autorizadas)
   Parametro: :login
   ============================================================================ */

function obtenerTiendas(PDO $pdo, string $login): ?array
{
    $sql = "SELECT
        v.id_tienda,
        v.codigo_tienda,
        v.nombre_tienda,
        v.id_zona_operativa,
        v.codigo_zona,
        v.nombre_zona,
        v.tipo_asignacion_base,
        v.fl_principal,
        v.fecha_inicio_vigencia,
        v.fecha_fin_vigencia
    FROM vw_sod_usuario_tienda_resumen AS v
    WHERE CONVERT(TRIM(v.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          =
          CONVERT(TRIM(:login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
      AND v.estado_operativo = 'VIGENTE'
      AND v.fl_usuario_vigente = 'S'
    ORDER BY
        v.fl_principal DESC,
        v.nombre_tienda ASC,
        v.id_tienda ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login]);

    $tiendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$tiendas) {
        return null;
    }

    return array_map(function ($t) {
        return [
            'id_tienda'      => $t['id_tienda'],
            'codigo_tienda'  => $t['codigo_tienda'],
            'nombre_tienda'  => $t['nombre_tienda'],
            'zona_operativa' => $t['nombre_zona'],
        ];
    }, $tiendas);
}

/* ============================================================================
   3. obtenerAgendas  (V3.0 — jornada operable + muestra valida con SKU)
   Parametros: :login, :fecha (YYYY-MM-DD)
   Regla: jornada operable + agenda operable + fl_muestra_ok='S' + sku>0
   ============================================================================ */

function obtenerAgendas(PDO $pdo, string $login, string $fecha): ?array
{
    $sql = "SELECT
        a.id_agenda,
        a.numero_agenda,
        DATE(a.fecha_agenda) AS fecha_agenda,
        a.secuencia_dia,

        t.id_tienda,
        t.codigo_tienda,
        t.nombre_tienda,
        t.id_zona_operativa,
        z.codigo_zona,
        z.nombre_zona,

        ea.codigo_estado AS estado_agenda,

        jo.id_jornada,
        jo.estado_jornada,
        COALESCE(jo.fl_jornada_operable, 'N') AS fl_jornada_operable,

        p.id_muestra,
        p.codigo_muestra,
        p.nombre_muestra,
        p.id_kardex,
        p.codigo_kardex,
        p.sku_muestra,
        p.sku_kardex,
        p.operadores_agenda,
        p.fl_muestra_ok,
        p.fl_kardex_ok,
        p.fl_cobertura_ok,
        p.fl_operadores_ok,
        p.fl_lista_conteo,
        p.estado_preparacion,

        CASE
            WHEN COALESCE(jo.fl_jornada_operable, 'N') = 'S'
             AND ea.codigo_estado IN ('PLANIFICADA','ASIGNADA','LISTA','EN_CONTEO')
             AND COALESCE(p.fl_muestra_ok, 'N') = 'S'
             AND COALESCE(p.sku_muestra, 0) > 0
                THEN 'S'
            ELSE 'N'
        END AS fl_puede_contar,

        CASE
            WHEN COALESCE(p.fl_kardex_ok, 'N') = 'S'
             AND COALESCE(p.fl_cobertura_ok, 'N') = 'S'
                THEN 'S'
            ELSE 'N'
        END AS fl_procesos_posteriores

    FROM sod_ope_agenda AS a

    INNER JOIN sod_cfg_tienda AS t
            ON t.id_tienda = a.id_tienda

    LEFT JOIN sod_cfg_zona_operativa AS z
           ON z.id_zona_operativa = t.id_zona_operativa

    INNER JOIN sod_ope_estado_agenda AS ea
            ON ea.id_estado_agenda = a.id_estado_agenda

    INNER JOIN vw_sod_agenda_preparacion_resumen AS p
            ON p.id_agenda = a.id_agenda

    INNER JOIN vw_sod_agenda_jornada_operativa AS jo
            ON jo.id_agenda = a.id_agenda

    WHERE DATE(a.fecha_agenda) = :fecha
      AND a.fl_activo = 'S'
      AND ea.codigo_estado NOT IN ('CERRADA', 'SUSPENDIDA')
      AND jo.fl_jornada_operable = 'S'
      AND p.fl_muestra_ok = 'S'
      AND COALESCE(p.sku_muestra, 0) > 0
      AND EXISTS (
            SELECT 1
            FROM vw_sod_dash_agenda_usuario AS d
            WHERE d.id_agenda = a.id_agenda
              AND CONVERT(TRIM(d.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  =
                  CONVERT(TRIM(:login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          )
    ORDER BY
        t.nombre_tienda ASC,
        a.secuencia_dia ASC,
        a.numero_agenda ASC,
        a.id_agenda ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login, ':fecha' => $fecha]);

    $agendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $agendas ?: null;
}

/* ============================================================================
   3-B. obtenerAgendasFallbackPrueba  (FALLBACK TEMPORAL PARA PRUEBAS)
   Si no hay agenda para la fecha actual, busca la ultima agenda operable
   anterior asignada al usuario con muestra valida.
   NO USAR COMO REGLA PRODUCTIVA.
   ============================================================================ */

function obtenerAgendasFallbackPrueba(PDO $pdo, string $login, string $fecha): ?array
{
    $sql = "SELECT
        a.id_agenda,
        a.numero_agenda,
        DATE(a.fecha_agenda) AS fecha_agenda,
        a.secuencia_dia,

        t.id_tienda,
        t.codigo_tienda,
        t.nombre_tienda,
        t.id_zona_operativa,
        z.codigo_zona,
        z.nombre_zona,

        ea.codigo_estado AS estado_agenda,

        jo.id_jornada,
        jo.estado_jornada,
        COALESCE(jo.fl_jornada_operable, 'N') AS fl_jornada_operable,

        p.id_muestra,
        p.codigo_muestra,
        p.nombre_muestra,
        p.id_kardex,
        p.codigo_kardex,
        p.sku_muestra,
        p.sku_kardex,
        p.operadores_agenda,
        p.fl_muestra_ok,
        p.fl_kardex_ok,
        p.fl_cobertura_ok,
        p.fl_operadores_ok,
        p.fl_lista_conteo,
        p.estado_preparacion,

        CASE
            WHEN COALESCE(jo.fl_jornada_operable, 'N') = 'S'
             AND ea.codigo_estado IN ('PLANIFICADA','ASIGNADA','LISTA','EN_CONTEO')
             AND COALESCE(p.fl_muestra_ok, 'N') = 'S'
             AND COALESCE(p.sku_muestra, 0) > 0
                THEN 'S'
            ELSE 'N'
        END AS fl_puede_contar,

        CASE
            WHEN COALESCE(p.fl_kardex_ok, 'N') = 'S'
             AND COALESCE(p.fl_cobertura_ok, 'N') = 'S'
                THEN 'S'
            ELSE 'N'
        END AS fl_procesos_posteriores

    FROM sod_ope_agenda AS a

    INNER JOIN sod_cfg_tienda AS t
            ON t.id_tienda = a.id_tienda

    LEFT JOIN sod_cfg_zona_operativa AS z
           ON z.id_zona_operativa = t.id_zona_operativa

    INNER JOIN sod_ope_estado_agenda AS ea
            ON ea.id_estado_agenda = a.id_estado_agenda

    INNER JOIN vw_sod_agenda_preparacion_resumen AS p
            ON p.id_agenda = a.id_agenda

    INNER JOIN vw_sod_agenda_jornada_operativa AS jo
            ON jo.id_agenda = a.id_agenda

    WHERE DATE(a.fecha_agenda) <= :fecha
      AND a.fl_activo = 'S'
      AND ea.codigo_estado NOT IN ('CERRADA', 'SUSPENDIDA')
      AND jo.fl_jornada_operable = 'S'
      AND p.fl_muestra_ok = 'S'
      AND COALESCE(p.sku_muestra, 0) > 0
      AND EXISTS (
            SELECT 1
            FROM vw_sod_dash_agenda_usuario AS d
            WHERE d.id_agenda = a.id_agenda
              AND CONVERT(TRIM(d.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  =
                  CONVERT(TRIM(:login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          )
    ORDER BY
        DATE(a.fecha_agenda) DESC,
        t.nombre_tienda ASC,
        a.secuencia_dia ASC,
        a.numero_agenda ASC,
        a.id_agenda ASC
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login, ':fecha' => $fecha]);

    $agendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $agendas ?: null;
}

/* ============================================================================
   4. obtenerCodigosAgenda  (V4.0 optimizado — 2 consultas internas)
   Recibe id_agenda; el backend resuelve id_muestra internamente.
   Parametros: :id_agenda, :login
   ============================================================================ */

function obtenerCodigosAgenda(PDO $pdo, int $idAgenda, string $login): ?array
{
    /* --- Paso A: autorizar agenda + resolver id_muestra --------------------- */
    $agendaInfo = resolverAgendaMuestra($pdo, $idAgenda, $login);
    if (!$agendaInfo) {
        return null;
    }

    $idMuestra = (int) $agendaInfo['id_muestra'];

    /* --- Paso B: descargar productos/codigos por id_muestra ----------------- */
    $filas = obtenerCodigosMuestra($pdo, $idMuestra);

    /* Inyectar datos de agenda en cada fila para que agruparProductosConCodigos funcione */
    if ($filas) {
        foreach ($filas as &$fila) {
            $fila['id_agenda']       = $agendaInfo['id_agenda'];
            $fila['numero_agenda']   = $agendaInfo['numero_agenda'];
            $fila['fecha_agenda']    = $agendaInfo['fecha_agenda'];
            $fila['id_tienda']       = $agendaInfo['id_tienda'];
            $fila['id_muestra']      = $agendaInfo['id_muestra'];
            $fila['codigo_muestra']  = $agendaInfo['codigo_muestra'] ?? null;
            $fila['nombre_muestra']  = $agendaInfo['nombre_muestra'] ?? null;
        }
        unset($fila);
    }

    return $filas ?: null;
}

/* ============================================================================
   4-A. resolverAgendaMuestra  (Paso A — validar agenda + resolver id_muestra)
   Parametros: :id_agenda, :login
   ============================================================================ */

function resolverAgendaMuestra(PDO $pdo, int $idAgenda, string $login): ?array
{
    $sql = "SELECT
        a.id_agenda,
        a.numero_agenda,
        DATE(a.fecha_agenda) AS fecha_agenda,
        a.id_tienda,

        am.id_muestra,

        (
            SELECT COUNT(0)
            FROM sod_inv_muestra_det AS md_count
            WHERE md_count.id_muestra = am.id_muestra
              AND md_count.fl_activo = 'S'
        ) AS sku_muestra,

        jo.id_jornada,
        jo.estado_jornada,
        jo.fl_jornada_operable

    FROM sod_ope_agenda AS a

    INNER JOIN sod_ope_estado_agenda AS ea
            ON ea.id_estado_agenda = a.id_estado_agenda

    INNER JOIN sod_inv_agenda_muestra AS am
            ON am.id_agenda = a.id_agenda
           AND am.fl_activo = 'S'

    INNER JOIN vw_sod_agenda_jornada_operativa AS jo
            ON jo.id_agenda = a.id_agenda

    WHERE a.id_agenda = :id_agenda
      AND a.fl_activo = 'S'
      AND ea.codigo_estado NOT IN ('CERRADA', 'SUSPENDIDA')
      AND jo.fl_jornada_operable = 'S'

      AND EXISTS (
            SELECT 1
            FROM sod_inv_muestra_det AS md_ok
            WHERE md_ok.id_muestra = am.id_muestra
              AND md_ok.fl_activo = 'S'
          )

      AND EXISTS (
            SELECT 1
            FROM vw_sod_dash_agenda_usuario AS d
            WHERE d.id_agenda = a.id_agenda
              AND CONVERT(TRIM(d.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  =
                  CONVERT(TRIM(:login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          )

    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_agenda' => $idAgenda, ':login' => $login]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/* ============================================================================
   4-B. obtenerCodigosMuestra  (Paso B — descarga masiva por id_muestra)
   UNION ALL: SKU + barras snapshot + barras legacy (solo si no existe snapshot)
   Parametro: :id_muestra
   ============================================================================ */

function obtenerCodigosMuestra(PDO $pdo, int $idMuestra): ?array
{
    $sql = "SELECT DISTINCT
        cod.id_muestra_det,
        cod.id_producto,
        cod.codigo_ingreso AS codigo_lectura,
        cod.tipo_codigo,

        CASE
            WHEN CONVERT(cod.tipo_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'SKU' COLLATE utf8mb4_unicode_ci
                THEN NULL
            ELSE cod.codigo_ingreso
        END AS codigo_barras,

        md.orden_muestra,
        md.fl_obligatorio AS obligatorio,
        md.prioridad,
        md.observacion,
        md.clacom_origen,

        p.sku,
        p.descripcion_producto AS descripcion,
        p.unidad_medida,
        p.valor_referencia,
        p.fl_producto_critico AS producto_critico,
        p.fl_alto_valor AS producto_alto_valor,

        cc.id_clasificacion_comercial,
        cc.clacom,
        cc.codigo_departamento,
        cc.departamento,
        cc.codigo_familia,
        cc.familia,
        cc.codigo_subfamilia,
        cc.subfamilia,
        cc.codigo_grupo,
        cc.grupo,
        cc.codigo_conjunto,
        cc.conjunto

    FROM
    (
        /* 1. SKU */
        SELECT
            md1.id_muestra_det,
            md1.id_producto,
            p1.sku AS codigo_ingreso,
            'SKU' AS tipo_codigo
        FROM sod_inv_muestra_det AS md1
        INNER JOIN sod_cfg_producto AS p1
                ON p1.id_producto = md1.id_producto
               AND p1.fl_activo = 'S'
        WHERE md1.id_muestra = :id_muestra_sku
          AND md1.fl_activo = 'S'

        UNION ALL

        /* 2. BARRAS SNAPSHOT DE LA MUESTRA */
        SELECT
            md2.id_muestra_det,
            md2.id_producto,
            mb.codigo_barras AS codigo_ingreso,
            'BARRA_MUESTRA' AS tipo_codigo
        FROM sod_inv_muestra_det AS md2
        INNER JOIN sod_inv_muestra_det_barra AS mb
                ON mb.id_muestra_det = md2.id_muestra_det
               AND mb.fl_activo = 'S'
        WHERE md2.id_muestra = :id_muestra_snapshot
          AND md2.fl_activo = 'S'

        UNION ALL

        /* 3. BARRAS LEGACY: SOLO SI NO EXISTE SNAPSHOT */
        SELECT
            md3.id_muestra_det,
            md3.id_producto,
            pb.codigo_barras AS codigo_ingreso,
            'BARRA_CATALOGO_LEGACY' AS tipo_codigo
        FROM sod_inv_muestra_det AS md3
        INNER JOIN sod_cfg_producto_barra AS pb
                ON pb.id_producto = md3.id_producto
               AND pb.fl_activo = 'S'
        WHERE md3.id_muestra = :id_muestra_legacy
          AND md3.fl_activo = 'S'
          AND NOT EXISTS (
                SELECT 1
                FROM sod_inv_muestra_det_barra AS mbx
                WHERE mbx.id_muestra_det = md3.id_muestra_det
                  AND mbx.fl_activo = 'S'
          )
    ) AS cod

    INNER JOIN sod_inv_muestra_det AS md
            ON md.id_muestra_det = cod.id_muestra_det
           AND md.id_muestra = :id_muestra_join
           AND md.id_producto = cod.id_producto
           AND md.fl_activo = 'S'

    INNER JOIN sod_cfg_producto AS p
            ON p.id_producto = cod.id_producto
           AND p.fl_activo = 'S'

    LEFT JOIN sod_cfg_clasificacion_comercial AS cc
           ON cc.id_clasificacion_comercial = md.id_clasificacion_comercial
          AND cc.fl_activo = 'S'

    ORDER BY
        md.orden_muestra ASC,
        p.sku ASC,
        CASE cod.tipo_codigo
            WHEN 'BARRA_MUESTRA' THEN 1
            WHEN 'BARRA_CATALOGO_LEGACY' THEN 2
            WHEN 'SKU' THEN 3
            ELSE 9
        END ASC,
        cod.codigo_ingreso ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_muestra_sku'      => $idMuestra,
        ':id_muestra_snapshot' => $idMuestra,
        ':id_muestra_legacy'   => $idMuestra,
        ':id_muestra_join'     => $idMuestra,
    ]);

    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $filas ?: null;
}

/* ============================================================================
   5. agruparProductosConCodigos
   Agrupa filas de obtenerCodigosAgenda() por id_muestra_det / id_producto.
   ============================================================================ */

function agruparProductosConCodigos(array $filas): array
{
    $productos = [];

    foreach ($filas as $fila) {
        $key = $fila['id_muestra_det'] . '_' . $fila['id_producto'];

        if (!isset($productos[$key])) {
            $productos[$key] = [
                'id_muestra_det'  => (int) $fila['id_muestra_det'],
                'id_producto'     => (int) $fila['id_producto'],
                'sku'             => trim($fila['sku']),
                'codigo_barras'   => null,
                'descripcion'     => $fila['descripcion'] ?? null,
                'stock_sistema'   => 0,
                'codigos'         => [],
                '_codigos_vistos' => [],
            ];
        }

        $codigoLectura = normalizarCodigo($fila['codigo_lectura'] ?? $fila['codigo_ingreso'] ?? null);
        $tipoCodigo    = $fila['tipo_codigo'] ?? null;

        if ($codigoLectura !== null && !in_array($codigoLectura, $productos[$key]['_codigos_vistos'], true)) {
            $productos[$key]['_codigos_vistos'][] = $codigoLectura;

            $codigoBarras = null;
            if ($tipoCodigo !== null && $tipoCodigo !== 'SKU') {
                $codigoBarras = $codigoLectura;
            }

            $productos[$key]['codigos'][] = [
                'codigo_lectura' => $codigoLectura,
                'tipo_codigo'    => $tipoCodigo,
                'codigo_barras'  => $codigoBarras,
            ];
        }

        /* La primera fila trae el sku real, usarlo como codigo_barras display */
        if ($productos[$key]['codigo_barras'] === null && $tipoCodigo !== 'SKU' && $codigoLectura !== null) {
            $productos[$key]['codigo_barras'] = $codigoLectura;
        }
    }

    /* limpiar campo interno y convertir a array numerico */
    $resultado = [];
    foreach ($productos as $producto) {
        unset($producto['_codigos_vistos']);
        $resultado[] = $producto;
    }

    return $resultado;
}

/* ============================================================================
   6. construirMuestraDesdeAgenda
   Construye el objeto muestra que la APK espera.
   ============================================================================ */

function construirMuestraDesdeAgenda(array $agenda): array
{
    return [
        'id_muestra'             => (int) ($agenda['id_muestra'] ?? 0),
        'codigo_muestra'         => $agenda['codigo_muestra'] ?? '',
        'nombre_muestra'         => $agenda['nombre_muestra'] ?? '',
        'id_agenda'              => (int) ($agenda['id_agenda'] ?? 0),
        'numero_agenda'          => $agenda['numero_agenda'] ?? null,
        'fecha_agenda'           => $agenda['fecha_agenda'] ?? null,
    ];
}

/* ============================================================================
   7. construirEventoDesdeAgenda
   Construye el objeto evento local que la APK espera.
   ============================================================================ */

function construirEventoDesdeAgenda(array $agenda, array $tienda): array
{
    return [
        'sucursal_id'      => (int) $tienda['id_tienda'],
        'fecha_programada' => date('d-m-Y'),
        'estado'           => 'ABIERTO',
        'id_agenda'        => (int) ($agenda['id_agenda'] ?? 0),
    ];
}

/* ============================================================================
   normalizarCodigo
   Trim y uppercase para codigos alfanumericos. Null si viene vacio.
   ============================================================================ */

function normalizarCodigo(?string $codigo): ?string
{
    if ($codigo === null) {
        return null;
    }

    $limpio = trim($codigo);

    if ($limpio === '') {
        return null;
    }

    return strtoupper($limpio);
}
