<?php
# ws/api/tablet/cierres/envio.php
# POST { agenda_id, destinatarios: [...] }
# Envia ZIP del cierre por correo via SMTP (Mailpit en dev)

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$agendaId = $input['agenda_id'] ?? '';
$destinatarios = $input['destinatarios'] ?? [];

if (empty($agendaId)) errorResponse('Falta agenda_id');
if (empty($destinatarios)) errorResponse('Falta destinatarios');

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

    // 4. Generar ZIP en memoria
    $tempZip = tempnam(sys_get_temp_dir(), 'sod_zip_');
    $zip = new ZipArchive();
    $zip->open($tempZip, ZipArchive::OVERWRITE);

    // Resumen
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

    // Detalle CSV
    $csv = "SKU;Descripcion;Departamento;Subcategoria;Unidad;Stock Teorico;Cantidad Final;Diferencia;Valor Unitario;Valor Stock;Valor Fisico;Diferencia Valor;Clasificacion\n";
    foreach ($detalles as $det) {
        $csv .= implode(';', [
            $det['sku'], $det['descripcion_producto'], $det['categoria'] ?? '',
            $det['subcategoria'] ?? '', $det['unidad_medida'], $det['stock_teorico'],
            $det['cantidad_final'], $det['diferencia_cantidad'], $det['valor_unitario'],
            $det['valor_stock'], $det['valor_fisico'], $det['diferencia_valor'], $det['clasificacion'],
        ]) . "\n";
    }
    $zip->addFromString('02_Detalle_Productos.csv', $csv);

    // Por Departamento
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
    $deptoTxt = "RESUMEN POR DEPARTAMENTO\n" . str_repeat('=', 60) . "\n\n";
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

    // Hash Integridad
    $hashTxt = "INTEGRIDAD DEL CIERRE\n" . str_repeat('=', 40) . "\n\n";
    $hashTxt .= "Algoritmo: {$snap['algoritmo_hash']}\n";
    $hashTxt .= "Hash Cabecera: {$snap['hash_cabecera']}\n";
    $hashTxt .= "Inmutable: {$snap['fl_inmutable']}\n";
    $hashTxt .= "Fecha Cierre: {$snap['fecha_cierre']}\n";
    $hashTxt .= "Login Cierre: {$snap['login_cierre']}\n";
    $hashTxt .= "Origen: {$snap['origen_cierre']}\n\n";
    $hashTxt .= "HASH DETALLE (SHA-256 por SKU):\n" . str_repeat('-', 60) . "\n";
    foreach ($detalles as $det) {
        $hashTxt .= "{$det['sku']}: {$det['hash_detalle']}\n";
    }
    $zip->addFromString('04_Hash_Integridad.txt', $hashTxt);

    $zip->close();
    $zipContent = file_get_contents($tempZip);
    $zipSize = strlen($zipContent);
    $zipBase64 = base64_encode($zipContent);
    @unlink($tempZip);

    // 5. Nombre del ZIP
    $fechaOp = date('Y-m-d', strtotime($snap['fecha_agenda'] ?? 'now'));
    $codTienda = preg_replace('/[^A-Za-z0-9]/', '', $agenda['codigo_tienda']);
    $numAgenda = preg_replace('/[^A-Za-z0-9_-]/', '', $agenda['numero_agenda']);
    $zipName = "SODIMAC_INFORMES_{$codTienda}_{$fechaOp}_{$numAgenda}_V" . str_pad($snap['numero_version'] ?? 1, 3, '0', STR_PAD_LEFT) . ".zip";

    // 6. Asunto
    $asunto = "TAXO | Cierre Inventario | {$agenda['codigo_tienda']} {$agenda['nombre_tienda']} | {$fechaOp}";

    // 7. Cuerpo HTML
    $body = "<html><body style='font-family:Arial,sans-serif;font-size:13px;'>";
    $body .= "<h2 style='color:#1a1a2e;'>Expediente de Cierre - Inventario</h2>";
    $body .= "<table style='border-collapse:collapse;width:100%;'>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;width:150px;'><b>Tienda</b></td><td style='padding:5px;border:1px solid #ddd;'>{$agenda['codigo_tienda']} - {$agenda['nombre_tienda']}</td></tr>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;'><b>Agenda</b></td><td style='padding:5px;border:1px solid #ddd;'>{$agenda['numero_agenda']}</td></tr>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;'><b>Fecha</b></td><td style='padding:5px;border:1px solid #ddd;'>{$fechaOp}</td></tr>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;'><b>SKU Total</b></td><td style='padding:5px;border:1px solid #ddd;'>{$snap['cantidad_sku']}</td></tr>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;'><b>Coincidentes</b></td><td style='padding:5px;border:1px solid #ddd;color:#28a745;'>{$snap['cantidad_coincidentes']}</td></tr>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;'><b>Faltantes</b></td><td style='padding:5px;border:1px solid #ddd;color:#dc3545;'>{$snap['cantidad_faltantes']}</td></tr>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;'><b>Sobrantes</b></td><td style='padding:5px;border:1px solid #ddd;color:#ffc107;'>{$snap['cantidad_sobrantes']}</td></tr>";
    $body .= "<tr><td style='padding:5px;border:1px solid #ddd;background:#f8f9fa;'><b>Dif. Valor</b></td><td style='padding:5px;border:1px solid #ddd;'>" . ($snap['total_diferencia_valor'] != 0 ? '<b style="color:#dc3545;">$' . number_format($snap['total_diferencia_valor'], 0, ',', '.') . '</b>' : '<b style="color:#28a745;">$0</b>') . "</td></tr>";
    $body .= "</table>";
    $body .= "<p style='color:#666;font-size:11px;margin-top:15px;'>Adjunto: <b>{$zipName}</b> (" . number_format($zipSize / 1024, 1) . " KB)</p>";
    $body .= "<p style='color:#999;font-size:10px;'>SGO Mobile Inventario - " . date('Y-m-d H:i:s') . "</p>";
    $body .= "</body></html>";

    // 8. Enviar via SMTP (Mailpit en dev, SMTP real en prod)
    $smtpHost = '127.0.0.1';
    $smtpPort = 1025;
    $fromEmail = 'sgo@taxochile.cl';
    $fromName = 'SGO Inventario';

    $enviados = 0;
    $errores = [];

    foreach ($destinatarios as $dest) {
        $email = $dest['email'] ?? $dest;
        if (empty($email)) continue;

        try {
            $envelope = sendMailSmtp($smtpHost, $smtpPort, $fromEmail, $fromName, $email, $asunto, $body, $zipName, $zipBase64);
            $enviados++;
        } catch (Exception $e) {
            $errores[] = ['email' => $email, 'error' => $e->getMessage()];
        }
    }

    // 9. Registrar envio (opcional)
    try {
        $hasEnvioTable = false;
        try {
            $pdo->query("SELECT 1 FROM sod_rep_zip_envio LIMIT 1");
            $hasEnvioTable = true;
        } catch (Exception $e) {}

        if ($hasEnvioTable) {
            $stmtEnv = $pdo->prepare(
                "INSERT INTO sod_rep_zip_envio (
                    id_agenda, id_tienda, nombre_archivo, estado_envio,
                    asunto, cantidad_destinatarios, usuario_envio, fecha_envio
                ) VALUES (
                    :agenda, :tienda, :archivo, :estado,
                    :asunto, :cant, 'TABLET', NOW()
                )"
            );
            $stmtEnv->execute([
                ':agenda' => $agendaId,
                ':tienda' => $snap['id_tienda'],
                ':archivo' => $zipName,
                ':estado' => empty($errores) ? 'OK' : 'OK_CON_ERROR',
                ':asunto' => $asunto,
                ':cant' => $enviados,
            ]);
        }
    } catch (Exception $e) {}

    // 10. Respuesta
    okResponse([
        'enviados' => $enviados,
        'errores' => $errores,
        'asunto' => $asunto,
        'zip' => $zipName,
        'destinatarios' => count($destinatarios),
    ]);

} catch (PDOException $e) {
    errorResponse('Error al enviar correo: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    errorResponse('Error: ' . $e->getMessage(), 500);
}

/**
 * Envio simple via SMTP (sin libreria externa)
 * Funciona con Mailpit (dev) o cualquier SMTP sin auth
 */
function sendMailSmtp($host, $port, $fromEmail, $fromName, $toEmail, $subject, $htmlBody, $attachName = '', $attachBase64 = '') {
    $errno = 0;
    $errstr = '';
    $fp = fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) {
        throw new Exception("SMTP connection failed: {$errstr} ({$errno})");
    }

    // Read banner
    $banner = fgets($fp, 512);

    // EHLO
    fwrite($fp, "EHLO sgo.local\r\n");
    $resp = fgets($fp, 512);
    while (substr($resp, 3, 1) === '-') {
        $resp = fgets($fp, 512);
    }

    // MAIL FROM
    fwrite($fp, "MAIL FROM:<{$fromEmail}>\r\n");
    fgets($fp, 512);

    // RCPT TO
    fwrite($fp, "RCPT TO:<{$toEmail}>\r\n");
    fgets($fp, 512);

    // DATA
    fwrite($fp, "DATA\r\n");
    fgets($fp, 512);

    // Headers
    $boundary = md5(uniqid(time()));
    $headers = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "To: {$toEmail}\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: <" . md5(uniqid()) . "@sgo.local>\r\n";

    if (!empty($attachName)) {
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        fwrite($fp, $headers . "\r\n");

        // HTML body
        fwrite($fp, "--{$boundary}\r\n");
        fwrite($fp, "Content-Type: text/html; charset=UTF-8\r\n");
        fwrite($fp, "Content-Transfer-Encoding: quoted-printable\r\n\r\n");
        fwrite($fp, $htmlBody . "\r\n\r\n");

        // Attachment
        fwrite($fp, "--{$boundary}\r\n");
        fwrite($fp, "Content-Type: application/zip; name=\"{$attachName}\"\r\n");
        fwrite($fp, "Content-Disposition: attachment; filename=\"{$attachName}\"\r\n");
        fwrite($fp, "Content-Transfer-Encoding: base64\r\n\r\n");
        // Wrap base64 at 76 chars
        fwrite($fp, chunk_split($attachBase64, 76, "\r\n") . "\r\n");

        fwrite($fp, "--{$boundary}--\r\n");
    } else {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        fwrite($fp, $headers . "\r\n");
        fwrite($fp, $htmlBody . "\r\n\r\n");
    }

    fwrite($fp, ".\r\n");
    $resp = fgets($fp, 512);

    // QUIT
    fwrite($fp, "QUIT\r\n");
    fgets($fp, 512);

    fclose($fp);
    return true;
}
