<?php
# ============================================================
# ws/api/tablet/tags.php
# GET ?agenda_id=123
# Retorna tags de una agenda
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) {
    errorResponse('Falta agenda_id');
}

try {
    $sql = "SELECT 
        tg.id_tag AS id,
        tg.numero_tag AS cod_sod,
        tg.id_tipo_ubicacion,
        tg.estado_tag,
        tu.nombre_tipo_ubicacion,
        tu.codigo_tipo_ubicacion,
        CASE
            WHEN LOWER(tu.nombre_tipo_ubicacion) LIKE '%altillo%' THEN 'ALTILLO'
            WHEN LOWER(tu.nombre_tipo_ubicacion) LIKE '%punto de venta%'
              OR LOWER(tu.nombre_tipo_ubicacion) LIKE '%punto venta%'
              OR tu.codigo_tipo_ubicacion = 'PDV' THEN 'PDV'
            ELSE 'OTRO'
        END AS tipo,
        0 AS tienda_id,
        '' AS tienda_nombre,
        COUNT(cd.id_conteo_det) AS total_productos,
        SUM(CASE WHEN cd.cantidad > 0 THEN 1 ELSE 0 END) AS total_contados,
        CASE 
            WHEN COUNT(cd.id_conteo_det) = 0 THEN 0
            ELSE ROUND(SUM(CASE WHEN cd.cantidad > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(cd.id_conteo_det))
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
    LEFT JOIN sod_cfg_tipo_ubicacion AS tu ON tg.id_tipo_ubicacion = tu.id_tipo_ubicacion
    LEFT JOIN sod_inv_conteo_det AS cd ON tg.id_tag = cd.id_tag AND cd.estado_registro = 'VIGENTE'
    WHERE tg.id_agenda = :agenda_id
      AND tg.fl_activo = 'S'
    GROUP BY tg.id_tag, tg.numero_tag, tg.id_tipo_ubicacion, tg.estado_tag,
             tu.nombre_tipo_ubicacion, tu.codigo_tipo_ubicacion, tg.id_agenda
    ORDER BY tu.nombre_tipo_ubicacion ASC, tg.numero_tag ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => (int)$agendaId]);
    $tags = $stmt->fetchAll();

    okResponse($tags);

} catch (PDOException $e) {
    errorResponse('Error al obtener tags: ' . $e->getMessage(), 500);
}
