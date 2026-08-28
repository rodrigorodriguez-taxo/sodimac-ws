<?php
/**
 * POST /api/sincronizaciones/validacion-analista.php
 *
 * Guarda validación operacional de Altillos/PDV en Conteo 2 (Q13 + Q14).
 * Registra log completo en sod_pda_tag_carga + sod_pda_tag_carga_detalle
 * usando acceso_sodimac_db.php (taxochil_ac-sodimac).
 * Ejecuta Q13/Q14 vía sgo-captura.service.php (taxochil_taxo_clientes).
 * Idempotente: upsert por carga_uid, uuid por item para retry.
 */

require_once '../../config/acceso_sodimac_db.php';
require_once '../../helpers/response.php';
require_once 'sgo-captura.service.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

$db = new db();
$estadoConexion = $db->conectar();
if ($estadoConexion !== 'OK') {
    errorResponse('Error de conexion: ' . $estadoConexion, 500);
}

$pdoLog = $db->getConexion();

if (!$pdoLog instanceof PDO) {
    errorResponse('Error de conexion: no se pudo obtener PDO', 500);
}

// ──────────── Extraer campos del payload ────────────
$cargaUid       = trim($input['carga_uid'] ?? '');
$idAgenda       = isset($input['id_agenda']) ? (int)$input['id_agenda'] : 0;
$numeroAgenda   = trim($input['numero_agenda'] ?? '');
$codigoTienda   = trim($input['codigo_tienda'] ?? '');
$fechaProgramada = trim($input['fecha_programada'] ?? '');
$pdaCodigo      = trim($input['pda_codigo'] ?? '');
$operadorRut    = trim($input['operador_rut'] ?? '');
$login          = trim($input['login'] ?? '');
$modo           = strtoupper(trim($input['modo'] ?? ''));
$tipo           = strtoupper(trim($input['tipo'] ?? ''));
$idTag          = isset($input['id_tag']) ? (int)$input['id_tag'] : 0;
$numeroTag      = isset($input['numero_tag']) ? (int)$input['numero_tag'] : 0;
$motivo         = trim($input['motivo'] ?? 'Validacion operacional Sodimac');
$items          = $input['items'] ?? null;

// ──────────── Validaciones comunes ────────────
if ($cargaUid === '') {
    errorResponse('Falta carga_uid');
}
if ($idAgenda <= 0) {
    errorResponse('Falta id_agenda o valor invalido');
}
if ($numeroAgenda === '') {
    errorResponse('Falta numero_agenda');
}
if ($codigoTienda === '') {
    errorResponse('Falta codigo_tienda');
}
if ($fechaProgramada === '') {
    errorResponse('Falta fecha_programada');
}
if ($pdaCodigo === '') {
    errorResponse('Falta pda_codigo');
}
if ($operadorRut === '') {
    errorResponse('Falta operador_rut');
}
if ($login === '') {
    errorResponse('Falta login');
}
if ($modo !== 'TAG') {
    errorResponse('modo debe ser TAG');
}
if (!in_array($tipo, ['ALTILLO', 'PDV'], true)) {
    errorResponse('tipo debe ser ALTILLO o PDV');
}
if ($idTag <= 0) {
    errorResponse('Falta id_tag o valor invalido');
}
if ($numeroTag <= 0) {
    errorResponse('Falta numero_tag o valor invalido');
}
if (!is_array($items) || count($items) === 0) {
    errorResponse('Falta items o esta vacio');
}

foreach ($items as $idx => $item) {
    $idProducto  = isset($item['id_producto']) ? (int)$item['id_producto'] : 0;
    $sku         = trim($item['sku'] ?? '');
    $decision    = strtoupper(trim($item['decision'] ?? ''));
    $cantidad    = isset($item['cantidad']) ? (float)$item['cantidad'] : -1;
    $detalleUid  = trim($item['detalle_uid'] ?? '');
    $fechaHora   = trim($item['fecha_hora'] ?? '');

    if ($idProducto <= 0) {
        errorResponse("Item[$idx]: Falta id_producto o valor invalido");
    }
    if ($sku === '') {
        errorResponse("Item[$idx]: Falta sku");
    }
    if (!in_array($decision, ['CONFIRMAR', 'MODIFICAR'], true)) {
        errorResponse("Item[$idx]: decision debe ser CONFIRMAR o MODIFICAR");
    }
    if ($cantidad < 0) {
        errorResponse("Item[$idx]: cantidad no puede ser negativa");
    }
    if ($detalleUid === '') {
        errorResponse("Item[$idx]: Falta detalle_uid");
    }
    if ($fechaHora === '') {
        errorResponse("Item[$idx]: Falta fecha_hora");
    }
}

// ──────────── Calcular totales ────────────
$totalProductos = count($items);
$totalUnidades  = 0;
foreach ($items as $item) {
    $totalUnidades += (int)$item['cantidad'];
}

// ──────────── Payload completo para log ────────────
$payloadJson = json_encode($input, JSON_UNESCAPED_UNICODE);

