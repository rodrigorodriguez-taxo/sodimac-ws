<?php
# ============================================================
# ws/api/tablet/validacion/confirmar.php
# GET ?agenda_id=123&tag_id=456
# Verifica si un TAG está completamente validado
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

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
    // Obtener C1
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

    // Obtener C2
    $stmtC2 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 2
           AND tipo_conteo = 'VALIDACION' AND fl_activo = 'S'
           AND estado_conteo <> 'ANULADO'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC2->execute([':agenda_id' => $agendaId]);
    $c2 = $stmtC2->fetch();
    $idC2 = $c2 ? (int)$c2['id_conteo'] : null;

    // Contar capturas C1 del TAG
    $stmtTotal = $pdo->prepare(
        "SELECT COUNT(*) AS total
         FROM sod_inv_conteo_det
         WHERE id_conteo = :id_c1 AND id_tag = :tag_id
           AND estado_registro = 'VIGENTE'"
    );
    $stmtTotal->execute([':id_c1' => $idC1, ':tag_id' => $tagId]);
    $total = (int)$stmtTotal->fetchColumn();

    // Contar capturas C1 del TAG que tienen C2 válido
    $stmtConfirmadas = $pdo->prepare(
        "SELECT COUNT(DISTINCT d1.id_producto) AS confirmadas
         FROM sod_inv_conteo_det AS d1
         WHERE d1.id_conteo = :id_c1
           AND d1.id_tag = :tag_id
           AND d1.estado_registro = 'VIGENTE'
           AND EXISTS (
               SELECT 1 FROM sod_inv_conteo_det AS d2
               WHERE d2.id_conteo = :id_c2
                 AND d2.id_tag = d1.id_tag
                 AND d2.id_producto = d1.id_producto
                 AND d2.id_reconteo IS NULL
                 AND d2.origen = 'SGO_ANALISTA'
                 AND d2.estado_registro = 'VIGENTE'
           )"
    );
    $stmtConfirmadas->execute([
        ':id_c1' => $idC1,
        ':tag_id' => $tagId,
        ':id_c2' => $idC2 ?? 0,
    ]);
    $confirmadas = (int)$stmtConfirmadas->fetchColumn();

    $completado = ($total > 0 && $confirmadas >= $total);

    okResponse([
        'tag_id'        => (int)$tagId,
        'total_capturas'=> $total,
        'validadas'     => $confirmadas,
        'completado'    => $completado,
        'porcentaje'    => $total > 0 ? round(($confirmadas / $total) * 100) : 0,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al verificar TAG: ' . $e->getMessage(), 500);
}
