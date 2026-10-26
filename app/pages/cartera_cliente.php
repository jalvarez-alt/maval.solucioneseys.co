<?php
require_perm('cartera', 'ver');
require_once APP_DIR . '/cartera_estado.php';
$propias = empresas_list('propia');
$emp = (int)get('empresa', 0);
if ($emp && !isset($propias[$emp])) $emp = 0;
$clave = (string)get('c', '');
$activo = correo_activo();
$puedeEnviar = can('cartera', 'administrar');

// ---------- Listado de clientes ----------
if ($clave === '') {
    $title = 'Cartera por cliente';
    $clientes = car_clientes($emp);
    if ($b = get('b')) {
        $bk = car_cli_clave($b);
        $clientes = array_filter($clientes, fn($c) => str_contains($c['clave'], $bk) || ($c['nit'] !== '' && str_contains(emp_nit_clave($c['nit']), emp_nit_clave($b) ?: '-')));
    }
    $resumen = car_envios_resumen();
    $gestiones = all('SELECT ce.*, u.nombre AS usuario FROM cartera_envios ce LEFT JOIN usuarios u ON u.id = ce.usuario_id ORDER BY ce.fecha DESC, ce.id DESC LIMIT 15');
    $tot = ['saldo' => array_sum(array_column($clientes, 'saldo')), 'vencido' => array_sum(array_column($clientes, 'vencido'))];
    ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('cartera', array_filter(['empresa' => $emp ?: null])) ?>"><i class="bi bi-arrow-left"></i> Cartera</a>
  <div class="tabs m-0 ms-1">
    <a class="<?= !$emp ? 'on' : '' ?>" href="<?= url('cartera_cliente') ?>">Ambas empresas</a>
    <?php foreach ($propias as $pid => $pnom): ?><a class="<?= $emp === $pid ? 'on' : '' ?>" href="<?= url('cartera_cliente', ['empresa' => $pid]) ?>"><?= e($pnom) ?></a><?php endforeach; ?>
  </div>
</div>

<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-people"></i>Clientes con saldo</div><div class="v"><?= count($clientes) ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-receipt"></i>Por cobrar</div><div class="v"><?= money($tot['saldo']) ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-exclamation-triangle"></i>Vencido</div><div class="v <?= $tot['vencido'] > 0 ? 'text-danger' : '' ?>"><?= money($tot['vencido']) ?></div></div>
</div>

<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="cartera_cliente"><?php if ($emp): ?><input type="hidden" name="empresa" value="<?= $emp ?>"><?php endif; ?>
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Nombre o NIT del cliente">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <?php if (!$activo): ?><div class="actions"><span class="small text-danger">Correo de la aplicación sin configurar<?= can('config', 'administrar') ? ' · <a href="' . url('config_correo') . '">Configurar</a>' : '' ?></span></div><?php endif; ?>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Cliente</th><th class="r">Facturas</th><th class="r">Saldo</th><th class="r">Vencido</th><th>Vence la más antigua</th><th>Último estado de cuenta</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clientes as $c): $env = car_envio_cliente($c, $resumen); $ant = $c['facturas'][0]; ?>
      <tr>
        <td><a class="row-link" href="<?= url('cartera_cliente', array_filter(['c' => $c['clave'], 'empresa' => $emp ?: null])) ?>"><?= e($c['nombre']) ?></a><?= $c['nit'] ? '<br><small class="muted">NIT ' . e($c['nit']) . '</small>' : '' ?></td>
        <td class="r"><?= $c['n'] ?><?= $c['vencidas'] ? '<br><small class="text-danger">' . $c['vencidas'] . ' vencida' . ($c['vencidas'] > 1 ? 's' : '') . '</small>' : '' ?></td>
        <td class="r fw-semibold"><?= money($c['saldo']) ?></td>
        <td class="r <?= $c['vencido'] > 0 ? 'text-danger' : 'muted' ?>"><?= money($c['vencido']) ?></td>
        <td class="nowrap"><?= fdate($ant['fecha_vencimiento']) ?><?= $ant['dias'] > 0 ? '<br><small class="text-danger">' . $ant['dias'] . ' días</small>' : '' ?></td>
        <td class="nowrap small"><?= $env ? fdate($env['fecha'], true) . ' ' . ($env['ok'] ? '' : badge('Falló', 'danger')) . '<br><span class="muted">' . $env['n'] . ' envío' . ($env['n'] > 1 ? 's' : '') . '</span>' : '<span class="muted">Nunca</span>' ?></td>
        <td class="r"><a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= url('cartera_cliente', array_filter(['c' => $c['clave'], 'empresa' => $emp ?: null])) ?>"><i class="bi bi-envelope"></i> Estado de cuenta</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$clientes): ?><tr><td colspan="7" class="empty">No hay clientes con saldo pendiente<?= get('b') ? ' que coincidan con la búsqueda' : '' ?>.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="panel">
  <div class="panel-h"><h3>Últimas gestiones de cobro</h3></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Fecha</th><th>Cliente</th><th>Enviado a</th><th class="r">Facturas</th><th class="r">Saldo informado</th><th>Por</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach ($gestiones as $g): ?>
      <tr>
        <td class="nowrap small"><?= fdate($g['fecha'], true) ?></td>
        <td><a href="<?= url('cartera_cliente', ['c' => $g['cliente_clave']]) ?>"><?= e($g['cliente_nombre']) ?></a></td>
        <td class="small"><?= e($g['destinatarios']) ?></td>
        <td class="r"><?= (int)$g['n_facturas'] ?></td>
        <td class="r"><?= money($g['total_saldo']) ?></td>
        <td class="small muted"><?= e($g['usuario'] ?: '—') ?></td>
        <td><?= $g['ok'] ? badge('Enviado', 'success') : badge('Falló', 'danger') . '<br><small class="text-danger">' . e(mb_strimwidth((string)$g['error'], 0, 80, '…')) . '</small>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$gestiones): ?><tr><td colspan="7" class="empty">Aún no se ha enviado ningún estado de cuenta.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php
    return;
}