// ══════════════════════════════════════════════════════════════
// FASE 1: Log en taxochil_ac-sodimac (acceso_sodimac_db.php)
// Transacción independiente. Se commitea aunque falle SGO.
// ══════════════════════════════════════════════════════════════
try {
    $pdoLog->beginTransaction();

    $stmtLogCabecera = $pdoLog->prepare("
        INSERT INTO sod_pda_tag_carga (
            carga_uid, codigo_tienda, fecha_programada, iteracion,
            tag_codigo, zona_nombre, zona_descripcion,
            operador_rut, operador_login, pda_codigo,
            total_productos, total_unidades, estado, mensaje_error,
            payload_json, fecha_recepcion, fecha_procesado
        ) VALUES (
            :carga_uid, :codigo_tienda, :fecha_programada, 2,
            :tag_codigo, :zona_nombre, :zona_descripcion,
            :operador_rut, :operador_login, :pda_codigo,
            :total_productos, :total_unidades, :estado, NULL,
            :payload_json, NOW(), NULL
        )
        ON DUPLICATE KEY UPDATE
            codigo_tienda     = VALUES(codigo_tienda),
            fecha_programada  = VALUES(fecha_programada),
            iteracion         = VALUES(iteracion),
            tag_codigo        = VALUES(tag_codigo),
            zona_nombre       = VALUES(zona_nombre),
            zona_descripcion  = VALUES(zona_descripcion),
            operador_rut      = VALUES(operador_rut),
            operador_login    = VALUES(operador_login),
            pda_codigo        = VALUES(pda_codigo),
            total_productos   = VALUES(total_productos),
            total_unidades    = VALUES(total_unidades),
            estado            = VALUES(estado),
            mensaje_error     = NULL,
            payload_json      = VALUES(payload_json),
            fecha_recepcion   = NOW(),
            fecha_procesado   = NULL
    ");

    $stmtLogCabecera->execute([
        ':carga_uid'        => $cargaUid,
        ':codigo_tienda'    => $codigoTienda,
        ':fecha_programada' => $fechaProgramada,
        ':tag_codigo'       => $numeroTag,
        ':zona_nombre'      => $tipo,
        ':zona_descripcion' => 'VALIDACION_OPERACIONAL_ANALISTA',
        ':operador_rut'     => $operadorRut,
        ':operador_login'   => $login,
        ':pda_codigo'       => $pdaCodigo,
        ':total_productos'  => $totalProductos,
        ':total_unidades'   => $totalUnidades,
        ':estado'           => 'RECIBIDO',
        ':payload_json'     => $payloadJson,
    ]);

    $stmtLogId = $pdoLog->prepare("SELECT id FROM sod_pda_tag_carga WHERE carga_uid = :carga_uid");
    $stmtLogId->execute([':carga_uid' => $cargaUid]);
    $rowLogCabecera = $stmtLogId->fetch(PDO::FETCH_ASSOC);

    if (!$rowLogCabecera) {
        if ($pdoLog->inTransaction()) {
            $pdoLog->rollBack();
        }
        errorResponse('Error al obtener carga_id del log', 500);
    }

    $cargaId = (int)$rowLogCabecera['id'];

    $stmtLogDetalle = $pdoLog->prepare("
        INSERT INTO sod_pda_tag_carga_detalle (
            carga_id, sku, codigo_barras, descripcion,
            stock_sistema, cantidad_fisica, diferencia,
            fecha_hora, fecha_recepcion
        ) VALUES (
            :carga_id, :sku, NULL, :descripcion,
            NULL, :cantidad_fisica, NULL,
            :fecha_hora, NOW()
        )
        ON DUPLICATE KEY UPDATE
            descripcion     = VALUES(descripcion),
            cantidad_fisica = VALUES(cantidad_fisica),
            fecha_hora      = VALUES(fecha_hora),
            fecha_recepcion = NOW()
    ");

    foreach ($items as $item) {
        $sku       = trim($item['sku']);
        $decision  = strtoupper(trim($item['decision']));
        $cantidad  = (float)$item['cantidad'];
        $fechaHora = trim($item['fecha_hora']);

        $stmtLogDetalle->execute([
            ':carga_id'        => $cargaId,
            ':sku'             => $sku,
            ':descripcion'     => $decision,
            ':cantidad_fisica' => $cantidad,
            ':fecha_hora'      => $fechaHora,
        ]);
    }

    $pdoLog->commit();

} catch (Exception $e) {
    if ($pdoLog->inTransaction()) {
        $pdoLog->rollBack();
    }
    errorResponse('Error al guardar log: ' . $e->getMessage(), 500);
}

// ══════════════════════════════════════════════════════════════
// FASE 2: Q13/Q14 en taxochil_taxo_clientes (sgo-captura.service.php)
// Transacción independiente del log.
// ══════════════════════════════════════════════════════════════
try {
    $resultadoSgo = registrarValidacionAnalistaSgo($input, $items);

    // Marcar log como PROCESADO
    $stmtLogOk = $pdoLog->prepare("
        UPDATE sod_pda_tag_carga
        SET estado = 'PROCESADO',
            mensaje_error = NULL,
            fecha_procesado = NOW()
        WHERE carga_uid = :carga_uid
    ");
    $stmtLogOk->execute([':carga_uid' => $cargaUid]);

    okResponse([
        'carga_uid'       => $cargaUid,
        'carga_id'        => $cargaId,
        'id_conteo_2'     => $resultadoSgo['id_conteo_2'],
        'total_productos' => $resultadoSgo['total_productos'],
        'total_unidades'  => $resultadoSgo['total_unidades'],
        'resultados_sgo'  => $resultadoSgo['resultados'],
    ], 'Validacion guardada correctamente');

} catch (Exception $e) {
    // Marcar log como ERROR
    if ($cargaUid !== '') {
        try {
            $stmtLogErr = $pdoLog->prepare("
                UPDATE sod_pda_tag_carga
                SET estado = 'ERROR',
                    mensaje_error = :msg,
                    fecha_procesado = NOW()
                WHERE carga_uid = :carga_uid
            ");
            $stmtLogErr->execute([':msg' => $e->getMessage(), ':carga_uid' => $cargaUid]);
        } catch (Exception $ignored) {
        }
    }
    errorResponse('Error en validacion SGO: ' . $e->getMessage(), 500);
}
