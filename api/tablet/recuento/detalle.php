<?php
# ============================================================
# ws/api/tablet/recuento/detalle.php
# GET ?agenda_id=123
# Retorna productos del Recuento C3 con base quantity
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
    // Obtener C1
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

    // Obtener C2
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

    // Obtener C3
    $stmtC3 = $pdo->prepare(
        "SELECT id_conteo, estado_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 3
           AND tipo_conteo = 'RECONTEO' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC3->execute([':agenda_id' => $agendaId]);
    $c3 = $stmtC3->fetch();
    $idC3 = $c3 ? (int)$c3['id_conteo'] : 0;

    // Productos del C1 con base quantity y estado C3
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
        ) AS cantidad_prevariance,
        COALESCE(
            (SELECT SUM(rc.cantidad) FROM sod_inv_conteo_det AS rc
             WHERE rc.id_conteo = :id_c3 AND rc.id_producto = d1.id_producto
               AND rc.id_tag = d1.id_tag AND rc.id_reconteo IS NULL
               AND rc.origen = 'SGO_RECUENTO' AND rc.estado_registro = 'VIGENTE'),
            NULL
        ) AS cantidad_c3
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
        ':id_c3' => $idC3,
        ':agenda_id3' => $agendaId,
        ':id_c1' => $idC1,
    ]);
    $productos = $stmt->fetchAll();

    // Calcular base quantity para cada producto (misma lógica que sgo.app)
    // Prioridad: SGO_PREVARIANCE > SGO_ANALISTA > C1 > SGO_RECUENTO > 0
    foreach ($productos as &$prod) {
        if ((float)$prod['cantidad_prevariance'] > 0) {
            $prod['base_quantity'] = (float)$prod['cantidad_prevariance'];
            $prod['base_origen'] = 'SGO_PREVARIANCE';
        } elseif ((float)$prod['cantidad_c2'] > 0) {
            $prod['base_quantity'] = (float)$prod['cantidad_c2'];
            $prod['base_origen'] = 'SGO_ANALISTA';
        } else {
            $prod['base_quantity'] = (float)$prod['cantidad_c1'];
            $prod['base_origen'] = 'C1';
        }
        $prod['estado'] = $prod['cantidad_c3'] !== null ? 'MODIFICADO' : 'PENDIENTE';
    }
    unset($prod);

    // Estado cierre Recuento
    $stmtCierre = $pdo->prepare(
        "SELECT id_reconteo_cierre AS id_cierre,
                UPPER(COALESCE(estado_cierre, '')) AS estado_cierre
         FROM sod_inv_reconteo_cierre
         WHERE id_agenda = :agenda_id
         ORDER BY numero_version DESC, id_reconteo_cierre DESC
         LIMIT 1"
    );
    $stmtCierre->execute([':agenda_id' => $agendaId]);
    $cierre = $stmtCierre->fetch();

    // Stats del Recuento
    $totalPendientes = 0;
    $totalModificados = 0;
    foreach ($productos as $p) {
        if ($p['estado'] === 'MODIFICADO') $totalModificados++;
        else $totalPendientes++;
    }

    okResponse([
        'recuentos' => $idC3 ? [['id' => $idC3, 'estado' => $c3['estado_conteo']]] : [],
        'productos' => $productos,
        'total_pendientes' => $totalPendientes,
        'total_modificados' => $totalModificados,
        'estado_cierre' => $cierre ? $cierre['estado_cierre'] : 'SIN_REGISTRO',
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener recuento: ' . $e->getMessage(), 500);
}
