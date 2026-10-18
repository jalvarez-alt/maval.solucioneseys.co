<?php
/**
 * Tarea programada (cPanel → Cron Jobs), una vez al día:
 *   php /ruta/a/cron.php
 *   o bien  https://su-dominio/cron.php?token=TOKEN  (el token está en «Correo y notificaciones»)
 * Envía los avisos de mantenimientos próximos y los recordatorios de los pendientes.
 */
$cli = PHP_SAPI === 'cli';
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

if (!$cli) {
    $t = (string)($_GET['token'] ?? '');
    $real = (string)ajuste('cron_token', '');
    if ($real === '' || !hash_equals($real, $t)) { http_response_code(403); exit("Acceso denegado\n"); }
}
if (!correo_activo()) { echo "El envío de correo está desactivado o sin configurar.\n"; exit; }

ajuste_set('ult_recordatorio', today());
$r = enviar_recordatorios_mantenimiento(false);
echo date('c') . " · próximos: {$r['proximos']} · pendientes: {$r['pendientes']} · correos: {$r['correos']}\n";
