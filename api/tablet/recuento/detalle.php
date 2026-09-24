<?php
# ============================================================
# ws/api/tablet/recuento/detalle.php
# GET ?agenda_id=123
# Universo de Recuento C3 — réplica _8_blank_reconteo_final:
#   - stock_teorico/valor_unitario desde sod_inv_kardex_det
#   - filtro ABS(dif_inicial_valor) >= 100000
#   - estados: PENDIENTE / PARCIAL / RECONTADO (cobertura de tags)
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');
$agendaId = (int)$agendaId;

const UMBRAL_RECUENTO = 100000.0;

try {
    // ── C1 ──────────────────────────────────────────────────
    $stmtC1 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 1
           AND tipo_conteo = 'INICIAL' AND fl_activo = 'S'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC1->execute([':agenda_id' => $agendaId]);
    $c1 = $stmtC1->fetch();
    if (!$c1) errorResponse('No existe Conteo 1');
    $idC1 = (int)$c1['id_conteo'];

    // ── C2 ──────────────────────────────────────────────────
    $stmtC2 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 2
           AND tipo_conteo = 'VALIDACION' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC2->execute([':agenda_id' => $agendaId]);
    $c2 = $stmtC2->fetch();
    $idC2 = $c2 ? (int)$c2['id_conteo'] : 0;

    // ── C3 ──────────────────────────────────────────────────
    $stmtC3 = $pdo->prepare(
        "SELECT id_conteo, estado_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 3
           AND tipo_conteo = 'RECONTEO' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC3->execute([':agenda_id' => $agendaId]);
    $c3 = $stmtC3->fetch();
    $idC3 = $c3 ? (int)$c3['id_conteo'] : 0;

    // ── Kardex activo (stock_teorico + valor_unitario) ──────
    $stmtK = $pdo->prepare(
        "SELECT id_kardex FROM sod_inv_kardex
         WHERE id_agenda = :agenda_id AND fl_activo = 'S'
         ORDER BY id_kardex DESC LIMIT 1"
    );
    $stmtK->execute([':agenda_id' => $agendaId]);
    $k = $stmtK->fetch();
    if (!$k) {
        errorResponse('No existe Kardex activo para la agenda');
    }
    $idKardex = (int)$k['id_kardex'];

    // ── Tags del universo (C1 ∪ C2) con base + C3 ───────────
    $unionC2 = $idC2 > 0
        ? " UNION
           SELECT DISTINCT id_producto, id_tag
           FROM sod_inv_conteo_det
           WHERE id_conteo = {$idC2}
             AND id_reconteo IS NULL
             AND origen IN ('SGO_ANALISTA','SGO_PREVARIANCE')
             AND estado_registro = 'VIGENTE'"
        : "";

    $unionC3 = $idC3 > 0
        ? " UNION
           SELECT DISTINCT id_producto, id_tag
           FROM sod_inv_conteo_det
           WHERE id_conteo = {$idC3}
             AND id_reconteo IS NULL
             AND origen = 'SGO_RECUENTO'
             AND estado_registro = 'VIGENTE'"
        : "";

    $sql = "SELECT
        u.id_producto,
        u.id_tag,
        p.sku AS producto_sku,
        p.descripcion_producto AS producto_nombre,
        t.numero_tag,
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
        " . ($idC3 > 0
            ? "(SELECT SUM(x.cantidad) FROM sod_inv_conteo_det AS x
               WHERE x.id_conteo = :id_c3b AND x.id_producto = u.id_producto
                 AND x.id_tag = u.id_tag AND x.id_reconteo IS NULL
                 AND x.origen = 'SGO_RECUENTO' AND x.estado_registro = 'VIGENTE')"
            : "NULL"
        ) . " AS cantidad_c3,
        COALESCE(
            (SELECT SUM(d2.cantidad) FROM sod_inv_conteo_det AS d2
             WHERE d2.id_conteo = :id_c2d AND d2.id_producto = u.id_producto
               AND d2.id_tag = u.id_tag AND d2.id_reconteo IS NULL
               AND d2.origen = 'SGO_ANALISTA' AND d2.estado_registro = 'VIGENTE'),
            0
        ) AS cantidad_c2,
        COALESCE(
            (SELECT SUM(pv.cantidad) FROM sod_inv_conteo_det AS pv
             WHERE pv.id_conteo = :id_c2e AND pv.id_producto = u.id_producto
               AND pv.id_tag = u.id_tag AND pv.id_reconteo IS NULL
               AND pv.origen = 'SGO_PREVARIANCE' AND pv.estado_registro = 'VIGENTE'),
            0
        ) AS cantidad_prevariance
    FROM (
        SELECT DISTINCT id_producto, id_tag
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_c1c
          AND estado_registro = 'VIGENTE'
        {$unionC2}
        {$unionC3}
    ) AS u
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = u.id_producto
    INNER JOIN sod_inv_tag AS t ON t.id_tag = u.id_tag
         AND t.id_agenda = :agenda_id4 AND t.fl_activo = 'S'
    LEFT JOIN sod_inv_kardex_det AS kd
           ON kd.id_kardex = :id_kardex
          AND kd.id_producto = u.id_producto";

    $params = [
        ':id_c2b'  => $idC2,
        ':id_c2c'  => $idC2,
        ':id_c1b'  => $idC1,
        ':id_c2d'  => $idC2,
        ':id_c2e'  => $idC2,
        ':id_c1c'  => $idC1,
        ':agenda_id4' => $agendaId,
        ':id_kardex'  => $idKardex,
    ];
    if ($idC3 > 0) {
        $params[':id_c3b'] = $idC3;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rowsTag = $stmt->fetchAll();

    // ── Agregar por producto (réplica _8) ───────────────────
    $agg = [];
    foreach ($rowsTag as $r) {
        $pid = (int)$r['id_producto'];
        if (!isset($agg[$pid])) {
            $agg[$pid] = [
                'id_producto'        => $pid,
                'producto_sku'       => $r['producto_sku'],
                'producto_nombre'    => $r['producto_nombre'],
                'stock_teorico'      => (float)$r['stock_teorico'],
                'valor_unitario'     => (float)$r['valor_unitario'],
                'cantidad_c1_sum'    => 0.0,
                'cantidad_c2'        => 0.0,
                'cantidad_prevariance' => 0.0,
                'base_sum'           => 0.0,
                'cantidad_c3_sum'    => 0.0,
                'tags_total'         => 0,
                'tags_recontados'    => 0,
                'id_tag'             => (int)$r['id_tag'],
                'numero_tag'         => (int)$r['numero_tag'],
            ];
        }
        $base = (float)$r['cantidad_base'];
        $hasC3 = $r['cantidad_c3'] !== null;
        $fin = $hasC3 ? (float)$r['cantidad_c3'] : $base;

        $agg[$pid]['base_sum'] += $base;
        $agg[$pid]['cantidad_c3_sum'] += $fin;
        $agg[$pid]['cantidad_c2'] += (float)$r['cantidad_c2'];
        $agg[$pid]['cantidad_prevariance'] += (float)$r['cantidad_prevariance'];
        $agg[$pid]['tags_total']++;
        if ($hasC3) {
            $agg[$pid]['tags_recontados']++;
        }
    }

    // ── Filtro umbral + estados + base_quantity ─────────────
    $productos = [];
    $totalPendientes = 0;
    $totalParciales = 0;
    $totalRecontados = 0;

    foreach ($agg as $p) {
        $stock = $p['stock_teorico'];
        $vu = $p['valor_unitario'];
        $base = $p['base_sum'];
        $difInicial = $base - $stock;
        $difInicialValor = $difInicial * $vu;

        // Filtro universo >= $100.000 (réplica _8:3306/3324)
        if (abs($difInicialValor) < UMBRAL_RECUENTO) {
            continue;
        }

        $tagsTotal = $p['tags_total'];
        $tagsRec = $p['tags_recontados'];

        // Prioridad base: PV > ANALISTA > C1 (misma que captura)
        if ($p['cantidad_prevariance'] > 0) {
            $baseQuantity = $p['cantidad_prevariance'];
            $baseOrigen = 'SGO_PREVARIANCE';
        } elseif ($p['cantidad_c2'] > 0) {
            $baseQuantity = $p['cantidad_c2'];
            $baseOrigen = 'SGO_ANALISTA';
        } else {
            $baseQuantity = $p['cantidad_c1_sum'] ?: $base;
            $baseOrigen = 'C1';
        }

        // Estado por cobertura de tags (réplica _8:3236-3243 / operativo)
        if ($tagsRec <= 0) {
            $estado = 'PENDIENTE';
            $totalPendientes++;
        } elseif ($tagsTotal > 0 && $tagsRec < $tagsTotal) {
            $estado = 'PARCIAL';
            $totalParciales++;
        } else {
            $estado = 'RECONTADO';
            $totalRecontados++;
        }

        $productos[] = [
            'id_producto'         => $p['id_producto'],
            'producto_sku'        => $p['producto_sku'],
            'producto_nombre'     => $p['producto_nombre'],
            'id_tag'              => $p['id_tag'],
            'numero_tag'          => $p['numero_tag'],
            'stock_teorico'       => $stock,
            'valor_unitario'      => $vu,
            'dif_inicial'         => $difInicial,
            'dif_inicial_valor'   => $difInicialValor,
            'cantidad_c1'         => $base,
            'cantidad_c2'         => $p['cantidad_c2'],
            'cantidad_prevariance'=> $p['cantidad_prevariance'],
            'base_quantity'       => $baseQuantity,
            'base_origen'         => $baseOrigen,
            'cantidad_c3'         => $tagsRec > 0 ? $p['cantidad_c3_sum'] : null,
            'tags_total'          => $tagsTotal,
            'tags_recontados'     => $tagsRec,
            'estado'              => $estado,
        ];
    }

    usort($productos, function ($a, $b) {
        $aa = abs((float)$a['dif_inicial_valor']);
        $bb = abs((float)$b['dif_inicial_valor']);
        if (abs($aa - $bb) > 0.005) {
            return $aa < $bb ? 1 : -1;
        }
        return strcasecmp((string)$a['producto_sku'], (string)$b['producto_sku']);
    });

    // ── Estado cierre Recuento ──────────────────────────────
    $stmtCierre = $pdo->prepare(
        "SELECT id_reconteo_cierre AS id_cierre,
                UPPER(COALESCE(estado_cierre, '')) AS estado_cierre,
                COALESCE(observacion, '') AS observacion
         FROM sod_inv_reconteo_cierre
         WHERE id_agenda = :agenda_id
         ORDER BY numero_version DESC, id_reconteo_cierre DESC
         LIMIT 1"
    );
    $stmtCierre->execute([':agenda_id' => $agendaId]);
    $cierre = $stmtCierre->fetch();

    $snapshotCompleto = $cierre !== false
        && stripos((string)$cierre['observacion'], 'UNIVERSO_COMPLETO_RECUENTO_100K') !== false;

    okResponse([
        'recuentos' => $idC3 ? [['id' => $idC3, 'estado' => $c3['estado_conteo']]] : [],
        'productos' => $productos,
        'umbral' => UMBRAL_RECUENTO,
        'total_pendientes'   => $totalPendientes,
        'total_parciales'    => $totalParciales,
        'total_recontados'   => $totalRecontados,
        'estado_cierre' => $cierre ? $cierre['estado_cierre'] : 'SIN_REGISTRO',
        'snapshot_universo_completo' => $snapshotCompleto,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener recuento: ' . $e->getMessage(), 500);
}
