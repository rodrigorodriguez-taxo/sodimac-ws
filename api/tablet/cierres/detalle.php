<?php
# ============================================================
# ws/api/tablet/cierres/detalle.php
# GET ?agenda_id=123
# Retorna cierres disponibles para una agenda
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) {
    errorResponse('Falta agenda_id');
}

try {
    // Obtener cierres de la agenda
    $sql = "SELECT 
        c.id,
        c.agenda_id,
        c.tienda_id,
        t.nombre AS tienda_nombre,
        c.tipo_cierre,
        c.estado,
        c.productos_total,
        c.productos_validados,
        c.monto_total,
        c.monto_diferencia,
        c.observaciones,
        c.creado_por,
        c.creado_at,
        c.cerrado_por,
        c.cerrado_at
    FROM sod_cierres c
    INNER JOIN sod_tiendas t ON c.tienda_id = t.id
    WHERE c.agenda_id = :agenda_id
    ORDER BY c.tipo_cierre ASC, c.creado_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);
    $cierres = $stmt->fetchAll();

    // Si no hay cierres, crear uno de Pre Variance si aplica
    if (empty($cierres)) {
        // Verificar si hay Pre Variance aprobado
        $sqlCheck = "SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN pv.estado = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados
        FROM sod_pre_variance pv
        WHERE pv.agenda_id = :agenda_id";

        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([':agenda_id' => $agendaId]);
        $check = $stmtCheck->fetch();

        if ($check['total'] > 0 && $check['aprobados'] > 0) {
            // Obtener tienda de la agenda
            $sqlTienda = "SELECT tienda_id FROM sod_agendas WHERE id = :agenda_id LIMIT 1";
            $stmtTienda = $pdo->prepare($sqlTienda);
            $stmtTienda->execute([':agenda_id' => $agendaId]);
            $agendaInfo = $stmtTienda->fetch();

            if ($agendaInfo) {
                // Crear cierre de Pre Variance
                $sqlCreate = "INSERT INTO sod_cierres 
                    (agenda_id, tienda_id, tipo_cierre, estado, productos_total, productos_validados, monto_total, monto_diferencia, creado_por, creado_at)
                    VALUES 
                    (:agenda_id, :tienda_id, 'PRE_VARIANCE', 'PENDIENTE', 0, 0, 0, 0, 'SYSTEM', NOW())";

                $stmtCreate = $pdo->prepare($sqlCreate);
                $stmtCreate->execute([
                    ':agenda_id' => $agendaId,
                    ':tienda_id' => $agendaInfo['tienda_id'],
                ]);

                // Recargar cierres
                $stmt->execute([':agenda_id' => $agendaId]);
                $cierres = $stmt->fetchAll();
            }
        }
    }

    okResponse([
        'cierres' => $cierres,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener cierres: ' . $e->getMessage(), 500);
}
