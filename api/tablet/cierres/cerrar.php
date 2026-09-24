<?php
# ============================================================
# ws/api/tablet/cierres/cerrar.php
# POST { agenda_id, observaciones?, login? }
# Marca cierre de Pre Variance y Recuento como CERRADO
# y actualiza agenda a FINALIZADA
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['agenda_id'])) {
    errorResponse('Falta agenda_id');
}

$agendaId = $input['agenda_id'];
$login = $input['login'] ?? 'AUDITOR';

try {
    $pdo->beginTransaction();

    $pv = $pdo->prepare("SELECT id_cierre_prevariance, estado_cierre
        FROM sod_inv_prevariance_cierre WHERE id_agenda = :a LIMIT 1");
    $pv->execute([':a' => $agendaId]);
    $preVar = $pv->fetch();

    $rc = $pdo->prepare("SELECT id_reconteo_cierre, estado_cierre
        FROM sod_inv_reconteo_cierre WHERE id_agenda = :a AND fl_activo = 'S'
        ORDER BY numero_version DESC LIMIT 1");
    $rc->execute([':a' => $agendaId]);
    $recuento = $rc->fetch();

    if (!$preVar) {
        $pdo->rollBack();
        errorResponse('Pre Variance no encontrado para esta agenda');
    }

    if (!$recuento) {
        $pdo->rollBack();
        errorResponse('Recuento no encontrado para esta agenda');
    }

    $pvCerrado = $preVar['estado_cierre'] === 'CERRADO';
    $rcCerrado = $recuento['estado_cierre'] === 'CERRADO';

    if (!$pvCerrado) {
        $updPV = $pdo->prepare("UPDATE sod_inv_prevariance_cierre 
            SET estado_cierre = 'CERRADO', fecha_modificacion = NOW(3), usuario_modificacion = :login
            WHERE id_cierre_prevariance = :id");
        $updPV->execute([':login' => $login, ':id' => $preVar['id_cierre_prevariance']]);
    }

    if (!$rcCerrado) {
        $updRC = $pdo->prepare("UPDATE sod_inv_reconteo_cierre 
            SET estado_cierre = 'CERRADO', fecha_modificacion = NOW(3), usuario_modificacion = :login
            WHERE id_reconteo_cierre = :id");
        $updRC->execute([':login' => $login, ':id' => $recuento['id_reconteo_cierre']]);
    }

    $updAgenda = $pdo->prepare("UPDATE sod_ope_agenda 
        SET id_estado_agenda = (SELECT id_estado_agenda FROM sod_ope_estado_agenda WHERE codigo_estado = 'CERRADA' LIMIT 1),
            fecha_hora_cierre = NOW(),
            fecha_modificacion = NOW()
        WHERE id_agenda = :a AND fl_activo = 'S'");
    $updAgenda->execute([':a' => $agendaId]);

    $pdo->commit();

    okResponse(null, 'Cierre completado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al cerrar: ' . $e->getMessage(), 500);
}
