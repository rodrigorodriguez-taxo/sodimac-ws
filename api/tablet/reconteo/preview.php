<?php
# ============================================================
# ws/api/tablet/reconteo/preview.php
# GET ?agenda_id=123
# Preview reconteo: live query sin crear cierre
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Verificar reapertura activa
    $stmtReapertura = $pdo->prepare(
        "SELECT id_reapertura, motivo, fecha_apertura, login_apertura
         FROM sod_ope_agenda_reapertura
         WHERE id_agenda = :agenda_id AND fl_activa = 'S' AND tipo_reapertura = 'PRUEBA'
         ORDER BY id_reapertura DESC LIMIT 1"
    );
    $stmtReapertura->execute([':agenda_id' => $agendaId]);
    $reapertura = $stmtReapertura->fetch();

    if (!$reapertura) {
        errorResponse('No hay reapertura activa (modo pruebas) para esta agenda');
    }

    // 2. Obtener C1
    $stmtC1 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 1
           AND tipo_conteo = 'INICIAL' AND fl_activo = 'S'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC1->execute([':agenda_id' => $agendaId]);
    $c1 = $stmtC1->fetch();
    if (!$c1) errorResponse('No existe Conteo 1');
    $idC1 = (int)$c1['id_conteo'];

    // 3. Obtener C2
    $stmtC2 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 2
           AND tipo_conteo = 'VALIDACION' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC2->execute([':agenda_id' => $agendaId]);
    $c2 = $stmtC2->fetch();
    $idC2 = $c2 ? (int)$c2['id_conteo'] : 0;

    // 4. Query live: conteos C1+C2+C3 (misma logica que detalle.php)
    $sql = "SELECT
        d1.id_producto,
        p.sku AS producto_sku,
        p.descripcion_producto AS producto_nombre,
        t.numero_tag,
        d1.id_tag,
        SUM(d1.cantidad) AS cantidad_c1,
        COALESCE(
            (SELECT SUM(d2.cantidad) FROM sod_inv_conteo_det AS d2
             WHERE d2.id_conteo = :id_c2 AND d2.id_producto = d1.id_producto
               AND d2.id_tag = d1.id_tag AND d2.id_reconteo IS NULL
               AND d2.origen = 'SGO_ANALISTA' AND d2.estado_registro = 'VIGENTE'),
            0
        ) AS cantidad_c2,
        COALESCE(
            (SELECT SUM(pv.cantidad) FROM sod_inv_conteo_det AS pv
             WHERE pv.id_conteo = :id_c22 AND pv.id_producto = d1.id_producto
               AND pv.id_tag = d1.id_tag AND pv.id_reconteo IS NULL
               AND pv.origen = 'SGO_PREVARIANCE' AND pv.estado_registro = 'VIGENTE'),
            0
        ) AS cantidad_prevariance
    FROM sod_inv_conteo_det AS d1
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = d1.id_producto
    INNER JOIN sod_inv_tag AS t ON t.id_tag = d1.id_tag
           AND t.id_agenda = :agenda_id3 AND t.fl_activo = 'S'
    WHERE d1.id_conteo = :id_c1
      AND d1.estado_registro = 'VIGENTE'
    GROUP BY d1.id_producto, p.sku, p.descripcion_producto, t.numero_tag, d1.id_tag
    ORDER BY t.numero_tag, p.sku";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_c2' => $idC2,
        ':id_c22' => $idC2,
        ':agenda_id3' => $agendaId,
        ':id_c1' => $idC1,
    ]);
    $productos = $stmt->fetchAll();

    // 5. Calcular estados
    $totalRecontados = 0;
    $totalPendientes = 0;
    $totalConDiferencia = 0;
    $montoTotalDiferencia = 0;

    foreach ($productos as &$prod) {
        // Base quantity: misma logica que detalle.php
        if ((float)$prod['cantidad_prevariance'] > 0) {
            $prod['base_quantity'] = (float)$prod['cantidad_prevariance'];
        } elseif ((float)$prod['cantidad_c2'] > 0) {
            $prod['base_quantity'] = (float)$prod['cantidad_c2'];
        } else {
            $prod['base_quantity'] = (float)$prod['cantidad_c1'];
        }

        // Estado live: si C2 > 0 → RECONTADO, si C1 != base → CON_DIFERENCIA, sino PENDIENTE
        if ((float)$prod['cantidad_c2'] > 0) {
            $prod['estado'] = 'RECONTADO';
            $totalRecontados++;
        } elseif ((float)$prod['cantidad_c1'] != $prod['base_quantity']) {
            $prod['estado'] = 'CON_DIFERENCIA';
            $totalConDiferencia++;
            $prod['diferencia'] = (float)$prod['cantidad_c1'] - $prod['base_quantity'];
        } else {
            $prod['estado'] = 'PENDIENTE';
            $totalPendientes++;
        }
    }
    unset($prod);

    okResponse([
        'modo_pruebas' => true,
        'reapertura' => [
            'id' => (int)$reapertura['id_reapertura'],
            'motivo' => $reapertura['motivo'],
            'fecha_apertura' => $reapertura['fecha_apertura'],
            'login_apertura' => $reapertura['login_apertura'],
        ],
        'productos' => $productos,
        'total_recontados' => $totalRecontados,
        'total_pendientes' => $totalPendientes,
        'total_con_diferencia' => $totalConDiferencia,
        'fecha_corte' => date('Y-m-d H:i:s'),
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener preview: ' . $e->getMessage(), 500);
}