// ---------- Estado de cuenta de un cliente ----------
$cli = car_cliente($clave, $emp);
if (!$cli) {
    flash('El cliente no tiene facturas con saldo pendiente' . ($emp ? ' en ' . $propias[$emp] : '') . '.', 'info');
    redirect(url('cartera_cliente', array_filter(['empresa' => $emp ?: null])));
}
$title = 'Estado de cuenta';
$contacto = car_contacto($cli);
$qsCli = array_filter(['c' => $cli['clave'], 'empresa' => $emp ?: null]);
$porId = array_column($cli['facturas'], null, 'id');

$asuntoDef = 'Estado de cuenta - ' . $cli['nombre'];
$emisores = array_unique(array_column($cli['facturas'], 'empresa'));
$firma = 'Departamento de cartera' . (count($emisores) === 1 ? "\n" . reset($emisores) : '');
$msgDef = 'Cordial saludo' . ($contacto && $contacto['contacto'] ? ', ' . $contacto['contacto'] : '') . ".\n\n"
    . "Le compartimos el estado de cuenta con las facturas que a la fecha se encuentran pendientes de pago. "
    . "Le agradecemos programar el pago de los saldos vencidos. Si ya realizó el pago, por favor envíenos el soporte para aplicarlo.\n\n"
    . "Atentamente,\n" . $firma;

// Valores del formulario: los enviados (vista previa o error) o los predeterminados
$enviado = $_SERVER['REQUEST_METHOD'] === 'POST';
$para = $enviado ? (string)post('para', '') : (string)($contacto['email'] ?? '');
$asunto = $enviado ? (string)post('asunto', '') : $asuntoDef;
$mensaje = $enviado ? (string)post('mensaje', '') : $msgDef;
$sel = $enviado ? array_values(array_filter(array_map('intval', (array)post('f', [])), fn($i) => isset($porId[$i]))) : array_keys($porId);
$previa = null;
$errores = [];

