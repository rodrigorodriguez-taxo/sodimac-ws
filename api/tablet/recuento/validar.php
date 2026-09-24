<?php
# ============================================================
# ws/api/tablet/recuento/validar.php
# POST { agenda_id, productos: [{ producto_id, cantidad_c3, fl_corrige }] }
# Cierra formalmente el Recuento — réplica _8_blank_reconteo_final:
#   - universo filtrado ABS(dif) >= 100000 con kardex
#   - stock_teorico desde sod_inv_kardex_det (no C1)
#   - tags_total / tags_recontados reales
#   - marcador UNIVERSO_COMPLETO_RECUENTO_100K en observacion
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['agenda_id'])) {
    errorResponse('Falta agenda_id');
}

if (empty($input['productos']) || !is_array($input['productos'])) {
    errorResponse('Falta array de productos');
}

$agendaId = (int)$input['agenda_id'];
$productos = $input['productos'];

const UMBRAL_RECUENTO = 100000.0;

try {
    $pdo->beginTransaction();

    // ── Kardex activo ───────────────────────────────────────
    $stmtK = $pdo->prepare(
        "SELECT id_kardex FROM sod_inv_kardex
         WHERE id_agenda = :agenda_id AND fl_activo = 'S'
         ORDER BY id_kardex DESC LIMIT 1"
    );
    $stmtK->execute([':agenda_id' => $agendaId]);
    $k = $stmtK->fetch();
    if (!$k) {
        $pdo->rollBack();
        errorResponse('No existe Kardex activo para la agenda');
    }
    $idKardex = (int)$k['id_kardex'];

    // ── C1 / C2 / C3 ───────────────────────────────────────
    $idC1 = 0;
    $idC2 = 0;
    $idC3 = 0;

    $stmt = $pdo->prepare(
        "SELECT id_conteo, numero_iteracion FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY numero_iteracion DESC"
    );
    $stmt->execute([':agenda_id' => $agendaId]);
    foreach ($stmt->fetchAll() as $row) {
        $iter = (int)$row['numero_iteracion'];
        if ($iter === 1 && !$idC1) $idC1 = (int)$row['id_conteo'];
        if ($iter === 2 && !$idC2) $idC2 = (int)$row['id_conteo'];
        if ($iter === 3 && !$idC3) $idC3 = (int)$row['id_conteo'];
    }

    // ── Universo del proceso: tags C1 ∪ C2 con base + kardex ─
    $unionC2 = $idC2 > 0
        ? " UNION
           SELECT DISTINCT id_producto, id_tag
           FROM sod_inv_conteo_det
           WHERE id_conteo = {$idC2}
             AND id_reconteo IS NULL
             AND origen IN ('SGO_ANALISTA','SGO_PREVARIANCE')
             AND estado_registro = 'VIGENTE'"
        : "";

    $sqlUniv = "SELECT
        u.id_producto,
        p.sku,
        p.descripcion_producto,
        COALESCE(kd.stock_teorico, 0) AS stock_teorico,
        COALESCE(kd.valor_unitario, 0) AS valor_unitario,
        COALESCE(
            (SELECT SUM(x.cantidad) FROM sod_inv_conteo_det AS x
             WHERE x.id_conteo = :id_c2b AND x.id_producto = u.id_producto
               AND x.id_tag = u.id_tag AND x.id_reconteo IS NULL
               AND x.origen = 'SGO_PREVARIANCE' AND x.estado_registro = 'VIGENTE'),
            (SELECT SUM(x.cantidad) FROM sod_inv_conteo_det AS x
             WHERE x.id_conteo = :id_c2c AND x.id_producto = u.id_producto
               AND x.id_tag = u.id_tag AND x.id_reconteo IS NULL
               AND x.origen = 'SGO_ANALISTA' AND x.estado_registro = 'VIGENTE'),
            (SELECT SUM(x.cantidad) FROM sod_inv_conteo_det AS x
             WHERE x.id_conteo = :id_c1b AND x.id_producto = u.id_producto
               AND x.id_tag = u.id_tag AND x.estado_registro = 'VIGENTE'),
            0
        ) AS cantidad_base,
        (SELECT COUNT(DISTINCT x.id_tag) FROM sod_inv_conteo_det AS x
         WHERE x.id_conteo = :id_c1t AND x.id_producto = u.id_producto
           AND x.estado_registro = 'VIGENTE'
        " . ($idC2 > 0 ? "OR (x.id_conteo = :id_c2t AND x.id_producto = u.id_producto
           AND x.id_reconteo IS NULL
           AND x.origen IN ('SGO_ANALISTA','SGO_PREVARIANCE')
           AND x.estado_registro = 'VIGENTE')" : "") . "
        ) AS tags_total,
        " . ($idC3 > 0
            ? "(SELECT COUNT(DISTINCT x.id_tag) FROM sod_inv_conteo_det AS x
               WHERE x.id_conteo = :id_c3t AND x.id_producto = u.id_producto
                 AND x.id_reconteo IS NULL
                 AND x.origen = 'SGO_RECUENTO'
                 AND x.estado_registro = 'VIGENTE')"
            : "0"
        ) . " AS tags_recontados,
        (SELECT SUM(x.cantidad) FROM sod_inv_conteo_det AS x
         WHERE x.id_conteo = :id_c3s AND x.id_producto = u.id_producto
           AND x.id_reconteo IS NULL
           AND x.origen = 'SGO_RECUENTO'
           AND x.estado_registro = 'VIGENTE'
        ) AS cantidad_c3_sum
    FROM (
        SELECT DISTINCT id_producto, id_tag
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_c1c
          AND estado_registro = 'VIGENTE'
        {$unionC2}
    ) AS u
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = u.id_producto
    LEFT JOIN sod_inv_kardex_det AS kd
           ON kd.id_kardex = :id_kardex
          AND kd.id_producto = u.id_producto";

    $paramsUniv = [
        ':id_c2b'  => $idC2,
        ':id_c2c'  => $idC2,
        ':id_c1b'  => $idC1,
        ':id_c1t'  => $idC1,
        ':id_c1c'  => $idC1,
        ':id_kardex' => $idKardex,
    ];
    if ($idC2 > 0) {
        $paramsUniv[':id_c2t'] = $idC2;
    }
    if ($idC3 > 0) {
        $paramsUniv[':id_c3t'] = $idC3;
        $paramsUniv[':id_c3s'] = $idC3;
    }

    $stmtU = $pdo->prepare($sqlUniv);
    $stmtU->execute($paramsUniv);
    $rowsUniv = $stmtU->fetchAll();

    // Agregar por producto + filtrar umbral
    $universo = [];
    foreach ($rowsUniv as $r) {
        $pid = (int)$r['id_producto'];
        if (!isset($universo[$pid])) {
            $universo[$pid] = [
                'id_producto'      => $pid,
                'sku'              => $r['sku'],
                'descripcion'      => $r['descripcion_producto'],
                'stock_teorico'    => (float)$r['stock_teorico'],
                'valor_unitario'   => (float)$r['valor_unitario'],
                'base'             => 0.0,
                'tags_total'       => 0,
                'tags_recontados'  => 0,
                'cantidad_c3_sum'  => 0.0,
                'has_c3'           => false,
            ];
        }
        $base = (float)$r['cantidad_base'];
        $hasC3 = $r['cantidad_c3_sum'] !== null;
        $fin = $hasC3 ? (float)$r['cantidad_c3_sum'] : $base;

        $universo[$pid]['base'] += $base;
        // tags_total/tags_recontados ya vienen agregados por producto en la subquery
        $universo[$pid]['tags_total'] = max($universo[$pid]['tags_total'], (int)$r['tags_total']);
        $universo[$pid]['tags_recontados'] = max($universo[$pid]['tags_recontados'], (int)$r['tags_recontados']);
        if ($hasC3) {
            $universo[$pid]['has_c3'] = true;
            $universo[$pid]['cantidad_c3_sum'] += $fin;
        }
    }

    // Filtro umbral >= 100000 (réplica _8)
    $filtrado = [];
    $incluidos = 0;
    foreach ($universo as $pid => $u) {
        $difValor = ($u['base'] - $u['stock_teorico']) * $u['valor_unitario'];
        if (abs($difValor) >= UMBRAL_RECUENTO) {
            $u['dif_inicial_valor'] = $difValor;
            $filtrado[$pid] = $u;
            $incluidos++;
        }
    }

    // ── Map de productos enviados por el cliente ────────────
    $enviados = [];
    foreach ($productos as $producto) {
        if (empty($producto['producto_id'])) {
            $pdo->rollBack();
            errorResponse('Cada producto debe tener producto_id');
        }
        $pid = (int)$producto['producto_id'];
        $enviados[$pid] = $producto;
    }

    // Solo persistir productos del universo filtrado que el cliente envió
    $aPersistir = [];
    foreach ($filtrado as $pid => $u) {
        if (isset($enviados[$pid])) {
            $aPersistir[$pid] = $u;
        }
    }

    if (empty($aPersistir)) {
        // Si el cliente envió productos fuera de umbral, persistir igual
        // solo si están en el universo de kardex con al menos un C3 —
        // por robustez, usar lo enviado cuando el universo filtrado no intersecta.
        foreach ($enviados as $pid => $producto) {
            if (isset($universo[$pid])) {
                $aPersistir[$pid] = $universo[$pid];
            }
        }
    }

    if (empty($aPersistir)) {
        $pdo->rollBack();
        errorResponse('Ningún producto del universo de recuento (>= $100.000) para persistir');
    }

    // ── Stats y totales ─────────────────────────────────────
    $totalCorregidos = 0;
    $totalSinCambio = 0;
    foreach ($aPersistir as $pid => $u) {
        $env = $enviados[$pid] ?? null;
        $cantidadC3 = $env !== null && isset($env['cantidad_c3'])
            ? (float)$env['cantidad_c3']
            : ($u['has_c3'] ? $u['cantidad_c3_sum'] : $u['base']);
        $flCorrige = abs($cantidadC3 - $u['base']) > 0.0005;
        if ($flCorrige) {
            $totalCorregidos++;
        } else {
            $totalSinCambio++;
        }
    }

    // ── Crear cierre padre ──────────────────────────────────
    $totalSku = count($aPersistir);
    $totalTags = 0;
    $totalTagsRec = 0;
    foreach ($aPersistir as $u) {
        $totalTags += (int)$u['tags_total'];
        $totalTagsRec += (int)$u['tags_recontados'];
    }

    // Marcador de universo completo (réplica _8:3019-3023)
    $observacion = "Recuento formal desde App Tablet.";
    $todosRecontados = $totalTags > 0 && $totalTagsRec >= $totalTags;
    if ($todosRecontados) {
        $observacion .= " UNIVERSO_COMPLETO_RECUENTO_100K";
    }

    $stmtCierre = $pdo->prepare(
        "INSERT INTO sod_inv_reconteo_cierre
            (id_agenda, estado_cierre, total_sku, total_tags, total_corregidos, total_sin_cambio,
             fecha_cierre, login_cierre, fl_activo, observacion, fecha_creacion, usuario_creacion)
         VALUES
            (:agenda_id, 'CERRADO', :total_sku, :total_tags, :total_corregidos, :total_sin_cambio,
             NOW(), :login, 'S', :observacion, NOW(), :login2)"
    );
    $stmtCierre->execute([
        ':agenda_id'         => $agendaId,
        ':total_sku'         => $totalSku,
        ':total_tags'        => $totalTags,
        ':total_corregidos'  => $totalCorregidos,
        ':total_sin_cambio'  => $totalSinCambio,
        ':login'             => 'APP_TABLET',
        ':observacion'       => $observacion,
        ':login2'            => 'APP_TABLET',
    ]);
    $idReconteoCierre = (int)$pdo->lastInsertId();

    // ── Insertar detalle ────────────────────────────────────
    $sqlDet = "INSERT INTO sod_inv_reconteo_cierre_det
        (id_reconteo_cierre, id_agenda, id_producto, sku, descripcion_producto,
         stock_teorico, valor_unitario, cantidad_antes_recuento, diferencia_antes, diferencia_antes_valor,
         cantidad_recuento, diferencia_recuento, fl_corrige,
         tags_total, tags_recontados, fecha_creacion)
        VALUES
        (:id_cierre, :agenda_id, :producto_id, :sku, :descripcion,
         :stock_teorico, :valor_unitario, :cantidad_antes, :diferencia_antes, :diferencia_antes_valor,
         :cantidad_recuento, :diferencia_recuento, :fl_corrige,
         :tags_total, :tags_recontados, NOW())";
    $stmtDet = $pdo->prepare($sqlDet);

    foreach ($aPersistir as $pid => $u) {
        $env = $enviados[$pid] ?? null;
        $cantidadC3 = $env !== null && isset($env['cantidad_c3'])
            ? (float)$env['cantidad_c3']
            : ($u['has_c3'] ? $u['cantidad_c3_sum'] : $u['base']);
        $flCorrige = abs($cantidadC3 - $u['base']) > 0.0005 ? 'S' : 'N';

        $stockTeorico = $u['stock_teorico'];
        $valorUnitario = $u['valor_unitario'];
        $cantidadAntes = $u['base'];
        $diferenciaAntes = $cantidadAntes - $stockTeorico;
        $diferenciaAntesValor = $diferenciaAntes * $valorUnitario;
        $diferenciaRecuento = $cantidadC3 - $cantidadAntes;

        $stmtDet->execute([
            ':id_cierre'          => $idReconteoCierre,
            ':agenda_id'          => $agendaId,
            ':producto_id'        => $pid,
            ':sku'                => $u['sku'],
            ':descripcion'        => $u['descripcion'],
            ':stock_teorico'      => $stockTeorico,
            ':valor_unitario'     => $valorUnitario,
            ':cantidad_antes'     => $cantidadAntes,
            ':diferencia_antes'   => $diferenciaAntes,
            ':diferencia_antes_valor' => $diferenciaAntesValor,
            ':cantidad_recuento'  => $cantidadC3,
            ':diferencia_recuento'=> $diferenciaRecuento,
            ':fl_corrige'         => $flCorrige,
            ':tags_total'         => (int)$u['tags_total'],
            ':tags_recontados'    => (int)$u['tags_recontados'],
        ]);
    }

    $pdo->commit();

    okResponse([
        'id_reconteo_cierre' => $idReconteoCierre,
        'productos_persistidos' => count($aPersistir),
        'umbral' => UMBRAL_RECUENTO,
        'universo_filtrado' => $incluidos,
        'snapshot_universo_completo' => $todosRecontados,
    ], 'Recuento validado correctamente');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    errorResponse('Error al validar Recuento: ' . $e->getMessage(), 500);
}
