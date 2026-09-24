<?php
# ws/api/tablet/cierres/integridad.php
# POST { id_cierre_agenda, login? }
# Verifica integridad del snapshot inmutable

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();
if (empty($input['id_cierre_agenda'])) errorResponse('Falta id_cierre_agenda');

$idCierre = $input['id_cierre_agenda'];
$login = $input['login'] ?? 'AUDITOR';
$inicio = microtime(true);

try {
    // 1. Obtener cabecera del cierre
    $stmt = $pdo->prepare(
        "SELECT * FROM sod_inv_cierre_agenda WHERE id_cierre_agenda = :id"
    );
    $stmt->execute([':id' => $idCierre]);
    $cierre = $stmt->fetch();
    if (!$cierre) errorResponse('Cierre no encontrado');

    // 2. Obtener detalles
    $stmtDet = $pdo->prepare(
        "SELECT * FROM sod_inv_cierre_agenda_det WHERE id_cierre_agenda = :id"
    );
    $stmtDet->execute([':id' => $idCierre]);
    $detalles = $stmtDet->fetchAll();

    // === CHECK 1: Hash cabecera ===
    $headerData = json_encode([
        'id_agenda' => $cierre['id_agenda'],
        'version' => $cierre['numero_version'],
        'stock' => (float)$cierre['total_stock_unidades'],
        'fisico' => (float)$cierre['total_fisico_unidades'],
        'dif' => (float)$cierre['total_diferencia_unidades'],
        'v_stock' => (float)$cierre['total_valor_stock'],
        'v_fisico' => (float)$cierre['total_valor_fisico'],
    ]);
    $hashRecalculado = hash('sha256', $headerData);
    $flHashCabeceraOk = ($hashRecalculado === $cierre['hash_cabecera']) ? 'S' : 'N';

    // === CHECK 2: Detalle completo ===
    $cantidadDetalleEsperada = (int)$cierre['cantidad_sku'];
    $cantidadDetalleActual = count($detalles);
    $flDetalleCompleto = ($cantidadDetalleActual >= $cantidadDetalleEsperada) ? 'S' : 'N';

    // === CHECK 3: Hash de detalle ===
    $hashOk = 0;
    $hashError = 0;
    foreach ($detalles as $det) {
        $detHash = json_encode([
            'id_producto' => (int)$det['id_producto'],
            'sku' => $det['sku'],
            'stock' => (float)$det['stock_teorico'],
            'final' => (float)$det['cantidad_final'],
            'dif' => (float)$det['diferencia_cantidad'],
        ]);
        $hashCalc = hash('sha256', $detHash);
        if ($hashCalc === $det['hash_detalle']) $hashOk++;
        else $hashError++;
    }

    // === CHECK 4: Resumen consistente ===
    $totalStockCalc = 0;
    $totalFisicoCalc = 0;
    $totalDifCalc = 0;
    $totalDifAbsCalc = 0;
    $totalValStockCalc = 0;
    $totalValFisicoCalc = 0;
    $totalDifValCalc = 0;
    $totalDifValAbsCalc = 0;

    foreach ($detalles as $det) {
        $totalStockCalc += (float)$det['stock_teorico'];
        $totalFisicoCalc += (float)$det['cantidad_final'];
        $totalDifCalc += (float)$det['diferencia_cantidad'];
        $totalDifAbsCalc += abs((float)$det['diferencia_cantidad']);
        $totalValStockCalc += (float)$det['valor_stock'];
        $totalValFisicoCalc += (float)$det['valor_fisico'];
        $totalDifValCalc += (float)$det['diferencia_valor'];
        $totalDifValAbsCalc += abs((float)$det['diferencia_valor']);
    }

    $tolerancia = 0.51;
    $flResumenOk = (
        abs($totalStockCalc - (float)$cierre['total_stock_unidades']) < $tolerancia &&
        abs($totalFisicoCalc - (float)$cierre['total_fisico_unidades']) < $tolerancia &&
        abs($totalDifCalc - (float)$cierre['total_diferencia_unidades']) < $tolerancia &&
        abs($totalDifAbsCalc - (float)$cierre['total_diferencia_unidades_abs']) < $tolerancia &&
        abs($totalValStockCalc - (float)$cierre['total_valor_stock']) < $tolerancia &&
        abs($totalValFisicoCalc - (float)$cierre['total_valor_fisico']) < $tolerancia
    ) ? 'S' : 'N';

    // === CHECK 5: Inmutable ===
    $flInmutableOk = ($cierre['fl_inmutable'] === 'S') ? 'S' : 'N';

    // === CHECK 6: Estructura ===
    $flEstructuraOk = (
        !empty($cierre['hash_cabecera']) &&
        !empty($cierre['algoritmo_hash']) &&
        $cierre['estado_cierre'] === 'CERRADA' &&
        $cantidadDetalleActual > 0
    ) ? 'S' : 'N';

    // Calcular estado general
    $checks = [$flHashCabeceraOk, $flDetalleCompleto, $flResumenOk, $flInmutableOk, $flEstructuraOk];
    $todosOk = !in_array('N', $checks);
    $estadoVerificacion = $todosOk ? 'OK' : ($cantidadDetalleActual < $cantidadDetalleEsperada ? 'INCOMPLETO' : 'ALTERADO');

    // Calcular controles OK/errores
    $controlesOk = 0;
    $errores = 0;
    foreach ($checks as $c) {
        if ($c === 'S') $controlesOk++;
        else $errores++;
    }

    // Resumen
    $resumenParts = [];
    if ($flHashCabeceraOk === 'S') $resumenParts[] = 'Hash cabecera OK';
    else $resumenParts[] = 'Hash cabecera ALTERADO';
    if ($flDetalleCompleto === 'S') $resumenParts[] = 'Detalle completo';
    else $resumenParts[] = 'Detalle incompleto';
    if ($flResumenOk === 'S') $resumenParts[] = 'Resumen consistente';
    else $resumenParts[] = 'Resumen inconsistente';
    if ($flInmutableOk === 'S') $resumenParts[] = 'Inmutabilidad OK';
    else $resumenParts[] = 'Inmutabilidad COMPROMETIDA';

    $duracionMs = (int)((microtime(true) - $inicio) * 1000);

    // Insertar verificacion
    $stmtIns = $pdo->prepare(
        "INSERT INTO sod_inv_cierre_verificacion (
            id_cierre_agenda, codigo_cierre, numero_version_cierre, motor_version,
            estado_verificacion, hash_cabecera_guardado, hash_cabecera_recalculado,
            fl_hash_cabecera_ok, cantidad_detalle_esperada, cantidad_detalle_actual,
            cantidad_detalle_hash_ok, cantidad_detalle_hash_error,
            fl_detalle_completo, fl_criterios_completos, fl_inmutable_ok, fl_estructura_ok,
            cantidad_controles_ok, cantidad_advertencias, cantidad_errores,
            resumen_verificacion, duracion_ms, fecha_verificacion, login_verificacion, origen_verificacion
        ) VALUES (
            :id_cierre, :codigo, :version, 'V1',
            :estado, :hash_guardado, :hash_recalculado,
            :fl_hash, :det_esperada, :det_actual,
            :hash_ok, :hash_error,
            :fl_det, 'S', :fl_inmut, :fl_est,
            :ctrl_ok, 0, :errores,
            :resumen, :duracion, NOW(6), :login, 'TABLET'
        )"
    );
    $stmtIns->execute([
        ':id_cierre' => $idCierre,
        ':codigo' => $cierre['codigo_cierre'],
        ':version' => $cierre['numero_version'],
        ':estado' => $estadoVerificacion,
        ':hash_guardado' => $cierre['hash_cabecera'],
        ':hash_recalculado' => $hashRecalculado,
        ':fl_hash' => $flHashCabeceraOk,
        ':det_esperada' => $cantidadDetalleEsperada,
        ':det_actual' => $cantidadDetalleActual,
        ':hash_ok' => $hashOk,
        ':hash_error' => $hashError,
        ':fl_det' => $flDetalleCompleto,
        ':fl_inmut' => $flInmutableOk,
        ':fl_est' => $flEstructuraOk,
        ':ctrl_ok' => $controlesOk,
        ':errores' => $errores,
        ':resumen' => implode('. ', $resumenParts),
        ':duracion' => $duracionMs,
        ':login' => $login,
    ]);

    $idVerificacion = $pdo->lastInsertId();

    okResponse([
        'id_verificacion' => (int)$idVerificacion,
        'estado_verificacion' => $estadoVerificacion,
        'checks' => [
            'hash_cabecera' => $flHashCabeceraOk === 'S' ? 'OK' : 'ERROR',
            'hash_cabecera_guardado' => $cierre['hash_cabecera'],
            'hash_cabecera_recalculado' => $hashRecalculado,
            'detalle_completo' => $flDetalleCompleto === 'S' ? 'OK' : 'ERROR',
            'cantidad_detalle_esperada' => $cantidadDetalleEsperada,
            'cantidad_detalle_actual' => $cantidadDetalleActual,
            'hash_detalle_ok' => $hashOk,
            'hash_detalle_error' => $hashError,
            'resumen_consistente' => $flResumenOk === 'S' ? 'OK' : 'ERROR',
            'inmutable_ok' => $flInmutableOk === 'S' ? 'OK' : 'ERROR',
            'estructura_ok' => $flEstructuraOk === 'S' ? 'OK' : 'ERROR',
        ],
        'resumen' => implode('. ', $resumenParts),
        'duracion_ms' => $duracionMs,
    ], 'Verificacion de integridad completada');

} catch (PDOException $e) {
    errorResponse('Error en verificacion: ' . $e->getMessage(), 500);
}
