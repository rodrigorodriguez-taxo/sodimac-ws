<?php
# ============================================================
# ws/api/tablet/etapa.php
# GET ?agenda_id=1
# Retorna etapa actual de la agenda
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) {
    errorResponse('Falta agenda_id');
}

try {
    $sql = "SELECT
        a.id_agenda,
        a.id_estado_agenda,
        ea.codigo_estado,
        ea.nombre_estado,
        ea.grupo_visual,
        ea.fl_permite_captura,
        ea.fl_permite_reconteo,
        ea.fl_permite_cierre
    FROM sod_ope_agenda AS a
    INNER JOIN sod_ope_estado_agenda AS ea ON a.id_estado_agenda = ea.id_estado_agenda
    WHERE a.id_agenda = :agenda_id
    AND a.fl_activo = 'S'
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);
    $agenda = $stmt->fetch();

    if (!$agenda) {
        errorResponse('Agenda no encontrada', 404);
    }

    $permiteValidacion = ($agenda['fl_permite_captura'] === 'S');
    $motivoBloqueo = null;

    if (!$permiteValidacion) {
        $motivoBloqueo = 'Etapa "' . $agenda['nombre_estado'] . '" no permite validacion';
    }

    okResponse([
        'etapa_actual'      => $agenda['codigo_estado'],
        'nombre_estado'     => $agenda['nombre_estado'],
        'grupo_visual'      => $agenda['grupo_visual'],
        'permite_validacion' => $permiteValidacion,
        'permite_reconteo'  => ($agenda['fl_permite_reconteo'] === 'S'),
        'permite_cierre'    => ($agenda['fl_permite_cierre'] === 'S'),
        'motivo_bloqueo'    => $motivoBloqueo,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al obtener etapa: ' . $e->getMessage(), 500);
}