if ($enviado) {
    require_perm('cartera', 'administrar');
    [$dest, $malos] = car_correos($para);
    if (!$dest) $errores[] = 'Escriba al menos un correo de destino.';
    if ($malos) $errores[] = 'Correos no válidos: ' . implode(', ', $malos) . '.';
    if (!$sel) $errores[] = 'Seleccione al menos una factura.';
    if (trim($asunto) === '') $errores[] = 'Escriba el asunto.';
    if (!$activo) $errores[] = 'El correo de la aplicación no está configurado o está desactivado.';
    $facturas = array_map(fn($i) => $porId[$i], $sel);

    if (!$errores && post('accion') === 'enviar') {
        $html = car_estado_html($cli, $facturas, $mensaje);
        $res = enviar_correo($dest, $asunto, $html, ['motivo' => 'cartera', 'desde_nombre' => car_remitente($facturas)]);
        insert('cartera_envios', [
            'fecha' => now(), 'cliente_clave' => $cli['clave'], 'cliente_nombre' => mb_substr($cli['nombre'], 0, 150), 'cliente_nit' => $cli['nit'] ?: null,
            'empresa_id' => $emp ?: null, 'destinatarios' => mb_substr(implode(', ', $dest), 0, 500), 'asunto' => mb_substr($asunto, 0, 255), 'mensaje' => $mensaje,
            'facturas' => json_encode(array_map(fn($f) => ['id' => (int)$f['id'], 'numero' => $f['numero'], 'empresa' => $f['empresa'], 'vence' => $f['fecha_vencimiento'], 'total' => (float)$f['total'], 'saldo' => round((float)$f['saldo'], 2)], $facturas), JSON_UNESCAPED_UNICODE),
            'n_facturas' => count($facturas), 'total_saldo' => round(array_sum(array_column($facturas, 'saldo')), 2),
            'ok' => $res['ok'] ? 1 : 0, 'error' => $res['ok'] ? null : mb_substr((string)$res['error'], 0, 500), 'usuario_id' => user()['id'],
        ]);
        if ($res['ok']) {
            error_log('MAVAL: ' . user()['email'] . ' envió estado de cuenta de ' . $cli['nombre'] . ' (' . count($facturas) . ' facturas) a ' . implode(', ', $dest) . '.');
            flash('Estado de cuenta enviado a ' . implode(', ', $dest) . '.');
            redirect(url('cartera_cliente', $qsCli));
        }
        $errores[] = 'No se pudo enviar: ' . rtrim((string)$res['error'], '. ') . '. El intento quedó registrado en el historial.';
    } elseif (!$errores) {
        $previa = car_estado_html($cli, $facturas, $mensaje);
    }
}

