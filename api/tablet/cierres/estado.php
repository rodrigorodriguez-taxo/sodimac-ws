<?php
# ============================================================
# ws/api/tablet/cierres/estado.php
# GET ?agenda_id=123
# Retorna estado consolidado de todos los cierres de una agenda
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
    // Obtener cierres
    $sql = "SELECT 
        c.id,
        c.tipo_cierre,
        c.estado,
        c.productos_total,
        c.productos_validados,
        c.monto_total,
        c.monto_diferencia,
        c.cerrado_at
    FROM sod_cierres c
    WHERE c.agenda_id = :agenda_id
    ORDER BY c.tipo_cierre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);
    $cierres = $stmt->fetchAll();

    // Verificar prerequisitos
    $preVarianceCerrado = false;
    $recuentoCerrado = false;
    $puedeCerrarFinal = false;

    foreach ($cierres as $cierre) {
        if ($cierre['tipo_cierre'] === 'PRE_VARIANCE' && $cierre['estado'] === 'COMPLETADO') {
            $preVarianceCerrado = true;
        }
        if ($cierre['tipo_cierre'] === 'RECUENTO' && $cierre['estado'] === 'COMPLETADO') {
            $recuentoCerrado = true;
        }
    }

    $puedeCerrarFinal = $preVarianceCerrado && $recuentoCerrado;

    // Verificar si hay cierres pendientes
    $hayPreVariancePendiente = false;
    $hayRecuentoPendiente = false;

    foreach ($cierres as $cierre) {
        if ($cierre['tipo_cierre'] === 'PRE_VARIANCE' && $cierre['estado'] === 'PENDIENTE') {
            $hayPreVariancePendiente = true;
        }
        if ($cierre['tipo_cierre'] === 'RECUENTO' && $cierre['estado'] === 'PENDIENTE') {
            $hayRecuentoPendiente = true;
        }
    }

    okResponse([
        'cierres' => $cierres,
        'pre_variance_cerrado' => $preVarianceCerrado,
        'recuento_cerrado' => $recuentoCerrado,
        'puede_cerrar_final' => $puedeCerrarFinal,
        'hay_pre_variance_pendiente' => $hayPreVariancePendiente,
        'hay_recuento_pendiente' => $hayRecuentoPendiente,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener estado de cierres: ' . $e->getMessage(), 500);
}
