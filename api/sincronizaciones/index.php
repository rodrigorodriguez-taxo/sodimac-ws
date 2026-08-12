<?php
# ============================================================
# ws/api/sincronizaciones/index.php
# POST { evento_id, pda_id, coordinador_id, tipo, registros_procesados, observaciones }
# Registra una sincronizacion (DESCARGA_A_PDA o CARGA_DESDE_PDA)
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$input = getJsonInput();

$eventoId = filter_var($input['evento_id'] ?? null, FILTER_VALIDATE_INT);
$pdaId = filter_var($input['pda_id'] ?? null, FILTER_VALIDATE_INT);
$coordinadorId = filter_var($input['coordinador_id'] ?? null, FILTER_VALIDATE_INT);
$tipo = $input['tipo'] ?? '';
$registros = filter_var($input['registros_procesados'] ?? null, FILTER_VALIDATE_INT);
$observaciones = !empty($input['observaciones']) ? substr($input['observaciones'], 0, 500) : null;

if (!$eventoId || !$pdaId || !$coordinadorId) {
    errorResponse('Faltan evento_id, pda_id o coordinador_id');
}

if (!in_array($tipo, ['DESCARGA_A_PDA', 'CARGA_DESDE_PDA'], true)) {
    errorResponse('tipo debe ser DESCARGA_A_PDA o CARGA_DESDE_PDA');
}

try {
    $stmt = $pdo->prepare("INSERT INTO sod_sincronizacion
        (evento_id, pda_id, coordinador_id, tipo, fecha_hora, registros_procesados, observaciones)
        VALUES
        (:evento_id, :pda_id, :coordinador_id, :tipo, NOW(), :registros, :observaciones)");

    $stmt->execute([
        ':evento_id'          => $eventoId,
        ':pda_id'             => $pdaId,
        ':coordinador_id'     => $coordinadorId,
        ':tipo'               => $tipo,
        ':registros'          => $registros,
        ':observaciones'      => $observaciones,
    ]);

    okResponse(['id' => (int) $pdo->lastInsertId()], 'Sincronizacion registrada');
} catch (PDOException $e) {
    errorResponse('Error de base de datos', 500);
}
