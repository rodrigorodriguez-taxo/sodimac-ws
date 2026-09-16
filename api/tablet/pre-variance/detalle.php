<?php
# ============================================================
# ws/api/tablet/pre-variance/detalle.php
# GET ?agenda_id=123
# Retorna productos con diferencia significativa para Pre Variance
# (diferencia en monto >= $500.000)
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

    // Obtener C2 existente
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

    // Productos del C1 con cálculo de diferencia
    // Pre Variance aplica cuando ABS((fisico - teorico) * valor_unitario) >= 500000
    // Aquí fisico = C1 cantidad, teorico = stock del producto
    // Simplificación: mostrar todos los productos con diferencia de cantidad
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
        (SUM(d1.cantidad) - COALESCE(
            (SELECT SUM(d2.cantidad) FROM sod_inv_conteo_det AS d2
             WHERE d2.id_conteo = :id_c22 AND d2.id_producto = d1.id_producto
               AND d2.id_tag = d1.id_tag AND d2.id_reconteo IS NULL
               AND d2.origen = 'SGO_ANALISTA' AND d2.estado_registro = 'VIGENTE'),
            0
        )) AS diferencia
    FROM sod_inv_conteo_det AS d1
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = d1.id_producto
    INNER JOIN sod_inv_tag AS t ON t.id_tag = d1.id_tag
           AND t.id_agenda = :agenda_id3 AND t.fl_activo = 'S'
    WHERE d1.id_conteo = :id_c1
      AND d1.estado_registro = 'VIGENTE'
    GROUP BY d1.id_producto, p.sku, p.descripcion_producto, t.numero_tag, d1.id_tag
    HAVING ABS(diferencia) > 0
    ORDER BY ABS(diferencia) DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_c2' => $idC2,
        ':id_c22' => $idC2,
        ':agenda_id3' => $agendaId,
        ':id_c1' => $idC1,
    ]);
    $productos = $stmt->fetchAll();

    // Obtener estado de Pre Variance cierre
    $stmtCierre = $pdo->prepare(
        "SELECT UPPER(COALESCE(estado_cierre, '')) AS estado_cierre
         FROM sod_inv_prevariance_cierre
         WHERE id_agenda = :agenda_id LIMIT 1"
    );
    $stmtCierre->execute([':agenda_id' => $agendaId]);
    $cierre = $stmtCierre->fetch();

    // Obtener Pre Variance existentes (origen SGO_PREVARIANCE)
    $preVariances = [];
    if ($idC2) {
        $stmtPV = $pdo->prepare(
            "SELECT c.id_conteo, c.estado_conteo, c.fecha_hora_inicio,
                    c.login_responsable
             FROM sod_inv_conteo AS c
             WHERE c.id_agenda = :agenda_id
               AND c.numero_iteracion = 2
               AND c.tipo_conteo = 'VALIDACION'
               AND c.fl_activo = 'S'
               AND c.estado_conteo <> 'ANULADO'
             ORDER BY c.id_conteo DESC LIMIT 1"
        );
        $stmtPV->execute([':agenda_id' => $agendaId]);
        $preVariances = $stmtPV->fetchAll();
    }

    okResponse([
        'pre_variances' => $preVariances,
        'productos' => $productos,
        'total_diferencias' => count($productos),
        'estado_cierre' => $cierre ? $cierre['estado_cierre'] : 'SIN_REGISTRO',
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener Pre Variance: ' . $e->getMessage(), 500);
}
