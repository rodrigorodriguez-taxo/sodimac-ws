<?php
# ============================================================
# ws/api/tablet/tags/reactivar.php
# POST { tag_id }
# Reactiva TAG anulado: ANULADO -> ABIERTO
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
    $stmt = $pdo->prepare("SELECT id_tag, estado_tag, numero_tag, fl_activo
                           FROM sod_inv_tag WHERE id_tag = :id");
    $stmt->execute([':id' => $tagId]);
    $tag = $stmt->fetch();

    if (!$tag) {
        errorResponse('TAG no encontrado');
    }

    if ($tag['estado_tag'] !== 'ANULADO') {
        errorResponse('Solo se pueden reactivar TAGs con estado ANULADO. Estado actual: ' . $tag['estado_tag']);
    }

    $login = getBearerToken() ?? 'app_user';

    $upd = $pdo->prepare("UPDATE sod_inv_tag
        SET estado_tag = 'ABIERTO',
            fl_activo = 'S',
            fecha_modificacion = NOW(),
            usuario_modificacion = :login
        WHERE id_tag = :id");
    $upd->execute([':login' => $login, ':id' => $tagId]);

    okResponse([
        'id_tag' => (int) $tagId,
        'numero_tag' => (int) $tag['numero_tag'],
        'estado_tag' => 'ABIERTO',
    ], 'TAG ' . $tag['numero_tag'] . ' reactivado correctamente');

} catch (PDOException $e) {
    errorResponse('Error al reactivar TAG: ' . $e->getMessage(), 500);
}
