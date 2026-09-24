<?php
# ============================================================
# ws/api/tablet/tags/anular.php
# POST { tag_id }
# Anula TAG abierto: ABIERTO -> ANULADO
# No permite anular TAGs con capturas
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
    $stmt = $pdo->prepare("SELECT id_tag, estado_tag, numero_tag
                           FROM sod_inv_tag WHERE id_tag = :id");
    $stmt->execute([':id' => $tagId]);
    $tag = $stmt->fetch();

    if (!$tag) {
        errorResponse('TAG no encontrado');
    }

    if ($tag['estado_tag'] !== 'ABIERTO') {
        errorResponse('Solo se pueden anular TAGs con estado ABIERTO. Estado actual: ' . $tag['estado_tag']);
    }

    $capturas = $pdo->prepare("SELECT COUNT(*) AS total
        FROM sod_inv_conteo_det
        WHERE id_tag = :id AND estado_registro = 'VIGENTE'");
    $capturas->execute([':id' => $tagId]);
    $numCapturas = (int) $capturas->fetch()['total'];

    if ($numCapturas > 0) {
        errorResponse('No se puede anular el TAG ' . $tag['numero_tag'] . ': tiene ' . $numCapturas . ' captura(s) registrada(s). Elimine las capturas primero.');
    }

    $login = getBearerToken() ?? 'app_user';

    $upd = $pdo->prepare("UPDATE sod_inv_tag
        SET estado_tag = 'ANULADO',
            fl_activo = 'N',
            fecha_modificacion = NOW(),
            usuario_modificacion = :login
        WHERE id_tag = :id");
    $upd->execute([':login' => $login, ':id' => $tagId]);

    okResponse([
        'id_tag' => (int) $tagId,
        'numero_tag' => (int) $tag['numero_tag'],
        'estado_tag' => 'ANULADO',
    ], 'TAG ' . $tag['numero_tag'] . ' anulado correctamente');

} catch (PDOException $e) {
    errorResponse('Error al anular TAG: ' . $e->getMessage(), 500);
}
