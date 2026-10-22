<?php
require_once APP_DIR . '/cotizaciones.php';
require_perm('cotizaciones', 'administrar');
$title = 'Configurar cotizaciones';

// Vista del banner actual (el directorio uploads/ no es público)
if (get('banner')) {
    $f = glob(UPLOAD_DIR . '/cot_banner.*')[0] ?? APP_ROOT . '/assets/img/banner_cotizacion.png';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: no-cache');
    readfile($f);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    if ($acc === 'guardar') {
        $errs = [];
        foreach (['cot_mail_desde' => 'Correo remitente', 'cot_mail_responder' => 'Responder a', 'cot_email' => 'Correo de la empresa'] as $k => $l) {
            if (post($k) && !filter_var(post($k), FILTER_VALIDATE_EMAIL)) $errs[] = "«$l» no es un correo válido.";
        }
        if (post('cot_mail_activo') && !post('cot_mail_desde')) $errs[] = 'Para activar el envío indique el correo remitente.';
        if (post('cot_mail_activo') && post('cot_mail_modo') !== 'mail' && !post('cot_smtp_host')) $errs[] = 'Indique el servidor SMTP (o elija «Función mail() del servidor»).';
        if ($errs) { foreach ($errs as $er) flash($er, 'danger'); redirect(url('cotizacion_config')); }
        $mapa = [
            'cot_mail_activo' => post('cot_mail_activo') ? '1' : '0', 'cot_mail_modo' => post('cot_mail_modo') === 'mail' ? 'mail' : 'smtp', 'cot_smtp_host' => post('cot_smtp_host'),
            'cot_smtp_puerto' => (string)max(1, (int)post('cot_smtp_puerto', 465)), 'cot_smtp_seguridad' => in_array(post('cot_smtp_seguridad'), ['ssl', 'tls', 'none'], true) ? post('cot_smtp_seguridad') : 'ssl',
            'cot_smtp_usuario' => post('cot_smtp_usuario'), 'cot_mail_desde' => mb_strtolower((string)post('cot_mail_desde', '')), 'cot_mail_desde_nombre' => post('cot_mail_desde_nombre'),
            'cot_mail_responder' => post('cot_mail_responder'), 'cot_smtp_sin_verificar' => post('cot_smtp_sin_verificar') ? '1' : '0',
            'cot_nombre' => post('cot_nombre'), 'cot_direccion' => post('cot_direccion'), 'cot_telefonos' => post('cot_telefonos'), 'cot_email' => post('cot_email'), 'cot_web' => post('cot_web'),
            'cot_ciudad' => post('cot_ciudad'), 'cot_firmante' => post('cot_firmante'), 'cot_cargo' => post('cot_cargo'), 'cot_firma_lineas' => post('cot_firma_lineas'),
            'cot_validez' => post('cot_validez'), 'cot_entrega' => post('cot_entrega'), 'cot_pago' => post('cot_pago'),
            'cot_inicio' => (string)max(1, (int)post('cot_inicio', 1)), 'cot_texto_final' => post('cot_texto_final'),
        ];
        foreach ($mapa as $k => $v) ajuste_set($k, $v === null ? null : (string)$v);
        if ((string)($_POST['cot_smtp_clave'] ?? '') !== '') ajuste_set('cot_smtp_clave', secreto_cifrar((string)$_POST['cot_smtp_clave']));
        flash('Configuración de cotizaciones guardada.');
    } elseif ($acc === 'probar') {
        $to = mb_strtolower((string)post('destino', ''));
        $cfg = correo_config('cot');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) flash('Escriba un correo de destino válido para la prueba.', 'danger');
        else {
            $res = enviar_correo($to, 'Prueba de correo de cotizaciones', correo_plantilla('Correo de prueba',
                '<p>Si recibió este mensaje, la cuenta de correo de <strong>cotizaciones</strong> está bien configurada.</p>'
                . correo_ficha(['Servidor' => e($cfg['modo'] === 'mail' ? 'mail() del servidor' : $cfg['host'] . ':' . $cfg['puerto'] . ' (' . $cfg['seguridad'] . ')'), 'Remitente' => e($cfg['desde'])])),
                ['cuenta' => 'cot', 'motivo' => 'prueba', 'forzar' => true]);
            flash($res['ok'] ? 'Correo de prueba enviado a ' . $to . '.' : 'No se pudo enviar: ' . $res['error'], $res['ok'] ? 'success' : 'danger');
        }
    } elseif ($acc === 'banner') {
        $f = $_FILES['banner'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) flash('Seleccione una imagen JPG o PNG.', 'warning');
        elseif ($f['size'] > 3 * 1024 * 1024) flash('La imagen supera 3 MB.', 'warning');
        else {
            $datos = (string)file_get_contents($f['tmp_name']);
            $info = @getimagesizefromstring($datos);
            $pruebaPdf = new SimplePdf(false);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || !$pruebaPdf->addImage($datos)) {
                flash('Imagen no compatible. Use un JPG o un PNG de 8 bits sin entrelazado (guárdelo de nuevo desde un editor si es necesario).', 'danger');
            } else {
                foreach (glob(UPLOAD_DIR . '/cot_banner.*') ?: [] as $old) @unlink($old);
                file_put_contents(UPLOAD_DIR . '/cot_banner.' . ($info[2] === IMAGETYPE_PNG ? 'png' : 'jpg'), $datos);
                flash('Encabezado actualizado.');
            }
        }
    } elseif ($acc === 'banner_quitar') {
        foreach (glob(UPLOAD_DIR . '/cot_banner.*') ?: [] as $old) @unlink($old);
        flash('Se restauró el encabezado predeterminado.');
    }
    redirect(url('cotizacion_config'));
}

