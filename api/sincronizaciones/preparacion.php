<?php

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

$correo = trim($input['correo'] ?? '');
$rut    = trim($input['rut'] ?? '');

if ($correo === '') {
    errorResponse('Falta correo');
}

if ($rut === '') {
    errorResponse('Falta rut');
}

try {
    $data = prepararSincronizacion($pdo, $correo, $rut);
    okResponse($data, 'Datos preparados correctamente');
} catch (PDOException $e) {
    errorResponse('Error: ' . $e->getMessage(), 500);
}

/* ============================================================================
   Flujo V3 — Contrato Sincronización Auditor V6
   ============================================================================
   1. validar usuario          -> obtenerUsuario()
   2. tiendas autorizadas      -> obtenerTiendas()          (maestro)
   3. agenda lista del dia     -> obtenerAgendas()          (V3.0)
   4. productos/codigos        -> obtenerCodigosAgenda()    (V4.0 optimizado)
      4a. resolverAgendaMuestra()  (Paso A — autorizar + resolver id_muestra)
      4b. obtenerCodigosMuestra()  (Paso B — descarga por id_muestra)
   5. agrupar por producto     -> agruparProductosConCodigos()
   6. responder contrato APK
   ============================================================================ */

function prepararSincronizacion(PDO $pdo, string $correo, string $rut): array
{
    /* --- 1. validar usuario ------------------------------------------------- */
    $usuario = obtenerUsuario($pdo, $correo, $rut);
    if (!$usuario) {
        errorResponse('Usuario no existe o inactivo', 401);
    }

    $login = $usuario['login'];

    /* --- 2. tiendas autorizadas (maestro) ----------------------------------- */
    $tiendas = obtenerTiendas($pdo, $login);
    if (!$tiendas) {
        errorResponse('No existen tiendas asignadas', 401);
    }

    /* --- 3. agenda lista del dia -------------------------------------------- */
    $fecha = date('Y-m-d');
    $agendas = obtenerAgendas($pdo, $login, $fecha);
    if (!$agendas) {
        errorResponse('No existen agendas listas para hoy', 401);
    }

    $agendaSeleccionada = current($agendas);

    /* --- 4. productos/codigos de la agenda ---------------------------------- */
    $filasCodigos = obtenerCodigosAgenda($pdo, (int) $agendaSeleccionada['id_agenda'], $login);
    if (!$filasCodigos) {
        errorResponse('No existen productos para la agenda seleccionada', 401);
    }

    /* --- 5. agrupar productos ----------------------------------------------- */
    $productos = agruparProductosConCodigos($filasCodigos);

    /* --- 6. construir muestra y evento desde agenda ------------------------- */
    $muestras = construirMuestraDesdeAgenda($agendaSeleccionada);
    $eventos  = construirEventoDesdeAgenda($agendaSeleccionada, current($tiendas));

    /* --- 7. responder contrato APK ------------------------------------------ */
    return [
        'usuario'       => $usuario,
        'tiendas'       => $tiendas,
        'muestras'      => $muestras,
        'eventos'       => $eventos,
        'productos'     => $productos,
        'zonas_tienda'  => array(
            0 => array("VENTA",     "Piso de venta y gancheras"),
            1 => array("ALTILLO",   "Altillos y storage superior"),
            2 => array("BODEGA",    "Bodega y trastienda"),
            3 => array("RECEPCION", "Zona de recepcion"),
        ),
    ];
}

/* ============================================================================
   1. obtenerUsuario  (V6 — identidad funcional + RUT + vigencia)
   Parametros: :login_1 (login), :login_2 (email), :rut_normalizado
   ============================================================================ */

