<?php
# ws/api/tablet/informes/prevariance.php
# GET ?agenda_id=123
# Informe Pre-Variance

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Obtener cierre Pre-Variance
    $stmtPV = $pdo->prepare(
        "SELECT * FROM sod_inv_prevariance_cierre
         WHERE id_agenda = :agenda_id
         ORDER BY id_cierre_prevariance DESC LIMIT 1"
    );
    $stmtPV->execute([':agenda_id' => $agendaId]);
    $pv = $stmtPV->fetch();
    if (!$pv) errorResponse('No existe cierre Pre-Variance');

    // 2. Obtener detalles del Pre-Variance (usando kardex vs conteo)
    $stmtDet = $pdo->prepare(
        "SELECT
            p.sku, p.descripcion_producto, p.unidad_medida, p.categoria,
            k.stock_teorico, k.valor_unitario,
            COALESCE(SUM(d.cantidad), 0) AS cantidad_capturada,
            k.stock_teorico - COALESCE(SUM(d.cantidad), 0) AS diferencia,
            k.valor_unitario * (k.stock_teorico - COALESCE(SUM(d.cantidad), 0)) AS diferencia_valor
         FROM sod_inv_kardex_det k
         INNER JOIN sod_cfg_producto p ON p.id_producto = k.id_producto
         INNER JOIN sod_inv_kardex kd ON kd.id_kardex = k.id_kardex AND kd.id_agenda = :agenda_id
         LEFT JOIN sod_inv_conteo_det d ON d.id_producto = k.id_producto
              AND d.id_agenda = :agenda_id2 AND d.estado_registro = 'VIGENTE'
         WHERE kd.fl_activo = 'S'
           AND kd.estado_kardex IN ('CARGADO','VALIDADO')
         GROUP BY p.sku, p.descripcion_producto, p.unidad_medida, p.categoria,
                  k.stock_teorico, k.valor_unitario, k.id_producto
         ORDER BY p.sku"
    );
    $stmtDet->execute([':agenda_id' => $agendaId, ':agenda_id2' => $agendaId]);
    $detalles = $stmtDet->fetchAll();

    // 3. Calcular totales
    $totalStock = 0;
    $totalFisico = 0;
    $totalDifUnid = 0;
    $totalValStock = 0;
    $totalValFisico = 0;
    $totalDifVal = 0;
    $conformes = 0;
    $diferencias = 0;

    foreach ($detalles as $det) {
        $totalStock += (float)$det['stock_teorico'];
        $totalFisico += (float)$det['cantidad_capturada'];
        $totalDifUnid += abs((float)$det['diferencia']);
        $totalValStock += (float)$det['stock_teorico'] * (float)$det['valor_unitario'];
        $totalValFisico += (float)$det['cantidad_capturada'] * (float)$det['valor_unitario'];
        $totalDifVal += abs((float)$det['diferencia_valor']);
        if ((float)$det['diferencia'] == 0) $conformes++;
        else $diferencias++;
    }

    // 4. Resumen por departamento
    $porDepartamento = [];
    foreach ($detalles as $det) {
        $cat = $det['categoria'] ?? 'SIN CATEGORIA';
        if (!isset($porDepartamento[$cat])) {
            $porDepartamento[$cat] = [
                'categoria' => $cat,
                'cantidad_sku' => 0,
                'total_stock' => 0,
                'total_fisico' => 0,
                'total_diferencia' => 0,
                'conformes' => 0,
                'diferencias' => 0,
            ];
        }
        $porDepartamento[$cat]['cantidad_sku']++;
        $porDepartamento[$cat]['total_stock'] += (float)$det['stock_teorico'];
        $porDepartamento[$cat]['total_fisico'] += (float)$det['cantidad_capturada'];
        $porDepartamento[$cat]['total_diferencia'] += abs((float)$det['diferencia']);
        if ((float)$det['diferencia'] == 0) $porDepartamento[$cat]['conformes']++;
        else $porDepartamento[$cat]['diferencias']++;
    }

    okResponse([
        'cierre' => [
            'id' => (int)$pv['id_cierre_prevariance'],
            'estado' => $pv['estado_cierre'],
            'fecha' => $pv['fecha_confirmacion'] ?? $pv['fecha_creacion'],
            'login' => $pv['login_confirmacion'] ?? '',
        ],
        'resumen' => [
            'cantidad_sku' => count($detalles),
            'conformes' => $conformes,
            'diferencias' => $diferencias,
            'total_stock_unidades' => $totalStock,
            'total_fisico_unidades' => $totalFisico,
            'total_diferencia_unidades' => $totalDifUnid,
            'total_valor_stock' => $totalValStock,
            'total_valor_fisico' => $totalValFisico,
            'total_diferencia_valor' => $totalDifVal,
        ],
        'detalles' => array_map(function($d) {
            return [
                'sku' => $d['sku'],
                'descripcion' => $d['descripcion_producto'],
                'categoria' => $d['categoria'] ?? '',
                'unidad_medida' => $d['unidad_medida'],
                'stock_teorico' => (float)$d['stock_teorico'],
                'cantidad_capturada' => (float)$d['cantidad_capturada'],
                'diferencia' => (float)$d['diferencia'],
                'valor_unitario' => (float)$d['valor_unitario'],
                'valor_stock' => (float)$d['stock_teorico'] * (float)$d['valor_unitario'],
                'valor_fisico' => (float)$d['cantidad_capturada'] * (float)$d['valor_unitario'],
                'diferencia_valor' => (float)$d['diferencia_valor'],
                'estado' => (float)$d['diferencia'] == 0 ? 'CONFORME' : 'DIFERENCIA',
            ];
        }, $detalles),
        'por_departamento' => array_values($porDepartamento),
    ]);

} catch (PDOException $e) {
    errorResponse('Error al generar informe: ' . $e->getMessage(), 500);
}
