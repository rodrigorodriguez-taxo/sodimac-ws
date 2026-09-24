<?php
# ============================================================
# ws/api/tablet/productos/buscar.php
# GET ?agenda_id=123&q=9010696
# Busca productos por SKU o descripción dentro de la muestra de la agenda
# ============================================================

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
$q        = trim($_GET['q'] ?? '');

if (empty($agendaId)) errorResponse('Falta agenda_id');
if (empty($q))        errorResponse('Falta parametro de busqueda (q)');

try {
    $sql = "SELECT 
        p.id_producto,
        p.sku,
        p.descripcion_producto,
        p.codigo_barras
    FROM sod_cfg_producto AS p
    INNER JOIN sod_inv_muestra_det AS md ON md.id_producto = p.id_producto AND md.fl_activo = 'S'
    INNER JOIN sod_inv_agenda_muestra AS am ON am.id_muestra = md.id_muestra AND am.fl_activo = 'S'
    WHERE am.id_agenda = :agenda_id
      AND p.fl_activo = 'S'
      AND (p.sku LIKE :q1 OR p.codigo_barras LIKE :q2 OR p.descripcion_producto LIKE :q3)
    ORDER BY p.sku ASC
    LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':agenda_id' => $agendaId,
        ':q1' => '%' . $q . '%',
        ':q2' => '%' . $q . '%',
        ':q3' => '%' . $q . '%',
    ]);
    $productos = $stmt->fetchAll();

    okResponse([
        'productos' => $productos,
        'total' => count($productos),
    ]);

} catch (PDOException $e) {
    errorResponse('Error al buscar productos: ' . $e->getMessage(), 500);
}
