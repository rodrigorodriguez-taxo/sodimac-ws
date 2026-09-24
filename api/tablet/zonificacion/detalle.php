<?php
# ============================================================
# ws/api/tablet/zonificacion/detalle.php
# Reporte de validacion de zonificacion
# GET ?agenda_id=X
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId) || !is_numeric($agendaId)) {
    errorResponse('Falta o es invalido el parametro agenda_id');
}

$agendaId = (int) $agendaId;

try {
    // 1. Verificar acceso del usuario
    $usrLogin = getBearerToken() ?? 'app_user';
    
    // 2. Obtener contexto de la agenda
    $sqlContexto = "SELECT a.id_agenda, a.numero_agenda, a.fecha_agenda,
                           t.codigo_tienda, t.nombre_tienda, 
                           e.nombre_estado AS codigo_estado
                    FROM sod_ope_agenda a
                    LEFT JOIN sod_cfg_tienda t ON a.id_tienda = t.id_tienda
                    LEFT JOIN sod_ope_estado_agenda e ON a.id_estado_agenda = e.id_estado_agenda
                    WHERE a.id_agenda = :id_agenda";
    $stmtCtx = $pdo->prepare($sqlContexto);
    $stmtCtx->execute([':id_agenda' => $agendaId]);
    $agenda = $stmtCtx->fetch();
    
    if (!$agenda) {
        errorResponse('No se encontro la agenda especificada');
    }

    // 3. Encontrar Conte Inicial (Conteo 1)
    $sqlConteo = "SELECT id_conteo 
                  FROM sod_inv_conteo 
                  WHERE id_agenda = :id_agenda 
                    AND numero_iteracion = 1 
                    AND tipo_conteo = 'INICIAL' 
                    AND fl_activo = 'S'
                  ORDER BY id_conteo DESC LIMIT 1";
    $stmtConteo = $pdo->prepare($sqlConteo);
    $stmtConteo->execute([':id_agenda' => $agendaId]);
    $conteo = $stmtConteo->fetch();
    
    if (!$conteo) {
        errorResponse('No existe Conte Inicial para esta agenda');
    }
    $idConteo = $conteo['id_conteo'];

    // 4. Encontrar Zonificacion activa
    $sqlZonificacion = "SELECT id_zonificacion, estado, fecha_confirmacion, login_confirmacion
                        FROM sod_ope_agenda_zonificacion
                        WHERE id_agenda = :id_agenda AND fl_activo = 'S'
                        ORDER BY id_zonificacion DESC LIMIT 1";
    $stmtZonif = $pdo->prepare($sqlZonificacion);
    $stmtZonif->execute([':id_agenda' => $agendaId]);
    $zonificacion = $stmtZonif->fetch();
    
    if (!$zonificacion) {
        errorResponse('No existe Zonificacion formal para esta agenda');
    }
    $idZonificacion = $zonificacion['id_zonificacion'];

    // 5. Obtener detalle de zonas con validacion cruzada
    $sqlZonas = "SELECT 
                    zd.id_zonificacion_det,
                    zd.orden,
                    zd.tag_desde,
                    zd.tag_hasta,
                    zd.descripcion,
                    zd.qty_zonificado,
                    COUNT(DISTINCT t.id_tag) AS tag_validados,
                    COUNT(DISTINCT cd.id_producto) AS productos_distintos,
                    COUNT(cd.id_conteo_det) AS total_capturas,
                    COALESCE(SUM(cd.cantidad), 0) AS total_unidades
                 FROM sod_ope_agenda_zonificacion_det zd
                 LEFT JOIN sod_inv_tag t 
                    ON t.id_agenda = :agenda_id
                    AND t.numero_tag BETWEEN zd.tag_desde AND zd.tag_hasta
                    AND t.fl_activo = 'S'
                    AND t.estado_tag <> 'ANULADO'
                 LEFT JOIN sod_inv_conteo_det cd
                    ON cd.id_tag = t.id_tag
                    AND cd.id_conteo = :id_conteo
                    AND cd.estado_registro = 'VIGENTE'
                 WHERE zd.id_zonificacion = :id_zonificacion
                    AND zd.fl_activo = 'S'
                 GROUP BY zd.id_zonificacion_det, zd.orden, zd.tag_desde, 
                          zd.tag_hasta, zd.descripcion, zd.qty_zonificado
                 ORDER BY zd.orden";
    $stmtZonas = $pdo->prepare($sqlZonas);
    $stmtZonas->execute([
        ':id_zonificacion' => $idZonificacion,
        ':id_conteo' => $idConteo,
        ':agenda_id' => $agendaId
    ]);
    $zonasRaw = $stmtZonas->fetchAll();

    // Procesar zonas
    $zonas = [];
    $totalPlan = 0;
    $totalValidados = 0;
    
    foreach ($zonasRaw as $z) {
        $tagValidados = (int) $z['tag_validados'];
        $qtyZonificado = (int) $z['qty_zonificado'];
        $pendientes = max(0, $qtyZonificado - $tagValidados);
        $porcentaje = $qtyZonificado > 0 
            ? round(($tagValidados / $qtyZonificado) * 100, 2) 
            : 0;
        
        $zonas[] = [
            'id_zonificacion_det' => (int) $z['id_zonificacion_det'],
            'orden' => (int) $z['orden'],
            'tag_desde' => (int) $z['tag_desde'],
            'tag_hasta' => (int) $z['tag_hasta'],
            'descripcion' => $z['descripcion'],
            'qty_zonificado' => $qtyZonificado,
            'tag_validados' => $tagValidados,
            'pendientes' => $pendientes,
            'porcentaje_cobertura' => $porcentaje,
            'productos_distintos' => (int) $z['productos_distintos'],
            'total_capturas' => (int) $z['total_capturas'],
            'total_unidades' => (float) $z['total_unidades'],
        ];
        
        $totalPlan += $qtyZonificado;
        $totalValidados += $tagValidados;
    }

    // 6. Encontrar TAGs fuera de zonificacion
    $sqlFuera = "SELECT 
                    t.numero_tag,
                    tu.descripcion AS zona,
                    COUNT(DISTINCT cd.id_producto) AS sku_distintos,
                    COUNT(cd.id_conteo_det) AS capturas,
                    GROUP_CONCAT(DISTINCT CONCAT(p.sku, ' - ', p.descripcion_producto) SEPARATOR '|') AS productos
                 FROM sod_inv_conteo_det cd
                 JOIN sod_inv_tag t ON cd.id_tag = t.id_tag
                 LEFT JOIN sod_cfg_tipo_ubicacion tu ON t.id_tipo_ubicacion = tu.id_tipo_ubicacion
                 LEFT JOIN sod_cfg_producto p ON cd.id_producto = p.id_producto
                 WHERE cd.id_conteo = :id_conteo
                   AND cd.estado_registro = 'VIGENTE'
                   AND t.fl_activo = 'S'
                   AND t.estado_tag <> 'ANULADO'
                   AND NOT EXISTS (
                       SELECT 1 
                       FROM sod_ope_agenda_zonificacion_det zd
                       WHERE zd.id_zonificacion = :id_zonificacion
                         AND zd.fl_activo = 'S'
                         AND t.numero_tag BETWEEN zd.tag_desde AND zd.tag_hasta
                   )
                 GROUP BY t.numero_tag, tu.descripcion
                 ORDER BY t.numero_tag";
    $stmtFuera = $pdo->prepare($sqlFuera);
    $stmtFuera->execute([
        ':id_conteo' => $idConteo,
        ':id_zonificacion' => $idZonificacion
    ]);
    $tagsFueraRaw = $stmtFuera->fetchAll();

    $tagsFuera = [];
    foreach ($tagsFueraRaw as $tf) {
        $productos = explode('|', $tf['productos'] ?? '');
        $tagsFuera[] = [
            'numero_tag' => (int) $tf['numero_tag'],
            'zona' => $tf['zona'] ?? 'Sin zona',
            'sku_distintos' => (int) $tf['sku_distintos'],
            'capturas' => (int) $tf['capturas'],
            'productos' => array_slice($productos, 0, 4),
            'productos_total' => count($productos),
        ];
    }

    // 7. Calcular KPIs
    $pendientes = max(0, $totalPlan - $totalValidados);
    $fueraZonificacion = count($tagsFuera);
    $porcentajeCobertura = $totalPlan > 0 
        ? round(($totalValidados / $totalPlan) * 100, 2) 
        : 0;

    // 8. Generar observaciones
    $observaciones = [];
    if ($pendientes > 0) {
        $observaciones[] = "Existen $pendientes TAG planificados pendientes de validar.";
    }
    if ($fueraZonificacion > 0) {
        $observaciones[] = "Se detectaron $fueraZonificacion TAG utilizados fuera de la Zonificacion formal.";
    }
    if (empty($observaciones)) {
        $observaciones[] = "Sin observaciones. Los TAG validados cubren la Zonificacion informada.";
    }

    // 9. Respuesta
    okResponse([
        'agenda' => [
            'id_agenda' => (int) $agenda['id_agenda'],
            'numero_agenda' => $agenda['numero_agenda'],
            'codigo_tienda' => $agenda['codigo_tienda'],
            'nombre_tienda' => $agenda['nombre_tienda'],
            'fecha' => $agenda['fecha_agenda'],
            'estado' => $agenda['codigo_estado'],
        ],
        'kpi' => [
            'tag_planificados' => $totalPlan,
            'tag_validados' => $totalValidados,
            'pendientes' => $pendientes,
            'fuera_zonificacion' => $fueraZonificacion,
            'porcentaje_cobertura' => $porcentajeCobertura,
        ],
        'zonas' => $zonas,
        'tags_fuera_zona' => $tagsFuera,
        'observaciones' => $observaciones,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al consultar zonificacion: ' . $e->getMessage(), 500);
}
