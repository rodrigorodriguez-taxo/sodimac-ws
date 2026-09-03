<?php

require_once '../../config/acceso_sodimac_db.php';
require_once '../../helpers/response.php';
require_once 'sgo-captura.service.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

// Conectar a taxochil_ac-sodimac
$db = new db();
$estadoConexion = $db->conectar();
if ($estadoConexion !== 'OK') {
    errorResponse('Error de conexion: ' . $estadoConexion, 500);
}

$pdo = $db->getConexion();

if (!$pdo instanceof PDO) {
    errorResponse('Error de conexion: no se pudo obtener PDO', 500);
}

// ──────────── Extraer campos del payload ────────────
$cargaUid         = trim($input['carga_uid'] ?? '');
$codigoTienda     = trim($input['codigo_tienda'] ?? '');
$fechaProgramada  = trim($input['fecha_programada'] ?? '');
$iteracion        = isset($input['iteracion']) ? (int)$input['iteracion'] : 0;
$tagCodigo        = trim($input['tag_codigo'] ?? '');
$zonaNombre       = trim($input['zona_nombre'] ?? '');
$zonaDescripcion  = trim($input['zona_descripcion'] ?? '');

// ──────────── Normalizar zona_nombre ────────────
// Mapeo de aliases antiguos de la APK a códigos oficiales de sod_cfg_tipo_ubicacion.
$zonaAliases = [
    'BODEGA_TRASTIENDA' => 'BODEGA',
    'EXHIBICIONES'      => 'EXHIBICION',
    'PTO_VTA_OTROS'     => 'OTRO',
];
$zonaOficiales = ['PUNTO_VENTA', 'ALTILLO', 'SALA_VENTAS', 'BODEGA', 'RECEPCION', 'TRASTIENDA', 'EXHIBICION', 'GANCHERA', 'OTRO'];

$zonaUpper = strtoupper($zonaNombre);
if (isset($zonaAliases[$zonaUpper])) {
    $zonaNombre = $zonaAliases[$zonaUpper];
} else {
    $zonaNombre = $zonaUpper;
}
$operadorRut      = trim($input['operador_rut'] ?? '');
$operadorLogin    = trim($input['operador_login'] ?? '');
$pdaCodigo        = trim($input['pda_codigo'] ?? '');
$detalles         = $input['detalles'] ?? null;
$codigoMuestra    = trim($input['codigo_muestra'] ?? '');
$idAgenda         = isset($input['id_agenda']) ? (int)$input['id_agenda'] : 0;
$numeroAgenda     = trim($input['numero_agenda'] ?? '');
$ubicacionCodigo  = trim($input['ubicacion_codigo'] ?? '');

// ──────────── Validaciones comunes ────────────
if ($cargaUid === '') {
    errorResponse('Falta carga_uid');
}
if ($codigoTienda === '') {
    errorResponse('Falta codigo_tienda');
}
if ($fechaProgramada === '') {
    errorResponse('Falta fecha_programada');
}
if ($iteracion <= 0) {
    errorResponse('Falta iteracion o valor invalido');
}
if ($tagCodigo === '') {
    errorResponse('Falta tag_codigo');
}
if ($zonaNombre === '') {
    errorResponse('Falta zona_nombre');
}
if (!in_array($zonaNombre, $zonaOficiales, true)) {
    errorResponse('Tipo de ubicacion no valido para SGO: ' . ($input['zona_nombre'] ?? ''));
}
if ($operadorRut === '') {
    errorResponse('Falta operador_rut');
}
if ($operadorLogin === '') {
    errorResponse('Falta operador_login');
}
if ($pdaCodigo === '') {
    errorResponse('Falta pda_codigo');
}
if (!is_array($detalles) || count($detalles) === 0) {
    errorResponse('Falta detalles o esta vacio');
}

// ──────────── Validar y calcular totales ────────────
foreach ($detalles as $idx => $detalle) {
    $sku = trim($detalle['sku'] ?? '');
    $cantidadFisica = $detalle['cantidad_fisica'] ?? null;

    if ($sku === '') {
        errorResponse("Detalle[$idx]: Falta sku");
    }
    if ($cantidadFisica === null || !is_numeric($cantidadFisica)) {
        errorResponse("Detalle[$idx]: Falta cantidad_fisica o valor invalido");
    }
    if ((float)$cantidadFisica < 0) {
        errorResponse("Detalle[$idx]: cantidad_fisica no puede ser negativa");
    }
}

$totalProductos = count($detalles);
$totalUnidades  = 0;
foreach ($detalles as $detalle) {
    $totalUnidades += (int)$detalle['cantidad_fisica'];
}

