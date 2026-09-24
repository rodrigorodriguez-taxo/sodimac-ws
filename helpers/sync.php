<?php
# ============================================================
# ws/helpers/sync.php
# Sync diferido durable — sod_inv_agenda_sync_canonica
# Réplica de $marcar_sync_canonica_pendiente (_0:4010-4081)
# ============================================================

/**
 * Determina si la tabla de sync durable existe.
 */
function sync_tabla_instalada(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'sod_inv_agenda_sync_canonica'"
        );
        $cache = ((int)$stmt->fetchColumn()) > 0;
    } catch (PDOException $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * Marca una agenda como pendiente de recálculo canónico (dentro de la
 * misma transacción del guardado). Si la tabla no existe, hace fallback
 * a la llamada inline best-effort (comportamiento anterior de la API).
 *
 * Contextos válidos: VALIDACION | PREVARIANCE | RECUENTO
 */
function sync_marcar_pendiente(
    PDO $pdo,
    int $agendaId,
    string $contexto,
    bool $invalidarC3,
    string $login
): void {
    if ($agendaId <= 0) {
        throw new RuntimeException('No existe una agenda valida para registrar sincronizacion pendiente.');
    }

    $contexto = strtoupper(trim($contexto));
    if (!in_array($contexto, ['VALIDACION', 'PREVARIANCE', 'RECUENTO'], true)) {
        throw new RuntimeException('Contexto de sincronizacion durable no valido.');
    }

    if (!sync_tabla_instalada($pdo)) {
        // Fallback: SP inline best-effort (legacy API)
        if ($invalidarC3) {
            try {
                $pdo->exec(
                    "CALL PRC_SOD_INV_C3_INVALIDAR_POSTERIOR_V1({$agendaId}, " .
                    $pdo->quote($login) . ")"
                );
            } catch (PDOException $e) {
                // best-effort
            }
        }
        try {
            $pdo->exec(
                "CALL PRC_SOD_AGENDA_METRICAS_RECALCULAR_CORE_V1({$agendaId}, " .
                $pdo->quote($login) . ")"
            );
        } catch (PDOException $e) {
            // best-effort — no bloquear el guardado
        }
        return;
    }

    $incInvalidar = $invalidarC3 ? 1 : 0;

    // Réplica de la SQL de ScriptCase (_0:4050-4080)
    $sql = "INSERT INTO sod_inv_agenda_sync_canonica (
        id_agenda, revision_pendiente, revision_procesada,
        revision_invalidar_pendiente, revision_invalidar_procesada,
        estado, contexto_ultimo, intentos, fecha_pendiente,
        login_ultimo, fecha_creacion, usuario_creacion,
        fecha_modificacion, usuario_modificacion
    ) VALUES (
        {$agendaId},
        1, 0,
        {$incInvalidar}, 0,
        'PENDIENTE',
        " . $pdo->quote($contexto) . ",
        0, NOW(3),
        " . $pdo->quote($login) . ",
        NOW(3), " . $pdo->quote($login) . ",
        NOW(3), " . $pdo->quote($login) . "
    )
    ON DUPLICATE KEY UPDATE
        revision_pendiente = revision_pendiente + 1,
        revision_invalidar_pendiente =
            revision_invalidar_pendiente + {$incInvalidar},
        estado = CASE
            WHEN estado = 'PROCESANDO' THEN 'PROCESANDO'
            ELSE 'PENDIENTE' END,
        contexto_ultimo = VALUES(contexto_ultimo),
        fecha_pendiente = NOW(3),
        login_ultimo = VALUES(login_ultimo),
        fecha_modificacion = NOW(3),
        usuario_modificacion = VALUES(usuario_modificacion)";

    $pdo->exec($sql);
}
