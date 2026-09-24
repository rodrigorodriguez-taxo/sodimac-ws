<?php
# ws/api/tablet/informes/captura-final-gestor.php
# GET ?agenda_id=123
# Informe Captura Final — réplica _4_blank_captura_final (fuente_final + muestra)

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Contexto
    $stmtCtx = $pdo->prepare(
        "SELECT c.id_agenda, c.numero_agenda, c.fecha_agenda,
                c.codigo_tienda, c.nombre_tienda, c.codigo_estado
         FROM vw_sod_rep_agenda_contexto AS c
         WHERE c.id_agenda = :agenda_id
         LIMIT 1"
    );
    $stmtCtx->execute([':agenda_id' => $agendaId]);
    $contexto = $stmtCtx->fetch();
    if (!$contexto) errorResponse('Agenda no encontrada');

    // 2. Muestra activa
    $stmtMuestra = $pdo->prepare(
        "SELECT am.id_muestra
         FROM sod_inv_agenda_muestra AS am
         INNER JOIN sod_inv_muestra AS m
                 ON m.id_muestra = am.id_muestra
                AND m.fl_activo = 'S'
         WHERE am.id_agenda = :agenda_id
           AND am.fl_activo = 'S'
         ORDER BY am.fecha_hora_asignacion DESC, am.id_agenda_muestra DESC
         LIMIT 1"
    );
    $stmtMuestra->execute([':agenda_id' => $agendaId]);
    $muestra = $stmtMuestra->fetch();
    $idMuestra = $muestra ? (int)$muestra['id_muestra'] : 0;

    // 3. Conteos C1, C2, C3
    $getConteo = function ($iter, $tipo) use ($pdo, $agendaId) {
        $stmt = $pdo->prepare(
            "SELECT c.id_conteo FROM sod_inv_conteo AS c
             WHERE c.id_agenda = :agenda_id
               AND c.numero_iteracion = :iter
               AND c.tipo_conteo = :tipo
               AND c.fl_activo = 'S'
               AND c.estado_conteo <> 'ANULADO'
             ORDER BY c.id_conteo DESC LIMIT 1"
        );
        $stmt->execute([':agenda_id' => $agendaId, ':iter' => $iter, ':tipo' => $tipo]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id_conteo'] : 0;
    };

    $idC1 = $getConteo(1, 'INICIAL');
    $idC2 = $getConteo(2, 'VALIDACION');
    $idC3 = $getConteo(3, 'RECONTEO');

    // 4. fisico_tag: determina fuente_final por (producto, tag)
    $fisicoTagSql = "
        SELECT
            :agenda_id AS id_agenda,
            u.id_producto,
            u.id_tag,
            COALESCE(rc.cantidad_c3, pv.cantidad_pre_variance, op.cantidad_c2, c1.cantidad_c1, 0) AS cantidad_final_tag,
            CASE
                WHEN rc.cantidad_c3 IS NOT NULL THEN 'C3_RECUENTO'
                WHEN pv.cantidad_pre_variance IS NOT NULL THEN 'PRE_VARIANCE'
                WHEN op.cantidad_c2 IS NOT NULL THEN 'C2_VALIDACION'
                WHEN c1.cantidad_c1 IS NOT NULL THEN 'C1_INICIAL'
                ELSE 'SIN_CONTEO'
            END AS fuente_final
        FROM (
            SELECT cd.id_producto, cd.id_tag
            FROM sod_inv_conteo_det AS cd
            WHERE cd.id_conteo = :id_c1
              AND cd.estado_registro = 'VIGENTE'
            UNION
            SELECT cd.id_producto, cd.id_tag
            FROM sod_inv_conteo_det AS cd
            WHERE cd.id_conteo = :id_c2
              AND cd.id_reconteo IS NULL
              AND cd.origen IN ('SGO_ANALISTA','SGO_PREVARIANCE')
              AND cd.estado_registro = 'VIGENTE'
            UNION
            SELECT cd.id_producto, cd.id_tag
            FROM sod_inv_conteo_det AS cd
            WHERE cd.id_conteo = :id_c3
              AND cd.id_reconteo IS NULL
              AND cd.origen = 'SGO_RECUENTO'
              AND cd.estado_registro = 'VIGENTE'
        ) AS u
        LEFT JOIN (
            SELECT cd.id_producto, cd.id_tag, SUM(cd.cantidad) AS cantidad_c1
            FROM sod_inv_conteo_det AS cd
            WHERE cd.id_conteo = :id_c1_agg
              AND cd.estado_registro = 'VIGENTE'
            GROUP BY cd.id_producto, cd.id_tag
        ) AS c1 ON c1.id_producto = u.id_producto AND c1.id_tag = u.id_tag
        LEFT JOIN (
            SELECT cd.id_producto, cd.id_tag, SUM(cd.cantidad) AS cantidad_c2
            FROM sod_inv_conteo_det AS cd
            WHERE cd.id_conteo = :id_c2_agg
              AND cd.id_reconteo IS NULL
              AND cd.origen = 'SGO_ANALISTA'
              AND cd.estado_registro = 'VIGENTE'
            GROUP BY cd.id_producto, cd.id_tag
        ) AS op ON op.id_producto = u.id_producto AND op.id_tag = u.id_tag
        LEFT JOIN (
            SELECT cd.id_producto, cd.id_tag, SUM(cd.cantidad) AS cantidad_pre_variance
            FROM sod_inv_conteo_det AS cd
            WHERE cd.id_conteo = :id_c2_pv
              AND cd.id_reconteo IS NULL
              AND cd.origen = 'SGO_PREVARIANCE'
              AND cd.estado_registro = 'VIGENTE'
            GROUP BY cd.id_producto, cd.id_tag
        ) AS pv ON pv.id_producto = u.id_producto AND pv.id_tag = u.id_tag
        LEFT JOIN (
            SELECT cd.id_producto, cd.id_tag, SUM(cd.cantidad) AS cantidad_c3
            FROM sod_inv_conteo_det AS cd
            WHERE cd.id_conteo = :id_c3_agg
              AND cd.id_reconteo IS NULL
              AND cd.origen = 'SGO_RECUENTO'
              AND cd.estado_registro = 'VIGENTE'
            GROUP BY cd.id_producto, cd.id_tag
        ) AS rc ON rc.id_producto = u.id_producto AND rc.id_tag = u.id_tag
    ";

    // 5. Query principal: detalle filtrado por fuente_final + muestra
    $sql = "SELECT
        cd.id_conteo_det,
        t.numero_tag,
        p.sku,
        p.codigo_barras,
        p.descripcion_producto,
        cd.cantidad,
        cd.fecha_hora_captura,
        cd.login_operador,
        c.numero_iteracion,
        c.tipo_conteo,
        fv.fuente_final,
        fv.cantidad_final_tag,
        COALESCE(
            NULLIF(TRIM(su.rut), ''),
            CASE
                WHEN TRIM(cd.login_operador) REGEXP '^[0-9Kk.-]+$'
                THEN TRIM(cd.login_operador)
                ELSE ''
            END
        ) AS rut_sdv,
        cd.origen,
        cd.dispositivo
    FROM (
        " . $fisicoTagSql . "
    ) AS fv
    INNER JOIN sod_inv_conteo_det AS cd
            ON cd.id_agenda = fv.id_agenda
           AND cd.id_producto = fv.id_producto
           AND cd.id_tag = fv.id_tag
           AND cd.estado_registro = 'VIGENTE'
    INNER JOIN sod_inv_conteo AS c
            ON c.id_conteo = cd.id_conteo
           AND c.fl_activo = 'S'
    INNER JOIN sod_inv_tag AS t
            ON t.id_tag = fv.id_tag
           AND t.id_agenda = fv.id_agenda
    INNER JOIN sod_cfg_producto AS p
            ON p.id_producto = fv.id_producto
    INNER JOIN sod_inv_muestra_det AS md
            ON md.id_producto = fv.id_producto
           AND md.fl_activo = 'S'
           AND md.id_muestra = :id_muestra
    LEFT JOIN sec_users AS su
           ON CONVERT(su.login USING utf8mb4) COLLATE utf8mb4_unicode_ci
              =
              CONVERT(cd.login_operador USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHERE fv.id_agenda = :agenda_id2
      AND (
            (fv.fuente_final = 'C1_INICIAL'
             AND c.numero_iteracion = 1
             AND c.tipo_conteo = 'INICIAL')
            OR
            (fv.fuente_final = 'C2_VALIDACION'
             AND c.numero_iteracion = 2
             AND c.tipo_conteo = 'VALIDACION'
             AND cd.id_reconteo IS NULL
             AND cd.origen = 'SGO_ANALISTA')
            OR
            (fv.fuente_final = 'PRE_VARIANCE'
             AND c.numero_iteracion = 2
             AND c.tipo_conteo = 'VALIDACION'
             AND cd.id_reconteo IS NULL
             AND cd.origen = 'SGO_PREVARIANCE')
            OR
            (fv.fuente_final = 'C3_RECUENTO'
             AND c.numero_iteracion = 3
             AND c.tipo_conteo = 'RECONTEO'
             AND cd.id_reconteo IS NULL
             AND cd.origen = 'SGO_RECUENTO')
          )
    ORDER BY
        CAST(t.numero_tag AS UNSIGNED),
        t.numero_tag,
        p.sku,
        cd.fecha_hora_captura,
        cd.id_conteo_det";

    $params = [
        ':agenda_id' => $agendaId,
        ':agenda_id2' => $agendaId,
        ':id_muestra' => $idMuestra,
        ':id_c1' => $idC1,
        ':id_c1_agg' => $idC1,
        ':id_c2' => $idC2,
        ':id_c2_agg' => $idC2,
        ':id_c2_pv' => $idC2,
        ':id_c3' => $idC3,
        ':id_c3_agg' => $idC3,
    ];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $capturas = $stmt->fetchAll();

    // 6. Formatear RUT sin puntos
    $fmtRut = function ($rut) {
        $s = trim((string)$rut);
        if ($s === '') return '';
        return str_replace(['.', ' '], '', $s);
    };

    foreach ($capturas as $i => $r) {
        $capturas[$i]['rut_sdv'] = $fmtRut($r['rut_sdv']);
    }

    // 7. Formatear fuente_final para UI
    $fmtFuente = function ($v) {
        $map = [
            'C1_INICIAL' => 'C1 Inicial',
            'C2_VALIDACION' => 'C2 Validación',
            'PRE_VARIANCE' => 'Pre Variance',
            'C3_RECUENTO' => 'C3 Reconteo',
        ];
        return $map[$v] ?? $v;
    };

    foreach ($capturas as $i => $r) {
        $capturas[$i]['fuente_final_label'] = $fmtFuente($r['fuente_final']);
    }

    // 8. Resumen
    $totalUnidades = 0.0;
    $tagsUnicos = [];
    $skuUnicos = [];

    foreach ($capturas as $r) {
        $totalUnidades += (float)$r['cantidad'];
        $tagsUnicos[(string)$r['numero_tag']] = true;
        $skuUnicos[(string)$r['sku']] = true;
    }

    okResponse([
        'contexto' => $contexto,
        'id_muestra' => $idMuestra,
        'conteos' => [
            'c1' => $idC1,
            'c2' => $idC2,
            'c3' => $idC3,
        ],
        'resumen' => [
            'registros' => count($capturas),
            'tags_unicos' => count($tagsUnicos),
            'sku_unicos' => count($skuUnicos),
            'total_unidades' => $totalUnidades,
        ],
        'capturas' => $capturas,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al generar informe: ' . $e->getMessage(), 500);
}
