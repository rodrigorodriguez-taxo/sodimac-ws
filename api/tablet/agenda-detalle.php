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
        a.id_agenda,
        a.fecha_agenda,
        a.id_tienda,
        t.nombre_tienda,
        a.numero_agenda,
        a.id_estado_agenda,
        ea.codigo_estado,
        a.fecha_hora_inicio,
        a.fecha_hora_termino,
        a.fecha_hora_cierre,
        a.titulo_agenda,
        a.categoria_muestra,
        a.ultima_sincronizacion,
        a.fl_incidencia,
        a.observacion
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
        tg.id_tag,
        tg.numero_tag,
        tg.id_tipo_ubicacion,
        tu.nombre_tipo_ubicacion,
        tu.codigo_tipo_ubicacion,
        tg.estado_tag,
        COUNT(md.id_producto) AS total_productos,
        COUNT(DISTINCT cd.id_producto) AS total_contados,
        CASE 
            WHEN COUNT(md.id_producto) = 0 THEN 0
            ELSE ROUND(COUNT(DISTINCT cd.id_producto) * 100.0 / COUNT(md.id_producto))
        END AS porcentaje_avance
    FROM sod_inv_tag AS tg
    INNER JOIN sod_cfg_tipo_ubicacion AS tu ON tg.id_tipo_ubicacion = tu.id_tipo_ubicacion
    LEFT JOIN sod_inv_conteo_det AS cd ON cd.id_agenda = tg.id_agenda AND cd.id_tag = tg.id_tag AND cd.estado_registro = 'VIGENTE'
    LEFT JOIN sod_inv_muestra_det AS md ON md.id_producto = cd.id_producto AND md.fl_activo = 'S'
    WHERE tg.id_agenda = :agenda_id
      AND tg.fl_activo = 'S'
    GROUP BY tg.id_tag, tg.numero_tag, tg.id_tipo_ubicacion, tu.nombre_tipo_ubicacion, 
             tu.codigo_tipo_ubicacion, tg.estado_tag
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
