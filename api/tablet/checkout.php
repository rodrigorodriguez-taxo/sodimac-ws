<?php
# ============================================================
# ws/api/tablet/checkout.php
# POST { agenda_id, lat?, lng? }
# Marca fin de jornada en la agenda
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['agenda_id'])) {
    errorResponse('Falta agenda_id');
}

$agendaId = $input['agenda_id'];

try {
    $sql = "UPDATE sod_ope_agenda 
        SET fecha_hora_termino = NOW(),
            fecha_modificacion = NOW()
        WHERE id_agenda = :agenda_id
        AND fl_activo = 'S'
        AND fecha_hora_inicio IS NOT NULL
        AND fecha_hora_termino IS NULL";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);

    if ($stmt->rowCount() === 0) {
        errorResponse('Agenda no encontrada, no iniciada, o ya finalizada');
    }

    okResponse(null, 'Check-out registrado');

} catch (PDOException $e) {
    errorResponse('Error en check-out: ' . $e->getMessage(), 500);
}
