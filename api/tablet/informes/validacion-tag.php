<?php
# ============================================================
# ws/api/tablet/informes/validacion-tag.php
# GET ?agenda_id=123[&tipo_revision=ALTILLO|PDV]
# Informe de Validacion de TAGs
# Réplica de ScriptCase blank_sod_inf_validacion_tag
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');
$tipoRevision = strtoupper(trim($_GET['tipo_revision'] ?? ''));

try {
    // ── 1. Obtener C1 (INICIAL) ────────────────────────────
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

    // ── 2. Obtener C2 (VALIDACION) ─────────────────────────
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

    // ── 3. Obtener Kardex vigente ──────────────────────────
    $stmtKardex = $pdo->prepare(
        "SELECT id_kardex FROM sod_inv_kardex
         WHERE id_agenda = :agenda_id
           AND fl_activo = 'S'
           AND estado_kardex IN ('CARGADO','VALIDADO')
         ORDER BY id_kardex DESC LIMIT 1"
    );
    $stmtKardex->execute([':agenda_id' => $agendaId]);
    $kardex = $stmtKardex->fetch();
    $idKardex = $kardex ? (int)$kardex['id_kardex'] : 0;

    // ── 4. UNION C2 (SGO_ANALISTA, id_reconteo IS NULL) ────
    $unionC2 = '';
    if ($idC2 > 0) {
        $unionC2 = "
        UNION
        SELECT DISTINCT id_tag, id_producto
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_c2_u
          AND id_reconteo IS NULL
          AND origen = 'SGO_ANALISTA'
          AND estado_registro = 'VIGENTE'
          AND EXISTS (
              SELECT 1 FROM sod_inv_conteo_det AS c1_tag
              WHERE c1_tag.id_conteo = :id_c1_u
                AND c1_tag.id_tag = sod_inv_conteo_det.id_tag
                AND c1_tag.estado_registro = 'VIGENTE'
          )";
    }

    // ── 5. Query principal (C1 + C2 + kardex) ──────────────
    $sql = "SELECT
        t.id_tag,
        t.numero_tag,
        COALESCE(tu.codigo_tipo_ubicacion, '') AS codigo_tipo,
        COALESCE(tu.nombre_tipo_ubicacion, 'Sin zona') AS zona,
        p.id_producto,
        p.sku,
        p.descripcion_producto,
        COALESCE(c1a.cantidad_c1, 0) AS cantidad_c1,
        COALESCE(c1a.registros_c1, 0) AS registros_c1,
        COALESCE(c1a.operadores, '') AS operadores_taxo,
        c2a.cantidad_c2,
        COALESCE(c2a.registros_c2, 0) AS registros_c2,
        COALESCE(c2a.analistas, '') AS analistas,
        COALESCE(kd.valor_unitario, 0) AS valor_unitario
    FROM (
        SELECT DISTINCT id_tag, id_producto
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_c1
          AND estado_registro = 'VIGENTE'
        " . $unionC2 . "
    ) AS u
    INNER JOIN sod_inv_tag AS t ON t.id_tag = u.id_tag AND t.id_agenda = :agenda_id2
    LEFT JOIN sod_cfg_tipo_ubicacion AS tu ON tu.id_tipo_ubicacion = t.id_tipo_ubicacion
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = u.id_producto
    LEFT JOIN (
        SELECT id_tag, id_producto,
               SUM(cantidad) AS cantidad_c1,
               COUNT(*) AS registros_c1,
               GROUP_CONCAT(DISTINCT login_operador ORDER BY login_operador SEPARATOR ', ') AS operadores
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_c1_agg AND estado_registro = 'VIGENTE'
        GROUP BY id_tag, id_producto
    ) AS c1a ON c1a.id_tag = u.id_tag AND c1a.id_producto = u.id_producto
    LEFT JOIN (
        SELECT id_tag, id_producto,
               SUM(cantidad) AS cantidad_c2,
               COUNT(*) AS registros_c2,
               GROUP_CONCAT(DISTINCT login_operador ORDER BY login_operador SEPARATOR ', ') AS analistas
        FROM sod_inv_conteo_det
        WHERE id_conteo = :id_c2_agg
          AND id_reconteo IS NULL
          AND origen = 'SGO_ANALISTA'
          AND estado_registro = 'VIGENTE'
        GROUP BY id_tag, id_producto
    ) AS c2a ON c2a.id_tag = u.id_tag AND c2a.id_producto = u.id_producto
    LEFT JOIN sod_inv_kardex_det AS kd
           ON kd.id_kardex = :id_kardex AND kd.id_producto = u.id_producto
    ORDER BY t.numero_tag, p.sku";

    $params = [
        ':id_c1' => $idC1,
        ':id_c1_agg' => $idC1,
        ':id_c1_u' => $idC1,
        ':agenda_id2' => $agendaId,
        ':id_c2_agg' => $idC2,
        ':id_kardex' => $idKardex,
    ];
    if ($idC2 > 0) {
        $params[':id_c2_u'] = $idC2;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw = $stmt->fetchAll();

    // ── 6. Procesar y calcular costos ──────────────────────
    $esAltillo = fn($codigo, $zona) =>
        stripos($zona, 'altillo') !== false;
    $esPdv = fn($codigo, $zona) =>
        stripos($zona, 'punto de venta') !== false
        || stripos($zona, 'punto venta') !== false
        || strtolower($codigo) === 'pdv';

    $filas = [];
    $tags = [];

    foreach ($raw as $r) {
        // Filtro por tipo de revisión (opcional)
        if ($tipoRevision !== '') {
            $coincide = ($tipoRevision === 'ALTILLO')
                ? $esAltillo($r['codigo_tipo'], $r['zona'])
                : $esPdv($r['codigo_tipo'], $r['zona']);
            if (!$coincide) continue;
        }

        $c1n = (float)$r['cantidad_c1'];
        $c2raw = $r['cantidad_c2'];
        $vu = (float)$r['valor_unitario'];

        $revisado = ($c2raw !== null);
        $c2n = $revisado ? (float)$c2raw : null;
        $correccion = $revisado ? ($c2n - $c1n) : 0.0;
        $cantidadFinal = $revisado ? $c2n : $c1n;
        $costoInicial = $c1n * $vu;
        $costoCorreccion = $correccion * $vu;
        $costoFinal = $cantidadFinal * $vu;

        $row = [
            'id_tag' => (int)$r['id_tag'],
            'numero_tag' => (int)$r['numero_tag'],
            'codigo_tipo' => $r['codigo_tipo'],
            'zona' => $r['zona'],
            'id_producto' => (int)$r['id_producto'],
            'sku' => $r['sku'],
            'descripcion_producto' => $r['descripcion_producto'],
            'cantidad_c1' => $c1n,
            'registros_c1' => (int)$r['registros_c1'],
            'operadores_taxo' => $r['operadores_taxo'],
            'cantidad_c2' => $c2n,
            'registros_c2' => (int)$r['registros_c2'],
            'analistas' => $r['analistas'],
            'revisado' => $revisado,
            'correccion' => $correccion,
            'cantidad_final' => $cantidadFinal,
            'valor_unitario' => $vu,
            'costo_inicial' => $costoInicial,
            'costo_correccion' => $costoCorreccion,
            'costo_final' => $costoFinal,
        ];
        $filas[] = $row;

        // Agregar por TAG
        $tid = $row['id_tag'];
        if (!isset($tags[$tid])) {
            $tags[$tid] = [
                'id_tag' => $tid,
                'numero_tag' => $row['numero_tag'],
                'zona' => $row['zona'],
                'revisado' => false,
                'registros' => 0,
                'registros_rev' => 0,
                'cantidad' => 0.0,
                'costo' => 0.0,
                'costo_rev' => 0.0,
                'analistas' => '',
                'productos' => [],
            ];
        }
        $tags[$tid]['registros'] += $row['registros_c1'];
        $tags[$tid]['cantidad'] += $c1n;
        $tags[$tid]['costo'] += $costoInicial;
        if ($revisado) {
            $tags[$tid]['revisado'] = true;
            $tags[$tid]['registros_rev'] += $row['registros_c1'];
            $tags[$tid]['costo_rev'] += $costoInicial;
            $tags[$tid]['analistas'] = $row['analistas'];
        }
        $tags[$tid]['productos'][] = $row;
    }

    $tagsArr = array_values($tags);
    $totalTags = count($tagsArr);
    $tagsRevisados = count(array_filter($tagsArr, fn($t) => $t['revisado']));
    $totalCostoInicial = array_sum(array_map(fn($t) => $t['costo'], $tagsArr));
    $totalCostoFinal = array_sum(array_map(fn($t) => $t['costo_rev'], $tagsArr));

    okResponse([
        'tipo_revision' => $tipoRevision ?: 'TODOS',
        'resumen' => [
            'total_tags' => $totalTags,
            'tags_revisados' => $tagsRevisados,
            'total_productos' => count($filas),
            'costo_inicial' => $totalCostoInicial,
            'costo_revisado' => $totalCostoFinal,
        ],
        'tags' => $tagsArr,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al generar informe: ' . $e->getMessage(), 500);
}
