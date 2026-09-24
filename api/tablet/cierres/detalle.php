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
        "SELECT id_cierre_prevariance AS id, id_agenda AS agenda_id, id_tienda AS tienda_id,
                'PRE_VARIANCE' AS tipo_cierre,
                CASE estado_cierre
                    WHEN 'PENDIENTE_ENVIO' THEN 'PENDIENTE'
                    WHEN 'CERRADO' THEN 'COMPLETADO'
                    WHEN 'ANULADO' THEN 'ANULADO'
                    ELSE estado_cierre
                END AS estado,
                estado_cierre AS estado_cierre,
                COALESCE(observacion, '') AS observaciones,
                login_confirmacion AS creado_por,
                fecha_confirmacion AS creado_at,
                login_confirmacion AS cerrado_por,
                fecha_confirmacion AS cerrado_at
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

    // 4. Snapshot inmutable
    $stmtSnap = $pdo->prepare(
        "SELECT id_cierre_agenda, codigo_cierre, numero_version, fl_inmutable,
                total_stock_unidades, total_fisico_unidades, total_diferencia_unidades,
                total_valor_stock, total_valor_fisico, hash_cabecera
         FROM sod_inv_cierre_agenda
         WHERE id_agenda = :agenda_id AND fl_inmutable = 'S'
         ORDER BY numero_version DESC LIMIT 1"
    );
    $stmtSnap->execute([':agenda_id' => $agendaId]);
    $snapshot = $stmtSnap->fetch();

    okResponse([
        'cierres' => $cierres,
        'cierre_agenda' => $snapshot ? [
            'id_cierre_agenda' => (int)$snapshot['id_cierre_agenda'],
            'codigo_cierre' => $snapshot['codigo_cierre'],
            'numero_version' => (int)$snapshot['numero_version'],
            'fl_inmutable' => $snapshot['fl_inmutable'],
            'total_stock_unidades' => (float)$snapshot['total_stock_unidades'],
            'total_fisico_unidades' => (float)$snapshot['total_fisico_unidades'],
            'total_diferencia_unidades' => (float)$snapshot['total_diferencia_unidades'],
            'total_valor_stock' => (float)$snapshot['total_valor_stock'],
            'total_valor_fisico' => (float)$snapshot['total_valor_fisico'],
            'hash_cabecera' => $snapshot['hash_cabecera'],
        ] : null,
        'estado_agenda' => $agenda ? $agenda['codigo_estado'] : 'DESCONOCIDO',
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener cierres: ' . $e->getMessage(), 500);
}
