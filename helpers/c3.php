<?php
# ============================================================
# ws/helpers/c3.php
# Validación de justificación para invalidar C3 posterior
# Réplica del WHERE interno de PRC_SOD_INV_C3_INVALIDAR_POSTERIOR_V1
# ============================================================

/**
 * Determina si hay justificación para invalidar C3:
 * existe al menos una fila C3 VIGENTE con captura C2/PV posterior
 * para el mismo producto+tag.
 *
 * Defensa en profundidad: aunque el SP pierda su WHERE interno,
 * el endpoint no lo invoca sin justificación (misma filosofía que
 * $invalidar_c3 en ScriptCase).
 */
function c3_invalidacion_justificada(PDO $pdo, int $agendaId): bool
{
    if ($agendaId <= 0) {
        return false;
    }

    $sql = "SELECT EXISTS(
        SELECT 1
        FROM sod_inv_conteo_det AS c3
        INNER JOIN sod_inv_conteo AS cc3
                ON cc3.id_conteo = c3.id_conteo
               AND cc3.numero_iteracion = 3
               AND cc3.tipo_conteo = 'RECONTEO'
               AND cc3.fl_activo = 'S'
        INNER JOIN sod_inv_conteo_det AS nx
                ON nx.id_agenda = c3.id_agenda
               AND nx.id_producto = c3.id_producto
               AND nx.id_tag = c3.id_tag
               AND nx.id_reconteo IS NULL
               AND nx.estado_registro = 'VIGENTE'
               AND nx.origen IN ('SGO_ANALISTA', 'SGO_PREVARIANCE')
               AND nx.fecha_hora_captura > c3.fecha_hora_captura
        INNER JOIN sod_inv_conteo AS cnx
                ON cnx.id_conteo = nx.id_conteo
               AND cnx.numero_iteracion = 2
               AND cnx.tipo_conteo = 'VALIDACION'
               AND cnx.fl_activo = 'S'
        WHERE c3.id_agenda = :id_agenda
          AND c3.id_reconteo IS NULL
          AND c3.origen = 'SGO_RECUENTO'
          AND c3.estado_registro = 'VIGENTE'
    )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_agenda' => $agendaId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Ejecuta PRC_SOD_INV_C3_INVALIDAR_POSTERIOR_V1 solo si hay justificación.
 * Best-effort: un fallo del SP no bloquea el guardado (igual que ScriptCase).
 */
function c3_invalidar_si_justificado(PDO $pdo, int $agendaId, string $login): void
{
    if (!c3_invalidacion_justificada($pdo, $agendaId)) {
        return;
    }

    try {
        $pdo->exec(
            "CALL PRC_SOD_INV_C3_INVALIDAR_POSTERIOR_V1({$agendaId}, " .
            $pdo->quote($login) . ")"
        );
    } catch (PDOException $e) {
        // Best-effort: no bloquear el guardado
    }
}
