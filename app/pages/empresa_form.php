<?php
require_perm('empresas', get('id') ? 'editar' : 'crear');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM empresas WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$title = $r ? 'Editar empresa' : 'Nueva empresa';
$errors = [];
$usuarios = [];
foreach (all('SELECT u.id, u.nombre, u.email, e.nombre AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.activo = 1 ORDER BY u.nombre') as $us) $usuarios[(int)$us['id']] = $us;

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
    ];
    if ($d['nombre'] === '') $errors[] = 'El nombre es obligatorio.';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es válido.';
    if ($r && $r['tipo'] === 'propia' && $d['tipo'] !== 'propia' && val('SELECT COUNT(*) FROM facturas WHERE empresa_id = ?', [$id]) > 0) {
        $errors[] = 'Esta empresa tiene facturas en cartera; no puede cambiarse a tipo Cliente.';
    }
    $notif = array_values(array_unique(array_map('intval', (array)($_POST['notificar'] ?? []))));
    if (!$errors) {
        if ($r) { update('empresas', $d, $id); flash('Empresa actualizada.'); }
        else { $d['creado'] = now(); $id = (int)insert('empresas', $d); flash('Empresa creada.'); }
        q('DELETE FROM empresa_notificar WHERE empresa_id = ?', [$id]);
        foreach ($notif as $uid) if (isset($usuarios[$uid])) q('INSERT INTO empresa_notificar (empresa_id, usuario_id) VALUES (?, ?)', [$id, $uid]);
        redirect(url('empresas'));
    }
    $r = array_merge($r ?? [], $d);
} else {
    $notif = $id ? array_map('intval', array_column(all('SELECT usuario_id FROM empresa_notificar WHERE empresa_id = ?', [$id]), 'usuario_id')) : [];
}
$r = $r ?? ['nombre' => '', 'nit' => '', 'tipo' => 'cliente', 'contacto' => '', 'telefono' => '', 'email' => '', 'direccion' => '', 'activo' => 1];
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
      <label class="form-label mb-0">Usuarios a notificar cuando se envía un ticket de esta empresa</label>
      <div class="form-text mb-2">Reciben un correo cada vez que se crea un ticket de esta empresa (además del solicitante y el equipo de soporte).</div>
      <?php if (!$usuarios): ?><div class="text-muted small">No hay usuarios activos.</div><?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:4px 16px;max-height:260px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;padding:10px">
        <?php foreach ($usuarios as $uid => $us): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="notificar[]" value="<?= $uid ?>" id="nu<?= $uid ?>" <?= in_array($uid, $notif, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="nu<?= $uid ?>"><?= e($us['nombre']) ?><small class="text-muted d-block"><?= e($us['email']) ?><?= $us['empresa'] ? ' · ' . e($us['empresa']) : '' ?></small></label></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= url('empresas') ?>">Cancelar</a>
    </div>
  </form>
</div>
