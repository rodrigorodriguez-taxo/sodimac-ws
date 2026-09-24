<?php
# ============================================================
# ws/api/tablet/tags/cerrar.php
# POST { tag_id }
# Cierra TAG validado: ABIERTO -> FINALIZADO
# Verifica que todas las capturas C1 tengan C2 antes de cerrar
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();
$tagId = $input['tag_id'] ?? 0;

if (!$tagId) {
    errorResponse('Falta tag_id');
}

try {
    $stmt = $pdo->prepare("SELECT id_tag, id_agenda, estado_tag, numero_tag
                           FROM sod_inv_tag WHERE id_tag = :id");
    $stmt->execute([':id' => $tagId]);
    $tag = $stmt->fetch();

    if (!$tag) {
        errorResponse('TAG no encontrado');
    }

    if ($tag['estado_tag'] !== 'ABIERTO') {
        errorResponse('Solo se pueden cerrar TAGs con estado ABIERTO. Estado actual: ' . $tag['estado_tag']);
    }

    // ── Verificar completitud C1 vs C2 ──────────────────────
    $agendaId = (int)$tag['id_agenda'];

    // Obtener C1
    $stmtC1 = $pdo->prepare(
        "SELECT id_conteo FROM sod_inv_conteo
         WHERE id_agenda = :agenda_id AND numero_iteracion = 1
           AND tipo_conteo = 'INICIAL' AND fl_activo = 'S'
         ORDER BY id_conteo DESC LIMIT 1"
    );
    $stmtC1->execute([':agenda_id' => $agendaId]);
    $c1 = $stmtC1->fetch();
    if (!$c1) errorResponse('No existe Conteo 1 para esta agenda');
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

    if ($total === 0) {
        errorResponse('Este TAG no tiene capturas C1 para cerrar');
    }

    if ($confirmadas < $total) {
        $faltantes = $total - $confirmadas;
        errorResponse("TAG incompleto: {$confirmadas}/{$total} productos validados. Faltan {$faltantes} productos por validar.");
    }

    // ── Cerrar TAG ──────────────────────────────────────────
    $login = getBearerToken() ?? 'app_user';

    $upd = $pdo->prepare("UPDATE sod_inv_tag
        SET estado_tag = 'FINALIZADO',
            fecha_modificacion = NOW(),
            usuario_modificacion = :login
        WHERE id_tag = :id");
    $upd->execute([':login' => $login, ':id' => $tagId]);

    okResponse([
        'id_tag' => (int) $tagId,
        'numero_tag' => (int) $tag['numero_tag'],
        'estado_tag' => 'FINALIZADO',
    ], 'TAG ' . $tag['numero_tag'] . ' cerrado correctamente');

} catch (PDOException $e) {
    errorResponse('Error al cerrar TAG: ' . $e->getMessage(), 500);
}
