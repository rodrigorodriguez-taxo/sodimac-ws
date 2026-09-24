<?php
# ============================================================
# ws/api/tablet/cierres/anular-prevariance.php
# POST { agenda_id, motivo }
# Anula cierre Pre-Viance: CERRADO/PENDIENTE_ENVIO -> ANULADO
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();
$agendaId = $input['agenda_id'] ?? 0;
$motivo = trim($input['motivo'] ?? '');

if (!$agendaId) errorResponse('Falta agenda_id');
if (strlen($motivo) < 10) errorResponse('El motivo debe tener al menos 10 caracteres');

try {
    $pdo->beginTransaction();

    // Buscar cierre pre-variance
    $stmt = $pdo->prepare("SELECT id_cierre_prevariance, estado_cierre, observacion
        FROM sod_inv_prevariance_cierre
        WHERE id_agenda = :agenda
        ORDER BY id_cierre_prevariance DESC LIMIT 1");
    $stmt->execute([':agenda' => $agendaId]);
    $cierre = $stmt->fetch();

    if (!$cierre) {
        $pdo->rollBack();
        errorResponse('No existe cierre Pre-Variance para esta agenda');
    }

    if (!in_array($cierre['estado_cierre'], ['PENDIENTE_ENVIO', 'CERRADO'])) {
        $pdo->rollBack();
        errorResponse('El cierre Pre-Variance esta en estado ' . $cierre['estado_cierre'] . '. Solo se pueden anular cierres PENDIENTE_ENVIO o CERRADO.');
    }

    // Verificar que recuento NO este formalmente cerrado
    $recuento = $pdo->prepare("SELECT id_reconteo_cierre
        FROM sod_inv_reconteo_cierre
        WHERE id_agenda = :agenda AND fl_activo = 'S'
        ORDER BY numero_version DESC LIMIT 1");
    $recuento->execute([':agenda' => $agendaId]);
    $rec = $recuento->fetch();

    if ($rec) {
        $recEstado = $pdo->prepare("SELECT estado_cierre FROM sod_inv_reconteo_cierre WHERE id_reconteo_cierre = :id");
        $recEstado->execute([':id' => $rec['id_reconteo_cierre']]);
        $recEst = $recEstado->fetch();
        if ($recEst && $recEst['estado_cierre'] === 'CERRADO') {
            $pdo->rollBack();
            errorResponse('No se puede anular: el Cierre de Recuento ya esta formalmente cerrado.');
        }
    }

    $login = getBearerToken() ?? 'app_user';
    $obsAnterior = $cierre['observacion'] ?? '';
    $nuevaObs = $obsAnterior ? $obsAnterior . " | ANULADO: " . $motivo : "ANULADO: " . $motivo;

    // Actualizar cierre
    $upd = $pdo->prepare("UPDATE sod_inv_prevariance_cierre
        SET estado_cierre = 'ANULADO',
            observacion = :obs,
            fecha_modificacion = NOW(),
            usuario_modificacion = :login
        WHERE id_cierre_prevariance = :id");
    $upd->execute([
        ':obs' => $nuevaObs,
        ':login' => $login,
        ':id' => $cierre['id_cierre_prevariance'],
    ]);

    // Obtener datos adicionales del cierre para el audit trail
    $cierreFull = $pdo->prepare("SELECT id_tienda, fecha_confirmacion, login_confirmacion,
        confirmacion_texto, observacion, id_zip, id_envio, fecha_envio, login_envio
        FROM sod_inv_prevariance_cierre WHERE id_cierre_prevariance = :id");
    $cierreFull->execute([':id' => $cierre['id_cierre_prevariance']]);
    $full = $cierreFull->fetch();

    // Insertar registro de anulacion (audit trail completo)
    $ins = $pdo->prepare("INSERT INTO sod_inv_prevariance_cierre_anulacion
        (id_cierre_prevariance, id_agenda, id_tienda, estado_cierre_anterior,
         fecha_confirmacion_ant, login_confirmacion_ant, confirmacion_texto_ant,
         observacion_ant, id_zip_ant, id_envio_ant, fecha_envio_ant, login_envio_ant,
         motivo_anulacion, confirmacion_anulacion, login_anulacion, fecha_anulacion)
        VALUES
        (:cierre, :agenda, :tienda, :estado,
         :fec_conf, :log_conf, :txt_conf,
         :obs, :zip, :envio, :fec_env, :log_env,
         :motivo, 'ANULAR PRE VARIANCE', :login, NOW())");
    $ins->execute([
        ':cierre' => $cierre['id_cierre_prevariance'],
        ':agenda' => $agendaId,
        ':tienda' => $full['id_tienda'] ?? 0,
        ':estado' => $cierre['estado_cierre'],
        ':fec_conf' => $full['fecha_confirmacion'] ?? null,
        ':log_conf' => $full['login_confirmacion'] ?? null,
        ':txt_conf' => $full['confirmacion_texto'] ?? null,
        ':obs' => $full['observacion'] ?? null,
        ':zip' => $full['id_zip'] ?? null,
        ':envio' => $full['id_envio'] ?? null,
        ':fec_env' => $full['fecha_envio'] ?? null,
        ':log_env' => $full['login_envio'] ?? null,
        ':motivo' => $motivo,
        ':login' => $login,
    ]);

    $pdo->commit();

    okResponse([
        'id_cierre_prevariance' => (int) $cierre['id_cierre_prevariance'],
        'estado_cierre' => 'ANULADO',
        'agenda_id' => (int) $agendaId,
    ], 'Cierre Pre-Variance anulado correctamente. La agenda ha vuelto a Etapa 2.');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al anular cierre: ' . $e->getMessage(), 500);
}
