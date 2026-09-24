<?php
# ============================================================
# ws/api/tablet/tag-detalle.php
# GET ?agenda_id=123&tag_id=456
# Retorna detalle de TAG con capturas C1 para validación
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
$tagId    = $_GET['tag_id'] ?? '';

if (empty($agendaId) || empty($tagId)) {
    errorResponse('Falta agenda_id o tag_id');
}

try {
    // Obtener TAG
    $sqlTag = "SELECT
        t.id_tag,
        t.numero_tag,
        t.id_tipo_ubicacion,
        t.estado_tag,
        COALESCE(tu.codigo_tipo_ubicacion, '') AS codigo_tipo,
        COALESCE(tu.nombre_tipo_ubicacion, '') AS nombre_tipo,
        CASE
            WHEN LOWER(tu.nombre_tipo_ubicacion) LIKE '%altillo%' THEN 'ALTILLO'
            WHEN LOWER(tu.nombre_tipo_ubicacion) LIKE '%punto de venta%'
              OR LOWER(tu.nombre_tipo_ubicacion) LIKE '%punto venta%'
              OR tu.codigo_tipo_ubicacion = 'PDV' THEN 'PDV'
            ELSE 'OTRO'
        END AS tipo_validacion
    FROM sod_inv_tag AS t
    LEFT JOIN sod_cfg_tipo_ubicacion AS tu ON tu.id_tipo_ubicacion = t.id_tipo_ubicacion
    WHERE t.id_tag = :tag_id
      AND t.id_agenda = :agenda_id
    LIMIT 1";

    $stmtTag = $pdo->prepare($sqlTag);
    $stmtTag->execute([':tag_id' => $tagId, ':agenda_id' => $agendaId]);
    $tag = $stmtTag->fetch();

    if (!$tag) {
        errorResponse('TAG no encontrado', 404);
    }

    // Obtener C1 (Conteo 1)
    $sqlC1 = "SELECT c.id_conteo
    FROM sod_inv_conteo AS c
    WHERE c.id_agenda = :agenda_id
      AND c.numero_iteracion = 1
      AND c.tipo_conteo = 'INICIAL'
      AND c.fl_activo = 'S'
    ORDER BY c.id_conteo DESC
    LIMIT 1";

    $stmtC1 = $pdo->prepare($sqlC1);
    $stmtC1->execute([':agenda_id' => $agendaId]);
    $c1 = $stmtC1->fetch();

    if (!$c1) {
        errorResponse('No existe Conteo 1 para esta agenda');
    }

    $idC1 = $c1['id_conteo'];

    // Obtener C2 (Conteo 2 — VALIDACION)
    $sqlC2 = "SELECT c.id_conteo
    FROM sod_inv_conteo AS c
    WHERE c.id_agenda = :agenda_id
      AND c.numero_iteracion = 2
      AND c.tipo_conteo = 'VALIDACION'
      AND c.fl_activo = 'S'
      AND c.estado_conteo <> 'ANULADO'
    ORDER BY c.id_conteo DESC
    LIMIT 1";

    $stmtC2 = $pdo->prepare($sqlC2);
    $stmtC2->execute([':agenda_id' => $agendaId]);
    $c2 = $stmtC2->fetch();
    $idC2 = $c2 ? $c2['id_conteo'] : null;

    // Obtener capturas C1 del TAG
    $sqlC1 = "SELECT
        d1.id_conteo_det,
        d1.id_producto,
        p.sku AS producto_sku,
        p.descripcion_producto AS producto_nombre,
        d1.cantidad AS c1_cantidad,
        d1.fecha_hora_captura,
        d1.login_operador,
        d1.estado_registro
    FROM sod_inv_conteo_det AS d1
    INNER JOIN sod_cfg_producto AS p ON p.id_producto = d1.id_producto
    WHERE d1.id_conteo = :id_c1
      AND d1.id_tag = :tag_id
      AND d1.estado_registro = 'VIGENTE'
    ORDER BY d1.fecha_hora_captura ASC";

    $stmtC1 = $pdo->prepare($sqlC1);
    $stmtC1->execute([':id_c1' => $idC1, ':tag_id' => $tagId]);
    $capturas = $stmtC1->fetchAll();

    // Obtener productos C1 para excluir del segundo query
    $idsC1 = array_column($capturas, 'id_producto');

    // Obtener items incorporados solo en C2 (no existen en C1)
    if ($idC2) {
        if (!empty($idsC1)) {
            $placeholders = implode(',', array_fill(0, count($idsC1), '?'));
            $sqlInc = "SELECT
                d2.id_conteo_det,
                d2.id_producto,
                p.sku AS producto_sku,
                p.descripcion_producto AS producto_nombre,
                0 AS c1_cantidad,
                d2.fecha_hora_captura,
                d2.login_operador,
                d2.estado_registro
            FROM sod_inv_conteo_det AS d2
            INNER JOIN sod_cfg_producto AS p ON p.id_producto = d2.id_producto
            WHERE d2.id_conteo = ?
              AND d2.id_tag = ?
              AND d2.id_reconteo IS NULL
              AND d2.origen = 'SGO_ANALISTA'
              AND d2.estado_registro = 'VIGENTE'
              AND d2.id_producto NOT IN ($placeholders)
              AND d2.id_origen_externo LIKE ?";

            $paramsInc = array_merge([$idC2, $tagId], $idsC1, ['SGO-VAL-TAG-INC-%']);
            $stmtInc = $pdo->prepare($sqlInc);
            $stmtInc->execute($paramsInc);
            $incorporadas = $stmtInc->fetchAll();
            $capturas = array_merge($capturas, $incorporadas);
        } else {
            $sqlInc2 = "SELECT
                d2.id_conteo_det,
                d2.id_producto,
                p.sku AS producto_sku,
                p.descripcion_producto AS producto_nombre,
                0 AS c1_cantidad,
                d2.fecha_hora_captura,
                d2.login_operador,
                d2.estado_registro
            FROM sod_inv_conteo_det AS d2
            INNER JOIN sod_cfg_producto AS p ON p.id_producto = d2.id_producto
            WHERE d2.id_conteo = :id_c2
              AND d2.id_tag = :tag_id2
              AND d2.id_reconteo IS NULL
              AND d2.origen = 'SGO_ANALISTA'
              AND d2.estado_registro = 'VIGENTE'
              AND d2.id_origen_externo LIKE :patron";

            $stmtInc2 = $pdo->prepare($sqlInc2);
            $stmtInc2->execute([':id_c2' => $idC2, ':tag_id2' => $tagId, ':patron' => 'SGO-VAL-TAG-INC-%']);
            $incorporadas = $stmtInc2->fetchAll();
            $capturas = array_merge($capturas, $incorporadas);
        }
    }

    // Para cada captura, buscar si tiene C2 (validación)
    $capturasConEstado = [];
    $totalConfirmadas = 0;
    $totalModificadas = 0;
    $totalPendientes = 0;

    foreach ($capturas as $cap) {
        $estado = 'PENDIENTE';
        $c2Cant = null;
        $motivo = null;

        // Item incorporado (solo existe en C2, c1_cantidad = 0)
        if ((float)$cap['c1_cantidad'] === 0.0 && $idC2) {
            $sqlC2Inc = "SELECT
                d2.cantidad AS c2_cantidad,
                d2.observacion
            FROM sod_inv_conteo_det AS d2
            WHERE d2.id_conteo = :id_c2
              AND d2.id_tag = :tag_id
              AND d2.id_producto = :id_producto
              AND d2.id_reconteo IS NULL
              AND d2.origen = 'SGO_ANALISTA'
              AND d2.estado_registro = 'VIGENTE'
              AND d2.id_origen_externo LIKE 'SGO-VAL-TAG-INC-%'
            LIMIT 1";

            $stmtC2Inc = $pdo->prepare($sqlC2Inc);
            $stmtC2Inc->execute([
                ':id_c2' => $idC2,
                ':tag_id' => $tagId,
                ':id_producto' => $cap['id_producto'],
            ]);
            $c2Det = $stmtC2Inc->fetch();

            if ($c2Det) {
                $estado = 'MODIFICADA';
                $totalModificadas++;
                $c2Cant = $c2Det['c2_cantidad'];
                $motivo = $c2Det['observacion'];
            } else {
                $totalPendientes++;
            }
        } elseif ($idC2) {
            // Item normal (C1 + posible C2)
            $sqlC2Det = "SELECT
                d2.cantidad AS c2_cantidad,
                d2.observacion,
                d2.id_motivo_correccion
            FROM sod_inv_conteo_det AS d2
            WHERE d2.id_conteo = :id_c2
              AND d2.id_tag = :tag_id
              AND d2.id_producto = :id_producto
              AND d2.id_reconteo IS NULL
              AND d2.origen = 'SGO_ANALISTA'
              AND d2.estado_registro = 'VIGENTE'
            ORDER BY d2.id_conteo_det DESC
            LIMIT 1";

            $stmtC2Det = $pdo->prepare($sqlC2Det);
            $stmtC2Det->execute([
                ':id_c2' => $idC2,
                ':tag_id' => $tagId,
                ':id_producto' => $cap['id_producto'],
            ]);
            $c2Det = $stmtC2Det->fetch();

            if ($c2Det) {
                if ((float)$c2Det['c2_cantidad'] === (float)$cap['c1_cantidad']) {
                    $estado = 'CONFIRMADA';
                    $totalConfirmadas++;
                } else {
                    $estado = 'MODIFICADA';
                    $totalModificadas++;
                }
                $c2Cant = $c2Det['c2_cantidad'];
                $motivo = $c2Det['observacion'];
            } else {
                $totalPendientes++;
            }
        } else {
            $totalPendientes++;
        }

        $capturasConEstado[] = [
            'id_conteo_det_c1' => (int)$cap['id_conteo_det'],
            'id_producto'      => (int)$cap['id_producto'],
            'producto_sku'     => $cap['producto_sku'],
            'producto_nombre'  => $cap['producto_nombre'],
            'c1_cantidad'      => (float)$cap['c1_cantidad'],
            'c2_cantidad'      => $c2Cant !== null ? (float)$c2Cant : null,
            'estado'           => $estado,
            'motivo'           => $motivo,
            'login_operador'   => $cap['login_operador'],
            'fecha_captura'    => $cap['fecha_hora_captura'],
        ];
    }

    okResponse([
        'tag' => $tag,
        'capturas' => $capturasConEstado,
        'total_pendientes'  => $totalPendientes,
        'total_confirmadas' => $totalConfirmadas,
        'total_modificadas' => $totalModificadas,
        'id_conteo_c1' => (int)$idC1,
        'id_conteo_c2' => $idC2 ? (int)$idC2 : null,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener tag: ' . $e->getMessage(), 500);
}
