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

        $stmt->execute([
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

        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila) {
            $resultados[] = [
                'detalle_uid'        => $detalleUid,
                'resultado'          => $fila['resultado'] ?? null,
                'id_conteo_det'      => $fila['id_conteo_det'] ?? null,
                'id_conteo'          => $fila['id_conteo'] ?? null,
                'id_tag'             => $fila['id_tag'] ?? null,
                'id_producto'        => $fila['id_producto'] ?? null,
                'id_origen_externo'  => $fila['id_origen_externo'] ?? null,
                'sku'                => $fila['sku'] ?? null,
                'cantidad_evento'    => $fila['cantidad_evento'] ?? null,
                'mensaje'            => $fila['mensaje'] ?? null,
            ];
        } else {
            $resultados[] = [
                'detalle_uid' => $detalleUid,
                'resultado'   => 'SIN_RESPUESTA',
                'sku'         => $codigoUsable,
            ];
        }

        $stmt->closeCursor();
    }

    return $resultados;
}
