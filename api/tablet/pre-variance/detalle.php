<?php
# ============================================================
# ws/api/tablet/pre-variance/detalle.php
# GET ?agenda_id=123
# Lista de productos Pre-Variance (diferencia >= $500.000)
# Réplica de ScriptCase _3_blank_pre_variance (usa kardex)
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

$umbral = 500000.0;

try {
    // ── Obtener C1 (Conteo Inicial) ────────────────────────
    $stmtC1 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 1
           AND tipo_conteo = 'INICIAL' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC1->execute([':agenda_id' => $agendaId]);
    $c1 = $stmtC1->fetch();
    if (!$c1) errorResponse('No existe Conteo 1 para la agenda.');
    $idC1 = (int)$c1['id_conteo'];

    // ── Obtener C2 (Conteo Validación) ─────────────────────
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

    // ── Obtener Kardex activo (CARGADO o VALIDADO) ─────────
    $stmtKardex = $pdo->prepare(
        "SELECT id_kardex FROM sod_inv_kardex
         WHERE id_agenda = :agenda_id
           AND fl_activo = 'S'
           AND estado_kardex IN ('CARGADO','VALIDADO')
         ORDER BY fecha_hora_carga DESC, id_kardex DESC
         LIMIT 1"
    );
    $stmtKardex->execute([':agenda_id' => $agendaId]);
    $kardex = $stmtKardex->fetch();
    if (!$kardex) errorResponse('No existe Kardex activo para calcular Pre Variance.');
    $idKardex = (int)$kardex['id_kardex'];

    // ── Productos con diferencia >= umbral ─────────────────
    // Réplica ScriptCase: clave id_producto:id_tag desde
    // vw_sod_inv_fisico_tag_vigente (cantidad_c1/c2 por TAG).
    $sql = "SELECT
        kd.id_producto,
        p.sku AS producto_sku,
        p.descripcion_producto AS producto_nombre,
        kd.stock_teorico,
        kd.valor_unitario AS precio_unitario,
        kd.id_kardex,
        x.id_tag,
        x.numero_tag,
        x.cantidad_c1,
        x.cantidad_c2,
        COALESCE(x.fisico_operacional, 0) AS cantidad_inventariada,
        COALESCE(x.fisico_operacional, 0) - kd.stock_teorico AS diferencia,
        ROUND(ABS(
            (COALESCE(x.fisico_operacional, 0) - kd.stock_teorico) * kd.valor_unitario
        ), 0) AS diferencia_monto,
        'PENDIENTE' AS estado
    FROM sod_inv_kardex_det AS kd
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = kd.id_producto
    LEFT JOIN (
        SELECT
            t.id_producto,
            t.id_tag,
            t.numero_tag,
            t.cantidad_c1,
            t.cantidad_c2,
            t.fisico_operacional,
            t.orden_monto
        FROM (
            SELECT
                v.id_producto,
                v.id_tag,
                v.numero_tag,
                v.cantidad_c1,
                v.cantidad_c2,
                SUM(
                    CASE
                        WHEN v.cantidad_c2 IS NOT NULL THEN v.cantidad_c2
                        WHEN v.cantidad_c1 IS NOT NULL THEN v.cantidad_c1
                        ELSE 0
                    END
                ) OVER (PARTITION BY v.id_producto) AS fisico_operacional,
                ROW_NUMBER() OVER (
                    PARTITION BY v.id_producto
                    ORDER BY
                        CASE
                            WHEN v.cantidad_c2 IS NOT NULL THEN 0
                            WHEN v.cantidad_c1 IS NOT NULL THEN 1
                            ELSE 2
                        END,
                        v.id_tag
                ) AS orden_monto
            FROM vw_sod_inv_fisico_tag_vigente AS v
            WHERE v.id_agenda = :agenda_id2
        ) AS t
        WHERE t.orden_monto = 1
    ) AS x ON x.id_producto = kd.id_producto
    WHERE kd.id_kardex = :id_kardex
      AND ABS(
          (COALESCE(x.fisico_operacional, 0) - kd.stock_teorico) * kd.valor_unitario
      ) >= :umbral
    ORDER BY ABS(
        (COALESCE(x.fisico_operacional, 0) - kd.stock_teorico) * kd.valor_unitario
    ) DESC, p.sku";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':agenda_id2' => $agendaId,
        ':id_kardex' => $idKardex,
        ':umbral' => $umbral,
    ]);
    $productos = $stmt->fetchAll();

    // id estable para la UI (track by) — id_producto:id_tag
    foreach ($productos as $i => &$prod) {
        $prod['id'] = ($i + 1);
        $prod['id_tag'] = (int)($prod['id_tag'] ?? 0);
        $prod['numero_tag'] = (int)($prod['numero_tag'] ?? 0);
        $prod['cantidad_c1'] = $prod['cantidad_c1'] !== null && $prod['cantidad_c1'] !== ''
            ? (float)$prod['cantidad_c1'] : 0;
        $prod['cantidad_c2'] = $prod['cantidad_c2'] !== null && $prod['cantidad_c2'] !== ''
            ? (float)$prod['cantidad_c2'] : 0;
        $prod['diferencia'] = (float)$prod['diferencia'];
        $prod['diferencia_monto'] = (float)$prod['diferencia_monto'];
        $prod['stock_teorico'] = (float)$prod['stock_teorico'];
        $prod['precio_unitario'] = (float)$prod['precio_unitario'];
    }
    unset($prod);

    // ── Restaurar estado desde Pre-Variance guardado ────────
    if ($idC2 && count($productos) > 0) {
        $stmtPVState = $pdo->prepare(
            "SELECT id_producto, id_tag, observacion, cantidad
             FROM sod_inv_conteo_det
             WHERE id_conteo = :id_c2
               AND id_reconteo IS NULL
               AND origen = 'SGO_PREVARIANCE'
               AND estado_registro = 'VIGENTE'"
        );
        $stmtPVState->execute([':id_c2' => $idC2]);
        $pvRecords = $stmtPVState->fetchAll();

        $pvMap = [];
        foreach ($pvRecords as $pv) {
            $key = $pv['id_producto'] . ':' . $pv['id_tag'];
            $pvMap[$key] = $pv;
        }

        foreach ($productos as &$prod) {
            $key = $prod['id_producto'] . ':' . $prod['id_tag'];
            if (isset($pvMap[$key])) {
                $obs = $pvMap[$key]['observacion'] ?? '';
                if (strpos($obs, 'APROBADO') !== false) {
                    $prod['estado'] = 'APROBADO';
                } elseif (strpos($obs, 'RECHAZADO') !== false) {
                    $prod['estado'] = 'RECHAZADO';
                }
            }
        }
        unset($prod);
    }

    // ── Verificar si Pre Variance fue finalizado ──────────
    $stmtCierre = $pdo->prepare(
        "SELECT estado_cierre, fecha_confirmacion
         FROM sod_inv_prevariance_cierre
         WHERE id_agenda = :id_agenda
         LIMIT 1"
    );
    $stmtCierre->execute([':id_agenda' => $agendaId]);
    $cierre = $stmtCierre->fetch();

    // ── Pre-Variance existentes ────────────────────────────
    $preVariances = [];
    if ($idC2) {
        $stmtPV = $pdo->prepare(
            "SELECT c.id_conteo, c.estado_conteo, c.fecha_hora_inicio,
                    c.login_responsable
             FROM sod_inv_conteo AS c
             WHERE c.id_agenda = :agenda_id
               AND c.numero_iteracion = 2
               AND c.tipo_conteo = 'VALIDACION'
               AND c.fl_activo = 'S'
               AND c.estado_conteo <> 'ANULADO'
             ORDER BY c.id_conteo DESC LIMIT 1"
        );
        $stmtPV->execute([':agenda_id' => $agendaId]);
        $preVariances = $stmtPV->fetchAll();
    }

    okResponse([
        'pre_variances' => $preVariances,
        'productos' => $productos,
        'total_diferencias' => count($productos),
        'umbral' => $umbral,
        'id_kardex' => $idKardex,
        'estado_cierre' => $cierre ? strtoupper($cierre['estado_cierre']) : 'SIN_REGISTRO',
        'fecha_cierre' => $cierre ? $cierre['fecha_confirmacion'] : null,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener Pre Variance: ' . $e->getMessage(), 500);
}
