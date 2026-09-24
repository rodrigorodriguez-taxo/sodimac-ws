<?php
/**
 * ============================================================================
 * SODIMAC - RECEPCION TAG PDA V2
 * ============================================================================
 *
 * OBJETIVO
 * - Recibir el TAG completo desde la PDA.
 * - Persistir de forma durable cabecera + TODAS las lecturas.
 * - Conservar el codigo REAL ingresado por la PDA.
 * - NO procesar SGO dentro del request HTTP.
 * - Responder OK solamente despues del COMMIT.
 *
 * IMPORTANTE
 * - carga_uid    = idempotencia del TAG.
 * - lectura_uid  = idempotencia/trazabilidad de cada lectura.
 * - codigo_lectura = codigo REAL capturado por PDA.
 *
 * El procesamiento posterior queda a cargo de:
 *
 *   PRC_SOD_PDA_LECTURAS_PROCESAR_V1
 *
 * ============================================================================
 */

require_once '../../config/acceso_sodimac_db.php';
require_once '../../helpers/response.php';

/*
 * IMPORTANTE:
 * Ya NO cargamos:
 *
 * require_once 'sgo-captura.service.php';
 *
 * porque este endpoint NO llama SGO.
 */

corsHeaders();


/* ==========================================================================
   METODO
   ========================================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}


/* ==========================================================================
   FUNCIONES LOCALES
   ========================================================================== */

/**
 * Valida YYYY-MM-DD.
 */
function fechaYmdValida(string $fecha): bool
{
    if ($fecha === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);

    return $dt !== false
        && $dt->format('Y-m-d') === $fecha;
}


/**
 * Normaliza fecha PDA a DATETIME(3).
 *
 * Entrada esperada:
 *   2026-09-16 12:42:41.794
 *   2026-09-16 12:42:41
 *
 * Retorna:
 *   2026-09-16 12:42:41.794
 *
 * Si viene una fecha invalida retorna NULL.
 *
 * NO rechazamos el TAG completo por una fecha incorrecta:
 * la lectura queda persistida y posteriormente el worker
 * la dejará como ERROR controlado.
 */
function normalizarFechaHoraPda($valor): ?string
{
    $valor = trim((string)$valor);

    if ($valor === '') {
        return null;
    }

    /*
     * Fecha sin milisegundos.
     */
    if (preg_match(
        '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
        $valor
    )) {
        $valor .= '.000';
    }

    /*
     * Permitir 1, 2 o 3 decimales y completar a 3.
     */
    if (preg_match(
        '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.(\d{1,3})$/',
        $valor,
        $m
    )) {
        $valor =
            $m[1] . '.' . str_pad(
                $m[2],
                3,
                '0',
                STR_PAD_RIGHT
            );
    }

    $dt = DateTime::createFromFormat(
        'Y-m-d H:i:s.v',
        $valor
    );

    if ($dt === false) {
        return null;
    }

    $errores = DateTime::getLastErrors();

    if (
        is_array($errores)
        &&
        (
            $errores['warning_count'] > 0
            ||
            $errores['error_count'] > 0
        )
    ) {
        return null;
    }

    return $dt->format('Y-m-d H:i:s.v');
}


/* ==========================================================================
   LEER JSON
   ========================================================================== */

$input = getJsonInput();

if (!$input) {
    errorResponse(
        'No se recibieron datos validos para sincronizar el TAG.',
        400
    );
}


/* ==========================================================================
   CAMPOS CABECERA
   ========================================================================== */

$cargaUid =
    trim((string)($input['carga_uid'] ?? ''));

$codigoTienda =
    trim((string)($input['codigo_tienda'] ?? ''));

$fechaProgramada =
    trim((string)($input['fecha_programada'] ?? ''));

$iteracion =
    isset($input['iteracion'])
        ? (int)$input['iteracion']
        : 0;

$tagCodigo =
    trim((string)($input['tag_codigo'] ?? ''));

