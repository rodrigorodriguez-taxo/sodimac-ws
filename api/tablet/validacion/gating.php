<?php
# ============================================================
# ws/api/tablet/validacion/gating.php
# GET ?agenda_id=123
# Retorna estado de Altillos (100%), PDV (30%) y Tags Zonificados
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // ── Obtener C1 ──────────────────────────────────────────
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

    // ── Obtener C2 ──────────────────────────────────────────
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

    // ── Evaluar completion por TAG ───────────────────────────
    $sqlGate = "SELECT
        t.id_tag, t.numero_tag,
        COALESCE(tu.codigo_tipo_ubicacion, '') AS codigo_tipo,
        COALESCE(tu.nombre_tipo_ubicacion, '') AS nombre_tipo,
        COUNT(DISTINCT d1.id_conteo_det) AS capturas_total,
        COUNT(DISTINCT
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM sod_inv_conteo_det AS d2
                    WHERE d2.id_conteo = :id_c2
                      AND d2.id_reconteo IS NULL
                      AND d2.origen = 'SGO_ANALISTA'
                      AND d2.id_tag = d1.id_tag
                      AND d2.id_producto = d1.id_producto
                      AND d2.estado_registro = 'VIGENTE'
                      AND d2.id_origen_externo LIKE CONCAT(
                          'SGO-VAL-LINEA-', :agenda_id, '-', d1.id_conteo_det, '-%'
                      )
                )
                OR (
                    NOT EXISTS (
                        SELECT 1 FROM sod_inv_conteo_det AS ds
                        WHERE ds.id_conteo = :id_c2
                          AND ds.id_reconteo IS NULL
                          AND ds.origen = 'SGO_ANALISTA'
                          AND ds.id_tag = d1.id_tag
                          AND ds.id_producto = d1.id_producto
                          AND ds.estado_registro = 'VIGENTE'
                          AND (
                              ds.id_origen_externo LIKE 'SGO-VAL-LINEA-%'
                              OR ds.id_origen_externo LIKE 'SGO-VAL-BASE-%'
                          )
                    )
                    AND EXISTS (
                        SELECT 1 FROM sod_inv_conteo_det AS dl
                        WHERE dl.id_conteo = :id_c2
                          AND dl.id_reconteo IS NULL
                          AND dl.origen = 'SGO_ANALISTA'
                          AND dl.id_tag = d1.id_tag
                          AND dl.id_producto = d1.id_producto
                          AND dl.estado_registro = 'VIGENTE'
                    )
                )
                THEN d1.id_conteo_det
                ELSE NULL
            END
        ) AS capturas_confirmadas
    FROM sod_inv_conteo_det AS d1
    INNER JOIN sod_inv_tag AS t ON t.id_tag = d1.id_tag
           AND t.id_agenda = :agenda_id
           AND t.fl_activo = 'S'
           AND t.estado_tag <> 'ANULADO'
    LEFT JOIN sod_cfg_tipo_ubicacion AS tu ON tu.id_tipo_ubicacion = t.id_tipo_ubicacion
    WHERE d1.id_conteo = :id_c1
      AND d1.estado_registro = 'VIGENTE'
    GROUP BY t.id_tag, t.numero_tag, tu.codigo_tipo_ubicacion, tu.nombre_tipo_ubicacion";

    $stmtGate = $pdo->prepare($sqlGate);
    $stmtGate->execute([
        ':id_c2' => $idC2,
        ':agenda_id' => $agendaId,
        ':id_c1' => $idC1,
    ]);
    $tagsGate = $stmtGate->fetchAll();

    // ── Calcular Altillos y PDV ─────────────────────────────
    $altillosTotal = 0;
    $altillosCompletos = 0;
    $pdvTotal = 0;
    $pdvCompletos = 0;

    foreach ($tagsGate as $tg) {
        $capTotal = max(0, (int)$tg['capturas_total']);
        $capConf = max(0, (int)$tg['capturas_confirmadas']);
        $tagCompleto = ($capTotal > 0 && $capConf >= $capTotal);

        $codTipo = strtolower(trim($tg['codigo_tipo']));
        $nomTipo = strtolower(trim($tg['nombre_tipo']));
        $esAltillo = strpos($nomTipo, 'altillo') !== false;
        $esPdv = (strpos($nomTipo, 'punto de venta') !== false
               || strpos($nomTipo, 'punto venta') !== false
               || $codTipo === 'pdv');

        if ($esAltillo) {
            $altillosTotal++;
            if ($tagCompleto) $altillosCompletos++;
        }

        if ($esPdv) {
            $pdvTotal++;
            if ($tagCompleto) $pdvCompletos++;
        }
    }

    $altillosOk = ($altillosTotal === 0 || $altillosCompletos >= $altillosTotal);
    $pdvObjetivo = $pdvTotal > 0 ? (int)ceil($pdvTotal * 0.30) : 0;
    $pdvOk = ($pdvTotal === 0 || $pdvCompletos >= $pdvObjetivo);

    // ── Tags Zonificados ────────────────────────────────────
    $stmtZonif = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.id_tag) AS cantidad
         FROM sod_inv_conteo_det AS d1
         INNER JOIN sod_inv_tag AS t ON t.id_tag = d1.id_tag
                AND t.id_agenda = :agenda_id AND t.fl_activo = 'S'
                AND t.estado_tag <> 'ANULADO'
         WHERE d1.id_conteo = :id_c1 AND d1.estado_registro = 'VIGENTE'
           AND EXISTS (
               SELECT 1 FROM sod_ope_agenda_zonificacion AS z
               INNER JOIN sod_ope_agenda_zonificacion_det AS zd
                       ON zd.id_zonificacion = z.id_zonificacion AND zd.fl_activo = 'S'
               WHERE z.id_agenda = :agenda_id AND z.fl_activo = 'S'
                 AND z.id_zonificacion = (
                     SELECT MAX(zx.id_zonificacion) FROM sod_ope_agenda_zonificacion AS zx
                     WHERE zx.id_agenda = :agenda_id AND zx.fl_activo = 'S'
                 )
                 AND t.numero_tag BETWEEN zd.tag_desde AND zd.tag_hasta
           )"
    );
    $stmtZonif->execute([
        ':agenda_id' => $agendaId,
        ':id_c1' => $idC1,
    ]);
    $tagsCubiertos = (int)$stmtZonif->fetchColumn();

    // ── TAGs total (no anulados) ────────────────────────────
    $stmtTotalTags = $pdo->prepare(
        "SELECT COUNT(*) FROM sod_inv_tag
         WHERE id_agenda = :agenda_id AND fl_activo = 'S'
           AND estado_tag <> 'ANULADO'"
    );
    $stmtTotalTags->execute([':agenda_id' => $agendaId]);
    $totalTags = (int)$stmtTotalTags->fetchColumn();

    $cumple = $altillosOk && $pdvOk;

    okResponse([
        'altillos' => [
            'total'     => $altillosTotal,
            'completos' => $altillosCompletos,
            'ok'        => $altillosOk,
        ],
        'pdv' => [
            'total'     => $pdvTotal,
            'completos' => $pdvCompletos,
            'objetivo'  => $pdvObjetivo,
            'ok'        => $pdvOk,
        ],
        'tags' => [
            'total'     => $totalTags,
            'cubiertos' => $tagsCubiertos,
        ],
        'cumple_prerequisitos' => $cumple,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al evaluar gating: ' . $e->getMessage(), 500);
}
