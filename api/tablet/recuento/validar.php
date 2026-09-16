<?php
# ============================================================
# ws/api/tablet/recuento/validar.php
# POST { recuento_id, productos: [{ producto_id, estado, cantidad_c3, motivo }] }
# Valida productos del Recuento (C3)
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['recuento_id'])) {
    errorResponse('Falta recuento_id');
}

if (empty($input['productos']) || !is_array($input['productos'])) {
    errorResponse('Falta array de productos');
}

$recuentoId = $input['recuento_id'];
$productos = $input['productos'];

try {
    $pdo->beginTransaction();

    // Actualizar cada producto
    $sqlUpdate = "UPDATE sod_recuento_producto 
        SET estado = :estado, 
            cantidad_c3 = :cantidad_c3,
            diferencia_c2_c3 = ABS(cantidad_c2 - :cantidad_c3),
            motivo_modificacion = :motivo,
            fecha_captura = NOW()
        WHERE id = :producto_id AND recuento_id = :recuento_id";

    $stmtUpdate = $pdo->prepare($sqlUpdate);

    foreach ($productos as $producto) {
        if (empty($producto['producto_id']) || empty($producto['estado'])) {
            $pdo->rollBack();
            errorResponse('Cada producto debe tener producto_id y estado');
        }

        $estado = $producto['estado'];
        if (!in_array($estado, ['PENDIENTE', 'CONFIRMADO', 'MODIFICADO'])) {
            $pdo->rollBack();
            errorResponse('Estado inválido: ' . $estado);
        }

        $cantidadC3 = $producto['cantidad_c3'] ?? 0;

        $stmtUpdate->execute([
            ':producto_id' => $producto['producto_id'],
            ':recuento_id' => $recuentoId,
            ':estado' => $estado,
            ':cantidad_c3' => $cantidadC3,
            ':motivo' => $producto['motivo'] ?? null,
        ]);
    }

    // Verificar si todos los productos están procesados
    $sqlCheck = "SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN estado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes
    FROM sod_recuento_producto
    WHERE recuento_id = :recuento_id";

    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([':recuento_id' => $recuentoId]);
    $check = $stmtCheck->fetch();

    // Actualizar estado del Recuento
    $nuevoEstado = $check['pendientes'] > 0 ? 'EN_CURSO' : 'FINALIZADO';
    $sqlUpdateRecuento = "UPDATE sod_recuento 
        SET estado = :estado,
            finalizado_por = :finalizado_por,
            finalizado_at = NOW()
        WHERE id = :recuento_id";

    $stmtUpdateRecuento = $pdo->prepare($sqlUpdateRecuento);
    $stmtUpdateRecuento->execute([
        ':estado' => $nuevoEstado,
        ':finalizado_por' => 'AUDITOR',
        ':recuento_id' => $recuentoId,
    ]);

    // Si el recuento se finaliza, invalidar captures posteriores (C2)
    if ($nuevoEstado === 'FINALIZADO') {
        $sqlInvalidate = "UPDATE sod_validacion_capturas vc
            INNER JOIN sod_capturas c ON vc.captura_id = c.id
            SET vc.estado = 'INVALIDADO'
            WHERE c.tag_id = (SELECT tag_id FROM sod_recuento WHERE id = :recuento_id)
            AND vc.estado = 'MODIFICADA'";

        $stmtInvalidate = $pdo->prepare($sqlInvalidate);
        $stmtInvalidate->execute([':recuento_id' => $recuentoId]);
    }

    $pdo->commit();

    okResponse(null, 'Recuento validado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al validar Recuento: ' . $e->getMessage(), 500);
}
