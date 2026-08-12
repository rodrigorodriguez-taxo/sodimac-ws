<?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$fechaParam = $_GET['fecha'] ?? null;

if ($fechaParam !== null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaParam)) {
        echo json_encode([
            'status' => 'ERROR',
            'msg'    => 'Formato de fecha invalido. Use YYYY-MM-DD',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $fechaConsulta = $fechaParam;
} else {
    $fechaConsulta = date('Y-m-d');
}

$sql = "
SELECT
    su.login,
    su.email,
    ue.rut,
    ue.rut_normalizado,

    CONCAT_WS(
        ' ',
        NULLIF(TRIM(ue.nombres), ''),
        NULLIF(TRIM(ue.apellido_paterno), ''),
        NULLIF(TRIM(ue.apellido_materno), '')
    ) AS nombre,

    COUNT(DISTINCT vt.id_tienda) AS tiendas_vigentes,
    COUNT(DISTINCT a.id_agenda) AS agendas_disponibles,

    a.id_agenda,
    a.numero_agenda,
    DATE(a.fecha_agenda) AS fecha_agenda,

    t.id_tienda,
    t.codigo_tienda,
    t.nombre_tienda,

    prep.id_muestra,
    prep.codigo_muestra,
    prep.nombre_muestra,

    COUNT(DISTINCT c.id_producto) AS productos,
    COUNT(DISTINCT c.codigo_ingreso) AS codigos

FROM sec_users AS su

INNER JOIN sod_sec_usuario_ext AS ue
        ON CONVERT(su.login USING utf8mb4)
           COLLATE utf8mb4_unicode_ci = ue.login

INNER JOIN vw_sod_usuario_tienda_resumen AS vt
        ON CONVERT(TRIM(vt.login) USING utf8mb4)
           COLLATE utf8mb4_unicode_ci
           =
           CONVERT(TRIM(su.login) USING utf8mb4)
           COLLATE utf8mb4_unicode_ci
       AND vt.estado_operativo = 'VIGENTE'
       AND vt.fl_usuario_vigente = 'S'

INNER JOIN vw_sod_dash_agenda_usuario AS d
        ON CONVERT(TRIM(d.login) USING utf8mb4)
           COLLATE utf8mb4_unicode_ci
           =
           CONVERT(TRIM(su.login) USING utf8mb4)
           COLLATE utf8mb4_unicode_ci

INNER JOIN sod_ope_agenda AS a
        ON a.id_agenda = d.id_agenda
       AND DATE(a.fecha_agenda) = :fecha
       AND a.fl_activo = 'S'

INNER JOIN sod_cfg_tienda AS t
        ON t.id_tienda = a.id_tienda

INNER JOIN sod_ope_estado_agenda AS ea
        ON ea.id_estado_agenda = a.id_estado_agenda
       AND ea.codigo_estado NOT IN ('CERRADA', 'SUSPENDIDA')

INNER JOIN vw_sod_agenda_preparacion_resumen AS prep
        ON prep.id_agenda = a.id_agenda
       AND prep.fl_lista_conteo = 'S'

INNER JOIN vw_sod_agenda_muestra_producto_codigo AS c
        ON c.id_agenda = a.id_agenda
       AND c.id_muestra = prep.id_muestra

WHERE su.active = 'Y'
  AND ue.fl_activo = 'S'
  AND (
        ue.fecha_inicio_vigencia IS NULL
        OR ue.fecha_inicio_vigencia <= NOW()
      )
  AND (
        ue.fecha_fin_vigencia IS NULL
        OR ue.fecha_fin_vigencia >= NOW()
      )

GROUP BY
    su.login,
    su.email,
    ue.rut,
    ue.rut_normalizado,
    nombre,
    a.id_agenda,
    a.numero_agenda,
    DATE(a.fecha_agenda),
    t.id_tienda,
    t.codigo_tienda,
    t.nombre_tienda,
    prep.id_muestra,
    prep.codigo_muestra,
    prep.nombre_muestra

HAVING tiendas_vigentes > 0
   AND agendas_disponibles > 0
   AND productos > 0
   AND codigos > 0

ORDER BY
    agendas_disponibles DESC,
    productos DESC,
    codigos DESC,
    su.login ASC

LIMIT 30
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fecha' => $fechaConsulta]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $usuarios = array_map(function ($row) {
        $rutNum = preg_replace('/[^0-9]/', '', $row['rut_normalizado']);
        return [
            'login'              => $row['login'],
            'email'              => $row['email'],
            'rut'                => $row['rut'],
            'rut_normalizado'    => $row['rut_normalizado'],
            'password_esperado'  => substr($rutNum, 0, 6),
            'nombre'             => $row['nombre'],
            'tiendas_vigentes'   => (int) $row['tiendas_vigentes'],
            'agendas_disponibles'=> (int) $row['agendas_disponibles'],
            'id_agenda'          => (int) $row['id_agenda'],
            'numero_agenda'      => $row['numero_agenda'],
            'fecha_agenda'       => $row['fecha_agenda'],
            'id_tienda'          => (int) $row['id_tienda'],
            'codigo_tienda'      => $row['codigo_tienda'],
            'nombre_tienda'      => $row['nombre_tienda'],
            'id_muestra'         => (int) $row['id_muestra'],
            'codigo_muestra'     => $row['codigo_muestra'],
            'nombre_muestra'     => $row['nombre_muestra'],
            'productos'          => (int) $row['productos'],
            'codigos'            => (int) $row['codigos'],
        ];
    }, $rows);

    echo json_encode([
        'status'         => 'OK',
        'fecha_busqueda' => date('Y-m-d H:i:s'),
        'fecha_consulta' => $fechaConsulta,
        'total'          => count($usuarios),
        'usuarios'       => $usuarios,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'ERROR',
        'msg'    => 'Error de query: ' . $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
