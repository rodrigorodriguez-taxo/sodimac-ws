<?php
# ============================================================
# ws/api/tablet/sync/procesar.php
# Worker de sync canónica — réplica de blank_sod_validacion_sync
# (sodv_sync_process) usando PRC_SOD_AGENDA_METRICAS_RECALCULAR_CORE_V1
#
# Uso CLI (cron):
#   php procesar.php --token=TOKEN
# Uso HTTP:
#   GET/POST con header X-Sync-Token: TOKEN  (o ?token=)
# ============================================================

$isCli = (PHP_SAPI === 'cli');

// Rutas absolutas: el worker se invoca desde CLI (cron) y HTTP
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/sync.php';

$syncConfig = require __DIR__ . '/../../../config/sync.php';
$expectedToken = (string)($syncConfig['token'] ?? '');

// ── Autenticación ──────────────────────────────────────────
$providedToken = '';
if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (strpos($arg, '--token=') === 0) {
            $providedToken = substr($arg, 8);
        }
    }
} else {
    corsHeaders();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }
    $providedToken = (string)($_SERVER['HTTP_X_SYNC_TOKEN'] ?? ($_GET['token'] ?? ''));
    $providedToken = trim($providedToken);
}

if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    if ($isCli) {
        fwrite(STDERR, "ERROR: token invalido\n");
        exit(1);
    }
    errorResponse('Token invalido', 401);
}

if (!sync_tabla_instalada($pdo)) {
    $result = ['ok' => false, 'msg' => 'Tabla sod_inv_agenda_sync_canonica no instalada', 'procesadas' => 0];
    if ($isCli) {
        echo json_encode($result) . "\n";
        exit(1);
    }
    okResponse($result, $result['msg']);
}

// ── Zero-overlay (réplica sodv_sync_apply_zero_overlay) ────
$zeroInstalado = false;
try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'sod_inv_reconteo_cero_confirmacion'"
    );
    $zeroInstalado = ((int)$stmt->fetchColumn()) > 0;
} catch (PDOException $e) {
    $zeroInstalado = false;
}

function sync_apply_zero_overlay(PDO $pdo, int $agendaId, string $login): void
{
    $pdo->exec(
        "UPDATE sod_inv_resultado AS rs
         INNER JOIN sod_inv_reconteo_cero_confirmacion AS z
                 ON z.id_agenda = rs.id_agenda
                AND z.id_producto = rs.id_producto
                AND z.fl_activo = 'S'
                AND z.estado_confirmacion = 'CONFIRMADO'
                AND z.tipo_confirmacion = 'CERO_SIN_TAG'
                AND ABS(z.cantidad_confirmada) <= 0.0005
         SET
             rs.cantidad_conteo = 0,
             rs.cantidad_final = 0,
             rs.diferencia_cantidad = 0 - COALESCE(rs.stock_teorico, 0),
             rs.diferencia_valor =
                 (0 - COALESCE(rs.stock_teorico, 0))
                 * COALESCE(rs.valor_unitario, 0),
             rs.clasificacion =
                 CASE
                     WHEN ABS(COALESCE(rs.stock_teorico, 0)) <= 0.0005
                         THEN 'COINCIDENTE'
                     ELSE 'FALTANTE'
                 END,
             rs.estado_revision = 'RESUELTO',
             rs.fl_requiere_decision_analista = 'N',
             rs.motivo_decision_pendiente = NULL,
             rs.fecha_calculo = NOW(),
             rs.usuario_calculo = " . $pdo->quote($login) . "
         WHERE rs.id_agenda = {$agendaId}
           AND NOT EXISTS (
                 SELECT 1
                 FROM sod_inv_conteo_det AS bx
                 INNER JOIN sod_inv_conteo AS bc
                         ON bc.id_conteo = bx.id_conteo
                        AND bc.id_agenda = bx.id_agenda
                        AND bc.fl_activo = 'S'
                 INNER JOIN sod_inv_tag AS bt
                         ON bt.id_tag = bx.id_tag
                        AND bt.id_agenda = bx.id_agenda
                        AND bt.fl_activo = 'S'
                        AND bt.estado_tag <> 'ANULADO'
                 WHERE bx.id_agenda = rs.id_agenda
                   AND bx.id_producto = rs.id_producto
                   AND bx.estado_registro = 'VIGENTE'
                   AND (
                         (bc.numero_iteracion = 1 AND bc.tipo_conteo = 'INICIAL')
                         OR
                         (bc.numero_iteracion = 2 AND bc.tipo_conteo = 'VALIDACION'
                          AND bx.id_reconteo IS NULL
                          AND bx.origen IN ('SGO_ANALISTA', 'SGO_PREVARIANCE'))
                       )
           )
           AND NOT EXISTS (
                 SELECT 1
                 FROM sod_inv_conteo_det AS c3x
                 INNER JOIN sod_inv_conteo AS c3c
                         ON c3c.id_conteo = c3x.id_conteo
                        AND c3c.id_agenda = c3x.id_agenda
                        AND c3c.numero_iteracion = 3
                        AND c3c.tipo_conteo = 'RECONTEO'
                        AND c3c.fl_activo = 'S'
                 WHERE c3x.id_agenda = rs.id_agenda
                   AND c3x.id_producto = rs.id_producto
                   AND c3x.id_reconteo IS NULL
                   AND c3x.origen = 'SGO_RECUENTO'
                   AND c3x.estado_registro = 'VIGENTE'
           )"
    );
}

