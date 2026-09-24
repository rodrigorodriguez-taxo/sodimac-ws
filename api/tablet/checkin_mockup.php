<?php
# ============================================================
# ws/api/tablet/checkin_mockup.php
# POST { id_agenda, lat, lng }
# Mockup controlado — simula check-in de auditoría.
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
    'id_agenda'   => $input['id_agenda'],
    'checkin_at'  => date('Y-m-d\TH:i:s'),
    'lat'         => $input['lat'] ?? -33.0472,
    'lng'         => $input['lng'] ?? -71.6125,
    'estado'      => 'EN_CURSO',
], 'Check-in registrado exitosamente');
