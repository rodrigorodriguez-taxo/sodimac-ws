<?php
# ============================================================
# ws/api/tablet/pre-variance/validar.php
# POST { pre_variance_id, productos: [{ producto_id, estado, motivo }] }
# Valida productos del Pre Variance
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['pre_variance_id'])) {
    errorResponse('Falta pre_variance_id');
}

if (empty($input['productos']) || !is_array($input['productos'])) {
    errorResponse('Falta array de productos');
}

$preVarianceId = $input['pre_variance_id'];
$productos = $input['productos'];

try {
    $pdo->beginTransaction();

    // Actualizar cada producto
    $sqlUpdate = "UPDATE sod_pre_variance_producto 
        SET estado = :estado, 
            motivo = :motivo,
            validado_por = :validado_por,
            validado_at = NOW()
        WHERE id = :producto_id AND pre_variance_id = :pre_variance_id";

    $stmtUpdate = $pdo->prepare($sqlUpdate);

    foreach ($productos as $producto) {
        if (empty($producto['producto_id']) || empty($producto['estado'])) {
            $pdo->rollBack();
            errorResponse('Cada producto debe tener producto_id y estado');
        }

        $estado = $producto['estado'];
        if (!in_array($estado, ['PENDIENTE', 'APROBADO', 'RECHAZADO'])) {
            $pdo->rollBack();
            errorResponse('Estado inválido: ' . $estado);
        }

        $stmtUpdate->execute([
            ':producto_id' => $producto['producto_id'],
            ':pre_variance_id' => $preVarianceId,
            ':estado' => $estado,
            ':motivo' => $producto['motivo'] ?? null,
            ':validado_por' => 'AUDITOR', // En producción obtener del token
        ]);
    }

    // Verificar si todos los productos están procesados
    $sqlCheck = "SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN estado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes
    FROM sod_pre_variance_producto
    WHERE pre_variance_id = :pre_variance_id";

    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([':pre_variance_id' => $preVarianceId]);
    $check = $stmtCheck->fetch();

    // Actualizar estado del Pre Variance
    $nuevoEstado = $check['pendientes'] > 0 ? 'EN_CURSO' : 'APROBADO';
    $sqlUpdatePV = "UPDATE sod_pre_variance 
        SET estado = :estado,
            validado_por = :validado_por,
            validado_at = NOW()
        WHERE id = :pre_variance_id";

    $stmtUpdatePV = $pdo->prepare($sqlUpdatePV);
    $stmtUpdatePV->execute([
        ':estado' => $nuevoEstado,
        ':validado_por' => 'AUDITOR',
        ':pre_variance_id' => $preVarianceId,
    ]);

    $pdo->commit();

    okResponse(null, 'Pre Variance validado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al validar Pre Variance: ' . $e->getMessage(), 500);
}
