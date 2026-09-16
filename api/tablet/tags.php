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

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);
    $tags = $stmt->fetchAll();

    okResponse($tags);

} catch (PDOException $e) {
    errorResponse('Error al obtener tags: ' . $e->getMessage(), 500);
}