// ──────────── Idempotencia: si ya fue procesado, responder OK inmediato ────────────
$stmtExistente = $pdo->prepare("
    SELECT id, estado, total_productos, total_unidades
    FROM sod_pda_tag_carga
    WHERE carga_uid = :carga_uid
    LIMIT 1
");
$stmtExistente->execute([':carga_uid' => $cargaUid]);
$rowExistente = $stmtExistente->fetch(PDO::FETCH_ASSOC);

if ($rowExistente && strtoupper(trim($rowExistente['estado'])) === 'PROCESADO') {
    okResponse([
        'carga_uid'       => $cargaUid,
        'carga_id'        => (int)$rowExistente['id'],
        'total_productos' => isset($rowExistente['total_productos']) ? (int)$rowExistente['total_productos'] : $totalProductos,
        'total_unidades'  => isset($rowExistente['total_unidades']) ? (float)$rowExistente['total_unidades'] : $totalUnidades,
    ], 'TAG ya procesado anteriormente');
}

// ──────────── Validaciones para SP (solo iteracion = 1) ────────────
if ($iteracion === 1) {
    if ($numeroAgenda === '' || in_array(strtolower($numeroAgenda), ['null', 'undefined'], true)) {
        errorResponse('Falta numero_agenda para iteracion inicial');
    }
    if (!ctype_digit($tagCodigo)) {
        errorResponse('tag_codigo debe ser numerico para iteracion inicial');
    }

    foreach ($detalles as $idx => $detalle) {
        $detalleUid = trim($detalle['detalle_uid'] ?? '');
        if ($detalleUid === '') {
            errorResponse("Detalle[$idx]: Falta detalle_uid para iteracion inicial");
        }

        $codigoUsable = trim($detalle['codigo_lectura'] ?? $detalle['codigo_barras'] ?? $detalle['sku'] ?? '');
        if ($codigoUsable === '') {
            errorResponse("Detalle[$idx]: Falta codigo_lectura, codigo_barras o sku");
        }

        $fechaHora = trim($detalle['fecha_hora'] ?? '');
        if ($fechaHora === '') {
            errorResponse("Detalle[$idx]: Falta fecha_hora para iteracion inicial");
        }
    }
}

// ──────────── Guardar payload completo ────────────
$payloadJson = json_encode($input, JSON_UNESCAPED_UNICODE);

try {
    $pdo->beginTransaction();

    // Upsert cabecera en sod_pda_tag_carga
    $stmtCabecera = $pdo->prepare("
        INSERT INTO sod_pda_tag_carga (
            carga_uid, codigo_tienda, fecha_programada, iteracion,
            tag_codigo, zona_nombre, zona_descripcion,
            operador_rut, operador_login, pda_codigo,
            total_productos, total_unidades, estado, mensaje_error,
            payload_json, fecha_recepcion, fecha_procesado
        ) VALUES (
            :carga_uid, :codigo_tienda, :fecha_programada, :iteracion,
            :tag_codigo, :zona_nombre, :zona_descripcion,
            :operador_rut, :operador_login, :pda_codigo,
            :total_productos, :total_unidades, :estado, :mensaje_error,
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
            mensaje_error     = VALUES(mensaje_error),
            payload_json      = VALUES(payload_json),
            fecha_recepcion   = NOW(),
            fecha_procesado   = NULL
    ");

    $stmtCabecera->execute([
        ':carga_uid'        => $cargaUid,
        ':codigo_tienda'    => $codigoTienda,
        ':fecha_programada' => $fechaProgramada,
        ':iteracion'        => $iteracion,
        ':tag_codigo'       => $tagCodigo,
        ':zona_nombre'      => $zonaNombre,
        ':zona_descripcion' => $zonaDescripcion,
        ':operador_rut'     => $operadorRut,
        ':operador_login'   => $operadorLogin,
        ':pda_codigo'       => $pdaCodigo,
        ':total_productos'  => $totalProductos,
        ':total_unidades'   => $totalUnidades,
        ':estado'           => 'RECIBIDO',
        ':mensaje_error'    => null,
        ':payload_json'     => $payloadJson,
    ]);

    // Obtener el id de la cabecera
    $stmtGetId = $pdo->prepare("SELECT id FROM sod_pda_tag_carga WHERE carga_uid = :carga_uid");
    $stmtGetId->execute([':carga_uid' => $cargaUid]);
    $rowCabecera = $stmtGetId->fetch(PDO::FETCH_ASSOC);

    if (!$rowCabecera) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        errorResponse('Error al obtener carga_id', 500);
    }

    $cargaId = $rowCabecera['id'];

    // Upsert detalle en sod_pda_tag_carga_detalle
    $stmtDetalle = $pdo->prepare("
        INSERT INTO sod_pda_tag_carga_detalle (
            carga_id, sku, codigo_barras, descripcion,
            stock_sistema, cantidad_fisica, diferencia,
            fecha_hora, fecha_recepcion
        ) VALUES (
            :carga_id, :sku, :codigo_barras, :descripcion,
            :stock_sistema, :cantidad_fisica, :diferencia,
            :fecha_hora, NOW()
        )
        ON DUPLICATE KEY UPDATE
            codigo_barras   = VALUES(codigo_barras),
            descripcion     = VALUES(descripcion),
            stock_sistema   = VALUES(stock_sistema),
            cantidad_fisica = VALUES(cantidad_fisica),
            diferencia      = VALUES(diferencia),
            fecha_hora      = VALUES(fecha_hora),
            fecha_recepcion = NOW()
    ");

    foreach ($detalles as $detalle) {
        $sku            = trim($detalle['sku']);
        $codigoBarras   = trim($detalle['codigo_barras'] ?? null);
        $descripcion    = trim($detalle['descripcion'] ?? null);
        $stockSistema   = isset($detalle['stock_sistema']) && is_numeric($detalle['stock_sistema']) ? (float)$detalle['stock_sistema'] : null;
        $cantidadFisica = (int)$detalle['cantidad_fisica'];
        $fechaHora      = trim($detalle['fecha_hora'] ?? null);

        $diferencia = null;
        if ($stockSistema !== null) {
            $diferencia = $cantidadFisica - $stockSistema;
        }

        $stmtDetalle->execute([
            ':carga_id'       => $cargaId,
            ':sku'            => $sku,
            ':codigo_barras'  => $codigoBarras,
            ':descripcion'    => $descripcion,
            ':stock_sistema'  => $stockSistema,
            ':cantidad_fisica' => $cantidadFisica,
            ':diferencia'     => $diferencia,
            ':fecha_hora'     => $fechaHora,
        ]);
    }

    // ──────────── Fase 5: Llamar servicio SGO para iteracion = 1 ────────────
    $resultadosSgo = null;
    if ($iteracion === 1) {
        $resultadosSgo = registrarCapturaInicialSgo($input, $detalles);

        // Verificar que todos los resultados del SP hayan sido exitosos
        $todosOk = true;
        foreach ($resultadosSgo as $r) {
            $resultado = strtoupper(trim($r['resultado'] ?? ''));
            if (!in_array($resultado, ['INSERTADO', 'DUPLICADO_IGNORADO'], true) || empty($r['id_conteo_det']) || empty($r['id_tag'])) {
                $todosOk = false;
                break;
            }
        }

        if ($todosOk) {
            $stmtEstado = $pdo->prepare("
                UPDATE sod_pda_tag_carga
                SET estado = 'PROCESADO',
                    mensaje_error = NULL,
                    fecha_procesado = NOW()
                WHERE carga_uid = :carga_uid
            ");
            $stmtEstado->execute([':carga_uid' => $cargaUid]);
        } else {
            // Recopilar mensajes de error de los detalles fallidos
            $errores = [];
            foreach ($resultadosSgo as $r) {
                $resultado = strtoupper(trim($r['resultado'] ?? ''));
                if (!in_array($resultado, ['INSERTADO', 'DUPLICADO_IGNORADO'], true) || empty($r['id_conteo_det']) || empty($r['id_tag'])) {
                    $errores[] = ($r['detalle_uid'] ?? '?') . ': ' . ($r['mensaje'] ?? $r['resultado'] ?? 'ERROR');
                }
            }
            $errorMsg = 'SP rechazó detalles: ' . implode('; ', $errores);

            $stmtEstado = $pdo->prepare("
                UPDATE sod_pda_tag_carga
                SET estado = 'ERROR',
                    mensaje_error = :msg,
                    fecha_procesado = NOW()
                WHERE carga_uid = :carga_uid
            ");
            $stmtEstado->execute([':msg' => $errorMsg, ':carga_uid' => $cargaUid]);

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            errorResponse('SP rechazo uno o mas detalles: ' . $errorMsg, 422);
        }
    }

    $pdo->commit();

    $response = [
        'carga_uid'       => $cargaUid,
        'carga_id'        => $cargaId,
        'total_productos' => $totalProductos,
        'total_unidades'  => $totalUnidades,
    ];
    if ($resultadosSgo !== null) {
        $response['resultados_sgo'] = $resultadosSgo;
    }

    okResponse($response, $iteracion === 1 ? 'TAG procesado correctamente' : 'TAG recibido correctamente');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    errorResponse('Error de base de datos: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    errorResponse('Error del servidor: ' . $e->getMessage(), 500);
}
