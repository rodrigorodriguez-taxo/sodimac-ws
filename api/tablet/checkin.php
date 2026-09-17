<?php
# ============================================================
# ws/api/tablet/checkin.php
# POST { agenda_id, lat?, lng? }
# Marca inicio de jornada en la agenda
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
        SET fecha_hora_inicio = NOW(),
            fecha_modificacion = NOW()
        WHERE id_agenda = :agenda_id
        AND fl_activo = 'S'
        AND fecha_hora_inicio IS NULL";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':agenda_id' => $agendaId]);

    if ($stmt->rowCount() === 0) {
        errorResponse('Agenda no encontrada o ya iniciada');
    }

    okResponse(null, 'Check-in registrado');

} catch (PDOException $e) {
    errorResponse('Error en check-in: ' . $e->getMessage(), 500);
}
