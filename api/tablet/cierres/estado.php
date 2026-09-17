<?php
# ============================================================
# ws/api/tablet/cierres/estado.php
# GET ?agenda_id=123
# Estado consolidado de cierres de una agenda
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
    $preVar = $pdo->prepare("SELECT id_cierre_prevariance, estado_cierre, fecha_confirmacion
        FROM sod_inv_prevariance_cierre WHERE id_agenda = :a LIMIT 1");
    $preVar->execute([':a' => $agendaId]);
    $pv = $preVar->fetch();

    $recCierre = $pdo->prepare("SELECT id_reconteo_cierre, estado_cierre, total_sku, total_corregidos
        FROM sod_inv_reconteo_cierre WHERE id_agenda = :a AND fl_activo = 'S'
        ORDER BY numero_version DESC LIMIT 1");
    $recCierre->execute([':a' => $agendaId]);
    $rc = $recCierre->fetch();

    $pvCerrado = $pv && in_array($pv['estado_cierre'], ['CERRADO', 'ENVIADO']);
    $rcCerrado = $rc && in_array($rc['estado_cierre'], ['CERRADO', 'ENVIADO']);

    okResponse([
        'pre_variance' => $pv ? [
            'id' => $pv['id_cierre_prevariance'],
            'estado' => $pv['estado_cierre'],
            'fecha' => $pv['fecha_confirmacion'],
        ] : null,
        'pre_variance_cerrado' => $pvCerrado,
        'recuento' => $rc ? [
            'id' => $rc['id_reconteo_cierre'],
            'estado' => $rc['estado_cierre'],
            'total_sku' => $rc['total_sku'],
            'total_corregidos' => $rc['total_corregidos'],
        ] : null,
        'recuento_cerrado' => $rcCerrado,
        'puede_cerrar_final' => $pvCerrado && $rcCerrado,
    ]);

} catch (PDOException $e) {
    errorResponse('Error: ' . $e->getMessage(), 500);
}