$zonaNombre =
    trim((string)($input['zona_nombre'] ?? ''));

$zonaDescripcion =
    trim((string)($input['zona_descripcion'] ?? ''));

$operadorRut =
    trim((string)($input['operador_rut'] ?? ''));

$operadorLogin =
    trim((string)($input['operador_login'] ?? ''));

$pdaCodigo =
    trim((string)($input['pda_codigo'] ?? ''));

$codigoMuestra =
    trim((string)($input['codigo_muestra'] ?? ''));

$idAgenda =
    isset($input['id_agenda'])
        ? (int)$input['id_agenda']
        : 0;

$numeroAgenda =
    trim((string)($input['numero_agenda'] ?? ''));

$detalles =
    $input['detalles'] ?? null;


/* ==========================================================================
   NORMALIZAR ZONA
   ========================================================================== */

$zonaAliases = [
    'BODEGA_TRASTIENDA' => 'BODEGA',
    'EXHIBICIONES'      => 'EXHIBICION',
    'PTO_VTA_OTROS'     => 'OTRO',
];

$zonaOficiales = [
    'PUNTO_VENTA',
    'ALTILLO',
    'SALA_VENTAS',
    'BODEGA',
    'RECEPCION',
    'TRASTIENDA',
    'EXHIBICION',
    'GANCHERA',
    'OTRO',
];

$zonaUpper = strtoupper($zonaNombre);

if (isset($zonaAliases[$zonaUpper])) {
    $zonaNombre = $zonaAliases[$zonaUpper];
} else {
    $zonaNombre = $zonaUpper;
}


/* ==========================================================================
   VALIDACIONES DEL TAG

   Estas SI invalidan el request completo.

   Son datos necesarios para identificar inequívocamente
   el envío y poder reintentarlo de forma segura.
   ========================================================================== */

if ($cargaUid === '') {
    errorResponse('Falta identificador del envio.', 400);
}

if (strlen($cargaUid) > 180) {
    errorResponse('Identificador del envio no valido.', 400);
}

if ($codigoTienda === '') {
    errorResponse('Falta codigo de tienda.', 400);
}

if (
    $fechaProgramada === ''
    ||
    !fechaYmdValida($fechaProgramada)
) {
    errorResponse('Fecha programada no valida.', 400);
}

if ($iteracion <= 0) {
    errorResponse('Iteracion no valida.', 400);
}

if ($tagCodigo === '') {
    errorResponse('Falta TAG.', 400);
}

if ($zonaNombre === '') {
    errorResponse('Falta tipo de ubicacion.', 400);
}

if (!in_array(
    $zonaNombre,
    $zonaOficiales,
    true
)) {
    errorResponse(
        'Tipo de ubicacion no valido.',
        400
    );
}

if ($operadorRut === '') {
    errorResponse('Falta operador.', 400);
}

if ($operadorLogin === '') {
    errorResponse('Falta login de operador.', 400);
}

if ($pdaCodigo === '') {
    errorResponse('Falta identificador de PDA.', 400);
}

if (
    !is_array($detalles)
    ||
    count($detalles) === 0
) {
    errorResponse(
        'El TAG no contiene productos para sincronizar.',
        400
    );
}


/* ==========================================================================
   VALIDACIONES CABECERA ITERACION INICIAL
   ========================================================================== */

if ($iteracion === 1) {

    if ($idAgenda <= 0) {
        errorResponse(
            'Falta agenda para sincronizar el TAG.',
            400
        );
    }

    if (
        $numeroAgenda === ''
        ||
        in_array(
            strtolower($numeroAgenda),
            ['null', 'undefined'],
            true
        )
    ) {
        errorResponse(
            'Falta numero de agenda.',
            400
        );
    }

    if (!ctype_digit($tagCodigo)) {
        errorResponse(
            'El TAG recibido no es valido.',
            400
        );
    }
}


