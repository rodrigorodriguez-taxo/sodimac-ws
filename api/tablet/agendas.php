<?php
# ============================================================
# ws/api/tablet/agendas.php
# GET ?rut=12345678-5
# Retorna agendas del auditor
# ============================================================

require_once '../../config/database.php';
require_once '../../helpers/response.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Metodo no permitido', 405);
}

$rut = $_GET['rut'] ?? '';
if (empty($rut)) {
    errorResponse('Falta rut del auditor');
}

$rutNormalizado = preg_replace('/[^0-9kK]/', '', trim($rut));

try {
    // Buscar el login del usuario por RUT
    $stmtUser = $pdo->prepare(
        "SELECT login FROM sod_sec_usuario_ext WHERE rut_normalizado = :rut AND fl_activo = 'S' LIMIT 1"
    );
    $stmtUser->execute([':rut' => $rutNormalizado]);
    $user = $stmtUser->fetch();

    if (!$user) {
        errorResponse('Usuario no encontrado', 404);
    }

    $login = $user['login'];

    $sql = "SELECT 
        a.id_agenda AS id,
        DATE_FORMAT(a.fecha_agenda, '%Y-%m-%d') AS fecha,
        a.id_tienda,
        t.nombre_tienda AS tienda_nombre,
        t.direccion AS tienda_direccion,
        a.numero_agenda,
        e.codigo_estado AS estado,
        a.fecha_hora_inicio AS checkin_at,
        a.fecha_hora_termino AS checkout_at,
        a.fecha_hora_cierre,
        a.titulo_agenda,
        a.categoria_muestra,
        a.ultima_sincronizacion,
        a.fl_incidencia,
        a.observacion,
        COUNT(DISTINCT tg.id_tag) AS total_tags,
        COUNT(DISTINCT CASE WHEN EXISTS (
            SELECT 1 FROM sod_inv_conteo_det cd
            INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
            WHERE cd.id_tag = tg.id_tag AND c.tipo_conteo = 'INICIAL' AND cd.estado_registro = 'VIGENTE'
        ) THEN tg.id_tag END) AS tags_contados,
        COUNT(DISTINCT CASE WHEN EXISTS (
            SELECT 1 FROM sod_inv_conteo_det cd
            INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
            WHERE cd.id_tag = tg.id_tag AND c.tipo_conteo = 'VALIDACION' AND cd.origen = 'SGO_ANALISTA' AND cd.estado_registro = 'VIGENTE'
        ) THEN tg.id_tag END) AS tags_validados,
        CASE 
            WHEN COUNT(DISTINCT tg.id_tag) = 0 THEN 0
            ELSE ROUND(COUNT(DISTINCT CASE WHEN EXISTS (
                SELECT 1 FROM sod_inv_conteo_det cd
                INNER JOIN sod_inv_conteo c ON c.id_conteo = cd.id_conteo
                WHERE cd.id_tag = tg.id_tag AND c.tipo_conteo = 'VALIDACION' AND cd.origen = 'SGO_ANALISTA' AND cd.estado_registro = 'VIGENTE'
            ) THEN tg.id_tag END) * 100.0 / COUNT(DISTINCT tg.id_tag))
        END AS porcentaje_avance
    FROM sod_ope_agenda AS a
    INNER JOIN sod_cfg_tienda AS t ON a.id_tienda = t.id_tienda
    INNER JOIN sod_ope_estado_agenda AS e ON a.id_estado_agenda = e.id_estado_agenda
    INNER JOIN sod_ope_agenda_usuario AS au ON au.id_agenda = a.id_agenda AND au.login = :login AND au.fl_activo = 'S'
    LEFT JOIN sod_inv_tag AS tg ON a.id_agenda = tg.id_agenda AND tg.fl_activo = 'S'
    WHERE a.fl_activo = 'S'
    GROUP BY a.id_agenda, a.fecha_agenda, a.id_tienda, t.nombre_tienda, t.direccion, a.numero_agenda, 
             e.codigo_estado, a.fecha_hora_inicio, a.fecha_hora_termino, a.fecha_hora_cierre,
             a.titulo_agenda, a.categoria_muestra, a.ultima_sincronizacion, a.fl_incidencia, a.observacion
    ORDER BY a.fecha_agenda DESC, t.nombre_tienda ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login]);
    $agendas = $stmt->fetchAll();

    okResponse($agendas);

} catch (PDOException $e) {
    errorResponse('Error al obtener agendas: ' . $e->getMessage(), 500);
}