$cfg = correo_config('cot');
$em = cot_emisor();
$personalizado = (bool)(glob(UPLOAD_DIR . '/cot_banner.*') ?: []);
?>
<form method="post" class="row g-3">
  <?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
  <div class="col-xl-7">
    <div class="panel">
      <div class="panel-h"><h3>Cuenta de correo de cotizaciones</h3><div class="actions"><?= correo_activo('cot') ? badge('Envío activo', 'success') : badge('Envío desactivado', 'secondary') ?></div></div>
      <p class="small muted">Es una cuenta <strong>distinta</strong> de la de la mesa de ayuda: las cotizaciones se envían, con el PDF adjunto, desde este buzón (p. ej. <em>cotizaciones@su-dominio</em>).</p>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="cot_mail_activo" value="1" id="ma" <?= ajuste('cot_mail_activo', 0) ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="ma">Permitir enviar cotizaciones por correo</label></div>
      <div class="form-grid">
        <div><label class="form-label">Método de envío</label><select class="form-select" name="cot_mail_modo"><?= options(['smtp' => 'SMTP (recomendado)', 'mail' => 'Función mail() del servidor'], $cfg['modo']) ?></select></div>
        <div data-show-when="cot_mail_modo:smtp"><label class="form-label">Servidor SMTP</label><input class="form-control" name="cot_smtp_host" value="<?= e($cfg['host']) ?>" placeholder="mail.su-dominio.co"></div>
        <div data-show-when="cot_mail_modo:smtp"><label class="form-label">Puerto</label><input class="form-control" type="number" name="cot_smtp_puerto" value="<?= (int)$cfg['puerto'] ?>"></div>
        <div data-show-when="cot_mail_modo:smtp"><label class="form-label">Seguridad</label><select class="form-select" name="cot_smtp_seguridad"><?= options(['ssl' => 'SSL/TLS (puerto 465)', 'tls' => 'STARTTLS (puerto 587)', 'none' => 'Ninguna (puerto 25)'], $cfg['seguridad']) ?></select></div>
        <div data-show-when="cot_mail_modo:smtp"><label class="form-label">Usuario</label><input class="form-control" name="cot_smtp_usuario" value="<?= e($cfg['usuario']) ?>" autocomplete="off" placeholder="cotizaciones@su-dominio.co"></div>
        <div data-show-when="cot_mail_modo:smtp"><label class="form-label">Contraseña</label><input class="form-control" type="password" name="cot_smtp_clave" autocomplete="new-password" placeholder="<?= $cfg['clave'] !== '' ? '•••••••• (guardada; escriba solo para cambiarla)' : '' ?>"></div>
        <div><label class="form-label req">Correo remitente</label><input class="form-control" type="email" name="cot_mail_desde" value="<?= e($cfg['desde']) ?>"></div>
        <div><label class="form-label">Nombre del remitente</label><input class="form-control" name="cot_mail_desde_nombre" value="<?= e($cfg['desde_nombre']) ?>" placeholder="Soluciones E&amp;S"></div>
        <div><label class="form-label">Responder a (opcional)</label><input class="form-control" type="email" name="cot_mail_responder" value="<?= e($cfg['responder']) ?>"></div>
        <div data-show-when="cot_mail_modo:smtp" class="d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="cot_smtp_sin_verificar" value="1" id="sv" <?= $cfg['sin_verificar'] ? 'checked' : '' ?>><label class="form-check-label small" for="sv">No verificar el certificado SSL (solo si falla la conexión)</label></div></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-h"><h3>Datos de su empresa (aparecen en el PDF)</h3></div>
      <div class="form-grid">
        <div><label class="form-label">Nombre de la empresa</label><input class="form-control" name="cot_nombre" value="<?= e($em['nombre']) ?>"></div>
        <div><label class="form-label">Ciudad</label><input class="form-control" name="cot_ciudad" value="<?= e($em['ciudad']) ?>"></div>
        <div><label class="form-label">Dirección</label><input class="form-control" name="cot_direccion" value="<?= e($em['direccion']) ?>"></div>
        <div><label class="form-label">Teléfonos</label><input class="form-control" name="cot_telefonos" value="<?= e($em['telefonos']) ?>"></div>
        <div><label class="form-label">Correo</label><input class="form-control" type="email" name="cot_email" value="<?= e($em['email']) ?>"></div>
        <div><label class="form-label">Sitio web</label><input class="form-control" name="cot_web" value="<?= e($em['web']) ?>"></div>
        <div><label class="form-label">Firma: nombre</label><input class="form-control" name="cot_firmante" value="<?= e($em['firmante']) ?>"></div>
        <div><label class="form-label">Firma: cargo</label><input class="form-control" name="cot_cargo" value="<?= e($em['cargo']) ?>"></div>
        <div class="full"><label class="form-label">Contacto en el cuadro «Cordial saludo» (una línea por renglón)</label><textarea class="form-control" name="cot_firma_lineas" rows="2"><?= e($em['firma_lineas']) ?></textarea></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-h"><h3>Valores por defecto y consecutivo</h3></div>
      <div class="form-grid">
        <div><label class="form-label">Validez de la oferta / vigencia</label><input class="form-control" name="cot_validez" value="<?= e($em['validez']) ?>"></div>
        <div><label class="form-label">Tiempo de entrega</label><input class="form-control" name="cot_entrega" value="<?= e($em['entrega']) ?>"></div>
        <div><label class="form-label">Forma de pago</label><input class="form-control" name="cot_pago" value="<?= e($em['pago']) ?>"></div>
        <div class="full"><label class="form-label">Texto al final de la cotización con ítems (por defecto)</label><textarea class="form-control" name="cot_texto_final" rows="3" placeholder="Ej.: Los precios no incluyen IVA. Garantía de 12 meses por defectos de fábrica."><?= e($em['texto_final']) ?></textarea><div class="form-text">Se carga en cada cotización nueva con ítems y se puede cambiar en cada una.</div></div>
        <div><label class="form-label">Iniciar el consecutivo en</label><input class="form-control" type="number" min="1" name="cot_inicio" value="<?= (int)ajuste('cot_inicio', 1) ?>"><div class="form-text">Próximo número: <strong><?= cot_siguiente() ?></strong>. El consecutivo es único para ambos formatos y nunca retrocede por debajo del último emitido.</div></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Guardar configuración</button></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="panel">
      <div class="panel-h"><h3>Probar el envío</h3></div>
      <p class="small muted">Guarde primero la configuración y luego envíe un correo de prueba.</p>
      <div class="d-flex gap-2">
        <input class="form-control" type="email" name="destino" form="f-probar" value="<?= e(user()['email']) ?>">
        <button class="btn btn-outline-primary text-nowrap" form="f-probar"><i class="bi bi-send me-1"></i>Enviar prueba</button>
      </div>
    </div>
    <div class="panel">
      <div class="panel-h"><h3>Encabezado del PDF</h3><div class="actions"><?= $personalizado ? badge('Personalizado', 'info') : badge('Predeterminado', 'secondary') ?></div></div>
      <img src="<?= url('cotizacion_config', ['banner' => 1, 'v' => time()]) ?>" alt="Encabezado actual" class="img-fluid border rounded mb-3" style="background:#fff">
      <p class="small muted">Imagen con el logo y las marcas que aparece arriba en ambos formatos. Use JPG o PNG (máx. 3 MB); proporción recomendada ancha, p. ej. 1080 × 250 px.</p>
      <div class="d-flex flex-wrap gap-2">
        <input class="form-control form-control-sm" style="max-width:260px" type="file" name="banner" form="f-banner" accept=".jpg,.jpeg,.png">
        <button class="btn btn-sm btn-outline-primary" form="f-banner">Subir</button>
        <?php if ($personalizado): ?><button class="btn btn-sm btn-outline-secondary" form="f-banner-q">Restaurar</button><?php endif; ?>
      </div>
    </div>
  </div>
</form>
<form method="post" id="f-probar" hidden><?= csrf_field() ?><input type="hidden" name="accion" value="probar"></form>
<form method="post" id="f-banner" hidden enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="accion" value="banner"></form>
<form method="post" id="f-banner-q" hidden data-confirm="¿Volver al encabezado predeterminado?"><?= csrf_field() ?><input type="hidden" name="accion" value="banner_quitar"></form>
