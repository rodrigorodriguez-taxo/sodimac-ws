<?php
# ws/api/tablet/cierres/export-zip.php
# GET ?agenda_id=123
# Genera ZIP con todos los informes del cierre

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Obtener datos de agenda
    $stmtAgenda = $pdo->prepare(
        "SELECT a.numero_agenda, a.fecha_agenda, t.codigo_tienda, t.nombre_tienda
         FROM sod_ope_agenda a
         INNER JOIN sod_cfg_tienda t ON t.id_tienda = a.id_tienda
         WHERE a.id_agenda = :agenda_id"
    );
    $stmtAgenda->execute([':agenda_id' => $agendaId]);
    $agenda = $stmtAgenda->fetch();
    if (!$agenda) errorResponse('Agenda no encontrada');

    // 2. Obtener snapshot
    $stmtSnap = $pdo->prepare(
        "SELECT * FROM sod_inv_cierre_agenda
         WHERE id_agenda = :agenda_id AND fl_inmutable = 'S'
         ORDER BY numero_version DESC LIMIT 1"
    );
    $stmtSnap->execute([':agenda_id' => $agendaId]);
    $snap = $stmtSnap->fetch();
    if (!$snap) errorResponse('No existe snapshot inmutable');

    // 3. Obtener detalles
    $stmtDet = $pdo->prepare(
        "SELECT d.*, p.categoria, p.subcategoria
         FROM sod_inv_cierre_agenda_det d
         INNER JOIN sod_cfg_producto p ON p.id_producto = d.id_producto
         WHERE d.id_cierre_agenda = :id_cierre
         ORDER BY d.sku"
    );
    $stmtDet->execute([':id_cierre' => $snap['id_cierre_agenda']]);
    $detalles = $stmtDet->fetchAll();

    // 4. Generar nombre del ZIP
    $fechaOp = date('Y-m-d', strtotime($snap['fecha_agenda'] ?? 'now'));
    $codTienda = preg_replace('/[^A-Za-z0-9]/', '', $agenda['codigo_tienda']);
    $numAgenda = preg_replace('/[^A-Za-z0-9_-]/', '', $agenda['numero_agenda']);

    // Obtener siguiente version (si la tabla existe)
    $nv = 1;
    try {
        $stmtVer = $pdo->prepare(
            "SELECT COALESCE(MAX(numero_version), 0) + 1 AS nv
             FROM sod_rep_zip_generacion WHERE id_agenda = :agenda_id"
        );
        $stmtVer->execute([':agenda_id' => $agendaId]);
        $nv = (int)$stmtVer->fetch()['nv'];
    } catch (Exception $e) {
        // Tabla no existe, version default = 1
    }
    $zipName = "SODIMAC_INFORMES_{$codTienda}_{$fechaOp}_{$numAgenda}_V" . str_pad($nv, 3, '0', STR_PAD_LEFT) . ".zip";

    // 5. Crear ZIP
    $tempZip = tempnam(sys_get_temp_dir(), 'sod_zip_');
    $zip = new ZipArchive();
    $zip->open($tempZip, ZipArchive::OVERWRITE);

    $manifest = [];

    // --- 00_INDICE.txt ---
    $indice = "EXPEDIENTE DE CIERRE - INVENTARIO\n";
    $indice .= "================================\n\n";
    $indice .= "Agenda: {$agenda['numero_agenda']}\n";
    $indice .= "Tienda: {$agenda['nombre_tienda']} ({$agenda['codigo_tienda']})\n";
    $indice .= "Fecha: {$fechaOp}\n";
    $indice .= "Codigo Cierre: {$snap['codigo_cierre']}\n";
    $indice .= "Version: {$snap['numero_version']}\n";
    $indice .= "Inmutable: {$snap['fl_inmutable']}\n";
    $indice .= "Hash: {$snap['hash_cabecera']}\n\n";
    $indice .= "ARCHIVOS INCLUIDOS:\n";
    $indice .= str_repeat('-', 60) . "\n";

    // --- 1. Resumen (TXT) ---
    $resumen = "RESUMEN DEL CIERRE\n";
    $resumen .= str_repeat('=', 40) . "\n\n";
    $resumen .= "SKU Total: {$snap['cantidad_sku']}\n";
    $resumen .= "Coincidentes: {$snap['cantidad_coincidentes']}\n";
    $resumen .= "Faltantes: {$snap['cantidad_faltantes']}\n";
    $resumen .= "Sobrantes: {$snap['cantidad_sobrantes']}\n\n";
    $resumen .= "Stock Total: " . number_format($snap['total_stock_unidades'], 0, ',', '.') . " unidades\n";
    $resumen .= "Fisico Total: " . number_format($snap['total_fisico_unidades'], 0, ',', '.') . " unidades\n";
    $resumen .= "Diferencia: " . number_format($snap['total_diferencia_unidades'], 0, ',', '.') . " unidades\n\n";
    $resumen .= "Valor Stock: $" . number_format($snap['total_valor_stock'], 0, ',', '.') . "\n";
    $resumen .= "Valor Fisico: $" . number_format($snap['total_valor_fisico'], 0, ',', '.') . "\n";
    $resumen .= "Diferencia Valor: $" . number_format($snap['total_diferencia_valor'], 0, ',', '.') . "\n";
    $zip->addFromString('01_Resumen.txt', $resumen);
    $manifest[] = ['archivo' => '01_Resumen.txt', 'estado' => 'OK'];

    // --- 2. Detalle (CSV) ---
    $csv = "SKU;Descripcion;Departamento;Subcategoria;Unidad;Stock Teorico;Cantidad Final;Diferencia;Valor Unitario;Valor Stock;Valor Fisico;Diferencia Valor;Clasificacion\n";
    foreach ($detalles as $det) {
        $csv .= implode(';', [
            $det['sku'],
            $det['descripcion_producto'],
            $det['categoria'] ?? '',
            $det['subcategoria'] ?? '',
            $det['unidad_medida'],
            $det['stock_teorico'],
            $det['cantidad_final'],
            $det['diferencia_cantidad'],
            $det['valor_unitario'],
            $det['valor_stock'],
            $det['valor_fisico'],
            $det['diferencia_valor'],
            $det['clasificacion'],
        ]) . "\n";
    }
    $zip->addFromString('02_Detalle_Productos.csv', $csv);
    $manifest[] = ['archivo' => '02_Detalle_Productos.csv', 'estado' => 'OK'];

    // --- 3. Por Departamento (TXT) ---
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

    $deptoTxt = "RESUMEN POR DEPARTAMENTO\n";
    $deptoTxt .= str_repeat('=', 60) . "\n\n";
    $deptoTxt .= str_pad('DEPARTAMENTO', 25) . str_pad('SKU', 6) . str_pad('STOCK', 10) . str_pad('FISICO', 10) . str_pad('DIF', 10) . "V.DIF\n";
    $deptoTxt .= str_repeat('-', 70) . "\n";
    foreach ($porDepto as $dept => $vals) {
        $deptoTxt .= str_pad(substr($dept, 0, 24), 25);
        $deptoTxt .= str_pad($vals['sku'], 6);
        $deptoTxt .= str_pad(number_format($vals['stock'], 0), 10);
        $deptoTxt .= str_pad(number_format($vals['fisico'], 0), 10);
        $deptoTxt .= str_pad(number_format($vals['dif'], 0), 10);
        $deptoTxt .= '$' . number_format($vals['val_dif'], 0) . "\n";
    }
    $zip->addFromString('03_Por_Departamento.txt', $deptoTxt);
    $manifest[] = ['archivo' => '03_Por_Departamento.txt', 'estado' => 'OK'];

    // --- 4. Hash de Integridad (TXT) ---
    $hashTxt = "INTEGRIDAD DEL CIERRE\n";
    $hashTxt .= str_repeat('=', 40) . "\n\n";
    $hashTxt .= "Algoritmo: {$snap['algoritmo_hash']}\n";
    $hashTxt .= "Hash Cabecera: {$snap['hash_cabecera']}\n";
    $hashTxt .= "Inmutable: {$snap['fl_inmutable']}\n";
    $hashTxt .= "Fecha Cierre: {$snap['fecha_cierre']}\n";
    $hashTxt .= "Login Cierre: {$snap['login_cierre']}\n";
    $hashTxt .= "Origen: {$snap['origen_cierre']}\n\n";

    // Hash de cada detalle
    $hashTxt .= "HASH DETALLE (SHA-256 por SKU):\n";
    $hashTxt .= str_repeat('-', 60) . "\n";
    foreach ($detalles as $det) {
        $hashTxt .= "{$det['sku']}: {$det['hash_detalle']}\n";
    }
    $zip->addFromString('04_Hash_Integridad.txt', $hashTxt);
    $manifest[] = ['archivo' => '04_Hash_Integridad.txt', 'estado' => 'OK'];

    // --- 5. Indice ---
    $indice .= "01_Resumen.txt - Resumen general del cierre\n";
    $indice .= "02_Detalle_Productos.csv - Detalle por SKU (CSV)\n";
    $indice .= "03_Por_Departamento.txt - Resumen por departamento\n";
    $indice .= "04_Hash_Integridad.txt - Hashes de integridad SHA-256\n";
    $indice .= str_repeat('-', 60) . "\n";
    $indice .= "Total archivos: 4\n";
    $indice .= "Generado: " . date('Y-m-d H:i:s') . "\n";
    $indice .= "Origen: TABLET\n";
    $zip->addFromString('00_INDICE.txt', $indice);

    // 6. Cerrar ZIP
    $zip->close();

    // 7. Registrar en BD (opcional - tabla puede no existir en dev)
    $tamano = filesize($tempZip);
    $sha256 = hash_file('sha256', $tempZip);

    $hasTrackingTable = false;
    try {
        $pdo->query("SELECT 1 FROM sod_rep_zip_generacion LIMIT 1");
        $hasTrackingTable = true;
    } catch (Exception $e) {
        // Tabla no existe - OK, es entorno de desarrollo
    }

    if ($hasTrackingTable) {
        $stmtIns = $pdo->prepare(
            "INSERT INTO sod_rep_zip_generacion (
                id_agenda, id_tienda, numero_version, nombre_archivo,
                tamano_bytes, sha256, estado_generacion,
                fecha_inicio, fecha_fin, usuario_generacion, origen_generacion,
                manifest_json, mensaje
            ) VALUES (
                :agenda, :tienda, :nv, :nombre,
                :tamano, :sha256, 'OK',
                NOW(), NOW(), 'TABLET', 'TABLET',
                :manifest, 'ZIP generado correctamente'
            )"
        );
        $stmtIns->execute([
            ':agenda' => $agendaId,
            ':tienda' => $snap['id_tienda'],
            ':nv' => $nv,
            ':nombre' => $zipName,
            ':tamano' => $tamano,
            ':sha256' => $sha256,
            ':manifest' => json_encode($manifest),
        ]);
    }

    // 8. Output ZIP
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . $tamano);
    readfile($tempZip);
    @unlink($tempZip);

} catch (PDOException $e) {
    errorResponse('Error al generar ZIP: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    errorResponse('Error: ' . $e->getMessage(), 500);
}
