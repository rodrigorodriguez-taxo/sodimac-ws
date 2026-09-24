<?php
# ============================================================
# ws/api/tablet/recuento/guardar.php
# POST { agenda_id, productos: [{ id_producto, id_tag, cantidad, observacion, id_motivo_correccion }] }
# Guarda Recuento C3 (origen SGO_RECUENTO)
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';
require_once '../../../helpers/sync.php';

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

    // ── 1. Obtener/Crear C3 header ──────────────────────────
    $sqlC3 = "INSERT INTO sod_inv_conteo (
        id_agenda, numero_iteracion, tipo_conteo, estado_conteo,
        motivo, fecha_hora_inicio, login_responsable, fl_activo, usuario_creacion
    ) VALUES (
        :id_agenda, 3, 'RECONTEO', 'EN_PROCESO',
        'Recuento Analista desde App Tablet.',
        NOW(), :login, 'S', :login
    )
    ON DUPLICATE KEY UPDATE
        id_conteo = LAST_INSERT_ID(id_conteo),
        estado_conteo = 'EN_PROCESO',
        fecha_hora_termino = NULL,
        login_responsable = VALUES(login_responsable),
        fecha_modificacion = NOW(),
        usuario_modificacion = VALUES(usuario_creacion)";

    $stmtC3 = $pdo->prepare($sqlC3);
    $stmtC3->execute([':id_agenda' => $agendaId, ':login' => $login]);
    $idC3 = $pdo->lastInsertId();

    if (!$idC3 || (int)$idC3 <= 0) {
        $stmtFind = $pdo->prepare(
            "SELECT id_conteo FROM sod_inv_conteo
             WHERE id_agenda = :id_agenda AND numero_iteracion = 3
               AND tipo_conteo = 'RECONTEO' AND fl_activo = 'S'
             ORDER BY id_conteo DESC LIMIT 1"
        );
        $stmtFind->execute([':id_agenda' => $agendaId]);
        $row = $stmtFind->fetch();
        if (!$row) throw new RuntimeException('No fue posible crear/obtener Conteo 3 RECONTEO.');
        $idC3 = (int)$row['id_conteo'];
    }

    // ── 2. Obtener C1 y C2 para base quantity ───────────────
    $stmtC1 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :id_agenda AND numero_iteracion = 1
           AND tipo_conteo = 'INICIAL' AND fl_activo = 'S'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC1->execute([':id_agenda' => $agendaId]);
    $c1 = $stmtC1->fetch();
    $idC1 = $c1 ? (int)$c1['id_conteo'] : 0;

    $stmtC2 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :id_agenda AND numero_iteracion = 2
           AND tipo_conteo = 'VALIDACION' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC2->execute([':id_agenda' => $agendaId]);
    $c2 = $stmtC2->fetch();
    $idC2 = $c2 ? (int)$c2['id_conteo'] : 0;

    // ── 3. Para cada producto: marcar viejos + insertar ──────
    $sqlBaseQty = "SELECT COALESCE(
        (SELECT SUM(pv.cantidad) FROM sod_inv_conteo_det AS pv
         WHERE pv.id_conteo = :id_c2 AND pv.id_producto = :id_producto
           AND pv.id_tag = :id_tag AND pv.id_reconteo IS NULL
           AND pv.origen = 'SGO_PREVARIANCE' AND pv.estado_registro = 'VIGENTE'),
        (SELECT SUM(op.cantidad) FROM sod_inv_conteo_det AS op
         WHERE op.id_conteo = :id_c2 AND op.id_producto = :id_producto
           AND op.id_tag = :id_tag AND op.id_reconteo IS NULL
           AND op.origen = 'SGO_ANALISTA' AND op.estado_registro = 'VIGENTE'),
        (SELECT SUM(c1d.cantidad) FROM sod_inv_conteo_det AS c1d
         WHERE c1d.id_conteo = :id_c1 AND c1d.id_producto = :id_producto
           AND c1d.id_tag = :id_tag AND c1d.estado_registro = 'VIGENTE'),
        0
    ) AS cantidad";
    $stmtBase = $pdo->prepare($sqlBaseQty);

    $sqlMarkOld = "UPDATE sod_inv_conteo_det
    SET
        estado_registro = 'REEMPLAZADO',
        observacion = LEFT(
            CONCAT(COALESCE(observacion, ''), ' | Reemplazado por nuevo Recuento ', NOW()),
            500
        ),
        fecha_modificacion = NOW(),
        usuario_modificacion = :login
    WHERE id_conteo = :id_c3
      AND id_agenda = :id_agenda
      AND id_producto = :id_producto
      AND id_tag = :id_tag
      AND id_reconteo IS NULL
      AND origen = 'SGO_RECUENTO'
      AND estado_registro = 'VIGENTE'";
    $stmtMarkOld = $pdo->prepare($sqlMarkOld);

    $sqlInsert = "INSERT INTO sod_inv_conteo_det (
        id_conteo, id_reconteo, id_agenda, id_tag, id_producto,
        login_operador, cantidad, fecha_hora_captura, fecha_hora_recepcion,
        dispositivo, origen, id_origen_externo, estado_registro,
        observacion, id_motivo_correccion, usuario_creacion
    ) VALUES (
        :id_c3, NULL, :id_agenda, :id_tag, :id_producto,
        :login, :cantidad, NOW(3), NOW(3),
        'APP_TABLET', 'SGO_RECUENTO', :id_origen, 'VIGENTE',
        :observacion, :id_motivo_correccion, :login
    )";
    $stmtInsert = $pdo->prepare($sqlInsert);

    $ts  = date('YmdHis');
    $pos = 0;

    foreach ($productos as $prod) {
        $idProducto = (int)($prod['id_producto'] ?? 0);
        $idTag      = (int)($prod['id_tag'] ?? 0);
        $cantidad   = isset($prod['cantidad']) ? (float)$prod['cantidad'] : null;
        $motivo     = $prod['observacion'] ?? null;
        $idMotivo   = $prod['id_motivo_correccion'] ?? null;

        if ($idProducto <= 0 || $idTag <= 0) {
            $pdo->rollBack();
            errorResponse('Cada producto debe tener id_producto e id_tag');
        }

        // Si no se envió cantidad, usar base quantity
        if ($cantidad === null) {
            $stmtBase->execute([
                ':id_c2' => $idC2, ':id_c1' => $idC1,
                ':id_producto' => $idProducto, ':id_tag' => $idTag,
            ]);
            $cantidad = (float)$stmtBase->fetchColumn();
        }

        // Marcar viejos como REEMPLAZADO
        $stmtMarkOld->execute([
            ':login'     => $login,
            ':id_c3'     => $idC3,
            ':id_agenda' => $agendaId,
            ':id_producto' => $idProducto,
            ':id_tag'    => $idTag,
        ]);

        // Origin pattern: SGO-RC-{agenda}-{producto}-{tag}-{ts}-{pos}
        $pos++;
        $idOrigen = "SGO-RC-{$agendaId}-{$idProducto}-{$idTag}-{$ts}-{$pos}";

        $obs = "Recuento registrado desde App Tablet."
            . ($motivo ? " Observacion: {$motivo}" : '');

        $stmtInsert->execute([
            ':id_c3'                 => $idC3,
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

    // ── 4. Encolar recálculo canónico (invalidar_c3=false, igual que SC) ──
    sync_marcar_pendiente($pdo, $agendaId, 'RECUENTO', false, $login);

    $pdo->commit();

    okResponse([
        'id_conteo_c3' => (int)$idC3,
        'productos_procesados' => count($productos),
    ], 'Recuento guardado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al guardar recuento: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    errorResponse($e->getMessage(), 400);
}
