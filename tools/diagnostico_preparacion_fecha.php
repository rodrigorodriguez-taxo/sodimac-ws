<?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$fechaParam = $_GET['fecha'] ?? null;

if ($fechaParam !== null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaParam)) {
        echo json_encode([
            'status' => 'ERROR',
            'msg'    => 'Formato de fecha invalido. Use YYYY-MM-DD',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $fechaConsulta = $fechaParam;
} else {
    $fechaConsulta = date('Y-m-d');
}

$resumen = [];
$errores = [];

try {
    // 1. agendas activas para la fecha
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM sod_ope_agenda WHERE fl_activo = 'S' AND DATE(fecha_agenda) = :fecha");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $resumen['agendas_activas_fecha'] = (int) $stmt->fetch()['total'];
} catch (PDOException $e) {
    $errores['agendas_activas_fecha'] = $e->getMessage();
}

try {
    // 2. agendas activas no CERRADA/SUSPENDIDA
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM sod_ope_agenda a INNER JOIN sod_ope_estado_agenda ea ON ea.id_estado_agenda = a.id_estado_agenda WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha AND ea.codigo_estado NOT IN ('CERRADA', 'SUSPENDIDA')");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $resumen['agendas_no_cerradas'] = (int) $stmt->fetch()['total'];
} catch (PDOException $e) {
    $errores['agendas_no_cerradas'] = $e->getMessage();
}

try {
    // 3. agendas con fl_lista_conteo = 'S'
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM sod_ope_agenda a INNER JOIN vw_sod_agenda_preparacion_resumen prep ON prep.id_agenda = a.id_agenda WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha AND prep.fl_lista_conteo = 'S'");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $resumen['agendas_lista_conteo'] = (int) $stmt->fetch()['total'];
} catch (PDOException $e) {
    $errores['agendas_lista_conteo'] = $e->getMessage();
}

try {
    // 4. agendas con usuario asignado
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT d.id_agenda) AS total FROM vw_sod_dash_agenda_usuario d INNER JOIN sod_ope_agenda a ON a.id_agenda = d.id_agenda WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $resumen['agendas_con_usuario'] = (int) $stmt->fetch()['total'];
} catch (PDOException $e) {
    $errores['agendas_con_usuario'] = $e->getMessage();
}

try {
    // 5. usuarios activos asignados a agendas de la fecha
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT su.login) AS total FROM vw_sod_dash_agenda_usuario d INNER JOIN sod_ope_agenda a ON a.id_agenda = d.id_agenda INNER JOIN sec_users su ON CONVERT(su.login USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(d.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci INNER JOIN sod_sec_usuario_ext ue ON CONVERT(su.login USING utf8mb4) COLLATE utf8mb4_unicode_ci = ue.login WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha AND su.active = 'Y' AND ue.fl_activo = 'S' AND (ue.fecha_inicio_vigencia IS NULL OR ue.fecha_inicio_vigencia <= NOW()) AND (ue.fecha_fin_vigencia IS NULL OR ue.fecha_fin_vigencia >= NOW())");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $resumen['usuarios_activos_asignados'] = (int) $stmt->fetch()['total'];
} catch (PDOException $e) {
    $errores['usuarios_activos_asignados'] = $e->getMessage();
}

try {
    // 6. usuarios activos asignados con tienda vigente
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT su.login) AS total FROM vw_sod_dash_agenda_usuario d INNER JOIN sod_ope_agenda a ON a.id_agenda = d.id_agenda INNER JOIN sec_users su ON CONVERT(su.login USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(d.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci INNER JOIN sod_sec_usuario_ext ue ON CONVERT(su.login USING utf8mb4) COLLATE utf8mb4_unicode_ci = ue.login INNER JOIN vw_sod_usuario_tienda_resumen vt ON CONVERT(TRIM(vt.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(su.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci AND vt.estado_operativo = 'VIGENTE' AND vt.fl_usuario_vigente = 'S' WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha AND su.active = 'Y' AND ue.fl_activo = 'S' AND (ue.fecha_inicio_vigencia IS NULL OR ue.fecha_inicio_vigencia <= NOW()) AND (ue.fecha_fin_vigencia IS NULL OR ue.fecha_fin_vigencia >= NOW())");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $resumen['usuarios_con_tienda_vigente'] = (int) $stmt->fetch()['total'];
} catch (PDOException $e) {
    $errores['usuarios_con_tienda_vigente'] = $e->getMessage();
}

try {
    // 7. agendas con productos/códigos
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.id_agenda) AS total FROM sod_ope_agenda a INNER JOIN vw_sod_agenda_muestra_producto_codigo c ON c.id_agenda = a.id_agenda WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $resumen['agendas_con_productos_codigos'] = (int) $stmt->fetch()['total'];
} catch (PDOException $e) {
    $errores['agendas_con_productos_codigos'] = $e->getMessage();
}

// Muestras de datos
$muestras = [];

try {
    $stmt = $pdo->prepare("SELECT a.id_agenda, a.numero_agenda, DATE(a.fecha_agenda) AS fecha_agenda, ea.codigo_estado AS estado, prep.fl_lista_conteo FROM sod_ope_agenda a INNER JOIN sod_ope_estado_agenda ea ON ea.id_estado_agenda = a.id_estado_agenda LEFT JOIN vw_sod_agenda_preparacion_resumen prep ON prep.id_agenda = a.id_agenda WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha ORDER BY a.numero_agenda ASC LIMIT 10");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $muestras['agendas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errores['muestras_agendas'] = $e->getMessage();
}

try {
    $stmt = $pdo->prepare("SELECT DISTINCT d.login FROM vw_sod_dash_agenda_usuario d INNER JOIN sod_ope_agenda a ON a.id_agenda = d.id_agenda WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha LIMIT 10");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $muestras['usuarios_asignados'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errores['muestras_usuarios'] = $e->getMessage();
}

try {
    $stmt = $pdo->prepare("SELECT prep.id_agenda, prep.id_muestra, prep.codigo_muestra, prep.nombre_muestra, prep.fl_lista_conteo FROM vw_sod_agenda_preparacion_resumen prep INNER JOIN sod_ope_agenda a ON a.id_agenda = prep.id_agenda WHERE a.fl_activo = 'S' AND DATE(a.fecha_agenda) = :fecha LIMIT 10");
    $stmt->execute([':fecha' => $fechaConsulta]);
    $muestras['preparacion'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errores['muestras_preparacion'] = $e->getMessage();
}

echo json_encode([
    'status'         => 'OK',
    'fecha_busqueda' => date('Y-m-d H:i:s'),
    'fecha_consulta' => $fechaConsulta,
    'resumen'        => $resumen,
    'muestras'       => $muestras,
    'errores'        => $errores,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