/* ==========================================================================
   APLANAR DETALLES A LECTURAS

   Una fila de sod_pda_tag_carga_detalle = UNA lectura PDA.
   ========================================================================== */

$filasLecturas = [];

$lecturasVistas = [];

$totalLecturas = 0;
$totalUnidades = 0.0;

foreach ($detalles as $idx => $detalle) {

    $sku =
        trim((string)($detalle['sku'] ?? ''));

    $detalleUid =
        trim((string)($detalle['detalle_uid'] ?? ''));

    $codigoBarras =
        trim((string)($detalle['codigo_barras'] ?? ''));

    $descripcion =
        trim((string)($detalle['descripcion'] ?? ''));

    $lecturas =
        $detalle['lecturas'] ?? null;


    /*
     * Sin lecturas no existe una unidad procesable.
     */
    if (
        !is_array($lecturas)
        ||
        count($lecturas) === 0
    ) {
        errorResponse(
            "Producto " . ($idx + 1) .
            " no contiene lecturas.",
            400
        );
    }


    foreach ($lecturas as $lIdx => $lectura) {

        $lecturaUid =
            trim(
                (string)(
                    $lectura['lectura_uid']
                    ?? ''
                )
            );


        /*
         * lectura_uid SI es obligatorio.
         *
         * Sin él no podemos garantizar:
         * - idempotencia
         * - trazabilidad
         * - reintento seguro
         */
        if ($lecturaUid === '') {
            errorResponse(
                "Lectura sin identificador unico.",
                400
            );
        }

        if (strlen($lecturaUid) > 180) {
            errorResponse(
                "Identificador de lectura no valido.",
                400
            );
        }


        /*
         * No permitimos dos eventos distintos usando
         * el mismo lectura_uid dentro del mismo payload.
         */
        if (isset($lecturasVistas[$lecturaUid])) {
            errorResponse(
                'El TAG contiene una lectura duplicada.',
                400
            );
        }

        $lecturasVistas[$lecturaUid] = true;


        /*
         * Cantidad debe poder persistirse como DECIMAL.
         *
         * Un valor negativo sí se guarda:
         * posteriormente el worker lo marcará ERROR.
         *
         * Un valor no numerico no puede persistirse de
         * forma fiel, por lo tanto es error de transporte.
         */
        $cantidadRaw =
            $lectura['cantidad'] ?? null;

        if (
            $cantidadRaw === null
            ||
            !is_numeric($cantidadRaw)
        ) {
            errorResponse(
                'Se recibio una cantidad no valida.',
                400
            );
        }

        $cantidad =
            (float)$cantidadRaw;


        /*
         * ESTE ES EL DATO CRITICO.
         *
         * Siempre viene desde lectura.codigo_lectura.
         *
         * NO usamos:
         * detalle.codigo_lectura
         * codigo_barras
         * sku
         *
         * como fallback.
         */
        $codigoLectura =
            trim(
                (string)(
                    $lectura['codigo_lectura']
                    ?? ''
                )
            );


        $medioCaptura =
            trim(
                (string)(
                    $lectura['medio_captura']
                    ?? ''
                )
            );


        /*
         * Fecha exacta PDA.
         *
         * Si está incorrecta se guarda NULL y el worker
         * la deja posteriormente como ERROR controlado.
         */
        $fechaHoraPda =
            normalizarFechaHoraPda(
                $lectura['fecha_hora'] ?? ''
            );


        /*
         * Compatibilidad con columna histórica DATETIME(0).
         */
        $fechaHoraLegacy =
            $fechaHoraPda !== null
                ? substr($fechaHoraPda, 0, 19)
                : null;


        /*
         * Iteracion 1 entra a la nueva cola.
         *
         * Otras iteraciones siguen almacenándose, pero
         * el worker V1 no las procesa.
         */
        $estadoProceso =
            $iteracion === 1
                ? 'PENDIENTE'
                : 'LEGADO';


        $filasLecturas[] = [
            'lectura_uid'      => $lecturaUid,
            'detalle_uid'      => $detalleUid !== ''
                                    ? $detalleUid
                                    : null,

            'sku'              => $sku,

            'codigo_barras'    => $codigoBarras !== ''
                                    ? $codigoBarras
                                    : null,

            'codigo_lectura'   => $codigoLectura !== ''
                                    ? $codigoLectura
                                    : null,

            'medio_captura'    => $medioCaptura !== ''
                                    ? $medioCaptura
                                    : null,

            'descripcion'      => $descripcion !== ''
                                    ? $descripcion
                                    : null,

            'cantidad'         => $cantidad,

            'fecha_hora'       => $fechaHoraLegacy,

            'fecha_hora_pda'   => $fechaHoraPda,

            'estado_proceso'   => $estadoProceso,
        ];


        $totalLecturas++;

        $totalUnidades += $cantidad;
    }
}


