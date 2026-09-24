<?php
# ============================================================
# ws/api/tablet/tags/crear.php
# POST { agenda_id, numero_tag, id_tipo_ubicacion }
# Crea un TAG nuevo dentro de un rango de zonificacion
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();
$agendaId = $input['agenda_id'] ?? 0;
$numeroTag = $input['numero_tag'] ?? 0;
$idTipoUbicacion = $input['id_tipo_ubicacion'] ?? 0;

if (!$agendaId) errorResponse('Falta agenda_id');
if (!$numeroTag) errorResponse('Falta numero_tag');
if (!$idTipoUbicacion) errorResponse('Falta id_tipo_ubicacion');

try {
    $pdo->beginTransaction();

    $existe = $pdo->prepare("SELECT COUNT(*) AS total
        FROM sod_inv_tag
        WHERE id_agenda = :agenda AND numero_tag = :tag AND fl_activo = 'S'");
    $existe->execute([':agenda' => $agendaId, ':tag' => $numeroTag]);
    if ((int) $existe->fetch()['total'] > 0) {
        $pdo->rollBack();
        errorResponse('El TAG ' . $numeroTag . ' ya existe para esta agenda');
    }

    $rango = $pdo->prepare("SELECT zd.id_zonificacion_det, zd.descripcion,
                                   tu.id_tipo_ubicacion
                            FROM sod_ope_agenda_zonificacion_det zd
                            JOIN sod_ope_agenda_zonificacion z
                                ON zd.id_zonificacion = z.id_zonificacion
                            LEFT JOIN sod_cfg_tipo_ubicacion tu
                                ON zd.descripcion = tu.descripcion
                            WHERE z.id_agenda = :agenda
                              AND z.fl_activo = 'S'
                              AND zd.fl_activo = 'S'
                              AND zd.tag_desde <= :tag
                              AND zd.tag_hasta >= :tag2
                            LIMIT 1");
    $rango->execute([':agenda' => $agendaId, ':tag' => $numeroTag, ':tag2' => $numeroTag]);
    $rangoRow = $rango->fetch();

    if (!$rangoRow) {
        $pdo->rollBack();
        errorResponse('El TAG ' . $numeroTag . ' no pertenece a ningun rango configurado para esta agenda');
    }

    $tipoFromRange = (int) $rangoRow['id_tipo_ubicacion'];
    $finalTipo = $tipoFromRange > 0 ? $tipoFromRange : (int) $idTipoUbicacion;

    $login = getBearerToken() ?? 'app_user';

    $ins = $pdo->prepare("INSERT INTO sod_inv_tag
        (id_agenda, numero_tag, id_tipo_ubicacion, estado_tag, fl_activo,
         fecha_hora_primer_uso, login_primer_uso, usuario_creacion, fecha_creacion)
        VALUES
        (:agenda, :tag, :tipo, 'ABIERTO', 'S', NOW(), :login1, :login2, NOW())");
    $ins->execute([
        ':agenda' => $agendaId,
        ':tag' => $numeroTag,
        ':tipo' => $finalTipo,
        ':login1' => $login,
        ':login2' => $login,
    ]);

    $newId = $pdo->lastInsertId();

    $pdo->commit();

    okResponse([
        'id_tag' => (int) $newId,
        'numero_tag' => (int) $numeroTag,
        'id_tipo_ubicacion' => (int) $finalTipo,
        'descripcion_zona' => $rangoRow['descripcion'],
        'estado_tag' => 'ABIERTO',
    ], 'TAG ' . $numeroTag . ' creado correctamente');

} catch (PDOException $e) {
    $pdo->rollBack();
    errorResponse('Error al crear TAG: ' . $e->getMessage(), 500);
}
