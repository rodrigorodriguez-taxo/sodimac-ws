<?php
/**
 * Servicio SGO - Captura Inicial
 *
 * Llama a PRC_SOD_PDA_CAPTURA_REGISTRAR_V1 por cada detalle del TAG finalizado.
 * Usa require_once '../../config/database.php' para conectar a taxochil_taxo_clientes.
 *
 * Este archivo NO debe ser invocado directamente por la APK.
 * Es un servicio interno usado por tag-finalizado.php.
 */

require_once '../../config/database.php';

/**
 * Registra captura inicial en SGO llamando el SP una vez por cada detalle.
 *
 * @param array $input    Payload completo recibido desde la APK.
 * @param array $detalles Array de detalles del conteo.
 * @return array          Resultados del SP por cada detalle.
 * @throws Exception      Si falla la conexion PDO o cualquier CALL al SP.
 */
function registrarCapturaInicialSgo(array $input, array $detalles): array
{
    global $pdo;

    if (!$pdo instanceof PDO) {
        throw new Exception('No se pudo obtener conexion PDO para SGO');
    }

    $numeroAgenda    = trim($input['numero_agenda'] ?? '');
    $operadorLogin   = trim($input['operador_login'] ?? '');
    $tagCodigo       = trim($input['tag_codigo'] ?? '');
    $zonaNombre      = trim($input['zona_nombre'] ?? '');
    $pdaCodigo       = trim($input['pda_codigo'] ?? '');
    $cargaUid        = trim($input['carga_uid'] ?? '');
    $codigoMuestra   = trim($input['codigo_muestra'] ?? '');
    $idAgenda        = isset($input['id_agenda']) ? (int)$input['id_agenda'] : 0;
    $ubicacionCodigo = trim($input['ubicacion_codigo'] ?? '');

    $observacion = implode(' | ', array_filter([
        $cargaUid      ? "carga: $cargaUid" : null,
        $codigoMuestra ? "muestra: $codigoMuestra" : null,
        $idAgenda      ? "agenda: $idAgenda" : null,
        $ubicacionCodigo ? "ubicacion: $ubicacionCodigo" : null,
    ]));

    $stmt = $pdo->prepare("CALL taxochil_taxo_clientes.PRC_SOD_PDA_CAPTURA_REGISTRAR_V1(
        :p_tipo_captura,
        :p_numero_agenda,
        :p_id_reconteo,
        :p_login_operador,
        :p_numero_tag,
        :p_codigo_ubicacion,
        :p_sku,
        :p_cantidad,
        :p_fecha_hora_captura,
        :p_numero_pda,
        :p_secuencia_local,
        :p_id_origen_externo,
        :p_observacion
    )");

    $resultados = [];

    foreach ($detalles as $detalle) {
        $codigoUsable = trim($detalle['codigo_lectura'] ?? $detalle['codigo_barras'] ?? $detalle['sku'] ?? '');
        $detalleUid   = trim($detalle['detalle_uid'] ?? '');

        $ok = $stmt->execute([
            ':p_tipo_captura'       => 'INICIAL',
            ':p_numero_agenda'      => $numeroAgenda,
            ':p_id_reconteo'        => null,
            ':p_login_operador'     => $operadorLogin,
            ':p_numero_tag'         => $tagCodigo,
            ':p_codigo_ubicacion'   => $zonaNombre,
            ':p_sku'                => $codigoUsable,
            ':p_cantidad'           => (int)$detalle['cantidad_fisica'],
            ':p_fecha_hora_captura' => trim($detalle['fecha_hora'] ?? ''),
            ':p_numero_pda'         => $pdaCodigo,
            ':p_secuencia_local'    => null,
            ':p_id_origen_externo'  => $detalleUid,
            ':p_observacion'        => $observacion,
        ]);

        if ($ok === false) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception(
                "SP fallo para detalle $detalleUid: " . ($errorInfo[2] ?? 'execute() retorno false')
            );
        }

        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila) {
            $resultado = trim($fila['resultado'] ?? '');
            $idConteoDet = $fila['id_conteo_det'] ?? null;
            $idTag = $fila['id_tag'] ?? null;

            // Si el SP devolvió un mensaje de error en lugar de INSERTADO/DUPLICADO_IGNORADO
            if (strtoupper($resultado) !== 'INSERTADO' && strtoupper($resultado) !== 'DUPLICADO_IGNORADO') {
                throw new Exception(
                    "SP rechazo detalle $detalleUid: " . ($fila['mensaje'] ?? $resultado)
                );
            }

            if (empty($idConteoDet) || empty($idTag)) {
                throw new Exception(
                    "SP respondio sin id_conteo_det o id_tag para $detalleUid"
                );
            }

            $resultados[] = [
                'detalle_uid'        => $detalleUid,
                'resultado'          => $resultado,
                'id_conteo_det'      => $idConteoDet,
                'id_conteo'          => $fila['id_conteo'] ?? null,
                'id_tag'             => $idTag,
                'id_producto'        => $fila['id_producto'] ?? null,
                'id_origen_externo'  => $fila['id_origen_externo'] ?? null,
                'sku'                => $fila['sku'] ?? null,
                'cantidad_evento'    => $fila['cantidad_evento'] ?? null,
                'mensaje'            => $fila['mensaje'] ?? null,
            ];
        } else {
            throw new Exception(
                "SP no devolvio respuesta para detalle $detalleUid"
            );
        }

        $stmt->closeCursor();
    }

    return $resultados;
}

