<?php
# ws/api/tablet/cierres/snapshot.php
# POST { agenda_id, login? }
# Crea snapshot inmutable del cierre

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();
if (empty($input['agenda_id'])) errorResponse('Falta agenda_id');

$agendaId = $input['agenda_id'];
$login = $input['login'] ?? 'AUDITOR';

try {
    $pdo->beginTransaction();

    // 1. Verificar que no exista snapshot vigente
    $stmtCheck = $pdo->prepare(
        "SELECT id_cierre_agenda FROM sod_inv_cierre_agenda
         WHERE id_agenda = :agenda_id AND fl_inmutable = 'S'
         ORDER BY numero_version DESC LIMIT 1"
    );
    $stmtCheck->execute([':agenda_id' => $agendaId]);
    if ($stmtCheck->fetch()) {
        $pdo->rollBack();
        errorResponse('Ya existe un snapshot inmutable para esta agenda');
    }

    // 2. Datos de agenda
    $stmtAgenda = $pdo->prepare(
        "SELECT a.id_agenda, a.numero_agenda, a.fecha_agenda, a.id_tienda,
                t.codigo_tienda, t.nombre_tienda
         FROM sod_ope_agenda a
         INNER JOIN sod_cfg_tienda t ON t.id_tienda = a.id_tienda
         WHERE a.id_agenda = :agenda_id"
    );
    $stmtAgenda->execute([':agenda_id' => $agendaId]);
    $agenda = $stmtAgenda->fetch();
    if (!$agenda) { $pdo->rollBack(); errorResponse('Agenda no encontrada'); }

    // 3. Obtener C1/C2/C3
    $stmtC1 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 1
           AND tipo_conteo = 'INICIAL' AND fl_activo = 'S'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC1->execute([':agenda_id' => $agendaId]);
    $c1 = $stmtC1->fetch();
    if (!$c1) { $pdo->rollBack(); errorResponse('No existe Conteo 1'); }
    $idC1 = (int)$c1['id_conteo'];

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

    $stmtC3 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 3
           AND tipo_conteo = 'RECONTEO' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC3->execute([':agenda_id' => $agendaId]);
    $c3 = $stmtC3->fetch();
    $idC3 = $c3 ? (int)$c3['id_conteo'] : 0;

    // 4. Query productos - aggregate by product (unique key on id_producto)
    $sql = "SELECT
        d1.id_producto, p.sku, p.descripcion_producto, p.codigo_barras,
        p.unidad_medida, p.valor_referencia,
        GROUP_CONCAT(DISTINCT t.numero_tag ORDER BY t.numero_tag SEPARATOR ',') AS tags,
        SUM(d1.cantidad) AS conteo_1,
        COALESCE((SELECT SUM(d2.cantidad) FROM sod_inv_conteo_det d2
         WHERE d2.id_conteo = :id_c2 AND d2.id_producto = d1.id_producto
           AND d2.id_reconteo IS NULL
           AND d2.origen = 'SGO_ANALISTA' AND d2.estado_registro = 'VIGENTE'), 0) AS conteo_2,
        COALESCE((SELECT SUM(pv.cantidad) FROM sod_inv_conteo_det pv
         WHERE pv.id_conteo = :id_c22 AND pv.id_producto = d1.id_producto
           AND pv.id_reconteo IS NULL
           AND pv.origen = 'SGO_PREVARIANCE' AND pv.estado_registro = 'VIGENTE'), 0) AS cantidad_prevariance,
        COALESCE((SELECT SUM(rc.cantidad) FROM sod_inv_conteo_det rc
         WHERE rc.id_conteo = :id_c3 AND rc.id_producto = d1.id_producto
           AND rc.id_reconteo IS NULL
           AND rc.origen = 'SGO_RECUENTO' AND rc.estado_registro = 'VIGENTE'), NULL) AS conteo_3,
        k.stock_teorico, k.valor_unitario AS valor_unitario_kardex
    FROM sod_inv_conteo_det d1
    INNER JOIN sod_cfg_producto p ON p.id_producto = d1.id_producto
    INNER JOIN sod_inv_tag t ON t.id_tag = d1.id_tag AND t.id_agenda = :agenda_id3 AND t.fl_activo = 'S'
    LEFT JOIN sod_inv_kardex kd ON kd.id_agenda = :agenda_id4 AND kd.fl_activo = 'S'
    LEFT JOIN sod_inv_kardex_det k ON k.id_producto = d1.id_producto AND k.id_kardex = kd.id_kardex
    WHERE d1.id_conteo = :id_c1 AND d1.estado_registro = 'VIGENTE'
    GROUP BY d1.id_producto, p.sku, p.descripcion_producto, p.codigo_barras,
             p.unidad_medida, p.valor_referencia,
             k.stock_teorico, k.valor_unitario
    ORDER BY p.sku";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_c2' => $idC2, ':id_c22' => $idC2, ':id_c3' => $idC3,
        ':agenda_id3' => $agendaId, ':agenda_id4' => $agendaId, ':id_c1' => $idC1,
    ]);
    $productos = $stmt->fetchAll();

    if (empty($productos)) { $pdo->rollBack(); errorResponse('No hay productos para snapshot'); }

    // 5. Calcular totales y preparar detalle
    $totals = [
        'stock' => 0, 'fisico' => 0, 'dif_uni' => 0, 'dif_uni_abs' => 0,
        'val_stock' => 0, 'val_fisico' => 0, 'dif_val' => 0, 'dif_val_abs' => 0,
        'coincidentes' => 0, 'faltantes' => 0, 'sobrantes' => 0,
    ];
    $detalles = [];

    // Get valid id_resultado
    $stmtRes = $pdo->query("SELECT id_resultado FROM sod_inv_resultado LIMIT 1");
    $idResultado = (int)$stmtRes->fetch()['id_resultado'];

    foreach ($productos as $prod) {
        $stock = (float)($prod['stock_teorico'] ?? 0);
        $c1v = (float)$prod['conteo_1'];
        $c2v = (float)$prod['conteo_2'];
        $prev = (float)$prod['cantidad_prevariance'];

        if ($prev > 0) $base = $prev;
        elseif ($c2v > 0) $base = $c2v;
        else $base = $c1v;

        $final = $base;
        $dif = $stock - $final;
        $vu = (float)($prod['valor_unitario_kardex'] ?? $prod['valor_referencia'] ?? 0);
        $vStock = $stock * $vu;
        $vFisico = $final * $vu;
        $difVal = $vStock - $vFisico;

        $totals['stock'] += $stock;
        $totals['fisico'] += $final;
        $totals['dif_uni'] += $dif;
        $totals['dif_uni_abs'] += abs($dif);
        $totals['val_stock'] += $vStock;
        $totals['val_fisico'] += $vFisico;
        $totals['dif_val'] += $difVal;
        $totals['dif_val_abs'] += abs($difVal);

        if ($dif == 0) $totals['coincidentes']++;
        elseif ($dif > 0) $totals['faltantes']++;
        else $totals['sobrantes']++;

        $hashData = json_encode([
            'id_producto' => $prod['id_producto'], 'sku' => $prod['sku'],
            'stock' => $stock, 'final' => $final, 'dif' => $dif,
        ]);

        $detalles[] = [
            $idResultado, $prod['id_producto'], $prod['sku'],
            $prod['codigo_barras'] ?? '', $prod['descripcion_producto'],
            $prod['unidad_medida'], $stock,
            $c1v, $c2v, $prod['conteo_3'] !== null ? (float)$prod['conteo_3'] : null,
            $final, $dif, $vu, $vStock, $vFisico, $difVal,
            $dif == 0 ? 'COINCIDENTE' : ($dif > 0 ? 'FALTANTE' : 'SOBRANTE'),
            'PENDIENTE', hash('sha256', $hashData),
        ];
    }

    // 6. Version
    $stmtVer = $pdo->prepare(
        "SELECT COALESCE(MAX(numero_version), 0) + 1 AS nv FROM sod_inv_cierre_agenda WHERE id_agenda = :a"
    );
    $stmtVer->execute([':a' => $agendaId]);
    $nv = (int)$stmtVer->fetch()['nv'];
    $codigo = 'CI-' . date('YmdHis') . '-' . $agendaId;

    $headerData = json_encode([
        'id_agenda' => $agendaId, 'version' => $nv,
        'stock' => $totals['stock'], 'fisico' => $totals['fisico'],
        'dif' => $totals['dif_uni'], 'v_stock' => $totals['val_stock'], 'v_fisico' => $totals['val_fisico'],
    ]);
    $hashCab = hash('sha256', $headerData);

    // Get valid id_muestra and id_cierre_validacion
    $stmtMuestra = $pdo->query("SELECT id_muestra FROM sod_inv_muestra ORDER BY id_muestra DESC LIMIT 1");
    $idMuestra = (int)$stmtMuestra->fetch()['id_muestra'];

    $stmtValid = $pdo->query("SELECT id_cierre_validacion FROM sod_inv_cierre_validacion ORDER BY id_cierre_validacion DESC LIMIT 1");
    $idValid = (int)$stmtValid->fetch()['id_cierre_validacion'];

    // 7. Insertar cabecera PRIMERO
    $stmtHead = $pdo->prepare(
        "INSERT INTO sod_inv_cierre_agenda (
            codigo_cierre, numero_version, id_agenda, id_cierre_validacion, id_muestra, codigo_muestra, version_muestra,
            numero_agenda, fecha_agenda, id_tienda, codigo_tienda, nombre_tienda,
            estado_cierre, cantidad_sku, cantidad_coincidentes, cantidad_faltantes, cantidad_sobrantes,
            total_stock_unidades, total_fisico_unidades, total_diferencia_unidades, total_diferencia_unidades_abs,
            total_valor_stock, total_valor_fisico, total_diferencia_valor, total_diferencia_valor_abs,
            hash_cabecera, algoritmo_hash, fl_inmutable, fecha_cierre, login_cierre, origen_cierre
        ) VALUES (
            :codigo, :nv, :agenda, :validacion, :muestra, 'SNAPSHOT', 1,
            :num_agenda, :fec_agenda, :tienda, :cod_tienda, :nom_tienda,
            'CERRADA', :sku, :coinc, :falt, :sobr,
            :stock, :fisico, :dif, :dif_abs,
            :v_stock, :v_fisico, :dif_val, :dif_val_abs,
            :hash, 'SHA-256', 'S', NOW(6), :login, 'TABLET'
        )"
    );
    $stmtHead->execute([
        ':codigo' => $codigo, ':nv' => $nv, ':agenda' => $agendaId,
        ':validacion' => $idValid, ':muestra' => $idMuestra,
        ':num_agenda' => $agenda['numero_agenda'], ':fec_agenda' => $agenda['fecha_agenda'],
        ':tienda' => $agenda['id_tienda'], ':cod_tienda' => $agenda['codigo_tienda'],
        ':nom_tienda' => $agenda['nombre_tienda'],
        ':sku' => count($productos), ':coinc' => $totals['coincidentes'],
        ':falt' => $totals['faltantes'], ':sobr' => $totals['sobrantes'],
        ':stock' => $totals['stock'], ':fisico' => $totals['fisico'],
        ':dif' => $totals['dif_uni'], ':dif_abs' => $totals['dif_uni_abs'],
        ':v_stock' => $totals['val_stock'], ':v_fisico' => $totals['val_fisico'],
        ':dif_val' => $totals['dif_val'], ':dif_val_abs' => $totals['dif_val_abs'],
        ':hash' => $hashCab, ':login' => $login,
    ]);
    $idCierre = $pdo->lastInsertId();

    // 8. Insertar detalle DESPUES de cabecera
    $stmtDet = $pdo->prepare(
        "INSERT INTO sod_inv_cierre_agenda_det (
            id_cierre_agenda, id_resultado_origen, id_producto, sku, codigo_barras, descripcion_producto,
            unidad_medida, stock_teorico, conteo_1, conteo_2, conteo_3,
            cantidad_final, diferencia_cantidad, valor_unitario,
            valor_stock, valor_fisico, diferencia_valor,
            clasificacion, estado_revision, hash_detalle, algoritmo_hash
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SHA-256'
        )"
    );

    foreach ($detalles as $det) {
        $stmtDet->execute(array_merge([$idCierre], $det));
    }

    $pdo->commit();

    okResponse([
        'id_cierre_agenda' => (int)$idCierre,
        'codigo_cierre' => $codigo,
        'numero_version' => $nv,
        'total_productos' => count($productos),
        'hash_cabecera' => $hashCab,
    ], 'Snapshot inmutable creado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al crear snapshot: ' . $e->getMessage(), 500);
}
