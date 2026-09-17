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
    $cierres = [];

    // 1. Pre Variance cierre
    $stmtPV = $pdo->prepare(
        "SELECT id_cierre_prevariance AS id, 'PRE_VARIANCE' AS tipo_cierre,
                estado_cierre, observacion, login_confirmacion AS login_cierre, fecha_confirmacion AS fecha_hora_cierre
         FROM sod_inv_prevariance_cierre
         WHERE id_agenda = :agenda_id
         ORDER BY id_cierre_prevariance DESC LIMIT 1"
    );
    $stmtPV->execute([':agenda_id' => $agendaId]);
    $pv = $stmtPV->fetch();
    if ($pv) $cierres[] = $pv;

    // 2. Recuento cierre
    $stmtRC = $pdo->prepare(
        "SELECT id_reconteo_cierre AS id, 'RECUENTO' AS tipo_cierre,
                estado_cierre, total_sku AS productos_total, total_corregidos AS productos_reconteados,
                observacion, login_cierre, fecha_cierre AS fecha_hora_cierre
         FROM sod_inv_reconteo_cierre
         WHERE id_agenda = :agenda_id AND fl_activo = 'S'
         ORDER BY numero_version DESC, id_reconteo_cierre DESC LIMIT 1"
    );
    $stmtRC->execute([':agenda_id' => $agendaId]);
    $rc = $stmtRC->fetch();
    if ($rc) $cierres[] = $rc;

    // 3. Estado agenda
    $stmtAgenda = $pdo->prepare(
        "SELECT ea.codigo_estado
         FROM sod_ope_agenda AS a
         INNER JOIN sod_ope_estado_agenda AS ea ON ea.id_estado_agenda = a.id_estado_agenda
         WHERE a.id_agenda = :agenda_id AND a.fl_activo = 'S'"
    );
    $stmtAgenda->execute([':agenda_id' => $agendaId]);
    $agenda = $stmtAgenda->fetch();

    okResponse([
        'cierres' => $cierres,
        'estado_agenda' => $agenda ? $agenda['codigo_estado'] : 'DESCONOCIDO',
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener cierres: ' . $e->getMessage(), 500);
}