function obtenerUsuario(PDO $pdo, string $correo, string $rut): ?array
{
    $stmt = $pdo->prepare("SELECT
        su.login                        AS login,
        ue.rut                          AS rut,
        ue.rut_normalizado              AS rut_normalizado,

        CONCAT_WS(
            ' ',
            NULLIF(TRIM(ue.nombres), ''),
            NULLIF(TRIM(ue.apellido_paterno), ''),
            NULLIF(TRIM(ue.apellido_materno), '')
        )                               AS nombre_completo,

        ue.nombres                      AS nombres,
        ue.apellido_paterno             AS apellido_paterno,
        ue.apellido_materno             AS apellido_materno,
        ue.cargo                        AS cargo,
        ue.tipo_usuario                 AS tipo_usuario,
        ue.fl_usuario_cliente           AS usuario_cliente

    FROM sec_users AS su

    INNER JOIN sod_sec_usuario_ext AS ue
            ON CONVERT(TRIM(su.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
               =
               CONVERT(TRIM(ue.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci

    WHERE (
            CONVERT(TRIM(su.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(TRIM(:login_1) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR
            CONVERT(TRIM(su.email) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(TRIM(:login_2) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        )
    AND ue.rut_normalizado = :rut_normalizado
    AND su.active = 'Y'
    AND ue.fl_activo = 'S'
    AND (ue.fecha_inicio_vigencia IS NULL OR ue.fecha_inicio_vigencia <= NOW())
    AND (ue.fecha_fin_vigencia IS NULL OR ue.fecha_fin_vigencia >= NOW())
    LIMIT 1");

    $stmt->execute([
        ':login_1'         => $correo,
        ':login_2'         => $correo,
        ':rut_normalizado' => $rut,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    return [
        'login'            => $user['login'],
        'rut'              => $user['rut'],
        'rut_normalizado'  => $user['rut_normalizado'],
        'nombre_completo'  => $user['nombre_completo'],
        'nombres'          => $user['nombres'],
        'apellido_paterno' => $user['apellido_paterno'],
        'apellido_materno' => $user['apellido_materno'],
        'cargo'            => $user['cargo'],
        'tipo_usuario'     => $user['tipo_usuario'],
        'usuario_cliente'  => $user['usuario_cliente'],
        'autenticado'      => true,
    ];
}

/* ============================================================================
   2. obtenerTiendas  (maestro de tiendas autorizadas)
   Parametro: :login
   ============================================================================ */

function obtenerTiendas(PDO $pdo, string $login): ?array
{
    $sql = "SELECT
        v.id_tienda,
        v.codigo_tienda,
        v.nombre_tienda,
        v.id_zona_operativa,
        v.codigo_zona,
        v.nombre_zona,
        v.tipo_asignacion_base,
        v.fl_principal,
        v.fecha_inicio_vigencia,
        v.fecha_fin_vigencia
    FROM vw_sod_usuario_tienda_resumen AS v
    WHERE CONVERT(TRIM(v.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          =
          CONVERT(TRIM(:login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
      AND v.estado_operativo = 'VIGENTE'
      AND v.fl_usuario_vigente = 'S'
    ORDER BY
        v.fl_principal DESC,
        v.nombre_tienda ASC,
        v.id_tienda ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login]);

    $tiendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$tiendas) {
        return null;
    }

    return array_map(function ($t) {
        return [
            'id_tienda'      => $t['id_tienda'],
            'codigo_tienda'  => $t['codigo_tienda'],
            'nombre_tienda'  => $t['nombre_tienda'],
            'zona_operativa' => $t['nombre_zona'],
        ];
    }, $tiendas);
}

/* ============================================================================
   3. obtenerAgendas  (V3.0 — jornada operable + muestra valida con SKU)
   Parametros: :login, :fecha (YYYY-MM-DD)
   Regla: jornada operable + agenda operable + fl_muestra_ok='S' + sku>0
   ============================================================================ */

function obtenerAgendas(PDO $pdo, string $login, string $fecha): ?array
{
    $sql = "SELECT
        a.id_agenda,
        a.numero_agenda,
        DATE(a.fecha_agenda) AS fecha_agenda,
        a.secuencia_dia,

        t.id_tienda,
        t.codigo_tienda,
        t.nombre_tienda,
        t.id_zona_operativa,
        z.codigo_zona,
        z.nombre_zona,

        ea.codigo_estado AS estado_agenda,

        jo.id_jornada,
        jo.estado_jornada,
        COALESCE(jo.fl_jornada_operable, 'N') AS fl_jornada_operable,

        p.id_muestra,
        p.codigo_muestra,
        p.nombre_muestra,
        p.id_kardex,
        p.codigo_kardex,
        p.sku_muestra,
        p.sku_kardex,
        p.operadores_agenda,
        p.fl_muestra_ok,
        p.fl_kardex_ok,
        p.fl_cobertura_ok,
        p.fl_operadores_ok,
        p.fl_lista_conteo,
        p.estado_preparacion,

        CASE
            WHEN COALESCE(jo.fl_jornada_operable, 'N') = 'S'
             AND ea.codigo_estado IN ('PLANIFICADA','ASIGNADA','LISTA','EN_CONTEO')
             AND COALESCE(p.fl_muestra_ok, 'N') = 'S'
             AND COALESCE(p.sku_muestra, 0) > 0
                THEN 'S'
            ELSE 'N'
        END AS fl_puede_contar,

        CASE
            WHEN COALESCE(p.fl_kardex_ok, 'N') = 'S'
             AND COALESCE(p.fl_cobertura_ok, 'N') = 'S'
                THEN 'S'
            ELSE 'N'
        END AS fl_procesos_posteriores

    FROM sod_ope_agenda AS a

    INNER JOIN sod_cfg_tienda AS t
            ON t.id_tienda = a.id_tienda

    LEFT JOIN sod_cfg_zona_operativa AS z
           ON z.id_zona_operativa = t.id_zona_operativa

    INNER JOIN sod_ope_estado_agenda AS ea
            ON ea.id_estado_agenda = a.id_estado_agenda

    INNER JOIN vw_sod_agenda_preparacion_resumen AS p
            ON p.id_agenda = a.id_agenda

    INNER JOIN vw_sod_agenda_jornada_operativa AS jo
            ON jo.id_agenda = a.id_agenda

    WHERE DATE(a.fecha_agenda) = :fecha
      AND a.fl_activo = 'S'
      AND ea.codigo_estado NOT IN ('CERRADA', 'SUSPENDIDA')
      AND jo.fl_jornada_operable = 'S'
      AND p.fl_muestra_ok = 'S'
      AND COALESCE(p.sku_muestra, 0) > 0
      AND EXISTS (
            SELECT 1
            FROM vw_sod_dash_agenda_usuario AS d
            WHERE d.id_agenda = a.id_agenda
              AND CONVERT(TRIM(d.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  =
                  CONVERT(TRIM(:login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          )
    ORDER BY
        t.nombre_tienda ASC,
        a.secuencia_dia ASC,
        a.numero_agenda ASC,
        a.id_agenda ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login, ':fecha' => $fecha]);

    $agendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $agendas ?: null;
}

/* ============================================================================
   4. obtenerCodigosAgenda  (V4.0 optimizado — 2 consultas internas)
   Recibe id_agenda; el backend resuelve id_muestra internamente.
   Parametros: :id_agenda, :login
   ============================================================================ */

function obtenerCodigosAgenda(PDO $pdo, int $idAgenda, string $login): ?array
{
    /* --- Paso A: autorizar agenda + resolver id_muestra --------------------- */
    $agendaInfo = resolverAgendaMuestra($pdo, $idAgenda, $login);
    if (!$agendaInfo) {
        return null;
    }

    $idMuestra = (int) $agendaInfo['id_muestra'];

    /* --- Paso B: descargar productos/codigos por id_muestra ----------------- */
    $filas = obtenerCodigosMuestra($pdo, $idMuestra);

    /* Inyectar datos de agenda en cada fila para que agruparProductosConCodigos funcione */
    if ($filas) {
        foreach ($filas as &$fila) {
            $fila['id_agenda']       = $agendaInfo['id_agenda'];
            $fila['numero_agenda']   = $agendaInfo['numero_agenda'];
            $fila['fecha_agenda']    = $agendaInfo['fecha_agenda'];
            $fila['id_tienda']       = $agendaInfo['id_tienda'];
            $fila['id_muestra']      = $agendaInfo['id_muestra'];
            $fila['codigo_muestra']  = $agendaInfo['codigo_muestra'] ?? null;
            $fila['nombre_muestra']  = $agendaInfo['nombre_muestra'] ?? null;
        }
        unset($fila);
    }

    return $filas ?: null;
}

/* ============================================================================
   4-A. resolverAgendaMuestra  (Paso A — validar agenda + resolver id_muestra)
   Parametros: :id_agenda, :login
   ============================================================================ */

function resolverAgendaMuestra(PDO $pdo, int $idAgenda, string $login): ?array
{
    $sql = "SELECT
        a.id_agenda,
        a.numero_agenda,
        DATE(a.fecha_agenda) AS fecha_agenda,
        a.id_tienda,

        am.id_muestra,

        (
            SELECT COUNT(0)
            FROM sod_inv_muestra_det AS md_count
            WHERE md_count.id_muestra = am.id_muestra
              AND md_count.fl_activo = 'S'
        ) AS sku_muestra,

        jo.id_jornada,
        jo.estado_jornada,
        jo.fl_jornada_operable

    FROM sod_ope_agenda AS a

    INNER JOIN sod_ope_estado_agenda AS ea
            ON ea.id_estado_agenda = a.id_estado_agenda

    INNER JOIN sod_inv_agenda_muestra AS am
            ON am.id_agenda = a.id_agenda
           AND am.fl_activo = 'S'

    INNER JOIN vw_sod_agenda_jornada_operativa AS jo
            ON jo.id_agenda = a.id_agenda

    WHERE a.id_agenda = :id_agenda
      AND a.fl_activo = 'S'
      AND ea.codigo_estado NOT IN ('CERRADA', 'SUSPENDIDA')
      AND jo.fl_jornada_operable = 'S'

      AND EXISTS (
            SELECT 1
            FROM sod_inv_muestra_det AS md_ok
            WHERE md_ok.id_muestra = am.id_muestra
              AND md_ok.fl_activo = 'S'
          )

      AND EXISTS (
            SELECT 1
            FROM vw_sod_dash_agenda_usuario AS d
            WHERE d.id_agenda = a.id_agenda
              AND CONVERT(TRIM(d.login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  =
                  CONVERT(TRIM(:login) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          )

    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_agenda' => $idAgenda, ':login' => $login]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/* ============================================================================
   4-B. obtenerCodigosMuestra  (Paso B — descarga masiva por id_muestra)
   UNION ALL: SKU + barras snapshot + barras legacy (solo si no existe snapshot)
   Parametro: :id_muestra
   ============================================================================ */

function obtenerCodigosMuestra(PDO $pdo, int $idMuestra): ?array
{
    $sql = "SELECT DISTINCT
        cod.id_muestra_det,
        cod.id_producto,
        cod.codigo_ingreso AS codigo_lectura,
        cod.tipo_codigo,

        CASE
            WHEN CONVERT(cod.tipo_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'SKU' COLLATE utf8mb4_unicode_ci
                THEN NULL
            ELSE cod.codigo_ingreso
        END AS codigo_barras,

        md.orden_muestra,
        md.fl_obligatorio AS obligatorio,
        md.prioridad,
        md.observacion,
        md.clacom_origen,

        p.sku,
        p.descripcion_producto AS descripcion,
        p.unidad_medida,
        p.valor_referencia,
        p.fl_producto_critico AS producto_critico,
        p.fl_alto_valor AS producto_alto_valor,

        cc.id_clasificacion_comercial,
        cc.clacom,
        cc.codigo_departamento,
        cc.departamento,
        cc.codigo_familia,
        cc.familia,
        cc.codigo_subfamilia,
        cc.subfamilia,
        cc.codigo_grupo,
        cc.grupo,
        cc.codigo_conjunto,
        cc.conjunto

    FROM
    (
        /* 1. SKU */
        SELECT
            md1.id_muestra_det,
            md1.id_producto,
            p1.sku AS codigo_ingreso,
            'SKU' AS tipo_codigo
        FROM sod_inv_muestra_det AS md1
        INNER JOIN sod_cfg_producto AS p1
                ON p1.id_producto = md1.id_producto
               AND p1.fl_activo = 'S'
        WHERE md1.id_muestra = :id_muestra_sku
          AND md1.fl_activo = 'S'

        UNION ALL

        /* 2. BARRAS SNAPSHOT DE LA MUESTRA */
        SELECT
            md2.id_muestra_det,
            md2.id_producto,
            mb.codigo_barras AS codigo_ingreso,
            'BARRA_MUESTRA' AS tipo_codigo
        FROM sod_inv_muestra_det AS md2
        INNER JOIN sod_inv_muestra_det_barra AS mb
                ON mb.id_muestra_det = md2.id_muestra_det
               AND mb.fl_activo = 'S'
        WHERE md2.id_muestra = :id_muestra_snapshot
          AND md2.fl_activo = 'S'

        UNION ALL

        /* 3. BARRAS LEGACY: SOLO SI NO EXISTE SNAPSHOT */
        SELECT
            md3.id_muestra_det,
            md3.id_producto,
            pb.codigo_barras AS codigo_ingreso,
            'BARRA_CATALOGO_LEGACY' AS tipo_codigo
        FROM sod_inv_muestra_det AS md3
        INNER JOIN sod_cfg_producto_barra AS pb
                ON pb.id_producto = md3.id_producto
               AND pb.fl_activo = 'S'
        WHERE md3.id_muestra = :id_muestra_legacy
          AND md3.fl_activo = 'S'
          AND NOT EXISTS (
                SELECT 1
                FROM sod_inv_muestra_det_barra AS mbx
                WHERE mbx.id_muestra_det = md3.id_muestra_det
                  AND mbx.fl_activo = 'S'
          )
    ) AS cod

    INNER JOIN sod_inv_muestra_det AS md
            ON md.id_muestra_det = cod.id_muestra_det
           AND md.id_muestra = :id_muestra_join
           AND md.id_producto = cod.id_producto
           AND md.fl_activo = 'S'

    INNER JOIN sod_cfg_producto AS p
            ON p.id_producto = cod.id_producto
           AND p.fl_activo = 'S'

    LEFT JOIN sod_cfg_clasificacion_comercial AS cc
           ON cc.id_clasificacion_comercial = md.id_clasificacion_comercial
          AND cc.fl_activo = 'S'

    ORDER BY
        md.orden_muestra ASC,
        p.sku ASC,
        CASE cod.tipo_codigo
            WHEN 'BARRA_MUESTRA' THEN 1
            WHEN 'BARRA_CATALOGO_LEGACY' THEN 2
            WHEN 'SKU' THEN 3
            ELSE 9
        END ASC,
        cod.codigo_ingreso ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_muestra_sku'      => $idMuestra,
        ':id_muestra_snapshot' => $idMuestra,
        ':id_muestra_legacy'   => $idMuestra,
        ':id_muestra_join'     => $idMuestra,
    ]);

    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $filas ?: null;
}

/* ============================================================================
   5. agruparProductosConCodigos
   Agrupa filas de obtenerCodigosAgenda() por id_muestra_det / id_producto.
   ============================================================================ */

function agruparProductosConCodigos(array $filas): array
{
    $productos = [];

    foreach ($filas as $fila) {
        $key = $fila['id_muestra_det'] . '_' . $fila['id_producto'];

        if (!isset($productos[$key])) {
            $productos[$key] = [
                'id_muestra_det'  => (int) $fila['id_muestra_det'],
                'id_producto'     => (int) $fila['id_producto'],
                'sku'             => trim($fila['sku']),
                'codigo_barras'   => null,
                'descripcion'     => $fila['descripcion'] ?? null,
                'stock_sistema'   => 0,
                'codigos'         => [],
                '_codigos_vistos' => [],
            ];
        }

        $codigoLectura = normalizarCodigo($fila['codigo_lectura'] ?? $fila['codigo_ingreso'] ?? null);
        $tipoCodigo    = $fila['tipo_codigo'] ?? null;

        if ($codigoLectura !== null && !in_array($codigoLectura, $productos[$key]['_codigos_vistos'], true)) {
            $productos[$key]['_codigos_vistos'][] = $codigoLectura;

            $codigoBarras = null;
            if ($tipoCodigo !== null && $tipoCodigo !== 'SKU') {
                $codigoBarras = $codigoLectura;
            }

            $productos[$key]['codigos'][] = [
                'codigo_lectura' => $codigoLectura,
                'tipo_codigo'    => $tipoCodigo,
                'codigo_barras'  => $codigoBarras,
            ];
        }

        /* La primera fila trae el sku real, usarlo como codigo_barras display */
        if ($productos[$key]['codigo_barras'] === null && $tipoCodigo !== 'SKU' && $codigoLectura !== null) {
            $productos[$key]['codigo_barras'] = $codigoLectura;
        }
    }

    /* limpiar campo interno y convertir a array numerico */
    $resultado = [];
    foreach ($productos as $producto) {
        unset($producto['_codigos_vistos']);
        $resultado[] = $producto;
    }

    return $resultado;
}

/* ============================================================================
   6. construirMuestraDesdeAgenda
   Construye el objeto muestra que la APK espera.
   ============================================================================ */

function construirMuestraDesdeAgenda(array $agenda): array
{
    return [
        'id_muestra'             => (int) ($agenda['id_muestra'] ?? 0),
        'codigo_muestra'         => $agenda['codigo_muestra'] ?? '',
        'nombre_muestra'         => $agenda['nombre_muestra'] ?? '',
        'id_agenda'              => (int) ($agenda['id_agenda'] ?? 0),
        'numero_agenda'          => $agenda['numero_agenda'] ?? null,
        'fecha_agenda'           => $agenda['fecha_agenda'] ?? null,
    ];
}

/* ============================================================================
   7. construirEventoDesdeAgenda
   Construye el objeto evento local que la APK espera.
   ============================================================================ */

function construirEventoDesdeAgenda(array $agenda, array $tienda): array
{
    return [
        'sucursal_id'      => (int) $tienda['id_tienda'],
        'fecha_programada' => date('d-m-Y'),
        'estado'           => 'ABIERTO',
        'id_agenda'        => (int) ($agenda['id_agenda'] ?? 0),
    ];
}

/* ============================================================================
   normalizarCodigo
   Trim y uppercase para codigos alfanumericos. Null si viene vacio.
   ============================================================================ */

function normalizarCodigo(?string $codigo): ?string
{
    if ($codigo === null) {
        return null;
    }

    $limpio = trim($codigo);

    if ($limpio === '') {
        return null;
    }

    return strtoupper($limpio);
}