/**
 * Procesa una agenda pendiente. Réplica de sodv_sync_process.
 * Usa CORE_V1 (decisión: wrapper mantenido por el DBA).
 */
function sync_procesar_agenda(PDO $pdo, int $agendaId, string $login, bool $zeroInstalado): array
{
    $lockName = 'SOD_SYNC_AGENDA_' . $agendaId;
    $inicio = microtime(true);
    $lockOk = false;

    $respuesta = [
        'ok' => false,
        'procesado' => false,
        'pendiente' => true,
        'ocupado' => false,
        'id_agenda' => $agendaId,
        'ms_total' => 0,
        'mensaje' => '',
    ];

    try {
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, 0) AS lock_ok");
        $stmt->execute([$lockName]);
        $lockOk = ((int)$stmt->fetchColumn()) === 1;

        if (!$lockOk) {
            $respuesta['ok'] = true;
            $respuesta['ocupado'] = true;
            $respuesta['mensaje'] = 'La agenda ya se esta sincronizando.';
            return $respuesta;
        }

        // Fase 1: leer estado FOR UPDATE
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT revision_pendiente, revision_procesada,
                        revision_invalidar_pendiente, revision_invalidar_procesada,
                        estado, contexto_ultimo
                 FROM sod_inv_agenda_sync_canonica
                 WHERE id_agenda = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$agendaId]);
            $row = $stmt->fetch();

            if (!$row) {
                $pdo->commit();
                $respuesta['ok'] = true;
                $respuesta['procesado'] = false;
                $respuesta['pendiente'] = false;
                $respuesta['mensaje'] = 'No existen cambios pendientes de sincronizacion.';
                return $respuesta;
            }

            $revPendiente = (int)$row['revision_pendiente'];
            $revProcesada = (int)$row['revision_procesada'];
            $revInvPendiente = (int)$row['revision_invalidar_pendiente'];
            $revInvProcesada = (int)$row['revision_invalidar_procesada'];

            if ($revPendiente <= $revProcesada && $revInvPendiente <= $revInvProcesada) {
                $pdo->exec(
                    "UPDATE sod_inv_agenda_sync_canonica
                     SET estado = 'OK', ultimo_error = NULL,
                         fecha_modificacion = NOW(3),
                         usuario_modificacion = " . $pdo->quote($login) . "
                     WHERE id_agenda = {$agendaId}"
                );
                $pdo->commit();
                $respuesta['ok'] = true;
                $respuesta['procesado'] = false;
                $respuesta['pendiente'] = false;
                $respuesta['mensaje'] = 'Resultados ya sincronizados.';
                return $respuesta;
            }

            $revisionObjetivo = $revPendiente;
            $revisionInvalidarObjetivo = $revInvPendiente;
            $invalidarC3 = $revInvPendiente > $revInvProcesada;

            $pdo->exec(
                "UPDATE sod_inv_agenda_sync_canonica
                 SET estado = 'PROCESANDO',
                     intentos = intentos + 1,
                     fecha_inicio = NOW(3),
                     ultimo_error = NULL,
                     login_ultimo = " . $pdo->quote($login) . ",
                     fecha_modificacion = NOW(3),
                     usuario_modificacion = " . $pdo->quote($login) . "
                 WHERE id_agenda = {$agendaId}"
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Fase 2: ejecutar SPs (CORE_V1 + invalidar si aplica)
        $inicioCore = microtime(true);
        $pdo->beginTransaction();
        try {
            if ($invalidarC3) {
                $pdo->exec(
                    "CALL PRC_SOD_INV_C3_INVALIDAR_POSTERIOR_V1({$agendaId}, " .
                    $pdo->quote($login) . ")"
                );
            }
            $pdo->exec(
                "CALL PRC_SOD_AGENDA_METRICAS_RECALCULAR_CORE_V1({$agendaId}, " .
                $pdo->quote($login) . ")"
            );
            if ($zeroInstalado) {
                sync_apply_zero_overlay($pdo, $agendaId, $login);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $msCore = (int)round((microtime(true) - $inicioCore) * 1000);

        // Fase 3: marcar procesado
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT revision_pendiente, revision_procesada,
                        revision_invalidar_pendiente, revision_invalidar_procesada
                 FROM sod_inv_agenda_sync_canonica
                 WHERE id_agenda = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$agendaId]);
            $post = $stmt->fetch();

            if (!$post) {
                throw new RuntimeException('Se perdio el estado durable de sincronizacion.');
            }

            $todaviaPendiente =
                (int)$post['revision_pendiente'] > $revisionObjetivo
                || (int)$post['revision_invalidar_pendiente'] > $revisionInvalidarObjetivo;

            $estadoFinal = $todaviaPendiente ? 'PENDIENTE' : 'OK';

            $pdo->exec(
                "UPDATE sod_inv_agenda_sync_canonica
                 SET revision_procesada = GREATEST(revision_procesada, {$revisionObjetivo}),
                     revision_invalidar_procesada = GREATEST(revision_invalidar_procesada, {$revisionInvalidarObjetivo}),
                     estado = " . $pdo->quote($estadoFinal) . ",
                     duracion_ultimo_ms = {$msCore},
                     fecha_ultimo_ok = NOW(3),
                     ultimo_error = NULL,
                     fecha_modificacion = NOW(3),
                     usuario_modificacion = " . $pdo->quote($login) . "
                 WHERE id_agenda = {$agendaId}"
            );
            $pdo->commit();

            $respuesta['ok'] = true;
            $respuesta['procesado'] = true;
            $respuesta['pendiente'] = $todaviaPendiente;
            $respuesta['revision'] = $revisionObjetivo;
            $respuesta['core_ms'] = $msCore;
            $respuesta['mensaje'] = $todaviaPendiente
                ? 'Resultados actualizados; existen cambios nuevos pendientes.'
                : 'Resultados actualizados correctamente.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $respuesta;
    } catch (Throwable $e) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $ignore) {
        }

        try {
            $pdo->exec(
                "UPDATE sod_inv_agenda_sync_canonica
                 SET estado = 'ERROR',
                     fecha_ultimo_error = NOW(3),
                     ultimo_error = LEFT(" . $pdo->quote(substr($e->getMessage(), 0, 1000)) . ", 1000),
                     login_ultimo = " . $pdo->quote($login) . ",
                     fecha_modificacion = NOW(3),
                     usuario_modificacion = " . $pdo->quote($login) . "
                 WHERE id_agenda = {$agendaId}"
            );
        } catch (Throwable $ignore) {
        }

        $respuesta['mensaje'] = $e->getMessage();
        return $respuesta;
    } finally {
        if ($lockOk) {
            try {
                $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?) AS liberado");
                $stmt->execute([$lockName]);
                $stmt->fetchColumn();
            } catch (Throwable $ignore) {
            }
        }
        $respuesta['ms_total'] = (int)round((microtime(true) - $inicio) * 1000);
    }
}

