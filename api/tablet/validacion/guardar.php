<?php
# ============================================================
# ws/api/tablet/validacion/guardar.php
# POST { agenda_id, tag_id, capturas: [{ id_conteo_det_c1, id_producto, decision, c2_cantidad, motivo }] }
# Guarda la validación C2 línea a línea (snapshot)
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';
require_once '../../../helpers/c3.php';
require_once '../../../helpers/sync.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

// Validaciones de entrada
if (empty($input['agenda_id'])) errorResponse('Falta agenda_id');
if (empty($input['tag_id']))    errorResponse('Falta tag_id');
if (empty($input['capturas']) || !is_array($input['capturas'])) {
    errorResponse('Falta array de capturas');
}

$agendaId = (int)$input['agenda_id'];
$tagId    = (int)$input['tag_id'];
$login    = $input['login'] ?? 'APP_TABLET';
$capturas = $input['capturas'];

try {
    $pdo->beginTransaction();

    // ── 1. Obtener/Crear C2 header ──────────────────────────
    $sqlC2 = "INSERT INTO sod_inv_conteo (
        id_agenda, numero_iteracion, tipo_conteo, estado_conteo,
        motivo, fecha_hora_inicio, login_responsable, fl_activo, usuario_creacion
    ) VALUES (
        :id_agenda, 2, 'VALIDACION', 'EN_PROCESO',
        'Validacion operacional Sodimac desde App Tablet.',
        NOW(), :login, 'S', :login2
    )
    ON DUPLICATE KEY UPDATE
        id_conteo = LAST_INSERT_ID(id_conteo),
        estado_conteo = 'EN_PROCESO',
        fecha_hora_termino = NULL,
        login_responsable = VALUES(login_responsable),
        fecha_modificacion = NOW(),
        usuario_modificacion = VALUES(usuario_creacion)";

    $stmtC2 = $pdo->prepare($sqlC2);
    $stmtC2->execute([':id_agenda' => $agendaId, ':login' => $login, ':login2' => $login]);
    $idC2 = $pdo->lastInsertId();

    if (!$idC2 || (int)$idC2 <= 0) {
        // Fallback: buscar C2 existente
        $stmtFind = $pdo->prepare(
            "SELECT id_conteo FROM sod_inv_conteo
             WHERE id_agenda = :id_agenda AND numero_iteracion = 2
               AND tipo_conteo = 'VALIDACION' AND fl_activo = 'S'
             ORDER BY id_conteo DESC LIMIT 1"
        );
        $stmtFind->execute([':id_agenda' => $agendaId]);
        $row = $stmtFind->fetch();
        if (!$row) {
            throw new RuntimeException('No fue posible crear/obtener Conteo 2 VALIDACION.');
        }
        $idC2 = (int)$row['id_conteo'];
    }

    // ── 2. Marcar C2 viejos como REEMPLAZADO ────────────────
    $sqlMarkOld = "UPDATE sod_inv_conteo_det
    SET
        estado_registro = 'REEMPLAZADO',
        observacion = LEFT(
            CONCAT(
                COALESCE(observacion, ''),
                ' | Reemplazado por snapshot linea-a-linea ',
                NOW()
            ),
            500
        ),
        fecha_modificacion = NOW(),
        usuario_modificacion = :login
    WHERE id_conteo = :id_c2
      AND id_agenda = :id_agenda
      AND id_tag = :id_tag
      AND id_reconteo IS NULL
      AND origen = 'SGO_ANALISTA'
      AND estado_registro = 'VIGENTE'";

    $stmtMarkOld = $pdo->prepare($sqlMarkOld);
    $stmtMarkOld->execute([
        ':login'    => $login,
        ':id_c2'    => $idC2,
        ':id_agenda'=> $agendaId,
        ':id_tag'   => $tagId,
    ]);

    // ── 3. Insertar nuevas líneas C2 ────────────────────────
    $sqlInsert = "INSERT INTO sod_inv_conteo_det (
        id_conteo, id_reconteo, id_agenda, id_tag, id_producto,
        login_operador, cantidad, fecha_hora_captura, fecha_hora_recepcion,
        dispositivo, origen, id_origen_externo, estado_registro,
        observacion, id_motivo_correccion, usuario_creacion
    ) VALUES (
        :id_c2, NULL, :id_agenda, :id_tag, :id_producto,
        :login, :cantidad, NOW(3), NOW(3),
        'APP_TABLET', 'SGO_ANALISTA', :id_origen, 'VIGENTE',
        :observacion, :id_motivo_correccion, :login2
    )";

    $stmtInsert = $pdo->prepare($sqlInsert);

    $ts = date('YmdHis');
    $pos = 0;

    foreach ($capturas as $cap) {
        $idC1Det     = (int)($cap['id_conteo_det_c1'] ?? 0);
        $idProducto  = (int)($cap['id_producto'] ?? 0);
        $decision    = strtoupper(trim($cap['decision'] ?? ''));
        $c2Cantidad  = $cap['c2_cantidad'] ?? null;
        $motivo      = $cap['motivo'] ?? null;
        $idMotivo    = $cap['id_motivo_correccion'] ?? null;

        if ($idC1Det <= 0 || $idProducto <= 0) {
            $pdo->rollBack();
            errorResponse('Cada captura debe tener id_conteo_det_c1 e id_producto');
        }

        if (!in_array($decision, ['CONFIRMAR', 'MODIFICAR'])) {
            $pdo->rollBack();
            errorResponse('Decision inválida: ' . $decision . '. Debe ser CONFIRMAR o MODIFICAR');
        }

        // Obtener cantidad base de C1
        $stmtBase = $pdo->prepare(
            "SELECT cantidad FROM sod_inv_conteo_det
             WHERE id_conteo_det = :id_c1_det AND estado_registro = 'VIGENTE'
             LIMIT 1"
        );
        $stmtBase->execute([':id_c1_det' => $idC1Det]);
        $rowBase = $stmtBase->fetch();
        if (!$rowBase) {
            $pdo->rollBack();
            errorResponse('Captura C1 no encontrada: ' . $idC1Det);
        }

        $cantidad = ($decision === 'MODIFICAR' && $c2Cantidad !== null)
            ? (float)$c2Cantidad
            : (float)$rowBase['cantidad'];

        // Origin pattern: SGO-VAL-LINEA-{agenda}-{c1det}-{ts}-{pos}
        $pos++;
        $idOrigen = "SGO-VAL-LINEA-{$agendaId}-{$idC1Det}-{$ts}-{$pos}";

        $obs = ($decision === 'MODIFICAR')
            ? "Validacion operacional modo TAG registrada por Analista Sodimac. " . ($motivo ?: 'Sin observacion')
            : "Validacion operacional modo TAG registrada por Analista Sodimac. Confirmado.";

        $stmtInsert->execute([
            ':id_c2'                 => $idC2,
            ':id_agenda'             => $agendaId,
            ':id_tag'                => $tagId,
            ':id_producto'           => $idProducto,
            ':login'                 => $login,
            ':cantidad'              => $cantidad,
            ':id_origen'             => $idOrigen,
            ':observacion'           => $obs,
            ':id_motivo_correccion'  => $idMotivo,
            ':login2'                => $login,
        ]);
    }

    // ── 4. Auditoría ────────────────────────────────────────
    $stmtAudit = $pdo->prepare(
        "INSERT INTO sod_aud_evento (
            fecha_evento, login, modulo, accion, entidad, entidad_id,
            id_agenda, valor_nuevo, resultado, mensaje_resultado
        ) VALUES (
            NOW(3), :login, 'APP_TABLET', 'VALIDAR_OPERACION_SGO',
            'sod_inv_conteo_det', 'TAG-{$tagId}',
            :id_agenda, NULL, 'OK',
            'Validacion operacional registrada desde App Tablet.'
        )"
    );
    $stmtAudit->execute([':login' => $login, ':id_agenda' => $agendaId]);

    // ── 5. Invalidar C3 (si justificado) + encolar recálculo ──
    $invalidarC3 = c3_invalidacion_justificada($pdo, $agendaId);
    sync_marcar_pendiente($pdo, $agendaId, 'VALIDACION', $invalidarC3, $login);

    $pdo->commit();

    okResponse([
        'id_conteo_c2' => (int)$idC2,
        'capturas_procesadas' => count($capturas),
    ], 'Validación C2 guardada correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al guardar validación: ' . $e->getMessage(), 500);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    errorResponse($e->getMessage(), 400);
}
