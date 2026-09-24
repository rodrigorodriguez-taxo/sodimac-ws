<?php
# ws/api/tablet/cierres/export-pdf.php
# GET ?agenda_id=123
# Genera PDF del expediente de cierre

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Obtener snapshot
    $stmtSnap = $pdo->prepare(
        "SELECT * FROM sod_inv_cierre_agenda
         WHERE id_agenda = :agenda_id AND fl_inmutable = 'S'
         ORDER BY numero_version DESC LIMIT 1"
    );
    $stmtSnap->execute([':agenda_id' => $agendaId]);
    $snap = $stmtSnap->fetch();
    if (!$snap) errorResponse('No existe snapshot inmutable');

    // 2. Obtener detalles
    $stmtDet = $pdo->prepare(
        "SELECT d.*, p.categoria, p.subcategoria
         FROM sod_inv_cierre_agenda_det d
         INNER JOIN sod_cfg_producto p ON p.id_producto = d.id_producto
         WHERE d.id_cierre_agenda = :id_cierre
         ORDER BY d.sku"
    );
    $stmtDet->execute([':id_cierre' => $snap['id_cierre_agenda']]);
    $detalles = $stmtDet->fetchAll();

    // 3. Resumen por departamento
    $porDepto = [];
    foreach ($detalles as $det) {
        $cat = $det['categoria'] ?? 'SIN CATEGORIA';
        if (!isset($porDepto[$cat])) {
            $porDepto[$cat] = ['sku' => 0, 'stock' => 0, 'fisico' => 0, 'dif' => 0, 'val_dif' => 0];
        }
        $porDepto[$cat]['sku']++;
        $porDepto[$cat]['stock'] += (float)$det['stock_teorico'];
        $porDepto[$cat]['fisico'] += (float)$det['cantidad_final'];
        $porDepto[$cat]['dif'] += (float)$det['diferencia_cantidad'];
        $porDepto[$cat]['val_dif'] += (float)$det['diferencia_valor'];
    }

    // 4. Generar HTML
    $html = '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; color: #333; }
