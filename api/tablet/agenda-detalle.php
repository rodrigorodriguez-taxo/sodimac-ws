<?php
# ============================================================
# ws/api/tablet/agenda-detalle.php
# GET ?id=123
# Retorna detalle de agenda con sus tags
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['id'] ?? '';
if (empty($agendaId)) {
    errorResponse('Falta id de la agenda');
}

try {
    $sql = "SELECT 
        a.id_agenda AS id,
        DATE_FORMAT(a.fecha_agenda, '%Y-%m-%d') AS fecha,
        a.id_tienda AS tienda_id,
        t.nombre_tienda AS tienda_nombre,
        t.direccion AS tienda_direccion,
        a.numero_agenda,
        a.id_estado_agenda,
        ea.codigo_estado AS estado,
        a.fecha_hora_inicio AS checkin_at,
        a.fecha_hora_termino AS checkout_at,
        a.fecha_hora_cierre,
        a.titulo_agenda,
        a.categoria_muestra,
        a.ultima_sincronizacion,
        a.fl_incidencia,
        a.observacion,
        COALESCE(a.numero_agenda, '') AS auditor_rut,
        COALESCE(a.titulo_agenda, '') AS auditor_nombre,
        ea.codigo_estado AS etapa,
        (SELECT COUNT(*) FROM sod_inv_tag WHERE id_agenda = a.id_agenda AND fl_activo = 'S') AS total_tags,
        (SELECT COUNT(DISTINCT cd.id_tag) FROM sod_inv_conteo_det cd 
         INNER JOIN sod_inv_tag tg ON cd.id_tag = tg.id_tag 
         INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
         WHERE tg.id_agenda = a.id_agenda 
           AND c.tipo_conteo = 'INICIAL'
           AND cd.estado_registro = 'VIGENTE') AS tags_contados,
        (SELECT COUNT(DISTINCT cd.id_tag) FROM sod_inv_conteo_det cd 
         INNER JOIN sod_inv_tag tg ON cd.id_tag = tg.id_tag 
         INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
         WHERE tg.id_agenda = a.id_agenda 
           AND c.tipo_conteo = 'VALIDACION'
           AND cd.origen = 'SGO_ANALISTA'
           AND cd.estado_registro = 'VIGENTE') AS tags_validados,
        CASE 
            WHEN (SELECT COUNT(*) FROM sod_inv_tag WHERE id_agenda = a.id_agenda AND fl_activo = 'S') = 0 THEN 0
            ELSE ROUND(
                (SELECT COUNT(DISTINCT cd.id_tag) FROM sod_inv_conteo_det cd 
                 INNER JOIN sod_inv_tag tg ON cd.id_tag = tg.id_tag 
                 INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
                 WHERE tg.id_agenda = a.id_agenda 
                   AND c.tipo_conteo = 'VALIDACION'
                   AND cd.origen = 'SGO_ANALISTA'
                   AND cd.estado_registro = 'VIGENTE') * 100.0 / 
                (SELECT COUNT(*) FROM sod_inv_tag WHERE id_agenda = a.id_agenda AND fl_activo = 'S')
            )
        END AS porcentaje_avance
    FROM sod_ope_agenda AS a
    INNER JOIN sod_cfg_tienda AS t ON a.id_tienda = t.id_tienda
    INNER JOIN sod_ope_estado_agenda AS ea ON ea.id_estado_agenda = a.id_estado_agenda
    WHERE a.id_agenda = :agenda_id
      AND a.fl_activo = 'S'
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);
    $agenda = $stmt->fetch();

    if (!$agenda) {
        errorResponse('Agenda no encontrada', 404);
    }

    $sqlTags = "SELECT 
        tg.id_tag AS id,
        tg.numero_tag AS cod_sod,
        tg.id_tipo_ubicacion,
        tu.nombre_tipo_ubicacion,
        tu.codigo_tipo_ubicacion,
        tg.estado_tag,
        CASE
            WHEN LOWER(tu.nombre_tipo_ubicacion) LIKE '%altillo%' THEN 'ALTILLO'
            WHEN LOWER(tu.nombre_tipo_ubicacion) LIKE '%punto de venta%'
              OR LOWER(tu.nombre_tipo_ubicacion) LIKE '%punto venta%'
              OR tu.codigo_tipo_ubicacion = 'PDV' THEN 'PDV'
            ELSE 'OTRO'
        END AS tipo,
        0 AS tienda_id,
        '' AS tienda_nombre,
        COALESCE(c1.total_productos, 0) AS total_productos,
        COALESCE(c2.total_contados, 0) AS total_contados,
        CASE 
            WHEN COALESCE(c1.total_productos, 0) = 0 THEN 0
            ELSE ROUND(COALESCE(c2.total_contados, 0) * 100.0 / c1.total_productos)
        END AS porcentaje_avance,
        COALESCE(
            (SELECT estado_conteo 
             FROM sod_inv_conteo c 
             WHERE c.id_agenda = tg.id_agenda 
               AND c.tipo_conteo = 'VALIDACION' 
               AND c.fl_activo = 'S'
             ORDER BY c.id_conteo DESC 
             LIMIT 1), 
            'PENDIENTE'
        ) AS validacion_estado,
        CASE 
            WHEN tg.estado_tag = 'ABIERTO' THEN 'PENDIENTE'
            WHEN tg.estado_tag = 'FINALIZADO' THEN 'FINALIZADO'
            WHEN tg.estado_tag = 'REGULARIZADO' THEN 'FINALIZADO'
            WHEN tg.estado_tag = 'ANULADO' THEN 'ANULADO'
            ELSE tg.estado_tag
        END AS estado
    FROM sod_inv_tag AS tg
    INNER JOIN sod_cfg_tipo_ubicacion AS tu ON tg.id_tipo_ubicacion = tu.id_tipo_ubicacion
    LEFT JOIN (
        SELECT cd.id_tag, COUNT(DISTINCT cd.id_producto) AS total_productos
        FROM sod_inv_conteo_det cd
        INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
        WHERE c.id_agenda = :agenda_id
          AND c.tipo_conteo = 'INICIAL'
          AND cd.estado_registro = 'VIGENTE'
        GROUP BY cd.id_tag
    ) AS c1 ON c1.id_tag = tg.id_tag
    LEFT JOIN (
        SELECT cd.id_tag, COUNT(DISTINCT cd.id_producto) AS total_contados
        FROM sod_inv_conteo_det cd
        INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
        WHERE c.id_agenda = :agenda_id2
          AND c.tipo_conteo = 'VALIDACION'
          AND cd.origen = 'SGO_ANALISTA'
          AND cd.estado_registro = 'VIGENTE'
        GROUP BY cd.id_tag
    ) AS c2 ON c2.id_tag = tg.id_tag
    WHERE tg.id_agenda = :agenda_id3
      AND tg.fl_activo = 'S'
    ORDER BY tu.codigo_tipo_ubicacion ASC, tg.numero_tag ASC";

    $stmtTags = $pdo->prepare($sqlTags);
    $stmtTags->execute([':agenda_id' => $agendaId, ':agenda_id2' => $agendaId, ':agenda_id3' => $agendaId]);
    $tags = $stmtTags->fetchAll();

    // ── Estado Pre-Variance ─────────────────────────────────
    $stmtPV = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN observacion LIKE '%APROBADO%' THEN 1 ELSE 0 END) AS aprobados,
                SUM(CASE WHEN observacion LIKE '%RECHAZADO%' THEN 1 ELSE 0 END) AS rechazados
         FROM sod_inv_conteo_det cd
         INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
         WHERE c.id_agenda = :agenda_id
           AND cd.origen = 'SGO_PREVARIANCE'
           AND cd.estado_registro = 'VIGENTE'"
    );
    $stmtPV->execute([':agenda_id' => $agendaId]);
    $pv = $stmtPV->fetch();

    $agenda['pre_variance_estado'] = [
        'total_productos' => (int)($pv['total'] ?? 0),
        'aprobados' => (int)($pv['aprobados'] ?? 0),
        'rechazados' => (int)($pv['rechazados'] ?? 0),
        'completado' => ($pv['total'] ?? 0) > 0 && ($pv['aprobados'] ?? 0) + ($pv['rechazados'] ?? 0) === (int)$pv['total'],
    ];

    // ── Estado Recuento ─────────────────────────────────────
    $stmtRC = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN fl_corrige = 'S' THEN 1 ELSE 0 END) AS corregidos
         FROM sod_inv_reconteo_cierre_det
         WHERE id_agenda = :agenda_id"
    );
    $stmtRC->execute([':agenda_id' => $agendaId]);
    $rc = $stmtRC->fetch();

    $recuentoTotal = (int)($rc['total'] ?? 0);
    $recuentoCorregidos = (int)($rc['corregidos'] ?? 0);

    $agenda['recuento_estado'] = [
        'total_productos' => $recuentoTotal,
        'corregidos' => $recuentoCorregidos,
        'completado' => $recuentoTotal > 0,
    ];

    // ── Reglas de negocio para botones ──────────────────────

    // Contar TAGs por tipo
    $stmtTagTypes = $pdo->prepare(
        "SELECT 
            tu.codigo_tipo_ubicacion,
            COUNT(*) AS total,
            COUNT(DISTINCT c2.id_tag) AS validados
         FROM sod_inv_tag tg
         INNER JOIN sod_cfg_tipo_ubicacion tu ON tg.id_tipo_ubicacion = tu.id_tipo_ubicacion
         LEFT JOIN (
            SELECT cd.id_tag
            FROM sod_inv_conteo_det cd
            INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
            WHERE c.id_agenda = :agenda_id
              AND c.tipo_conteo = 'VALIDACION'
              AND cd.origen = 'SGO_ANALISTA'
              AND cd.estado_registro = 'VIGENTE'
            GROUP BY cd.id_tag
         ) c2 ON c2.id_tag = tg.id_tag
         WHERE tg.id_agenda = :agenda_id2
           AND tg.fl_activo = 'S'
         GROUP BY tu.codigo_tipo_ubicacion"
    );
    $stmtTagTypes->execute([':agenda_id' => $agendaId, ':agenda_id2' => $agendaId]);
    $tagTypes = $stmtTagTypes->fetchAll();

    $altillosTotal = 0;
    $altillosValidados = 0;
    $pdvTotal = 0;
    $pdvValidados = 0;
    foreach ($tagTypes as $tt) {
        $code = strtoupper($tt['codigo_tipo_ubicacion']);
        if (strpos($code, 'ALTILLO') !== false || strpos($code, 'ALTI') !== false) {
            $altillosTotal = (int)$tt['total'];
            $altillosValidados = (int)$tt['validados'];
        } elseif (strpos($code, 'PUNTO') !== false || strpos($code, 'PDV') !== false || strpos($code, 'VENTA') !== false) {
            $pdvTotal = (int)$tt['total'];
            $pdvValidados = (int)$tt['validados'];
        }
    }

    $altillosCumple = $altillosTotal > 0 && $altillosValidados >= $altillosTotal;
    $pdvCumple = $pdvTotal > 0 && $pdvValidados >= ceil($pdvTotal * 0.3);

    // Pre Variance habilitada: altillos 100% + PDV 30%
    $prevarianceHabilitada = $altillosCumple && $pdvCumple;

    // Pre Variance cerrada: existe registro en cierre
    $prevarianceCerrada = ($agenda['pre_variance_estado']['completado'] ?? false);

    // Recuento habilitado: Pre Variance cerrada
    $recuentoHabilitado = $prevarianceCerrada;

    $agenda['reglas_negocio'] = [
        'pre_variance_habilitada' => $prevarianceHabilitada,
        'pre_variance_cerrada' => $prevarianceCerrada,
        'recuento_habilitado' => $recuentoHabilitado,
        'altillos' => [
            'total' => $altillosTotal,
            'validados' => $altillosValidados,
            'cumple' => $altillosCumple,
        ],
        'pdv' => [
            'total' => $pdvTotal,
            'validados' => $pdvValidados,
            'cumple' => $pdvCumple,
        ],
    ];

    okResponse([
        'agenda' => $agenda,
        'tags' => $tags,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener detalle: ' . $e->getMessage(), 500);
}