$historial = all('SELECT ce.*, u.nombre AS usuario FROM cartera_envios ce LEFT JOIN usuarios u ON u.id = ce.usuario_id WHERE ce.cliente_clave IN (' . implode(',', array_fill(0, count($cli['claves']), '?')) . ') ORDER BY ce.fecha DESC, ce.id DESC LIMIT 30', $cli['claves']);
$selSaldo = array_sum(array_map(fn($i) => (float)$porId[$i]['saldo'], $sel));
$hidden = function () use ($para, $asunto, $mensaje, $sel) {
    $h = csrf_field() . '<input type="hidden" name="para" value="' . e($para) . '"><input type="hidden" name="asunto" value="' . e($asunto) . '"><input type="hidden" name="mensaje" value="' . e($mensaje) . '">';
    foreach ($sel as $i) $h .= '<input type="hidden" name="f[]" value="' . $i . '">';
    return $h;
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('cartera_cliente', array_filter(['empresa' => $emp ?: null])) ?>"><i class="bi bi-arrow-left"></i> Cartera por cliente</a>
  <h2 class="h5 m-0 ms-1"><?= e($cli['nombre']) ?></h2>
  <?php if ($cli['nit']): ?><span class="muted">NIT <?= e($cli['nit']) ?></span><?php endif; ?>
  <div class="tabs m-0 ms-auto">
    <a class="<?= !$emp ? 'on' : '' ?>" href="<?= url('cartera_cliente', ['c' => $cli['clave']]) ?>">Ambas empresas</a>
    <?php foreach ($propias as $pid => $pnom): ?><a class="<?= $emp === $pid ? 'on' : '' ?>" href="<?= url('cartera_cliente', ['c' => $cli['clave'], 'empresa' => $pid]) ?>"><?= e($pnom) ?></a><?php endforeach; ?>
  </div>
</div>

<?php foreach ($errores as $er): ?><div class="alert alert-danger"><?= e($er) ?></div><?php endforeach; ?>

<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-receipt"></i>Saldo pendiente</div><div class="v"><?= money($cli['saldo']) ?></div><div class="sub"><?= $cli['n'] ?> factura<?= $cli['n'] > 1 ? 's' : '' ?> · facturado <?= money($cli['total']) ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-exclamation-triangle"></i>Vencido</div><div class="v <?= $cli['vencido'] > 0 ? 'text-danger' : '' ?>"><?= money($cli['vencido']) ?></div><div class="sub"><?= $cli['vencidas'] ?> vencida<?= $cli['vencidas'] === 1 ? '' : 's' ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-cash-coin"></i>Abonado</div><div class="v"><?= money($cli['pagado']) ?></div></div>
</div>

<?php if ($previa !== null): ?>
<div class="panel" id="previa">
  <div class="panel-h"><h3><i class="bi bi-eye me-2"></i>Vista previa del correo</h3>
    <div class="actions"><span class="small muted">Así lo recibirá el cliente. Revise y confirme el envío.</span></div></div>
  <div class="meta"><dl>
    <dt>De</dt><dd><?= e(car_remitente(array_map(fn($i) => $porId[$i], $sel)) . ' <' . correo_config()['desde'] . '>') ?></dd>
    <dt>Para</dt><dd><?= e(implode(', ', car_correos($para)[0])) ?></dd>
    <dt>Asunto</dt><dd><strong><?= e($asunto) ?></strong></dd>
    <dt>Facturas</dt><dd><?= count($sel) ?> · saldo <?= money($selSaldo) ?></dd>
  </dl></div>
  <iframe title="Vista previa del estado de cuenta" sandbox srcdoc="<?= e($previa) ?>" style="width:100%;height:640px;border:1px solid #e2e8f0;border-radius:6px;background:#f3f5f7"></iframe>
  <div class="form-actions">
    <a class="btn btn-outline-secondary" href="#enviar"><i class="bi bi-pencil me-1"></i>Seguir editando</a>
    <form method="post" class="d-inline"><?= $hidden() ?><input type="hidden" name="accion" value="enviar">
      <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Enviar ahora</button></form>
  </div>
</div>
<?php endif; ?>

<div class="detail-grid">
  <div>
    <div class="panel">
      <div class="panel-h"><h3>Facturas pendientes</h3><div class="actions"><span class="small muted" id="sel-info"></span></div></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><?php if ($puedeEnviar): ?><th class="sel-col"><input type="checkbox" class="form-check-input" id="sel-todas" title="Incluir todas"></th><?php endif; ?><th>Factura</th><?php if (!$emp): ?><th>Empresa</th><?php endif; ?><th>Emisión</th><th>Vence</th><th class="r">Valor</th><th class="r">Abonado</th><th class="r">Saldo</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($cli['facturas'] as $f): $est = factura_estado($f + ['anulada' => 0]); ?>
          <tr>
            <?php if ($puedeEnviar): ?><td class="sel-col"><input type="checkbox" class="form-check-input sel-f" form="f-envio" name="f[]" value="<?= (int)$f['id'] ?>" data-saldo="<?= (float)$f['saldo'] ?>" <?= in_array((int)$f['id'], $sel, true) ? 'checked' : '' ?>></td><?php endif; ?>
            <td class="nowrap"><a href="<?= url('factura', ['id' => $f['id']]) ?>"><?= e($f['numero']) ?></a><?= $f['concepto'] ? '<br><small class="muted">' . e(mb_strimwidth($f['concepto'], 0, 40, '…')) . '</small>' : '' ?></td>
            <?php if (!$emp): ?><td class="muted small"><?= e($f['empresa']) ?></td><?php endif; ?>
            <td class="nowrap"><?= fdate($f['fecha_emision']) ?></td>
            <td class="nowrap"><?= fdate($f['fecha_vencimiento']) ?><?= $f['dias'] > 0 ? '<br><small class="text-danger">' . $f['dias'] . ' días</small>' : '' ?></td>
            <td class="r"><?= money($f['total']) ?></td>
            <td class="r"><?= money($f['pagado']) ?></td>
            <td class="r fw-semibold"><?= money($f['saldo']) ?></td>
            <td><?= badge($est) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="<?= ($puedeEnviar ? 1 : 0) + ($emp ? 3 : 4) ?>">Total cartera del cliente</td><td class="r"><?= money($cli['total']) ?></td><td class="r"><?= money($cli['pagado']) ?></td><td class="r"><?= money($cli['saldo']) ?></td><td></td></tr></tfoot>
      </table></div>
    </div>

    <?php if ($puedeEnviar): ?>
    <div class="panel" id="enviar">
      <div class="panel-h"><h3><i class="bi bi-envelope me-2"></i>Enviar estado de cuenta</h3>
        <div class="actions"><?= $activo ? '<span class="small muted">Se envía desde la cuenta de la aplicación: ' . e(correo_config()['desde']) . '</span>' : '<span class="small text-danger">Correo de la aplicación sin configurar' . (can('config', 'administrar') ? ' · <a href="' . url('config_correo') . '">Configurar</a>' : '') . '</span>' ?></div></div>
      <form method="post" id="f-envio" action="<?= e(url('cartera_cliente', $qsCli)) ?>#previa"><?= csrf_field() ?><input type="hidden" name="accion" value="previa">
        <div class="form-grid">
          <div class="full"><label class="form-label req">Para (varios separados por coma)</label><input class="form-control" name="para" value="<?= e($para) ?>" required placeholder="cartera@cliente.com">
            <?php if (!$contacto || !$contacto['email']): ?><small class="muted">El cliente no tiene correo registrado en Empresas<?= $contacto && can('empresas', 'editar') ? ' · <a href="' . url('empresa_form', ['id' => $contacto['id']]) . '">Agregarlo</a>' : '' ?>.</small><?php endif; ?></div>
          <div class="full"><label class="form-label req">Asunto</label><input class="form-control" name="asunto" value="<?= e($asunto) ?>" maxlength="200" required></div>
          <div class="full"><label class="form-label">Mensaje</label><textarea class="form-control" name="mensaje" rows="8"><?= e($mensaje) ?></textarea>
            <small class="muted">Debajo del mensaje se agrega la tabla con las facturas seleccionadas, el saldo de cada una y el total.</small></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" <?= $activo ? '' : 'disabled' ?>><i class="bi bi-eye me-1"></i>Vista previa</button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel meta">
      <div class="panel-h"><h3>Cliente</h3></div>
      <dl>
        <dt>Nombre</dt><dd><?= e($cli['nombre']) ?></dd>
        <dt>NIT</dt><dd><?= e($cli['nit'] ?: '—') ?></dd>
        <?php if ($contacto): ?>
        <dt>Contacto</dt><dd><?= e($contacto['contacto'] ?: '—') ?></dd>
        <dt>Correo</dt><dd><?= e($contacto['email'] ?: '—') ?></dd>
        <dt>Teléfono</dt><dd><?= e($contacto['telefono'] ?: '—') ?></dd>
        <?php else: ?>
        <dt>Empresas</dt><dd class="muted">No está registrado como cliente<?= can('empresas', 'crear') ? ' · <a href="' . url('empresa_form') . '">Registrar</a>' : '' ?></dd>
        <?php endif; ?>
        <?php if (count($cli['nombres']) > 1): ?><dt>Aparece como</dt><dd class="small"><?= implode('<br>', array_map('e', array_keys($cli['nombres']))) ?></dd><?php endif; ?>
      </dl>
    </div>
    <div class="panel">
      <div class="panel-h"><h3>Historial de estados de cuenta</h3></div>
      <div class="tbl-wrap"><table class="tbl">
        <tbody>
        <?php foreach ($historial as $h): $fs = json_decode((string)$h['facturas'], true) ?: []; ?>
          <tr><td class="small">
            <div class="d-flex justify-content-between gap-2"><span class="muted nowrap"><?= fdate($h['fecha'], true) ?></span><?= $h['ok'] ? badge('Enviado', 'success') : badge('Falló', 'danger') ?></div>
            <div><?= e($h['asunto']) ?></div>
            <div class="muted">A: <?= e($h['destinatarios']) ?></div>
            <div><?= (int)$h['n_facturas'] ?> factura<?= (int)$h['n_facturas'] === 1 ? '' : 's' ?> · <?= money($h['total_saldo']) ?><?= $fs ? ' <span class="muted">(' . e(implode(', ', array_column($fs, 'numero'))) . ')</span>' : '' ?></div>
            <div class="muted">Por <?= e($h['usuario'] ?: '—') ?></div>
            <?php if (!$h['ok']): ?><div class="text-danger"><?= e($h['error']) ?></div><?php endif; ?>
          </td></tr>
        <?php endforeach; ?>
        <?php if (!$historial): ?><tr><td class="empty">Aún no se le ha enviado un estado de cuenta.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<?php if ($puedeEnviar): ?>
<script>
(function () {
  var checks = Array.prototype.slice.call(document.querySelectorAll('.sel-f')), todas = document.getElementById('sel-todas'), info = document.getElementById('sel-info');
  var fmt = function (v) { return '$ ' + Math.round(v).toLocaleString('es-CO'); };
  var refresh = function () {
    var on = checks.filter(function (c) { return c.checked; });
    var total = on.reduce(function (s, c) { return s + parseFloat(c.getAttribute('data-saldo')); }, 0);
    info.textContent = on.length + ' de ' + checks.length + ' en el correo · ' + fmt(total);
    todas.checked = on.length === checks.length; todas.indeterminate = on.length > 0 && on.length < checks.length;
  };
  todas.addEventListener('change', function () { checks.forEach(function (c) { c.checked = todas.checked; }); refresh(); });
  checks.forEach(function (c) { c.addEventListener('change', refresh); });
  refresh();
})();
</script>
<?php endif; ?>
