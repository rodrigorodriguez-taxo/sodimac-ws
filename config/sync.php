<?php
# ============================================================
# ws/config/sync.php
# Token de acceso al worker de sync canónica (cron / CLI)
# Cambiar este valor al desplegar en el servidor de producción.
# ============================================================

return [
    // Token esperado por sync/procesar.php (header X-Sync-Token o --token=)
    'token' => 'sgo-sync-canonica-2026',
];
