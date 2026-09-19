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
        a.fecha_agenda AS fecha,
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
         WHERE tg.id_agenda = a.id_agenda AND cd.estado_registro = 'VIGENTE') AS tags_contados,
        CASE 
            WHEN (SELECT COUNT(*) FROM sod_inv_tag WHERE id_agenda = a.id_agenda AND fl_activo = 'S') = 0 THEN 0
            ELSE ROUND(
                (SELECT COUNT(DISTINCT cd.id_tag) FROM sod_inv_conteo_det cd 
                 INNER JOIN sod_inv_tag tg ON cd.id_tag = tg.id_tag 
                 WHERE tg.id_agenda = a.id_agenda AND cd.estado_registro = 'VIGENTE') * 100.0 / 
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
        COUNT(md.id_producto) AS total_productos,
        COUNT(DISTINCT cd.id_producto) AS total_contados,
        CASE 
            WHEN COUNT(md.id_producto) = 0 THEN 0
            ELSE ROUND(COUNT(DISTINCT cd.id_producto) * 100.0 / COUNT(md.id_producto))
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
    LEFT JOIN sod_inv_conteo_det AS cd ON cd.id_agenda = tg.id_agenda AND cd.id_tag = tg.id_tag AND cd.estado_registro = 'VIGENTE'
    LEFT JOIN sod_inv_muestra_det AS md ON md.id_producto = cd.id_producto AND md.fl_activo = 'S'
    WHERE tg.id_agenda = :agenda_id
      AND tg.fl_activo = 'S'
    GROUP BY tg.id_tag, tg.numero_tag, tg.id_tipo_ubicacion, tu.nombre_tipo_ubicacion, 
             tu.codigo_tipo_ubicacion, tg.estado_tag, tg.id_agenda
    ORDER BY tu.codigo_tipo_ubicacion ASC, tg.numero_tag ASC";

    $stmtTags = $pdo->prepare($sqlTags);
    $stmtTags->execute([':agenda_id' => $agendaId]);
    $tags = $stmtTags->fetchAll();

    okResponse([
        'agenda' => $agenda,
        'tags' => $tags,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener detalle: ' . $e->getMessage(), 500);
}
