<?php
# ws/api/tablet/informes/ejecutivo.php
# GET ?agenda_id=123
# Informe Ejecutivo del proceso de inventario

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Datos de la agenda
    $stmtAgenda = $pdo->prepare(
        "SELECT a.*, t.nombre_tienda, t.codigo_tienda,
                ea.codigo_estado, ea.nombre_estado
         FROM sod_ope_agenda a
         INNER JOIN sod_cfg_tienda t ON t.id_tienda = a.id_tienda
         INNER JOIN sod_ope_estado_agenda ea ON ea.id_estado_agenda = a.id_estado_agenda
         WHERE a.id_agenda = :agenda_id"
    );
    $stmtAgenda->execute([':agenda_id' => $agendaId]);
    $agenda = $stmtAgenda->fetch();
    if (!$agenda) errorResponse('Agenda no encontrada');

    // 2. Pre-Variance
    $stmtPV = $pdo->prepare(
        "SELECT estado_cierre, fecha_confirmacion, login_confirmacion
         FROM sod_inv_prevariance_cierre
         WHERE id_agenda = :agenda_id
         ORDER BY id_cierre_prevariance DESC LIMIT 1"
    );
    $stmtPV->execute([':agenda_id' => $agendaId]);
    $pv = $stmtPV->fetch();

    // 3. Recuento
    $stmtRC = $pdo->prepare(
        "SELECT estado_cierre, total_sku, total_corregidos, fecha_cierre, login_cierre
         FROM sod_inv_reconteo_cierre
         WHERE id_agenda = :agenda_id AND fl_activo = 'S'
         ORDER BY numero_version DESC LIMIT 1"
    );
    $stmtRC->execute([':agenda_id' => $agendaId]);
    $rc = $stmtRC->fetch();

    // 4. Snapshot
    $stmtSnap = $pdo->prepare(
        "SELECT * FROM sod_inv_cierre_agenda
         WHERE id_agenda = :agenda_id AND fl_inmutable = 'S'
         ORDER BY numero_version DESC LIMIT 1"
    );
    $stmtSnap->execute([':agenda_id' => $agendaId]);
    $snap = $stmtSnap->fetch();

    // 5. Verificacion de integridad
    $stmtVer = $pdo->prepare(
        "SELECT estado_verificacion, fecha_verificacion, login_verificacion
         FROM sod_inv_cierre_verificacion
         WHERE id_cierre_agenda = :id_cierre
         ORDER BY id_verificacion DESC LIMIT 1"
    );
    if ($snap) {
        $stmtVer->execute([':id_cierre' => $snap['id_cierre_agenda']]);
    } else {
        $stmtVer->execute([':id_cierre' => 0]);
    }
    $verificacion = $stmtVer->fetch();

    // 6. TAGs
    $stmtTags = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COUNT(CASE WHEN fl_activo = 'S' THEN 1 END) AS activos,
                COUNT(CASE WHEN estado_tag = 'ANULADO' THEN 1 END) AS anulados
         FROM sod_inv_tag WHERE id_agenda = :agenda_id"
    );
    $stmtTags->execute([':agenda_id' => $agendaId]);
    $tags = $stmtTags->fetch();

    // 7. Capturas
    $stmtCapt = $pdo->prepare(
        "SELECT
            COUNT(CASE WHEN c.numero_iteracion = 1 THEN 1 END) AS capturas_c1,
            COUNT(CASE WHEN c.numero_iteracion = 2 THEN 1 END) AS capturas_c2,
            COUNT(CASE WHEN c.numero_iteracion = 3 THEN 1 END) AS capturas_c3
         FROM sod_inv_conteo_det d
         INNER JOIN sod_inv_conteo c ON c.id_conteo = d.id_conteo
         WHERE c.id_agenda = :agenda_id AND c.fl_activo = 'S'
           AND d.estado_registro = 'VIGENTE'"
    );
    $stmtCapt->execute([':agenda_id' => $agendaId]);
    $capturas = $stmtCapt->fetch();

    okResponse([
        'agenda' => [
            'id' => (int)$agenda['id_agenda'],
            'numero' => $agenda['numero_agenda'],
            'tienda' => $agenda['nombre_tienda'],
            'codigo_tienda' => $agenda['codigo_tienda'],
            'fecha' => $agenda['fecha_agenda'],
            'estado' => $agenda['codigo_estado'],
            'nombre_estado' => $agenda['nombre_estado'],
        ],
        'etapas' => [
            'pre_variance' => $pv ? [
                'estado' => $pv['estado_cierre'],
                'fecha' => $pv['fecha_confirmacion'],
                'login' => $pv['login_confirmacion'],
            ] : null,
            'recuento' => $rc ? [
                'estado' => $rc['estado_cierre'],
                'total_sku' => (int)$rc['total_sku'],
                'total_corregidos' => (int)$rc['total_corregidos'],
                'fecha' => $rc['fecha_cierre'],
                'login' => $rc['login_cierre'],
            ] : null,
            'cierre_final' => $snap ? [
                'codigo' => $snap['codigo_cierre'],
                'version' => (int)$snap['numero_version'],
                'cantidad_sku' => (int)$snap['cantidad_sku'],
                'total_stock' => (float)$snap['total_stock_unidades'],
                'total_fisico' => (float)$snap['total_fisico_unidades'],
                'total_diferencia' => (float)$snap['total_diferencia_unidades'],
                'total_diferencia_valor' => (float)$snap['total_diferencia_valor'],
                'hash' => $snap['hash_cabecera'],
                'inmutable' => $snap['fl_inmutable'],
                'fecha' => $snap['fecha_cierre'],
                'login' => $snap['login_cierre'],
            ] : null,
        ],
        'tags' => [
            'total' => (int)$tags['total'],
            'activos' => (int)$tags['activos'],
            'anulados' => (int)$tags['anulados'],
        ],
        'capturas' => [
            'c1' => (int)$capturas['capturas_c1'],
            'c2' => (int)$capturas['capturas_c2'],
            'c3' => (int)$capturas['capturas_c3'],
        ],
        'integridad' => $verificacion ? [
            'estado' => $verificacion['estado_verificacion'],
            'fecha' => $verificacion['fecha_verificacion'],
            'login' => $verificacion['login_verificacion'],
        ] : null,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al generar informe ejecutivo: ' . $e->getMessage(), 500);
}
