<?php
# ============================================================
# ws/api/tablet/checkout_mockup.php
# POST { id_agenda }
# Mockup controlado — simula check-out de auditoría.
# ============================================================

require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

if (empty($input['id_agenda'])) {
    errorResponse('Falta id_agenda');
}

okResponse([
    'id_agenda'    => $input['id_agenda'],
    'checkout_at'  => date('Y-m-d\TH:i:s'),
    'estado'       => 'COMPLETADA',
], 'Check-out registrado exitosamente');