$totalProductos =
    count($detalles);

$totalUnidades =
    round($totalUnidades, 3);


/* ==========================================================================
   PAYLOAD ORIGINAL

   Se conserva completo como respaldo adicional.
   ========================================================================== */

$payloadJson = json_encode(
    $input,
    JSON_UNESCAPED_UNICODE
    | JSON_INVALID_UTF8_SUBSTITUTE
);

if ($payloadJson === false) {
    errorResponse(
        'No fue posible preparar los datos para sincronizacion.',
        400
    );
}


/* ==========================================================================
   CONEXION BD RECEPCION
   ========================================================================== */

$db = new db();

$estadoConexion =
    $db->conectar();

if ($estadoConexion !== 'OK') {
    errorResponse(
        'No fue posible conectar con el servidor. Intenta nuevamente.',
        500
    );
}

$pdo = $db->getConexion();

if (!$pdo instanceof PDO) {
    errorResponse(
        'No fue posible conectar con el servidor. Intenta nuevamente.',
        500
    );
}


/*
 * La clase de conexión histórica no establece ERRMODE_EXCEPTION.
 *
 * Para este endpoint necesitamos que cualquier fallo SQL provoque
 * ROLLBACK del TAG completo.
 */
$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$pdo->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);

$pdo->setAttribute(
    PDO::ATTR_EMULATE_PREPARES,
    false
);


/* ==========================================================================
   IDEMPOTENCIA / REINTENTO
   ========================================================================== */

