<?php
# ============================================================
# ws/api/tablet/pre-variance/guardar.php
# POST { agenda_id, productos: [{ id_producto, id_tag, decision, cantidad, motivo }] }
# Guarda Pre Variance (origen SGO_PREVARIANCE)
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['agenda_id'])) errorResponse('Falta agenda_id');
if (empty($input['productos']) || !is_array($input['productos'])) {
    errorResponse('Falta array de productos');
}

$agendaId  = (int)$input['agenda_id'];
$login     = $input['login'] ?? 'APP_TABLET';
$productos = $input['productos'];

try {
    $pdo->beginTransaction();

    // ── 1. Obtener/Crear C2 header ──────────────────────────
    $sqlC2 = "INSERT INTO sod_inv_conteo (
        id_agenda, numero_iteracion, tipo_conteo, estado_conteo,
        motivo, fecha_hora_inicio, login_responsable, fl_activo, usuario_creacion
    ) VALUES (
        :id_agenda, 2, 'VALIDACION', 'EN_PROCESO',
        'Pre Variance desde App Tablet.',
        NOW(), :login, 'S', :login
    )
    ON DUPLICATE KEY UPDATE
        id_conteo = LAST_INSERT_ID(id_conteo),
        estado_conteo = 'EN_PROCESO',
        fecha_hora_termino = NULL,
        login_responsable = VALUES(login_responsable),
        fecha_modificacion = NOW(),
        usuario_modificacion = VALUES(usuario_creacion)";

    $stmtC2 = $pdo->prepare($sqlC2);
    $stmtC2->execute([':id_agenda' => $agendaId, ':login' => $login]);
    $idC2 = $pdo->lastInsertId();

    if (!$idC2 || (int)$idC2 <= 0) {
        $stmtFind = $pdo->prepare(
            "SELECT id_conteo FROM sod_inv_conteo
             WHERE id_agenda = :id_agenda AND numero_iteracion = 2
               AND tipo_conteo = 'VALIDACION' AND fl_activo = 'S'
             ORDER BY id_conteo DESC LIMIT 1"
        );
        $stmtFind->execute([':id_agenda' => $agendaId]);
        $row = $stmtFind->fetch();
        if (!$row) throw new RuntimeException('No fue posible crear/obtener Conteo 2 VALIDACION.');
        $idC2 = (int)$row['id_conteo'];
    }

    // ── 2. Marcar Pre Variance viejos como REEMPLAZADO ──────
    $sqlMarkOld = "UPDATE sod_inv_conteo_det
    SET
        estado_registro = 'REEMPLAZADO',
        observacion = LEFT(
            CONCAT(COALESCE(observacion, ''), ' | Reemplazado por nuevo Pre Variance ', NOW()),
            500
        ),
        fecha_modificacion = NOW(),
        usuario_modificacion = :login
    WHERE id_conteo = :id_c2
      AND id_agenda = :id_agenda
      AND id_reconteo IS NULL
      AND origen = 'SGO_PREVARIANCE'
      AND estado_registro = 'VIGENTE'";

    $stmtMarkOld = $pdo->prepare($sqlMarkOld);
    $stmtMarkOld->execute([':login' => $login, ':id_c2' => $idC2, ':id_agenda' => $agendaId]);

    // ── 3. Insertar Pre Variance ────────────────────────────
    $sqlInsert = "INSERT INTO sod_inv_conteo_det (
        id_conteo, id_reconteo, id_agenda, id_tag, id_producto,
        login_operador, cantidad, fecha_hora_captura, fecha_hora_recepcion,
        dispositivo, origen, id_origen_externo, estado_registro,
        observacion, id_motivo_correccion, usuario_creacion
    ) VALUES (
        :id_c2, NULL, :id_agenda, :id_tag, :id_producto,
        :login, :cantidad, NOW(3), NOW(3),
        'APP_TABLET', 'SGO_PREVARIANCE', :id_origen, 'VIGENTE',
        :observacion, :id_motivo_correccion, :login
    )";

    $stmtInsert = $pdo->prepare($sqlInsert);

    $ts  = date('YmdHis');
    $pos = 0;

    foreach ($productos as $prod) {
        $idProducto = (int)($prod['id_producto'] ?? 0);
        $idTag      = (int)($prod['id_tag'] ?? 0);
        $decision   = strtoupper(trim($prod['decision'] ?? ''));
        $cantidad   = (float)($prod['cantidad'] ?? 0);
        $motivo     = $prod['motivo'] ?? null;
        $idMotivo   = $prod['id_motivo_correccion'] ?? null;

        if ($idProducto <= 0 || $idTag <= 0) {
            $pdo->rollBack();
            errorResponse('Cada producto debe tener id_producto e id_tag');
        }

        if (!in_array($decision, ['APROBADO', 'RECHAZADO'])) {
            $pdo->rollBack();
            errorResponse('Decision inválida: ' . $decision . '. Debe ser APROBADO o RECHAZADO');
        }

        // Origin pattern: SGO-PV-{agenda}-{producto}-{tag}-{ts}-{pos}
        $pos++;
        $idOrigen = "SGO-PV-{$agendaId}-{$idProducto}-{$idTag}-{$ts}-{$pos}";

        $obs = ($decision === 'RECHAZADO')
            ? "Pre Variance RECHAZADO. " . ($motivo ?: 'Sin observacion')
            : "Pre Variance APROBADO.";

        $stmtInsert->execute([
            ':id_c2'                 => $idC2,
            ':id_agenda'             => $agendaId,
            ':id_tag'                => $idTag,
            ':id_producto'           => $idProducto,
            ':login'                 => $login,
            ':cantidad'              => $cantidad,
            ':id_origen'             => $idOrigen,
            ':observacion'           => $obs,
            ':id_motivo_correccion'  => $idMotivo,
        ]);
    }

    // ── 4. Invalidar C3 posterior y recalcular ──────────────
    $pdo->exec("CALL PRC_SOD_INV_C3_INVALIDAR_POSTERIOR_V1({$agendaId}, " . $pdo->quote($login) . ")");
    $pdo->exec("CALL PRC_SOD_AGENDA_METRICAS_RECALCULAR_CORE_V1({$agendaId}, " . $pdo->quote($login) . ")");

    $pdo->commit();

    okResponse([
        'id_conteo_c2' => (int)$idC2,
        'productos_procesados' => count($productos),
    ], 'Pre Variance guardado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al guardar Pre Variance: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    errorResponse($e->getMessage(), 400);
}
