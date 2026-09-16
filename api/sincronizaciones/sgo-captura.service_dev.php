<?php
/**
 * Servicio SGO - Captura Inicial (DEV)
 *
 * Versión dev que llama a PRC_SOD_PDA_CAPTURA_REGISTRAR_V1 por cada
 * lectura individual del detalle, NO por el SKU acumulado.
 * Usa lectura.cantidad en vez de detalle.cantidad_fisica.
 *
 * Este archivo NO debe ser invocado directamente por la APK.
 * Es un servicio interno usado por tag-finalizado_dev.php.
 */

require_once '../../config/database.php';

/**
 * Registra captura inicial en SGO llamando el SP una vez por cada lectura.
 *
 * @param array $input    Payload completo recibido desde la APK.
 * @param array $detalles Array de detalles del conteo.
 * @return array          Resultados del SP por cada lectura.
 * @throws Exception      Si falla la conexion PDO o cualquier CALL al SP.
 */
function registrarCapturaInicialSgoDev(array $input, array $detalles): array
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
        $lecturas     = $detalle['lecturas'] ?? [];

        foreach ($lecturas as $idx => $lectura) {
            $lecturaUid = trim($lectura['lectura_uid'] ?? '');
            $cantidad   = isset($lectura['cantidad']) ? (int)$lectura['cantidad'] : 0;
            $fechaHora  = trim($lectura['fecha_hora'] ?? $detalle['fecha_hora'] ?? '');

            $ok = $stmt->execute([
                ':p_tipo_captura'       => 'INICIAL',
                ':p_numero_agenda'      => $numeroAgenda,
                ':p_id_reconteo'        => null,
                ':p_login_operador'     => $operadorLogin,
                ':p_numero_tag'         => $tagCodigo,
                ':p_codigo_ubicacion'   => $zonaNombre,
                ':p_sku'                => $codigoUsable,
                ':p_cantidad'           => $cantidad,
                ':p_fecha_hora_captura' => $fechaHora,
                ':p_numero_pda'         => $pdaCodigo,
                ':p_secuencia_local'    => null,
                ':p_id_origen_externo'  => $lecturaUid,
                ':p_observacion'        => $observacion,
            ]);

            if ($ok === false) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception(
                    "SP fallo para lectura $lecturaUid: " . ($errorInfo[2] ?? 'execute() retorno false')
                );
            }

            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fila) {
                $resultado   = trim($fila['resultado'] ?? '');
                $idConteoDet = $fila['id_conteo_det'] ?? null;
                $idTag       = $fila['id_tag'] ?? null;

                if (strtoupper($resultado) !== 'INSERTADO' && strtoupper($resultado) !== 'DUPLICADO_IGNORADO') {
                    throw new Exception(
                        "SP rechazo lectura $lecturaUid: " . ($fila['mensaje'] ?? $resultado)
                    );
                }

                if (empty($idConteoDet) || empty($idTag)) {
                    throw new Exception(
                        "SP respondio sin id_conteo_det o id_tag para lectura $lecturaUid"
                    );
                }

                $resultados[] = [
                    'detalle_uid'        => $detalleUid,
                    'lectura_uid'        => $lecturaUid,
                    'resultado'          => $resultado,
                    'id_conteo_det'      => $idConteoDet,
                    'id_conteo'          => $fila['id_conteo'] ?? null,
                    'id_tag'             => $idTag,
                    'id_producto'        => $fila['id_producto'] ?? null,
                    'id_origen_externo'  => $fila['id_origen_externo'] ?? null,
                    'sku'                => $fila['sku'] ?? null,
                    'cantidad'           => $cantidad,
                    'cantidad_evento'    => $fila['cantidad_evento'] ?? null,
                    'mensaje'            => $fila['mensaje'] ?? null,
                ];
            } else {
                throw new Exception(
                    "SP no devolvio respuesta para lectura $lecturaUid"
                );
            }

            $stmt->closeCursor();
        }
    }

    return $resultados;
}
