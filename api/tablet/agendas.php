<?php
# ============================================================
# ws/api/tablet/agendas.php
# GET ?rut=12345678-5
# Retorna agendas del auditor
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$rut = $_GET['rut'] ?? '';
if (empty($rut)) {
    errorResponse('Falta rut del auditor');
}

$rutNormalizado = preg_replace('/[^0-9kK]/', '', trim($rut));

try {
    $sql = "SELECT 
        a.id,
        a.fecha,
        t.id AS tienda_id,
        t.nombre AS tienda_nombre,
        t.rut AS tienda_rut,
        a.auditor_rut,
        a.estado,
        a.etapa,
        COUNT(tg.id) AS total_tags,
        SUM(CASE WHEN tg.estado = 'FINALIZADO' THEN 1 ELSE 0 END) AS tags_contados,
        CASE 
            WHEN COUNT(tg.id) = 0 THEN 0
            ELSE ROUND(SUM(CASE WHEN tg.estado = 'FINALIZADO' THEN 1 ELSE 0 END) * 100.0 / COUNT(tg.id))
        END AS porcentaje_avance,
        a.checkin_at,
        a.checkout_at
    FROM sod_agendas_dia a
    INNER JOIN sod_tiendas t ON a.tienda_id = t.id
    LEFT JOIN sod_tags tg ON a.id = tg.agenda_id
    WHERE a.auditor_rut = :rut
    AND a.fecha = CURDATE()
    AND a.estado IN ('PENDIENTE', 'EN_CURSO', 'VALIDADA')
    GROUP BY a.id, a.fecha, t.id, t.nombre, t.rut, a.auditor_rut, a.estado, a.etapa, a.checkin_at, a.checkout_at
    ORDER BY a.fecha DESC, t.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':rut' => $rutNormalizado]);
    $agendas = $stmt->fetchAll();

    okResponse($agendas);

} catch (PDOException $e) {
    errorResponse('Error al obtener agendas: ' . $e->getMessage(), 500);
}