/**
 * Registra validación operacional de analista (Altillos/PDV) en Conteo 2.
 *
 * Ejecuta Q13 + Q14 contra taxochil_taxo_clientes.
 * No usa SP; inserta directo en sod_inv_conteo y sod_inv_conteo_det.
 *
 * @param array $input  Payload completo recibido desde la APK.
 * @param array $items  Array de items de validación.
 * @return array        Resultado con id_conteo_2, total_productos, total_unidades.
 * @throws Exception    Si falla la conexión PDO o cualquier operación SQL.
 */
function registrarValidacionAnalistaSgo(array $input, array $items): array
{
    global $pdo;

    if (!$pdo instanceof PDO) {
        throw new Exception('No se pudo obtener conexion PDO para SGO');
    }

    $idAgenda    = (int)$input['id_agenda'];
    $login       = trim($input['login'] ?? '');
    $motivo      = trim($input['motivo'] ?? 'Validacion operacional Sodimac');
    $idTag       = (int)$input['id_tag'];

    $totalProductos = count($items);
    $totalUnidades  = 0;
    foreach ($items as $item) {
        $totalUnidades += (int)$item['cantidad'];
    }

    $resultados = [];

    try {
        $pdo->beginTransaction();

        // ──────────── Q13: Crear/obtener Conteo 2 VALIDACION ────────────
        $stmtQ13 = $pdo->prepare("
            INSERT INTO sod_inv_conteo (
                id_agenda,
                numero_iteracion,
                tipo_conteo,
                estado_conteo,
                motivo,
                fecha_hora_inicio,
                login_responsable,
                fl_activo,
                usuario_creacion
            ) VALUES (
                :id_agenda,
                2,
                'VALIDACION',
                'EN_PROCESO',
                :motivo,
                NOW(),
                :login_responsable,
                'S',
                :usuario_creacion
            )
            ON DUPLICATE KEY UPDATE
                id_conteo = LAST_INSERT_ID(id_conteo),
                estado_conteo = 'EN_PROCESO',
                fecha_hora_termino = NULL,
                login_responsable = VALUES(login_responsable),
                fecha_modificacion = NOW(),
                usuario_modificacion = VALUES(usuario_creacion)
        ");

        $stmtQ13->execute([
            ':id_agenda'         => $idAgenda,
            ':motivo'            => $motivo,
            ':login_responsable' => $login,
            ':usuario_creacion'  => $login,
        ]);

        $stmtId = $pdo->prepare("SELECT LAST_INSERT_ID() AS id_conteo_2");
        $stmtId->execute();
        $rowId = $stmtId->fetch(PDO::FETCH_ASSOC);

        if (!$rowId) {
            throw new Exception('No se pudo obtener id_conteo_2');
        }

        $idConteo2 = (int)$rowId['id_conteo_2'];

        // ──────────── Q14: Guardar cada item ────────────
        $stmtUpdate = $pdo->prepare("
            UPDATE sod_inv_conteo_det
            SET
                estado_registro = 'REEMPLAZADO',
                observacion = LEFT(
                    CONCAT(
                        COALESCE(observacion,''),
                        ' | Reemplazado por nueva validacion operacional ',
                        NOW()
                    ),
                    500
                ),
                fecha_modificacion = NOW(),
                usuario_modificacion = :login
            WHERE id_conteo = :id_conteo_2
              AND id_agenda = :id_agenda
              AND id_tag = :id_tag
              AND id_producto = :id_producto
              AND id_reconteo IS NULL
              AND origen = 'SGO_ANALISTA'
              AND estado_registro = 'VIGENTE'
        ");

        $stmtInsert = $pdo->prepare("
            INSERT INTO sod_inv_conteo_det (
                id_conteo,
                id_reconteo,
                id_agenda,
                id_tag,
                id_producto,
                login_operador,
                cantidad,
                fecha_hora_captura,
                fecha_hora_recepcion,
                dispositivo,
                origen,
                id_origen_externo,
                estado_registro,
                observacion,
                usuario_creacion
            ) VALUES (
                :id_conteo_2,
                NULL,
                :id_agenda,
                :id_tag,
                :id_producto,
                :login_operador,
                :cantidad,
                :fecha_hora_captura,
                NOW(3),
                'APP_ANALISTA',
                'SGO_ANALISTA',
                :detalle_uid,
                'VIGENTE',
                :observacion,
                :usuario_creacion
            )
            ON DUPLICATE KEY UPDATE
                cantidad = VALUES(cantidad),
                observacion = VALUES(observacion),
                fecha_hora_captura = VALUES(fecha_hora_captura),
                usuario_modificacion = VALUES(usuario_creacion),
                fecha_modificacion = NOW()
        ");

        foreach ($items as $item) {
            $idProducto  = (int)$item['id_producto'];
            $cantidad    = (float)$item['cantidad'];
            $detalleUid  = trim($item['detalle_uid']);
            $fechaHora   = trim($item['fecha_hora']);
            $decision    = strtoupper(trim($item['decision']));

            $observacion = "Validacion: $decision - $motivo";

            // 1. Reemplazar versión anterior SGO_ANALISTA del mismo SKU+TAG
            $stmtUpdate->execute([
                ':login'        => $login,
                ':id_conteo_2'  => $idConteo2,
                ':id_agenda'    => $idAgenda,
                ':id_tag'       => $idTag,
                ':id_producto'  => $idProducto,
            ]);

            // 2. Insertar nueva cantidad completa (idempotente por detalle_uid)
            $stmtInsert->execute([
                ':id_conteo_2'         => $idConteo2,
                ':id_agenda'           => $idAgenda,
                ':id_tag'              => $idTag,
                ':id_producto'         => $idProducto,
                ':login_operador'      => $login,
                ':cantidad'            => $cantidad,
                ':fecha_hora_captura'  => $fechaHora,
                ':detalle_uid'         => $detalleUid,
                ':observacion'         => $observacion,
                ':usuario_creacion'    => $login,
            ]);

            $resultados[] = [
                'id_producto'  => $idProducto,
                'sku'          => $item['sku'],
                'decision'     => $decision,
                'cantidad'     => $cantidad,
                'detalle_uid'  => $detalleUid,
            ];
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'id_conteo_2'    => $idConteo2,
        'total_productos' => $totalProductos,
        'total_unidades'  => $totalUnidades,
        'resultados'      => $resultados,
    ];
}
