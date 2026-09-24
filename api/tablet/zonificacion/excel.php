<?php
# ============================================================
# ws/api/tablet/zonificacion/excel.php
# Genera archivo .xls (MHTML) — formato ScriptCase grid
# GET ?agenda_id=X
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId) || !is_numeric($agendaId)) {
    errorResponse('Falta o es invalido el parametro agenda_id');
}
$agendaId = (int) $agendaId;

try {
    // Obtener contexto de la agenda
    $sqlContexto = "SELECT a.id_agenda, a.numero_agenda, a.fecha_agenda,
                           t.codigo_tienda, t.nombre_tienda, 
                           e.nombre_estado AS codigo_estado
                    FROM sod_ope_agenda a
                    LEFT JOIN sod_cfg_tienda t ON a.id_tienda = t.id_tienda
                    LEFT JOIN sod_ope_estado_agenda e ON a.id_estado_agenda = e.id_estado_agenda
                    WHERE a.id_agenda = :id_agenda";
    $stmtCtx = $pdo->prepare($sqlContexto);
    $stmtCtx->execute([':id_agenda' => $agendaId]);
    $agenda = $stmtCtx->fetch();
    
    if (!$agenda) {
        errorResponse('No se encontro la agenda especificada');
    }

    // Encontrar Conte Inicial
    $sqlConteo = "SELECT id_conteo FROM sod_inv_conteo 
                  WHERE id_agenda = :id_agenda AND numero_iteracion = 1 
                  AND tipo_conteo = 'INICIAL' AND fl_activo = 'S'
                  ORDER BY id_conteo DESC LIMIT 1";
    $stmtConteo = $pdo->prepare($sqlConteo);
    $stmtConteo->execute([':id_agenda' => $agendaId]);
    $conteo = $stmtConteo->fetch();
    
    if (!$conteo) {
        errorResponse('No existe Conte Inicial para esta agenda');
    }
    $idConteo = $conteo['id_conteo'];

    // Encontrar Zonificacion activa
    $sqlZonificacion = "SELECT id_zonificacion FROM sod_ope_agenda_zonificacion
                        WHERE id_agenda = :id_agenda AND fl_activo = 'S'
                        ORDER BY id_zonificacion DESC LIMIT 1";
    $stmtZonif = $pdo->prepare($sqlZonificacion);
    $stmtZonif->execute([':id_agenda' => $agendaId]);
    $zonificacion = $stmtZonif->fetch();
    
    if (!$zonificacion) {
        errorResponse('No existe Zonificacion formal para esta agenda');
    }
    $idZonificacion = $zonificacion['id_zonificacion'];

    // Obtener detalle de zonas — formato ScriptCase: tag_desde, tag_hasta, descripcion, qty_zonificado, qty_contado, qty_pendiente, porcentaje_cierre
    $sqlZonas = "SELECT zd.orden, zd.tag_desde, zd.tag_hasta, zd.descripcion, zd.qty_zonificado,
                        COUNT(DISTINCT t.id_tag) AS tag_validados
                 FROM sod_ope_agenda_zonificacion_det zd
                 LEFT JOIN sod_inv_tag t ON t.id_agenda = :agenda_id
                    AND t.numero_tag BETWEEN zd.tag_desde AND zd.tag_hasta
                    AND t.fl_activo = 'S' AND t.estado_tag <> 'ANULADO'
                 WHERE zd.id_zonificacion = :id_zonificacion AND zd.fl_activo = 'S'
                 GROUP BY zd.id_zonificacion_det, zd.orden, zd.tag_desde, zd.tag_hasta, zd.descripcion, zd.qty_zonificado
                 ORDER BY zd.orden";
    $stmtZonas = $pdo->prepare($sqlZonas);
    $stmtZonas->execute([':id_zonificacion' => $idZonificacion, ':agenda_id' => $agendaId]);
    $zonas = $stmtZonas->fetchAll();

    // Generar MHTML Excel — grid simple como ScriptCase
    $boundary = md5(time());
    $fecha = date('d-m-Y', strtotime($agenda['fecha_agenda']));

    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; }
  table { border-collapse: collapse; width: 100%; }
  th { background: #f0f0f0; border: 1px solid #ccc; padding: 4px 8px; text-align: left; font-size: 10px; font-weight: bold; }
  td { border: 1px solid #ccc; padding: 4px 8px; font-size: 10px; }
</style>
</head>
<body>
<table>
  <tr>
    <th>Tag Desde</th>
    <th>Tag Hasta</th>
    <th>Descripcion</th>
    <th>Qty Zonificado</th>
    <th>Qty Contado</th>
    <th>Qty Pendiente</th>
    <th>% Cierre</th>
  </tr>';

    foreach ($zonas as $z) {
        $tagVal = (int) $z['tag_validados'];
        $qtyPlan = (int) $z['qty_zonificado'];
        $pend = max(0, $qtyPlan - $tagVal);
        $pct = $qtyPlan > 0 ? round(($tagVal / $qtyPlan) * 100, 0) : 0;

        $html .= '<tr>
    <td>' . $z['tag_desde'] . '</td>
    <td>' . $z['tag_hasta'] . '</td>
    <td>' . htmlspecialchars($z['descripcion']) . '</td>
    <td>' . $qtyPlan . '</td>
    <td>' . $tagVal . '</td>
    <td>' . $pend . '</td>
    <td>' . $pct . '%</td>
  </tr>';
    }

    $html .= '</table></body></html>';

    // Enviar como MHTML Excel
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="zonificacion_' . $agenda['numero_agenda'] . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo "MIME-Version: 1.0\r\n";
    echo "Content-Type: multipart/related; boundary=\"{$boundary}\"\r\n";
    echo "\r\n";
    echo "--{$boundary}\r\n";
    echo "Content-Type: text/html; charset=UTF-8\r\n";
    echo "Content-Transfer-Encoding: quoted-printable\r\n";
    echo "\r\n";
    echo chunk_split($html);
    echo "--{$boundary}--\r\n";

} catch (PDOException $e) {
    errorResponse('Error al generar Excel: ' . $e->getMessage(), 500);
}