// ── Seleccionar agendas pendientes ─────────────────────────
$loginWorker = 'SYNC_WORKER';

try {
    $stmt = $pdo->query(
        "SELECT id_agenda
         FROM sod_inv_agenda_sync_canonica
         WHERE revision_pendiente > revision_procesada
            OR revision_invalidar_pendiente > revision_invalidar_procesada
         ORDER BY fecha_pendiente ASC"
    );
    $pendientes = $stmt->fetchAll();
} catch (PDOException $e) {
    if ($isCli) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
    errorResponse('Error consultando cola: ' . $e->getMessage(), 500);
}

$resultados = [];
$procesadas = 0;
$okCount = 0;
$errorCount = 0;

foreach ($pendientes as $p) {
    $agendaId = (int)$p['id_agenda'];
    $r = sync_procesar_agenda($pdo, $agendaId, $loginWorker, $zeroInstalado);
    $resultados[] = $r;
    $procesadas++;
    if (!empty($r['ok']) && empty($r['ocupado'])) {
        $okCount++;
    } elseif (empty($r['ok'])) {
        $errorCount++;
    }
}

$payload = [
    'ok' => $errorCount === 0,
    'pendientes_encontradas' => count($pendientes),
    'procesadas' => $procesadas,
    'ok_count' => $okCount,
    'error_count' => $errorCount,
    'resultados' => $resultados,
];

if ($isCli) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    exit($errorCount > 0 ? 1 : 0);
}

okResponse($payload, 'Sync canónica procesada');
