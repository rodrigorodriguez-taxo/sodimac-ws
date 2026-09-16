<?php

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

$idAgenda = isset($input['id_agenda']) ? (int) $input['id_agenda'] : 0;

if ($idAgenda <= 0) {
    errorResponse('Falta id_agenda o valor invalido');
}

try {
    $sql = "SELECT
        CASE
            WHEN cpv.id_cierre_prevariance IS NULL
                THEN 'SIN_REGISTRO'
            WHEN EXISTS (
                SELECT 1
                FROM sod_rep_zip_envio AS e
                WHERE e.id_agenda = a.id_agenda
                  AND e.id_zip = cpv.id_zip
                  AND e.estado_envio = 'OK'
            )
                THEN 'ENVIADO'
            ELSE 'PENDIENTE'
        END AS estado_correo_prevariance
    FROM sod_ope_agenda AS a
    LEFT JOIN sod_inv_prevariance_cierre AS cpv
           ON cpv.id_cierre_prevariance = (
                SELECT MAX(cpv2.id_cierre_prevariance)
                FROM sod_inv_prevariance_cierre AS cpv2
                WHERE cpv2.id_agenda = a.id_agenda
           )
    WHERE a.id_agenda = :id_agenda
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_agenda' => $idAgenda]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        errorResponse('Agenda no encontrada', 404);
    }

    jsonResponse([
        'estado_correo_prevariance' => $row['estado_correo_prevariance'],
    ]);

} catch (PDOException $e) {
    errorResponse('Error de base de datos', 500);
}
