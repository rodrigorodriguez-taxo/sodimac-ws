<?php
# ws/api/tablet/informes/captura-final.php
# GET ?agenda_id=123
# Informe de Captura Final — réplica blank_sod_inf_captura_final (detalle por fila)

require_once '../../../config/database.php';
require_once '../../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$agendaId = $_GET['agenda_id'] ?? '';
if (empty($agendaId)) errorResponse('Falta agenda_id');

try {
    // 1. Contexto de la agenda
    $stmtCtx = $pdo->prepare(
        "SELECT c.id_agenda, c.numero_agenda, c.fecha_agenda,
                c.codigo_tienda, c.nombre_tienda, c.codigo_estado,
                c.fecha_hora_inicio, c.fecha_hora_termino
         FROM vw_sod_rep_agenda_contexto AS c
         WHERE c.id_agenda = :agenda_id
         LIMIT 1"
    );
    $stmtCtx->execute([':agenda_id' => $agendaId]);
    $contexto = $stmtCtx->fetch();
    if (!$contexto) errorResponse('Agenda no encontrada');

    // 2. Detalle de capturas (una fila por conteo_det)
    $stmtDet = $pdo->prepare(
        "SELECT
            cd.id_conteo_det,
            t.numero_tag,
            p.sku,
            p.codigo_barras,
            p.descripcion_producto,
            cd.cantidad,
            cd.fecha_hora_captura,
            cd.login_operador,
            c.numero_iteracion,
            c.tipo_conteo,
            COALESCE(
                NULLIF(TRIM(su.rut), ''),
                cd.login_operador
            ) AS rut_sdv,
            cd.origen,
            cd.dispositivo
         FROM sod_inv_conteo_det AS cd
         INNER JOIN sod_inv_conteo AS c
                 ON c.id_conteo = cd.id_conteo
                AND c.fl_activo = 'S'
         INNER JOIN sod_inv_tag AS t
                 ON t.id_tag = cd.id_tag
         INNER JOIN sod_cfg_producto AS p
                 ON p.id_producto = cd.id_producto
         LEFT JOIN sec_users AS su
                ON CONVERT(su.login USING utf8mb4)
                   COLLATE utf8mb4_unicode_ci
                   =
                   cd.login_operador
                   COLLATE utf8mb4_unicode_ci
         WHERE cd.id_agenda = :agenda_id
           AND cd.estado_registro = 'VIGENTE'
         ORDER BY cd.fecha_hora_captura, cd.id_conteo_det"
    );
    $stmtDet->execute([':agenda_id' => $agendaId]);
    $capturas = $stmtDet->fetchAll();

    // 3. Formatear RUT sin puntos
    $fmtRut = function ($rut) {
        $s = trim((string)$rut);
        if ($s === '') return '';
        $s = str_replace('.', '', $s);
        $s = str_replace(' ', '', $s);
        return $s;
    };

    foreach ($capturas as $i => $r) {
        $capturas[$i]['rut_sdv'] = $fmtRut($r['rut_sdv']);
    }

    // 4. Resumen
    $totalUnidades = 0.0;
    $tagsUnicos = [];
    $skuUnicos = [];

    foreach ($capturas as $r) {
        $totalUnidades += (float)$r['cantidad'];
        $tagsUnicos[(string)$r['numero_tag']] = true;
        $skuUnicos[(string)$r['sku']] = true;
    }

    okResponse([
        'contexto' => $contexto,
        'resumen' => [
            'registros' => count($capturas),
            'tags_unicos' => count($tagsUnicos),
            'sku_unicos' => count($skuUnicos),
            'total_unidades' => $totalUnidades,
        ],
        'capturas' => $capturas,
    ]);

} catch (PDOException $e) {
    errorResponse('Error al generar informe: ' . $e->getMessage(), 500);
}
