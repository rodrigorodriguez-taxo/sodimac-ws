<?php
# ============================================================
# ws/api/tablet/recuento/validar.php
# POST { agenda_id, productos: [{ producto_id, cantidad_c3, fl_corrige, motivo }] }
# Registra resultados del Recuento (C3) por producto
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

    $sqlUpdate = "UPDATE sod_inv_reconteo_cierre_det 
        SET cantidad_recuento = :cantidad,
            fl_corrige = :fl_corrige
        WHERE id_agenda = :agenda_id 
        AND id_producto = :producto_id";

    $stmtUpdate = $pdo->prepare($sqlUpdate);

    foreach ($productos as $producto) {
        if (empty($producto['producto_id'])) {
            $pdo->rollBack();
            errorResponse('Cada producto debe tener producto_id');
        }

        $cantidad = $producto['cantidad_c3'] ?? 0;
        $flCorrige = ($producto['fl_corrige'] === 'S') ? 'S' : 'N';

        $stmtUpdate->execute([
            ':cantidad' => $cantidad,
            ':fl_corrige' => $flCorrige,
            ':agenda_id' => $agendaId,
            ':producto_id' => $producto['producto_id'],
        ]);
    }

    $pdo->commit();

    okResponse(null, 'Recuento validado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al validar Recuento: ' . $e->getMessage(), 500);
}
