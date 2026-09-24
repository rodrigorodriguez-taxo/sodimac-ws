<?php
# ============================================================
# ws/api/tablet/validar-acceso.php
# GET ?agenda_id=1&rut=12345678-5
# Verifica si el usuario tiene acceso a la agenda
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
$rut = $_GET['rut'] ?? '';

if (empty($agendaId)) {
    errorResponse('Falta agenda_id');
}

if (empty($rut)) {
    errorResponse('Falta rut');
}

$rutNormalizado = preg_replace('/[^0-9kK]/', '', trim($rut));

try {
    $sql = "SELECT
        au.id_agenda_usuario,
        au.rol_agenda,
        au.fl_activo
    FROM sod_ope_agenda_usuario AS au
    INNER JOIN sod_sec_usuario_ext AS ue
        ON ue.login COLLATE utf8mb4_0900_ai_ci = au.login
    WHERE au.id_agenda = :agenda_id
    AND ue.rut_normalizado = :rut
    AND au.fl_activo = 'S'
    AND ue.fl_activo = 'S'
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':agenda_id' => $agendaId,
        ':rut' => $rutNormalizado,
    ]);
    $acceso = $stmt->fetch();

    okResponse([
        'acceso' => ($acceso !== false),
        'rol'    => $acceso ? $acceso['rol_agenda'] : null,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al validar acceso: ' . $e->getMessage(), 500);
}