try {

    $stmtExistente = $pdo->prepare("
        SELECT
            c.id,
            c.carga_uid,
            c.codigo_tienda,
            c.id_agenda,
            c.numero_agenda,
            c.iteracion,
            c.tag_codigo,
            c.total_productos,
            c.total_unidades,
            c.estado,

            (
                SELECT COUNT(*)
                FROM sod_pda_tag_carga_detalle AS dx
                WHERE dx.carga_id = c.id
            ) AS total_lecturas_guardadas

        FROM sod_pda_tag_carga AS c

        WHERE c.carga_uid = :carga_uid

        LIMIT 1
    ");

    $stmtExistente->execute([
        ':carga_uid' => $cargaUid,
    ]);

    $existente =
        $stmtExistente->fetch();


    if ($existente) {

        /*
         * Un mismo carga_uid NO puede representar otro TAG.
         */
        if (
            trim((string)$existente['codigo_tienda'])
                !== $codigoTienda

            ||
            (int)$existente['iteracion']
                !== $iteracion

            ||
            trim((string)$existente['tag_codigo'])
                !== $tagCodigo
        ) {
            errorResponse(
                'El identificador del envio ya existe con datos diferentes. Contacta soporte.',
                409
            );
        }


        if (
            !empty($existente['id_agenda'])
            &&
            $idAgenda > 0
            &&
            (int)$existente['id_agenda']
                !== $idAgenda
        ) {
            errorResponse(
                'El identificador del envio ya existe asociado a otra agenda.',
                409
            );
        }


        if (
            trim(
                (string)(
                    $existente['numero_agenda']
                    ?? ''
                )
            ) !== ''
            &&
            $numeroAgenda !== ''
            &&
            trim((string)$existente['numero_agenda'])
                !== $numeroAgenda
        ) {
            errorResponse(
                'El identificador del envio ya existe asociado a otra agenda.',
                409
            );
        }


        /*
         * Si ya fue recibido con la NUEVA arquitectura y
         * tiene exactamente el mismo número de lecturas,
         * el reintento está resuelto.
         *
         * NO reiniciamos estados.
         * NO volvemos a llamar procesos.
         */
        $esRecepcionV2 =
            !empty($existente['id_agenda'])
            &&
            trim(
                (string)(
                    $existente['numero_agenda']
                    ?? ''
                )
            ) !== '';


        if ($esRecepcionV2) {

            if (
                (int)$existente['total_lecturas_guardadas']
                !== $totalLecturas
            ) {
                errorResponse(
                    'El envio ya existe pero contiene una cantidad diferente de lecturas. Contacta soporte.',
                    409
                );
            }


            okResponse(
                [
                    'carga_uid'       => $cargaUid,
                    'carga_id'        => (int)$existente['id'],
                    'total_productos' => (int)$existente['total_productos'],
                    'total_lecturas'  => $totalLecturas,
                    'total_unidades'  => (float)$existente['total_unidades'],
                    'recepcion'       => 'YA_RECIBIDO',
                ],
                'TAG recibido anteriormente'
            );
        }


        /*
         * Una carga LEGADA que venga nuevamente puede migrarse
         * a esta estructura, pero la cantidad de lecturas debe
         * coincidir.
         */
        if (
            (int)$existente['total_lecturas_guardadas'] > 0
            &&
            (int)$existente['total_lecturas_guardadas']
                !== $totalLecturas
        ) {
            errorResponse(
                'El envio existente no coincide con el TAG reenviado. Contacta soporte.',
                409
            );
        }
    }


    /* ======================================================================
       TRANSACCION DE RECEPCION

       ESTA ES LA UNICA TRANSACCION DEL REQUEST.

       No existe ningún CALL a SGO dentro de ella.
       ====================================================================== */

    $pdo->beginTransaction();


    /* ======================================================================
       CABECERA
       ====================================================================== */

    $stmtCabecera = $pdo->prepare("
        INSERT INTO sod_pda_tag_carga (
            carga_uid,
            codigo_tienda,
            fecha_programada,

            id_agenda,
            numero_agenda,
            codigo_muestra,

            iteracion,
            tag_codigo,

            zona_nombre,
            zona_descripcion,

            operador_rut,
            operador_login,

            pda_codigo,

            total_productos,
            total_unidades,

            estado,

            mensaje_error,
            payload_json,

            fecha_recepcion,
            fecha_procesado
        )
        VALUES (
            :carga_uid,
            :codigo_tienda,
            :fecha_programada,

            :id_agenda,
            :numero_agenda,
            :codigo_muestra,

            :iteracion,
            :tag_codigo,

            :zona_nombre,
            :zona_descripcion,

            :operador_rut,
            :operador_login,

            :pda_codigo,

            :total_productos,
            :total_unidades,

            'RECIBIDO',

            NULL,
            :payload_json,

            NOW(),
            NULL
        )

        ON DUPLICATE KEY UPDATE

            codigo_tienda =
                VALUES(codigo_tienda),

            fecha_programada =
                VALUES(fecha_programada),

            id_agenda =
                VALUES(id_agenda),

            numero_agenda =
                VALUES(numero_agenda),

            codigo_muestra =
                VALUES(codigo_muestra),

            iteracion =
                VALUES(iteracion),

            tag_codigo =
                VALUES(tag_codigo),

            zona_nombre =
                VALUES(zona_nombre),

            zona_descripcion =
                VALUES(zona_descripcion),

            operador_rut =
                VALUES(operador_rut),

            operador_login =
                VALUES(operador_login),

            pda_codigo =
                VALUES(pda_codigo),

            total_productos =
                VALUES(total_productos),

            total_unidades =
                VALUES(total_unidades),

            payload_json =
                VALUES(payload_json)
    ");


    $stmtCabecera->execute([
        ':carga_uid'        => $cargaUid,
        ':codigo_tienda'    => $codigoTienda,
        ':fecha_programada' => $fechaProgramada,

        ':id_agenda'        => $idAgenda > 0
                                ? $idAgenda
                                : null,

        ':numero_agenda'    => $numeroAgenda !== ''
                                ? $numeroAgenda
                                : null,

        ':codigo_muestra'   => $codigoMuestra !== ''
                                ? $codigoMuestra
                                : null,

        ':iteracion'        => $iteracion,
        ':tag_codigo'       => $tagCodigo,

        ':zona_nombre'      => $zonaNombre,

        ':zona_descripcion' => $zonaDescripcion !== ''
                                ? $zonaDescripcion
                                : null,

        ':operador_rut'     => $operadorRut,
        ':operador_login'   => $operadorLogin,

        ':pda_codigo'       => $pdaCodigo,

        ':total_productos'  => $totalProductos,
        ':total_unidades'   => $totalUnidades,

        ':payload_json'     => $payloadJson,
    ]);


    /* ======================================================================
       OBTENER carga_id
       ====================================================================== */

    $stmtCarga = $pdo->prepare("
        SELECT id
        FROM sod_pda_tag_carga
        WHERE carga_uid = :carga_uid
        LIMIT 1
    ");

    $stmtCarga->execute([
        ':carga_uid' => $cargaUid,
    ]);

    $rowCarga =
        $stmtCarga->fetch();


    if (!$rowCarga) {
        throw new RuntimeException(
            'No se pudo recuperar carga_id.'
        );
    }

    $cargaId =
        (int)$rowCarga['id'];


    /* ======================================================================
       DETALLES POR BLOQUES

       200 lecturas por INSERT.

       Ejemplo 1.000 lecturas:
           5 INSERT masivos

       en lugar de:
           1.000 execute individuales
       ====================================================================== */

    $tamanoBloque = 200;


    foreach (
        array_chunk(
            $filasLecturas,
            $tamanoBloque
        )
        as $bloque
    ) {

        $valuesSql = [];
        $params = [];


        foreach ($bloque as $i => $fila) {

            $sufijo =
                '_' . $i;


            $valuesSql[] = "(
                :carga_id{$sufijo},
                :lectura_uid{$sufijo},
                :detalle_uid{$sufijo},
                :sku{$sufijo},
                :codigo_barras{$sufijo},
                :codigo_lectura{$sufijo},
                :medio_captura{$sufijo},
                :descripcion{$sufijo},

                0,
                :cantidad{$sufijo},
                0,

                :fecha_hora{$sufijo},
                :fecha_hora_pda{$sufijo},

                NOW(),

                :estado_proceso{$sufijo}
            )";


            $params[":carga_id{$sufijo}"] =
                $cargaId;

            $params[":lectura_uid{$sufijo}"] =
                $fila['lectura_uid'];

            $params[":detalle_uid{$sufijo}"] =
                $fila['detalle_uid'];

            $params[":sku{$sufijo}"] =
                $fila['sku'];

            $params[":codigo_barras{$sufijo}"] =
                $fila['codigo_barras'];

            $params[":codigo_lectura{$sufijo}"] =
                $fila['codigo_lectura'];

            $params[":medio_captura{$sufijo}"] =
                $fila['medio_captura'];

            $params[":descripcion{$sufijo}"] =
                $fila['descripcion'];

            $params[":cantidad{$sufijo}"] =
                $fila['cantidad'];

            $params[":fecha_hora{$sufijo}"] =
                $fila['fecha_hora'];

            $params[":fecha_hora_pda{$sufijo}"] =
                $fila['fecha_hora_pda'];

            $params[":estado_proceso{$sufijo}"] =
                $fila['estado_proceso'];
        }


        $sqlDetalle = "
            INSERT INTO sod_pda_tag_carga_detalle (
                carga_id,
                lectura_uid,
                detalle_uid,

                sku,
                codigo_barras,
                codigo_lectura,
                medio_captura,
                descripcion,

                stock_sistema,
                cantidad_fisica,
                diferencia,

                fecha_hora,
                fecha_hora_pda,
                fecha_recepcion,

                estado_proceso
            )
            VALUES
                " . implode(',', $valuesSql) . "

            ON DUPLICATE KEY UPDATE

                detalle_uid =
                    VALUES(detalle_uid),

                sku =
                    VALUES(sku),

                codigo_barras =
                    VALUES(codigo_barras),

                codigo_lectura =
                    VALUES(codigo_lectura),

                medio_captura =
                    VALUES(medio_captura),

                descripcion =
                    VALUES(descripcion),

                cantidad_fisica =
                    VALUES(cantidad_fisica),

                fecha_hora =
                    VALUES(fecha_hora),

                fecha_hora_pda =
                    VALUES(fecha_hora_pda),

                /*
                 * Una fila histórica LEGADO puede migrarse.
                 *
                 * Una fila ya PENDIENTE / PROCESANDO /
                 * PROCESADO / ERROR NO se reinicia por un
                 * reintento de la PDA.
                 */
                estado_proceso =
                    CASE

                        WHEN estado_proceso = 'LEGADO'
                            THEN VALUES(estado_proceso)

                        ELSE estado_proceso

                    END
        ";


        $stmtDetalle =
            $pdo->prepare($sqlDetalle);

        $stmtDetalle->execute($params);
    }


    /* ======================================================================
       CONTROL DE INTEGRIDAD ANTES DEL COMMIT
       ====================================================================== */

    $stmtCuenta = $pdo->prepare("
        SELECT COUNT(*) AS cantidad
        FROM sod_pda_tag_carga_detalle
        WHERE carga_id = :carga_id
    ");

    $stmtCuenta->execute([
        ':carga_id' => $cargaId,
    ]);

    $cantidadPersistida =
        (int)$stmtCuenta->fetchColumn();


    if ($cantidadPersistida !== $totalLecturas) {

        throw new RuntimeException(
            'Cantidad de lecturas persistidas no coincide con payload.'
        );
    }


    /* ======================================================================
       COMMIT

       DESDE ESTE PUNTO EL TAG YA ESTA SEGURO EN EL SERVIDOR.
       ====================================================================== */

    $pdo->commit();


    /* ======================================================================
       RESPUESTA APK

       NO esperamos:
       - sod_inv_conteo_det
       - sod_inv_captura_async
       - V31
       ====================================================================== */

    okResponse(
        [
            'carga_uid'       => $cargaUid,
            'carga_id'        => $cargaId,

            /*
             * La APK actual utiliza este campo.
             */
            'total_productos' => $totalProductos,

            'total_lecturas'  => $totalLecturas,
            'total_unidades'  => $totalUnidades,

            'recepcion'       => 'RECIBIDO',
        ],
        'TAG recibido correctamente'
    );


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[SOD_PDA_RECEPCION_V2][DB] '
        . $e->getMessage()
    );

    errorResponse(
        'No pudimos guardar el TAG en el servidor. Intenta nuevamente.',
        500
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[SOD_PDA_RECEPCION_V2] '
        . $e->getMessage()
    );

    errorResponse(
        'No pudimos completar la sincronizacion. Intenta nuevamente.',
        500
    );
}