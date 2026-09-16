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
    // Obtener agenda
    $sql = "SELECT 
        a.id,
        a.fecha,
        t.id AS tienda_id,
        t.nombre AS tienda_nombre,
        t.rut AS tienda_rut,
        a.auditor_rut,
        a.estado,
        a.etapa,
        a.checkin_at,
        a.checkout_at
    FROM sod_agendas_dia a
    INNER JOIN sod_tiendas t ON a.tienda_id = t.id
    WHERE a.id = :agenda_id
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);
    $agenda = $stmt->fetch();

    if (!$agenda) {
        errorResponse('Agenda no encontrada', 404);
    }

    // Obtener tags de la agenda
    $sqlTags = "SELECT 
        tg.id,
        tg.cod_sod,
        tg.tienda_id,
        t.nombre AS tienda_nombre,
        tg.tipo,
        tg.estado,
        COUNT(c.id) AS total_productos,
        SUM(CASE WHEN c.cantidad > 0 THEN 1 ELSE 0 END) AS total_contados,
        CASE 
            WHEN COUNT(c.id) = 0 THEN 0
            ELSE ROUND(SUM(CASE WHEN c.cantidad > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(c.id))
        END AS porcentaje_avance,
        COALESCE(v.estado, 'PENDIENTE') AS validacion_estado
    FROM sod_tags tg
    INNER JOIN sod_tiendas t ON tg.tienda_id = t.id
    LEFT JOIN sod_capturas c ON tg.id = c.tag_id
    LEFT JOIN sod_validaciones v ON tg.id = v.tag_id
    WHERE tg.agenda_id = :agenda_id
    GROUP BY tg.id, tg.cod_sod, tg.tienda_id, t.nombre, tg.tipo, tg.estado, v.estado
    ORDER BY tg.tipo ASC, tg.cod_sod ASC";

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
