<?php
# ============================================================
# ws/api/tablet/validacion/incorporar.php
# POST { agenda_id, tag_id, id_producto, cantidad, motivo, login }
# Incorpora un SKU nuevo durante la validación C2
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['agenda_id'])) errorResponse('Falta agenda_id');
if (empty($input['tag_id']))    errorResponse('Falta tag_id');
if (empty($input['id_producto'])) errorResponse('Falta id_producto');
if (!isset($input['cantidad']) || (float)$input['cantidad'] <= 0) {
    errorResponse('Falta cantidad válida');
}

$agendaId   = (int)$input['agenda_id'];
$tagId      = (int)$input['tag_id'];
$idProducto = (int)$input['id_producto'];
$cantidad   = (float)$input['cantidad'];
$motivo     = $input['motivo'] ?? 'SKU incorporado durante validación';
$login      = $input['login'] ?? 'APP_TABLET';

try {
    $pdo->beginTransaction();

    // ── 1. Validar que el producto pertenece a la muestra ────
    $stmtProd = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
         FROM sod_inv_agenda_muestra AS am
         INNER JOIN sod_inv_muestra_det AS md
                 ON md.id_muestra = am.id_muestra AND md.fl_activo = 'S'
         INNER JOIN sod_cfg_producto AS p
                 ON p.id_producto = md.id_producto AND p.fl_activo = 'S'
         WHERE am.id_agenda = :agenda_id
           AND am.fl_activo = 'S'
           AND p.id_producto = :id_producto"
    );
    $stmtProd->execute([':agenda_id' => $agendaId, ':id_producto' => $idProducto]);
    if ((int)$stmtProd->fetchColumn() <= 0) {
        throw new RuntimeException('El SKU no pertenece a la muestra vigente de la agenda.');
    }

    // ── 2. Obtener C2 header ────────────────────────────────
    $stmtC2 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 2
           AND tipo_conteo = 'VALIDACION' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC2->execute([':agenda_id' => $agendaId]);
    $c2 = $stmtC2->fetch();
    if (!$c2) throw new RuntimeException('No existe Conteo 2 VALIDACION para esta agenda.');
    $idC2 = (int)$c2['id_conteo'];

    // ── 3. Marcar C2 viejos del TAG+SKU como REEMPLAZADO ────
    $stmtMark = $pdo->prepare(
        "UPDATE sod_inv_conteo_det
         SET estado_registro = 'REEMPLAZADO',
             observacion = LEFT(
                 CONCAT(COALESCE(observacion, ''), ' | Reemplazado por nueva incorporacion C2 ', NOW()),
                 500
             ),
             fecha_modificacion = NOW(),
             usuario_modificacion = :login
         WHERE id_conteo = :id_c2
           AND id_agenda = :id_agenda
           AND id_tag = :tag_id
           AND id_producto = :id_producto
           AND id_reconteo IS NULL
           AND origen = 'SGO_ANALISTA'
           AND estado_registro = 'VIGENTE'"
    );
    $stmtMark->execute([
        ':login' => $login, ':id_c2' => $idC2,
        ':id_agenda' => $agendaId, ':tag_id' => $tagId,
        ':id_producto' => $idProducto,
    ]);

    // ── 4. Insertar nuevo registro INCORPORAR ────────────────
    $ts = date('YmdHis');
    $idOrigen = "SGO-VAL-TAG-INC-{$agendaId}-{$tagId}-{$idProducto}-{$ts}-1";

    $obs = "Validacion operacional modo TAG registrada por Analista Sodimac. "
         . "SKU INCORPORADO DURANTE VALIDACION - original=0 - validada={$cantidad}";

    $stmtInsert = $pdo->prepare(
        "INSERT INTO sod_inv_conteo_det (
            id_conteo, id_reconteo, id_agenda, id_tag, id_producto,
            login_operador, cantidad, fecha_hora_captura, fecha_hora_recepcion,
            dispositivo, origen, id_origen_externo, estado_registro,
            observacion, id_motivo_correccion, usuario_creacion
        ) VALUES (
            :id_c2, NULL, :id_agenda, :tag_id, :id_producto,
            :login, :cantidad, NOW(3), NOW(3),
            'APP_TABLET', 'SGO_ANALISTA', :id_origen, 'VIGENTE',
            :obs, NULL, :login
        )"
    );
    $stmtInsert->execute([
        ':id_c2' => $idC2, ':id_agenda' => $agendaId,
        ':tag_id' => $tagId, ':id_producto' => $idProducto,
        ':login' => $login, ':cantidad' => $cantidad,
        ':id_origen' => $idOrigen, ':obs' => $obs,
    ]);

    // ── 5. Invalidar C3 y recalcular ────────────────────────
    $pdo->exec("CALL PRC_SOD_INV_C3_INVALIDAR_POSTERIOR_V1({$agendaId}, " . $pdo->quote($login) . ")");
    $pdo->exec("CALL PRC_SOD_AGENDA_METRICAS_RECALCULAR_CORE_V1({$agendaId}, " . $pdo->quote($login) . ")");

    $pdo->commit();

    okResponse([
        'id_conteo_det' => (int)$pdo->lastInsertId(),
    ], 'SKU incorporado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al incorporar SKU: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    errorResponse($e->getMessage(), 400);
}
