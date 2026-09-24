<?php
# ws/api/tablet/informes/cierre-final.php
# GET ?agenda_id=123
# Informe de Cierre Final desde snapshot inmutable

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Obtener snapshot inmutable
    $stmtSnap = $pdo->prepare(
        "SELECT * FROM sod_inv_cierre_agenda
         WHERE id_agenda = :agenda_id AND fl_inmutable = 'S'
         ORDER BY numero_version DESC LIMIT 1"
    );
    $stmtSnap->execute([':agenda_id' => $agendaId]);
    $snapshot = $stmtSnap->fetch();

    if (!$snapshot) {
        errorResponse('No existe snapshot inmutable para esta agenda');
    }

    // 2. Obtener detalles del snapshot
    $stmtDet = $pdo->prepare(
        "SELECT d.*, p.categoria, p.subcategoria
         FROM sod_inv_cierre_agenda_det d
         INNER JOIN sod_cfg_producto p ON p.id_producto = d.id_producto
         WHERE d.id_cierre_agenda = :id_cierre
         ORDER BY d.sku"
    );
    $stmtDet->execute([':id_cierre' => $snapshot['id_cierre_agenda']]);
    $detalles = $stmtDet->fetchAll();

    // 3. Resumen por departamento
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
                'total_valor_stock' => 0,
                'total_valor_fisico' => 0,
                'total_diferencia_valor' => 0,
            ];
        }
        $porDepartamento[$cat]['cantidad_sku']++;
        $porDepartamento[$cat]['total_stock'] += (float)$det['stock_teorico'];
        $porDepartamento[$cat]['total_fisico'] += (float)$det['cantidad_final'];
        $porDepartamento[$cat]['total_diferencia'] += (float)$det['diferencia_cantidad'];
        $porDepartamento[$cat]['total_valor_stock'] += (float)$det['valor_stock'];
        $porDepartamento[$cat]['total_valor_fisico'] += (float)$det['valor_fisico'];
        $porDepartamento[$cat]['total_diferencia_valor'] += (float)$det['diferencia_valor'];
    }

    okResponse([
        'cierre' => [
            'id' => (int)$snapshot['id_cierre_agenda'],
            'codigo' => $snapshot['codigo_cierre'],
            'version' => (int)$snapshot['numero_version'],
            'tienda' => $snapshot['nombre_tienda'],
            'fecha_agenda' => $snapshot['fecha_agenda'],
            'fecha_cierre' => $snapshot['fecha_cierre'],
            'login_cierre' => $snapshot['login_cierre'],
            'hash_cabecera' => $snapshot['hash_cabecera'],
            'fl_inmutable' => $snapshot['fl_inmutable'],
        ],
        'resumen' => [
            'cantidad_sku' => (int)$snapshot['cantidad_sku'],
            'cantidad_coincidentes' => (int)$snapshot['cantidad_coincidentes'],
            'cantidad_faltantes' => (int)$snapshot['cantidad_faltantes'],
            'cantidad_sobrantes' => (int)$snapshot['cantidad_sobrantes'],
            'total_stock_unidades' => (float)$snapshot['total_stock_unidades'],
            'total_fisico_unidades' => (float)$snapshot['total_fisico_unidades'],
            'total_diferencia_unidades' => (float)$snapshot['total_diferencia_unidades'],
            'total_diferencia_unidades_abs' => (float)$snapshot['total_diferencia_unidades_abs'],
            'total_valor_stock' => (float)$snapshot['total_valor_stock'],
            'total_valor_fisico' => (float)$snapshot['total_valor_fisico'],
            'total_diferencia_valor' => (float)$snapshot['total_diferencia_valor'],
            'total_diferencia_valor_abs' => (float)$snapshot['total_diferencia_valor_abs'],
        ],
        'detalles' => array_map(function($d) {
            return [
                'sku' => $d['sku'],
                'descripcion' => $d['descripcion_producto'],
                'categoria' => $d['categoria'] ?? '',
                'subcategoria' => $d['subcategoria'] ?? '',
                'unidad_medida' => $d['unidad_medida'],
                'stock_teorico' => (float)$d['stock_teorico'],
                'cantidad_final' => (float)$d['cantidad_final'],
                'diferencia_cantidad' => (float)$d['diferencia_cantidad'],
                'valor_unitario' => (float)$d['valor_unitario'],
                'valor_stock' => (float)$d['valor_stock'],
                'valor_fisico' => (float)$d['valor_fisico'],
                'diferencia_valor' => (float)$d['diferencia_valor'],
                'clasificacion' => $d['clasificacion'],
                'tags_encontrados' => $d['tags_encontrados'] ?? '',
            ];
        }, $detalles),
        'por_departamento' => array_values($porDepartamento),
    ]);

} catch (PDOException $e) {
    errorResponse('Error al generar informe: ' . $e->getMessage(), 500);
}
