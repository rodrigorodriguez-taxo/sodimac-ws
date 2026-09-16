<?php
# ============================================================
# ws/api/tablet/cierres/cerrar.php
# POST { cierre_id, observaciones? }
# Cierra un cierre (PRE_VARIANCE, RECUENTO, o FINAL)
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['cierre_id'])) {
    errorResponse('Falta cierre_id');
}

$cierreId = $input['cierre_id'];
$observaciones = $input['observaciones'] ?? null;

try {
    $pdo->beginTransaction();

    // Obtener cierre actual
    $sql = "SELECT 
        c.id,
        c.agenda_id,
        c.tienda_id,
        c.tipo_cierre,
        c.estado,
        c.productos_total,
        c.productos_validados,
        c.monto_total,
        c.monto_diferencia
    FROM sod_cierres c
    WHERE c.id = :cierre_id
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cierre_id' => $cierreId]);
    $cierre = $stmt->fetch();

    if (!$cierre) {
        $pdo->rollBack();
        errorResponse('Cierre no encontrado', 404);
    }

    if ($cierre['estado'] === 'COMPLETADO') {
        $pdo->rollBack();
        errorResponse('El cierre ya está completado');
    }

    // Verificar prerequisites según tipo de cierre
    switch ($cierre['tipo_cierre']) {
        case 'PRE_VARIANCE':
            // Verificar que todos los Pre Variance estén aprobados
            $sqlCheck = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN estado = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados
            FROM sod_pre_variance
            WHERE agenda_id = :agenda_id";

            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([':agenda_id' => $cierre['agenda_id']]);
            $check = $stmtCheck->fetch();

            if ($check['total'] > 0 && $check['aprobados'] < $check['total']) {
                $pdo->rollBack();
                errorResponse('No se puede cerrar: hay Pre Variance pendientes de aprobación');
            }
            break;

        case 'RECUENTO':
            // Verificar que todos los Recuentos estén finalizados
            $sqlCheck = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN estado = 'FINALIZADO' THEN 1 ELSE 0 END) AS finalizados
            FROM sod_recuento
            WHERE agenda_id = :agenda_id";

            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([':agenda_id' => $cierre['agenda_id']]);
            $check = $stmtCheck->fetch();

            if ($check['total'] > 0 && $check['finalizados'] < $check['total']) {
                $pdo->rollBack();
                errorResponse('No se puede cerrar: hay Recuentos pendientes de finalización');
            }
            break;

        case 'FINAL':
            // Verificar que Pre Variance y Recuento estén cerrados
            $sqlCheckPV = "SELECT estado FROM sod_cierres 
                WHERE agenda_id = :agenda_id AND tipo_cierre = 'PRE_VARIANCE' LIMIT 1";
            $stmtCheckPV = $pdo->prepare($sqlCheckPV);
            $stmtCheckPV->execute([':agenda_id' => $cierre['agenda_id']]);
            $cierrePV = $stmtCheckPV->fetch();

            $sqlCheckR = "SELECT estado FROM sod_cierres 
                WHERE agenda_id = :agenda_id AND tipo_cierre = 'RECUENTO' LIMIT 1";
            $stmtCheckR = $pdo->prepare($sqlCheckR);
            $stmtCheckR->execute([':agenda_id' => $cierre['agenda_id']]);
            $cierreR = $stmtCheckR->fetch();

            if (($cierrePV && $cierrePV['estado'] !== 'COMPLETADO') || 
                ($cierreR && $cierreR['estado'] !== 'COMPLETADO')) {
                $pdo->rollBack();
                errorResponse('No se puede cerrar: Pre Variance y Recuento deben estar completados primero');
            }
            break;
    }

    // Calcular estadísticas finales
    $sqlStats = "";
    switch ($cierre['tipo_cierre']) {
        case 'PRE_VARIANCE':
            $sqlStats = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN estado = 'APROBADO' THEN 1 ELSE 0 END) AS validados,
                SUM(diferencia_monto) AS total_diferencia
            FROM sod_pre_variance_producto pvp
            INNER JOIN sod_pre_variance pv ON pvp.pre_variance_id = pv.id
            WHERE pv.agenda_id = :agenda_id";
            break;

        case 'RECUENTO':
            $sqlStats = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN estado IN ('CONFIRMADO', 'MODIFICADO') THEN 1 ELSE 0 END) AS validados,
                SUM(diferencia_monto) AS total_diferencia
            FROM sod_recuento_producto rp
            INNER JOIN sod_recuento r ON rp.recuento_id = r.id
            WHERE r.agenda_id = :agenda_id";
            break;

        case 'FINAL':
            $sqlStats = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN vc.estado IN ('CONFIRMADA', 'MODIFICADA') THEN 1 ELSE 0 END) AS validados,
                0 AS total_diferencia
            FROM sod_capturas c
            INNER JOIN sod_tags tg ON c.tag_id = tg.id
            LEFT JOIN sod_validacion_capturas vc ON c.id = vc.captura_id
            WHERE tg.agenda_id = :agenda_id
            AND c.cantidad > 0";
            break;
    }

    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute([':agenda_id' => $cierre['agenda_id']]);
    $stats = $stmtStats->fetch();

    // Actualizar cierre
    $sqlUpdate = "UPDATE sod_cierres 
        SET estado = 'COMPLETADO',
            productos_total = :total,
            productos_validados = :validados,
            monto_diferencia = :diferencia,
            observaciones = :observaciones,
            cerrado_por = :cerrado_por,
            cerrado_at = NOW()
        WHERE id = :cierre_id";

    $stmtUpdate = $pdo->prepare($sqlUpdate);
    $stmtUpdate->execute([
        ':total' => $stats['total'] ?? 0,
        ':validados' => $stats['validados'] ?? 0,
        ':diferencia' => $stats['total_diferencia'] ?? 0,
        ':observaciones' => $observaciones,
        ':cerrado_por' => 'AUDITOR',
        ':cierre_id' => $cierreId,
    ]);

    // Si es cierre FINAL, actualizar agenda a COMPLETADA
    if ($cierre['tipo_cierre'] === 'FINAL') {
        $sqlUpdateAgenda = "UPDATE sod_agendas 
            SET estado = 'COMPLETADA',
                fecha_cierre = NOW()
            WHERE id = :agenda_id";

        $stmtUpdateAgenda = $pdo->prepare($sqlUpdateAgenda);
        $stmtUpdateAgenda->execute([':agenda_id' => $cierre['agenda_id']]);
    }

    $pdo->commit();

    okResponse(null, 'Cierre completado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al cerrar: ' . $e->getMessage(), 500);
}
