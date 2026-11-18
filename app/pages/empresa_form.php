<?php
require_perm('empresas', get('id') ? 'editar' : 'crear');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM empresas WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$title = $r ? 'Editar empresa' : 'Nueva empresa';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'nombre' => (string)post('nombre', ''),
        'nit' => post('nit'),
        'tipo' => post('tipo') === 'propia' ? 'propia' : 'cliente',
        'contacto' => post('contacto'),
        'telefono' => post('telefono'),
        'email' => post('email'),
        'direccion' => post('direccion'),
        'activo' => post('activo') ? 1 : 0,
        'tk_desde' => mb_strtolower(trim((string)post('tk_desde', ''))) ?: null,
        'tk_desde_nombre' => trim((string)post('tk_desde_nombre', '')) ?: null,
        'tk_responder' => mb_strtolower(trim((string)post('tk_responder', ''))) ?: null,
        'tk_host' => trim((string)post('tk_host', '')) ?: null,
        'tk_puerto' => (int)post('tk_puerto', 465) ?: 465,
        'tk_seguridad' => in_array(post('tk_seguridad'), ['ssl', 'tls', 'none'], true) ? post('tk_seguridad') : 'ssl',
        'tk_usuario' => trim((string)post('tk_usuario', '')) ?: null,
    ];
    $lista = array_values(array_unique(array_filter(array_map('mb_strtolower', preg_split('/[\s,;]+/', trim((string)post('notificar_correos', '')))))));
    foreach ($lista as $c) if (!filter_var($c, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo a notificar «' . $c . '» no es válido.';
    $d['notificar_correos'] = $lista ? mb_substr(implode(', ', $lista), 0, 1000) : null;
    if ((string)post('tk_clave', '') !== '') $d['tk_clave'] = secreto_cifrar((string)post('tk_clave', ''));   // vacío = conservar la actual
    if ($d['tk_desde']) {
        if (!filter_var($d['tk_desde'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo de envío de tickets no es válido.';
        if (!$d['tk_host']) $errors[] = 'Escriba el servidor SMTP de la cuenta de tickets.';
        if ($d['tk_responder'] && !filter_var($d['tk_responder'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo «responder a» no es válido.';
    }
    if ($d['nombre'] === '') $errors[] = 'El nombre es obligatorio.';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es válido.';
    if ($r && $r['tipo'] === 'propia' && $d['tipo'] !== 'propia' && val('SELECT COUNT(*) FROM facturas WHERE empresa_id = ?', [$id]) > 0) {
        $errors[] = 'Esta empresa tiene facturas en cartera; no puede cambiarse a tipo Cliente.';
    }
    if (!$errors) {
        if ($r) { update('empresas', $d, $id); flash('Empresa actualizada.'); }
        else { $d['creado'] = now(); $id = (int)insert('empresas', $d); flash('Empresa creada.'); }
        redirect(url('empresas'));
    }
    $r = array_merge($r ?? [], $d);
}
$r = $r ?? ['nombre' => '', 'nit' => '', 'tipo' => 'cliente', 'contacto' => '', 'telefono' => '', 'email' => '', 'direccion' => '', 'tk_desde' => '', 'tk_desde_nombre' => '', 'tk_responder' => '', 'tk_host' => '', 'tk_puerto' => 465, 'tk_seguridad' => 'ssl', 'tk_usuario' => '', 'tk_clave' => '', 'notificar_correos' => '', 'activo' => 1];
?>
<div class="panel" style="max-width:820px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="full"><label class="form-label req">Razón social / nombre</label><input class="form-control" name="nombre" value="<?= e($r['nombre']) ?>" required></div>
      <div><label class="form-label">NIT</label><input class="form-control" name="nit" value="<?= e($r['nit']) ?>"></div>
      <div><label class="form-label">Tipo</label><select class="form-select" name="tipo"><?= options(['cliente' => 'Cliente', 'propia' => 'Propia (cartera y gastos)'], $r['tipo']) ?></select></div>
      <div><label class="form-label">Persona de contacto</label><input class="form-control" name="contacto" value="<?= e($r['contacto']) ?>"></div>
      <div><label class="form-label">Teléfono</label><input class="form-control" name="telefono" value="<?= e($r['telefono']) ?>"></div>
      <div><label class="form-label">Correo</label><input class="form-control" type="email" name="email" value="<?= e($r['email']) ?>"></div>
      <div><label class="form-label">Dirección</label><input class="form-control" name="direccion" value="<?= e($r['direccion']) ?>"></div>
      <div class="d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="activo" value="1" id="act" <?= $r['activo'] ? 'checked' : '' ?>><label class="form-check-label" for="act">Activa</label></div></div>
    </div>
    <div class="mt-4">
      <label class="form-label mb-0">Cuenta de correo para los tickets de esta empresa</label>
      <div class="form-text mb-2">Los correos de los tickets de esta empresa (creación, respuestas y cambios de estado) salen desde esta cuenta. Déjela vacía para usar la cuenta general de la mesa de ayuda. Si el envío con esta cuenta falla, se reintenta con la cuenta general.
        <button type="button" class="btn btn-sm btn-outline-primary ms-1" id="tk-m365"><i class="bi bi-microsoft"></i> Usar Microsoft 365</button></div>
      <div class="form-grid">
        <div><label class="form-label">Correo remitente</label><input class="form-control" type="email" name="tk_desde" value="<?= e($r['tk_desde'] ?? '') ?>" placeholder="soporte@empresa.com"></div>
        <div><label class="form-label">Nombre visible</label><input class="form-control" name="tk_desde_nombre" maxlength="120" value="<?= e($r['tk_desde_nombre'] ?? '') ?>" placeholder="Soporte Empresa"></div>
        <div><label class="form-label">Servidor SMTP</label><input class="form-control" name="tk_host" value="<?= e($r['tk_host'] ?? '') ?>" placeholder="mail.empresa.com" autocomplete="off"></div>
        <div><label class="form-label">Puerto y seguridad</label><div class="input-group"><input class="form-control" type="number" name="tk_puerto" value="<?= (int)($r['tk_puerto'] ?: 465) ?>"><select class="form-select" name="tk_seguridad"><?= options(['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => 'Ninguna'], $r['tk_seguridad'] ?: 'ssl') ?></select></div></div>
        <div><label class="form-label">Usuario SMTP</label><input class="form-control" name="tk_usuario" value="<?= e($r['tk_usuario'] ?? '') ?>" autocomplete="off"></div>
        <div><label class="form-label">Contraseña</label><input class="form-control" type="password" name="tk_clave" autocomplete="new-password" placeholder="<?= !empty($r['tk_clave']) ? '•••••••• (dejar vacío para conservar)' : '' ?>"></div>
        <div class="full"><label class="form-label">Responder a (opcional)</label><input class="form-control" type="email" name="tk_responder" value="<?= e($r['tk_responder'] ?? '') ?>"></div>
      </div>
    </div>
    <div class="mt-4">
      <label class="form-label mb-0" for="notif-correos">Correos a notificar de los tickets de esta empresa</label>
      <div class="form-text mb-2">Reciben una copia cuando se crea un ticket de esta empresa, cuando alguien responde y cuando cambia de estado (además del solicitante y el equipo de soporte; las notas internas no se envían). Escriba una dirección por línea o separadas por coma.</div>
      <textarea class="form-control" id="notif-correos" name="notificar_correos" rows="3" maxlength="1000" placeholder="gerencia@empresa.com&#10;sistemas@empresa.com"><?= e(str_replace(', ', "
", (string)($r['notificar_correos'] ?? ''))) ?></textarea>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= url('empresas') ?>">Cancelar</a>
    </div>
  </form>
</div>
<script>
(function () {
  var b = document.getElementById('tk-m365'); if (!b) return;
  b.addEventListener('click', function () {
    var q = function (n) { return document.querySelector('[name="' + n + '"]'); };
    q('tk_host').value = 'smtp.office365.com'; q('tk_puerto').value = 587; q('tk_seguridad').value = 'tls';
    if (!q('tk_usuario').value) q('tk_usuario').value = q('tk_desde').value;
  });
})();
</script>
