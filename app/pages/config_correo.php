<?php
require_perm('config', 'ver');
$title = 'Correo y notificaciones';
$puede = can('config', 'administrar');
$cfg = correo_config();

if (!ajuste('cron_token')) ajuste_set('cron_token', bin2hex(random_bytes(16)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('config', 'administrar');
    $acc = post('accion');
    if ($acc === 'guardar') {
        $desde = mb_strtolower((string)post('mail_desde', ''));
        $errs = [];
        if ($desde !== '' && !filter_var($desde, FILTER_VALIDATE_EMAIL)) $errs[] = 'El correo remitente no es válido.';
        foreach (['mail_responder' => 'Responder a'] as $k => $l) if (post($k) && !filter_var(post($k), FILTER_VALIDATE_EMAIL)) $errs[] = "«$l» no es un correo válido.";
        foreach (preg_split('/[,;\s]+/', (string)post('mant_copia', '')) as $c) if ($c !== '' && !filter_var($c, FILTER_VALIDATE_EMAIL)) $errs[] = "«$c» no es un correo válido (copia de mantenimientos).";
        if (post('mail_activo') && $desde === '') $errs[] = 'Para activar el envío indique el correo remitente.';
        if (post('mail_activo') && post('mail_modo') !== 'mail' && !post('smtp_host')) $errs[] = 'Indique el servidor SMTP (o elija «Función mail() del servidor»).';
        if ($errs) { foreach ($errs as $er) flash($er, 'danger'); redirect(url('config_correo')); }
        $mapa = [
            'mail_modo' => post('mail_modo') === 'mail' ? 'mail' : 'smtp', 'smtp_host' => post('smtp_host'), 'smtp_puerto' => (string)max(1, (int)post('smtp_puerto', 465)),
            'smtp_seguridad' => in_array(post('smtp_seguridad'), ['ssl', 'tls', 'none'], true) ? post('smtp_seguridad') : 'ssl', 'smtp_usuario' => post('smtp_usuario'),
            'mail_desde' => $desde, 'mail_desde_nombre' => post('mail_desde_nombre'), 'mail_responder' => post('mail_responder'),
            'smtp_sin_verificar' => post('smtp_sin_verificar') ? '1' : '0', 'mail_activo' => post('mail_activo') ? '1' : '0',
            'notif_tickets' => post('notif_tickets') ? '1' : '0', 'notif_tickets_equipo' => post('notif_tickets_equipo') ? '1' : '0',
            'notif_mantenimiento' => post('notif_mantenimiento') ? '1' : '0', 'mant_aviso_dias' => (string)max(0, min(90, (int)post('mant_aviso_dias', 7))),
            'mant_repetir' => (string)max(1, min(60, (int)post('mant_repetir', 7))), 'mant_copia' => post('mant_copia'), 'app_url' => rtrim((string)(post('app_url') ?: app_url()), '/'),
        ];
        foreach ($mapa as $k => $v) ajuste_set($k, $v === null ? null : (string)$v);
        if ((string)($_POST['smtp_clave'] ?? '') !== '') ajuste_set('smtp_clave', secreto_cifrar((string)$_POST['smtp_clave'])); // vacío = conservar la actual
        flash('Configuración guardada.');
    } elseif ($acc === 'probar') {
        $to = mb_strtolower((string)post('destino', ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) flash('Escriba un correo de destino válido para la prueba.', 'danger');
        else {
            $res = enviar_correo($to, 'Prueba de correo de ' . ($CONFIG['app_name'] ?? 'MAVAL'), correo_plantilla('Correo de prueba',
                '<p>Si recibió este mensaje, la cuenta de correo de la aplicación está bien configurada y ya puede enviar notificaciones de la mesa de ayuda y recordatorios de mantenimiento.</p>'
                . correo_ficha(['Servidor' => e($cfg['modo'] === 'mail' ? 'mail() del servidor' : $cfg['host'] . ':' . $cfg['puerto'] . ' (' . $cfg['seguridad'] . ')'), 'Remitente' => e($cfg['desde']), 'Enviado' => e(fdate(now(), true))]),
                app_url() . '/', 'Abrir la aplicación'), ['motivo' => 'prueba', 'forzar' => true]);
            flash($res['ok'] ? 'Correo de prueba enviado a ' . $to . '. Revise también la carpeta de spam.' : 'No se pudo enviar: ' . $res['error'], $res['ok'] ? 'success' : 'danger');
        }
    } elseif ($acc === 'recordatorios') {
        if (!correo_activo()) flash('Primero active y configure el envío de correo.', 'warning');
        else {
            $r = enviar_recordatorios_mantenimiento(false);
            flash("Recordatorios enviados: {$r['proximos']} mantenimiento(s) próximo(s) y {$r['pendientes']} pendiente(s) en {$r['correos']} correo(s).", $r['correos'] ? 'success' : 'info');
        }
    } elseif ($acc === 'token') {
        ajuste_set('cron_token', bin2hex(random_bytes(16)));
        flash('Se generó un nuevo token. Actualice la tarea programada del servidor con la nueva dirección.');
    }
    redirect(url('config_correo'));
}

$cfg = correo_config();
$log = all('SELECT * FROM correo_log ORDER BY id DESC LIMIT 15');
$token = ajuste('cron_token');
$cronUrl = app_url() . '/cron.php?token=' . $token;
$dis = $puede ? '' : ' disabled';
$on = fn($k, $def = 1) => (bool)ajuste($k, $def);
?>
<form method="post" class="row g-3">
  <?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
  <div class="col-xl-7">
    <div class="panel">
      <div class="panel-h"><h3>Cuenta de correo de la aplicación</h3>
        <div class="actions"><?= correo_activo() ? badge('Envío activo', 'success') : badge('Envío desactivado', 'secondary') ?></div></div>
      <p class="small muted">Con esta cuenta se envían los avisos de la <strong>mesa de ayuda</strong> (creación, cambios de estado y respuestas hasta el cierre del ticket) y los avisos y recordatorios del <strong>planeador de mantenimiento</strong>. Cree un buzón en cPanel (p. ej. <em>soporte@su-dominio</em>) y use sus datos.</p>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="mail_activo" value="1" id="mact" <?= $on('mail_activo', 0) ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label fw-semibold" for="mact">Enviar correos desde la aplicación</label></div>
      <div class="form-grid">
        <div><label class="form-label">Método de envío</label><select class="form-select" name="mail_modo" id="mmodo"<?= $dis ?>><?= options(['smtp' => 'SMTP (recomendado)', 'mail' => 'Función mail() del servidor'], $cfg['modo']) ?></select></div>
        <div data-show-when="mail_modo:smtp"><label class="form-label">Servidor SMTP</label><input class="form-control" name="smtp_host" value="<?= e($cfg['host']) ?>" placeholder="mail.su-dominio.co"<?= $dis ?>></div>
        <div data-show-when="mail_modo:smtp"><label class="form-label">Puerto</label><input class="form-control" type="number" name="smtp_puerto" value="<?= (int)$cfg['puerto'] ?>"<?= $dis ?>></div>
        <div data-show-when="mail_modo:smtp"><label class="form-label">Seguridad</label><select class="form-select" name="smtp_seguridad"<?= $dis ?>><?= options(['ssl' => 'SSL/TLS (puerto 465)', 'tls' => 'STARTTLS (puerto 587)', 'none' => 'Ninguna (puerto 25)'], $cfg['seguridad']) ?></select></div>
        <div data-show-when="mail_modo:smtp"><label class="form-label">Usuario</label><input class="form-control" name="smtp_usuario" value="<?= e($cfg['usuario']) ?>" placeholder="soporte@su-dominio.co" autocomplete="off"<?= $dis ?>></div>
        <div data-show-when="mail_modo:smtp"><label class="form-label">Contraseña</label><input class="form-control" type="password" name="smtp_clave" autocomplete="new-password" placeholder="<?= $cfg['clave'] !== '' ? '•••••••• (guardada; escriba solo para cambiarla)' : '' ?>"<?= $dis ?>></div>
        <div><label class="form-label req">Correo remitente</label><input class="form-control" type="email" name="mail_desde" value="<?= e($cfg['desde']) ?>" placeholder="soporte@su-dominio.co"<?= $dis ?>></div>
        <div><label class="form-label">Nombre del remitente</label><input class="form-control" name="mail_desde_nombre" value="<?= e($cfg['desde_nombre']) ?>" placeholder="Mesa de ayuda"<?= $dis ?>></div>
        <div><label class="form-label">Responder a (opcional)</label><input class="form-control" type="email" name="mail_responder" value="<?= e($cfg['responder']) ?>"<?= $dis ?>></div>
        <div data-show-when="mail_modo:smtp" class="d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="smtp_sin_verificar" value="1" id="sv" <?= $cfg['sin_verificar'] ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label small" for="sv">No verificar el certificado SSL del servidor (solo si falla la conexión)</label></div></div>
        <div class="full"><label class="form-label">Dirección pública de la aplicación (para los enlaces de los correos)</label><input class="form-control" name="app_url" value="<?= e(ajuste('app_url', '')) ?>" placeholder="<?= e(app_url()) ?>"<?= $dis ?>><div class="form-text">Si la deja vacía se guarda la dirección con la que está entrando ahora. Es necesaria para que los enlaces funcionen en los correos enviados por la tarea programada.</div></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-h"><h3>Qué se notifica</h3></div>
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="notif_tickets" value="1" id="nt" <?= $on('notif_tickets') ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label" for="nt"><strong>Mesa de ayuda:</strong> avisar al solicitante cuando su ticket se crea, cambia de estado (En proceso, En espera, Resuelto, Cerrado) o recibe respuesta</label></div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="notif_tickets_equipo" value="1" id="nte" <?= $on('notif_tickets_equipo') ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label" for="nte">También avisar al equipo de soporte (nuevos tickets, asignaciones y respuestas del solicitante)</label></div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="notif_mantenimiento" value="1" id="nm" <?= $on('notif_mantenimiento') ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label" for="nm"><strong>Planeador de mantenimiento:</strong> informar las fechas programadas y enviar recordatorios</label></div>
      <div class="form-grid">
        <div><label class="form-label">Avisar con … días de anticipación</label><input class="form-control" type="number" min="0" max="90" name="mant_aviso_dias" value="<?= (int)ajuste('mant_aviso_dias', 7) ?>"<?= $dis ?>><div class="form-text">Valor sugerido al programar; cada programación puede tener el suyo.</div></div>
        <div><label class="form-label">Repetir recordatorio de pendientes cada … días</label><input class="form-control" type="number" min="1" max="60" name="mant_repetir" value="<?= (int)ajuste('mant_repetir', 7) ?>"<?= $dis ?>></div>
        <div class="full"><label class="form-label">Enviar copia de los avisos de mantenimiento a (opcional)</label><input class="form-control" name="mant_copia" value="<?= e(ajuste('mant_copia', '')) ?>" placeholder="soporte@su-dominio.co, jefe-ti@su-dominio.co"<?= $dis ?>><div class="form-text">Los avisos llegan al responsable del equipo, a su usuario de la mesa de ayuda y al técnico asignado; aquí puede añadir otros destinatarios.</div></div>
      </div>
      <?php if ($puede): ?><div class="form-actions"><button class="btn btn-primary">Guardar configuración</button></div><?php endif; ?>
    </div>
  </div>

  <div class="col-xl-5">
    <?php if ($puede): ?>
    <div class="panel">
      <div class="panel-h"><h3>Probar el envío</h3></div>
      <p class="small muted">Guarde primero la configuración y luego envíe un correo de prueba.</p>
      <div class="d-flex gap-2">
        <input class="form-control" type="email" name="destino" form="f-probar" value="<?= e(user()['email']) ?>" placeholder="correo de destino">
        <button class="btn btn-outline-primary text-nowrap" form="f-probar"><i class="bi bi-send me-1"></i>Enviar prueba</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="panel">
      <div class="panel-h"><h3>Recordatorios automáticos</h3></div>
      <p class="small">La aplicación envía los recordatorios de mantenimiento <strong>una vez al día</strong>, con la primera visita de cualquier usuario. Para que salgan puntualmente aunque nadie entre, programe una tarea (<em>Cron Jobs</em> en cPanel) diaria, por ejemplo a las 7:00:</p>
      <label class="form-label small">Comando (recomendado)</label>
      <input class="form-control form-control-sm mb-2" readonly value="php <?= e(APP_ROOT . '/cron.php') ?>" onclick="this.select()">
      <label class="form-label small">o dirección web</label>
      <input class="form-control form-control-sm mb-2" readonly value="<?= e($cronUrl) ?>" onclick="this.select()">
      <?php if ($puede): ?>
      <div class="d-flex gap-2 mt-3">
        <button class="btn btn-sm btn-outline-primary" form="f-recordar"><i class="bi bi-bell me-1"></i>Enviar recordatorios ahora</button>
        <button class="btn btn-sm btn-outline-secondary" form="f-token">Nuevo token</button>
      </div>
      <?php endif; ?>
    </div>
    <div class="panel">
      <div class="panel-h"><h3>Últimos envíos</h3></div>
      <div class="tbl-wrap"><table class="tbl">
        <tbody>
        <?php foreach ($log as $l): ?>
          <tr><td class="nowrap small muted"><?= fdate($l['fecha'], true) ?></td>
            <td class="small"><?= e(mb_strimwidth($l['asunto'], 0, 48, '…')) ?><br><span class="muted"><?= e(mb_strimwidth($l['destinatarios'], 0, 50, '…')) ?></span><?= $l['ok'] ? '' : '<br><span class="text-danger">' . e($l['error']) . '</span>' ?></td>
            <td><?= $l['ok'] ? badge('Enviado', 'success') : badge('Falló', 'danger') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$log): ?><tr><td class="empty">Aún no se ha enviado ningún correo.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</form>
<form method="post" id="f-probar" hidden><?= csrf_field() ?><input type="hidden" name="accion" value="probar"></form>
<form method="post" id="f-recordar" hidden data-confirm="¿Enviar ahora los recordatorios pendientes?"><?= csrf_field() ?><input type="hidden" name="accion" value="recordatorios"></form>
<form method="post" id="f-token" hidden data-confirm="El token anterior dejará de funcionar. ¿Generar uno nuevo?"><?= csrf_field() ?><input type="hidden" name="accion" value="token"></form>
