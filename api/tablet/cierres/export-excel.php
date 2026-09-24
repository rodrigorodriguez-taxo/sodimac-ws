<?php
# ws/api/tablet/cierres/export-excel.php
# GET ?agenda_id=123
# Genera Excel (.xls) del expediente de cierre

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

    // 3. Generar MHTML .xls
    $boundary = md5(time());

    $header = "Content-Type: multipart/related; boundary=\"{$boundary}\"\r\n";
    $header .= "\r\n";
    $header .= "--{$boundary}\r\n";
    $header .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
    $header .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";

    $body = '<html xmlns:o="urn:schemas-microsoft-com:office:office"
xmlns:x="urn:schemas-microsoft-com:office:excel"
xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>Expediente Cierre</x:Name>
    <x:WorksheetOptions>
     <x:DisplayGridlines/>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml><![endif]-->
<style>
table { border-collapse: collapse; }
th { background: #1a1a2e; color: white; font-weight: bold; padding: 5px 8px; border: 1px solid #333; }
td { padding: 4px 8px; border: 1px solid #ddd; }
.header { font-size: 14pt; font-weight: bold; color: #1a1a2e; }
.subheader { font-size: 10pt; color: #666; }
.ok { color: #28a745; }
.error { color: #dc3545; }
.warning { color: #ffc107; }
</style>
</head>
<body>
<table>
<tr><td colspan="10" class="header">EXPEDIENTE DE CIERRE - INVENTARIO</td></tr>
<tr><td colspan="10" class="subheader">Codigo: ' . htmlspecialchars($snap['codigo_cierre']) . ' | Version: ' . $snap['numero_version'] . ' | Agenda: ' . htmlspecialchars($snap['numero_agenda']) . '</td></tr>
<tr><td colspan="10" class="subheader">Tienda: ' . htmlspecialchars($snap['nombre_tienda']) . ' | Fecha: ' . $snap['fecha_agenda'] . '</td></tr>
<tr><td colspan="10"></td></tr>
<tr><td colspan="10" class="header">RESUMEN</td></tr>
<tr><td>SKU Total</td><td>' . $snap['cantidad_sku'] . '</td><td></td><td>Coincidentes</td><td>' . $snap['cantidad_coincidentes'] . '</td><td></td><td>Faltantes</td><td>' . $snap['cantidad_faltantes'] . '</td><td>Sobrantes</td><td>' . $snap['cantidad_sobrantes'] . '</td></tr>
<tr><td>Stock (unid)</td><td>' . number_format($snap['total_stock_unidades'], 0, ',', '.') . '</td><td></td><td>Fisico (unid)</td><td>' . number_format($snap['total_fisico_unidades'], 0, ',', '.') . '</td><td></td><td>Dif (unid)</td><td>' . number_format($snap['total_diferencia_unidades'], 0, ',', '.') . '</td><td></td><td></td></tr>
<tr><td>Valor Stock</td><td>$' . number_format($snap['total_valor_stock'], 0, ',', '.') . '</td><td></td><td>Valor Fisico</td><td>$' . number_format($snap['total_valor_fisico'], 0, ',', '.') . '</td><td></td><td>Dif Valor</td><td>$' . number_format($snap['total_diferencia_valor'], 0, ',', '.') . '</td><td></td><td></td></tr>
<tr><td colspan="10"></td></tr>
<tr><td colspan="10" class="header">DETALLE POR PRODUCTO</td></tr>
<tr>
<th>SKU</th><th>Descripcion</th><th>Departamento</th><th>Subcategoria</th>
<th>Stock Teorico</th><th>Cantidad Final</th><th>Diferencia</th>
<th>Valor Unitario</th><th>Valor Stock</th><th>Valor Fisico</th><th>Dif Valor</th><th>Clasificacion</th>
</tr>';

    foreach ($detalles as $det) {
        $dif = (float)$det['diferencia_cantidad'];
        $cls = $dif == 0 ? 'ok' : ($dif > 0 ? 'error' : 'warning');
        $body .= '<tr>
            <td>' . htmlspecialchars($det['sku']) . '</td>
            <td>' . htmlspecialchars($det['descripcion_producto']) . '</td>
            <td>' . htmlspecialchars($det['categoria'] ?? '') . '</td>
            <td>' . htmlspecialchars($det['subcategoria'] ?? '') . '</td>
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

    $body .= '</table>
</body></html>';

    $footer = "\r\n--{$boundary}--";

    header('Content-Type: multipart/related; boundary="' . $boundary . '"');
    header('Content-Disposition: attachment; filename="cierre-' . $snap['codigo_cierre'] . '.xls"');
    echo $header . $body . $footer;

} catch (PDOException $e) {
    errorResponse('Error al generar Excel: ' . $e->getMessage(), 500);
}