h1 { font-size: 18px; color: #1a1a2e; border-bottom: 2px solid #e94560; padding-bottom: 5px; }
h2 { font-size: 14px; color: #1a1a2e; margin-top: 20px; }
.header-info { display: flex; justify-content: space-between; margin-bottom: 15px; }
.info-box { background: #f8f9fa; padding: 10px; border-radius: 5px; flex: 1; margin: 0 5px; }
.info-box h3 { margin: 0 0 5px 0; font-size: 12px; color: #666; }
.info-box p { margin: 2px 0; font-size: 11px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th { background: #1a1a2e; color: white; padding: 6px 8px; text-align: left; font-size: 10px; }
td { padding: 5px 8px; border-bottom: 1px solid #ddd; font-size: 10px; }
tr:nth-child(even) { background: #f8f9fa; }
.ok { color: #28a745; font-weight: bold; }
.error { color: #dc3545; font-weight: bold; }
.warning { color: #ffc107; font-weight: bold; }
.footer { margin-top: 20px; text-align: center; font-size: 9px; color: #999; }
.hash { font-family: monospace; font-size: 8px; word-break: break-all; }
.summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 10px 0; }
.summary-item { text-align: center; padding: 8px; background: #f8f9fa; border-radius: 5px; }
.summary-item .value { font-size: 16px; font-weight: bold; }
.summary-item .label { font-size: 9px; color: #666; }
</style>
</head>
<body>

<h1>EXPEDIENTE DE CIERRE - INVENTARIO</h1>

<div class="header-info">
    <div class="info-box">
        <h3>Agenda</h3>
        <p><b>Codigo:</b> ' . htmlspecialchars($snap['codigo_cierre']) . '</p>
        <p><b>Version:</b> ' . $snap['numero_version'] . '</p>
        <p><b>Agenda:</b> ' . htmlspecialchars($snap['numero_agenda']) . '</p>
        <p><b>Fecha Agenda:</b> ' . $snap['fecha_agenda'] . '</p>
    </div>
    <div class="info-box">
        <h3>Tienda</h3>
        <p><b>Nombre:</b> ' . htmlspecialchars($snap['nombre_tienda']) . '</p>
        <p><b>Codigo:</b> ' . htmlspecialchars($snap['codigo_tienda']) . '</p>
    </div>
    <div class="info-box">
        <h3>Cierre</h3>
        <p><b>Fecha:</b> ' . $snap['fecha_cierre'] . '</p>
        <p><b>Login:</b> ' . htmlspecialchars($snap['login_cierre']) . '</p>
        <p><b>Inmutable:</b> <span class="ok">' . ($snap['fl_inmutable'] === 'S' ? 'SI' : 'NO') . '</span></p>
    </div>
</div>

<h2>RESUMEN</h2>
<div class="summary-grid">
    <div class="summary-item">
        <div class="value">' . $snap['cantidad_sku'] . '</div>
        <div class="label">SKU Total</div>
    </div>
    <div class="summary-item">
        <div class="value ok">' . $snap['cantidad_coincidentes'] . '</div>
        <div class="label">Coincidentes</div>
    </div>
    <div class="summary-item">
        <div class="value error">' . $snap['cantidad_faltantes'] . '</div>
        <div class="label">Faltantes</div>
    </div>
    <div class="summary-item">
        <div class="value warning">' . $snap['cantidad_sobrantes'] . '</div>
        <div class="label">Sobrantes</div>
    </div>
</div>

<table>
<tr><th>Metrica</th><th>Valor</th></tr>
<tr><td>Stock Total (unidades)</td><td>' . number_format($snap['total_stock_unidades'], 0, ',', '.') . '</td></tr>
<tr><td>Fisico Total (unidades)</td><td>' . number_format($snap['total_fisico_unidades'], 0, ',', '.') . '</td></tr>
<tr><td>Diferencia (unidades)</td><td class="' . ($snap['total_diferencia_unidades'] != 0 ? 'error' : 'ok') . '">' . number_format($snap['total_diferencia_unidades'], 0, ',', '.') . '</td></tr>
<tr><td>Valor Stock</td><td>$' . number_format($snap['total_valor_stock'], 0, ',', '.') . '</td></tr>
<tr><td>Valor Fisico</td><td>$' . number_format($snap['total_valor_fisico'], 0, ',', '.') . '</td></tr>
<tr><td>Diferencia Valor</td><td class="' . ($snap['total_diferencia_valor'] != 0 ? 'error' : 'ok') . '">$' . number_format($snap['total_diferencia_valor'], 0, ',', '.') . '</td></tr>
</table>

<h2>DETALLE POR PRODUCTO</h2>
<table>
<tr><th>SKU</th><th>Descripcion</th><th>Depto</th><th>Stock</th><th>Fisico</th><th>Dif</th><th>V.Unit</th><th>V.Stock</th><th>V.Fisico</th><th>V.Dif</th><th>Clasif</th></tr>';

    foreach ($detalles as $det) {
        $dif = (float)$det['diferencia_cantidad'];
        $cls = $dif == 0 ? 'ok' : ($dif > 0 ? 'error' : 'warning');
        $html .= '<tr>
            <td>' . htmlspecialchars($det['sku']) . '</td>
            <td>' . htmlspecialchars(substr($det['descripcion_producto'], 0, 30)) . '</td>
            <td>' . htmlspecialchars($det['categoria'] ?? '') . '</td>
            <td>' . number_format($det['stock_teorico'], 0, ',', '.') . '</td>
            <td>' . number_format($det['cantidad_final'], 0, ',', '.') . '</td>
            <td class="' . $cls . '">' . number_format($dif, 0, ',', '.') . '</td>
            <td>$' . number_format($det['valor_unitario'], 0, ',', '.') . '</td>
            <td>$' . number_format($det['valor_stock'], 0, ',', '.') . '</td>
            <td>$' . number_format($det['valor_fisico'], 0, ',', '.') . '</td>
            <td class="' . ($det['diferencia_valor'] != 0 ? 'error' : 'ok') . '">$' . number_format($det['diferencia_valor'], 0, ',', '.') . '</td>
            <td>' . $det['clasificacion'] . '</td>
        </tr>';
    }

    $html .= '</table>';

    // Por departamento
    if (!empty($porDepto)) {
        $html .= '<h2>RESUMEN POR DEPARTAMENTO</h2>
        <table>
        <tr><th>Departamento</th><th>SKU</th><th>Stock</th><th>Fisico</th><th>Dif</th><th>V.Dif</th></tr>';
        foreach ($porDepto as $dept => $vals) {
            $html .= '<tr>
                <td>' . htmlspecialchars($dept) . '</td>
                <td>' . $vals['sku'] . '</td>
                <td>' . number_format($vals['stock'], 0, ',', '.') . '</td>
                <td>' . number_format($vals['fisico'], 0, ',', '.') . '</td>
                <td class="' . ($vals['dif'] != 0 ? 'error' : 'ok') . '">' . number_format($vals['dif'], 0, ',', '.') . '</td>
                <td class="' . ($vals['val_dif'] != 0 ? 'error' : 'ok') . '">$' . number_format($vals['val_dif'], 0, ',', '.') . '</td>
            </tr>';
        }
        $html .= '</table>';
    }

    // Hash
    $html .= '<h2>INTEGRIDAD</h2>
    <table>
    <tr><td>Algoritmo</td><td>' . $snap['algoritmo_hash'] . '</td></tr>
    <tr><td>Hash Cabecera</td><td class="hash">' . $snap['hash_cabecera'] . '</td></tr>
    </table>';

    $html .= '<div class="footer">
        Generado: ' . date('Y-m-d H:i:s') . ' | SGO Mobile Inventario | Origen: ' . $snap['origen_cierre'] . '
    </div>

</body></html>';

    // 5. Output como HTML para impresion/PDF
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="cierre-' . $snap['codigo_cierre'] . '.html"');
    echo $html;

} catch (PDOException $e) {
    errorResponse('Error al generar PDF: ' . $e->getMessage(), 500);
}
