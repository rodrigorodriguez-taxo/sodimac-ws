<?php
# ============================================================
# ws/api/tablet/pre-variance/validar.php
# POST { agenda_id, productos: [{ producto_id, estado, motivo }] }
# Valida productos del Pre Variance (diferencias C1 vs C2)
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

if (empty($input['productos']) || !is_array($input['productos'])) {
    errorResponse('Falta array de productos');
}

$agendaId = $input['agenda_id'];
$productos = $input['productos'];

try {
    $pdo->beginTransaction();

    $sqlUpdate = "UPDATE sod_inv_resultado 
        SET estado_revision = :estado,
            motivo_decision_pendiente = :motivo,
            usuario_calculo = :usuario,
            fecha_calculo = NOW()
        WHERE id_agenda = :agenda_id 
        AND id_producto = :producto_id";

    $stmtUpdate = $pdo->prepare($sqlUpdate);

    foreach ($productos as $producto) {
        if (empty($producto['producto_id']) || empty($producto['estado'])) {
            $pdo->rollBack();
            errorResponse('Cada producto debe tener producto_id y estado');
        }

        $estado = $producto['estado'];
        if (!in_array($estado, ['PENDIENTE', 'RECONTEO_SOLICITADO', 'RESUELTO', 'JUSTIFICADO'])) {
            $pdo->rollBack();
            errorResponse('Estado invalido: ' . $estado);
        }

        $stmtUpdate->execute([
            ':estado' => $estado,
            ':motivo' => $producto['motivo'] ?? null,
            ':usuario' => $producto['login'] ?? 'AUDITOR',
            ':agenda_id' => $agendaId,
            ':producto_id' => $producto['producto_id'],
        ]);
    }

    $pdo->commit();

    okResponse(null, 'Pre Variance validado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al validar Pre Variance: ' . $e->getMessage(), 500);
}
