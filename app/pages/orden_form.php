<?php
require_once APP_DIR . '/ordenes.php';
$id = (int)get('id', 0);
$o = $id ? ord_obtener($id) : null;
if ($id && !$o) not_found();
require_perm('ordenes', $id ? 'editar' : 'crear');
if ($o && in_array($o['estado'], ['Completada', 'Cancelada'], true) && !can('ordenes', 'aprobar')) { flash('La orden está ' . mb_strtolower($o['estado']) . '; para modificarla hay que reabrirla.', 'warning'); redirect(url('orden', ['id' => $id])); }
$title = $o ? 'Editar orden ' . ord_codigo($o) : 'Nueva orden de servicio';
$errors = [];
$u = user();

$clientes = all('SELECT id, nombre, nit, contacto, telefono, email, direccion FROM empresas WHERE tipo = ? AND activo = 1 ORDER BY nombre', ['cliente']);
$tecnicos = all('SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre');
$cots = all("SELECT id, consecutivo, cliente_nombre FROM cotizaciones WHERE estado IN ('Enviada','Aceptada') ORDER BY consecutivo DESC LIMIT 150");
$antes = $id ? ord_fotos($id, 'antes') : [];
$despues = $id ? ord_fotos($id, 'despues') : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'tipo' => in_array(post('tipo'), ORD_TIPOS, true) ? post('tipo') : ORD_TIPOS[0],
        'prioridad' => in_array(post('prioridad'), ORD_PRIORIDADES, true) ? post('prioridad') : 'Normal',
        'empresa_id' => null,
        'cliente_nombre' => mb_substr((string)post('cliente_nombre', ''), 0, 150), 'cliente_nit' => mb_substr((string)post('cliente_nit', ''), 0, 30) ?: null,
        'contacto' => mb_substr((string)post('contacto', ''), 0, 150) ?: null, 'telefono' => mb_substr((string)post('telefono', ''), 0, 40) ?: null,
        'cliente_email' => mb_substr((string)post('cliente_email', ''), 0, 150) ?: null, 'sede' => mb_substr((string)post('sede', ''), 0, 200) ?: null,
        'tecnico_id' => (int)post('tecnico_id', 0) ?: null, 'cotizacion_id' => (int)post('cotizacion_id', 0) ?: null, 'ticket_id' => (int)post('ticket_id', 0) ?: null,
        'referencia' => mb_substr((string)post('referencia', ''), 0, 80) ?: null,
        'fecha_programada' => valid_date(post('fecha_programada')), 'hora_programada' => preg_match('/^\d{2}:\d{2}$/', (string)post('hora_programada', '')) ? post('hora_programada') : null,
        'descripcion' => post('descripcion'),
    ];
    if ($o) {
        $d += ['trabajo_realizado' => post('trabajo_realizado'), 'observaciones' => post('observaciones'),
            'firma_nombre' => mb_substr((string)post('firma_nombre', ''), 0, 120) ?: null, 'firma_cargo' => mb_substr((string)post('firma_cargo', ''), 0, 100) ?: null];
    }
    foreach ($clientes as $c) if (mb_strtolower($c['nombre']) === mb_strtolower($d['cliente_nombre'])) $d['empresa_id'] = (int)$c['id'];
    if ($d['cotizacion_id'] && !val('SELECT COUNT(*) FROM cotizaciones WHERE id = ?', [$d['cotizacion_id']])) $d['cotizacion_id'] = null;
    if ($d['ticket_id'] && !val('SELECT COUNT(*) FROM tickets WHERE id = ?', [$d['ticket_id']])) $d['ticket_id'] = null;
    if ($d['cotizacion_id'] && !$d['referencia']) $d['referencia'] = 'Cotización ' . val('SELECT consecutivo FROM cotizaciones WHERE id = ?', [$d['cotizacion_id']]);
    $d['tecnico_nombre'] = $d['tecnico_id'] ? val('SELECT nombre FROM usuarios WHERE id = ?', [$d['tecnico_id']]) : null;
    $mat = inf_filas_post('mat', 4); $hor = inf_filas_post('hor', 4);

    if ($d['cliente_nombre'] === '') $errors[] = 'Indique el cliente.';
    if (trim((string)$d['descripcion']) === '') $errors[] = 'Describa el trabajo a realizar.';
    if ($d['cliente_email'] && !filter_var($d['cliente_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo del cliente no es válido.';
    if (!$errors) {
        $nuevoTecnico = !$o || (int)$o['tecnico_id'] !== (int)$d['tecnico_id'];
        $pdo = db(); $pdo->beginTransaction();
        try {
            if ($o) {
                if (in_array($o['estado'], ['Pendiente', 'Programada'], true)) $d['estado'] = $d['fecha_programada'] ? 'Programada' : 'Pendiente';
                $d['actualizado'] = now(); update('ordenes', $d, $id); q('DELETE FROM orden_filas WHERE orden_id = ?', [$id]);
            } else {
                $d += ['estado' => $d['fecha_programada'] ? 'Programada' : 'Pendiente', 'consecutivo' => ord_siguiente(), 'creado_por' => (int)$u['id'], 'creado' => now(), 'actualizado' => now()];
                $id = insert('ordenes', $d);
            }
            foreach (['materiales' => $mat, 'horas' => $hor] as $sec => $lista) {
                foreach ($lista as $n => $f) {
                    $row = ['orden_id' => $id, 'seccion' => $sec, 'orden' => $n + 1];
                    foreach ($f as $k => $v) $row['c' . $k] = $v === '' ? null : $v;
                    insert('orden_filas', $row);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        foreach ((array)($_POST['quitar_adj'] ?? []) as $aid) {
            $a = one('SELECT * FROM adjuntos WHERE id = ? AND entidad IN (\'orden_antes\', \'orden_despues\') AND entidad_id = ?', [(int)$aid, $id]);
            if ($a) { @unlink(UPLOAD_DIR . '/' . $a['archivo']); q('DELETE FROM adjuntos WHERE id = ?', [$a['id']]); }
        }
        save_uploads('fotos_antes', 'orden_antes', $id);
        save_uploads('fotos_despues', 'orden_despues', $id);
        if ($nuevoTecnico && $d['tecnico_id']) notificar_orden_asignada($id);
        flash($o ? 'Orden actualizada.' : 'Orden ' . ord_codigo(['consecutivo' => val('SELECT consecutivo FROM ordenes WHERE id = ?', [$id])]) . ' creada.');
        redirect(url('orden', ['id' => $id]));
    }
    $o = array_merge($o ?? [], $d);
    $filas = ['materiales' => array_map(fn($f) => ['c1' => $f[1], 'c2' => $f[2], 'c3' => $f[3], 'c4' => $f[4]], $mat), 'horas' => array_map(fn($f) => ['c1' => $f[1], 'c2' => $f[2], 'c3' => $f[3], 'c4' => $f[4]], $hor)];
    $editando = $id > 0;
} elseif ($o) {
    $filas = ord_filas($id); $editando = true;
} else {
    // Orden nueva: precarga desde un ticket o una cotización
    $editando = false; $filas = ['materiales' => [], 'horas' => []];
    $o = ['tipo' => ORD_TIPOS[0], 'prioridad' => 'Normal', 'cliente_nombre' => '', 'cliente_nit' => '', 'contacto' => '', 'telefono' => '', 'cliente_email' => '', 'sede' => '', 'tecnico_id' => (int)$u['id'],
        'ticket_id' => null, 'cotizacion_id' => null, 'referencia' => '', 'fecha_programada' => '', 'hora_programada' => '', 'descripcion' => '', 'trabajo_realizado' => '', 'observaciones' => '', 'firma_nombre' => '', 'firma_cargo' => ''];
    if ($tkId = (int)get('ticket', 0)) {
        [$sc, $sp] = ticket_scope('t');
        $tk = one("SELECT t.*, e.nombre AS emp_nombre, e.nit AS emp_nit, e.contacto AS emp_contacto, e.telefono AS emp_tel, e.email AS emp_email, e.direccion AS emp_dir,
                s.nombre AS sol_nombre, s.email AS sol_email, s.telefono AS sol_tel
            FROM tickets t LEFT JOIN empresas e ON e.id = t.empresa_id LEFT JOIN usuarios s ON s.id = t.solicitante_id WHERE t.id = ? AND $sc", array_merge([$tkId], $sp));
        if ($tk) {
            $o = array_merge($o, ['ticket_id' => (int)$tk['id'], 'cliente_nombre' => $tk['emp_nombre'] ?: ($tk['sol_nombre'] ?? ''), 'cliente_nit' => $tk['emp_nit'] ?? '',
                'contacto' => $tk['emp_contacto'] ?: ($tk['sol_nombre'] ?? ''), 'telefono' => $tk['emp_tel'] ?: ($tk['sol_tel'] ?? ''), 'cliente_email' => $tk['emp_email'] ?: ($tk['sol_email'] ?? ''), 'sede' => $tk['emp_dir'] ?? '',
                'prioridad' => ord_prioridad_de_ticket((string)$tk['prioridad']), 'tipo' => $tk['categoria'] === 'Locativas' ? 'Mantenimiento correctivo' : 'Soporte en sitio',
                'referencia' => $tk['codigo'], 'descripcion' => $tk['codigo'] . ' · ' . $tk['asunto'] . "\n\n" . trim((string)$tk['descripcion']),
                'tecnico_id' => (int)($tk['asignado_id'] ?: $u['id'])]);
        }
    } elseif ($cotId = (int)get('cotizacion', 0)) {
        $cot = cot_obtener($cotId);
        if ($cot) {
            $items = array_map(fn($it) => '- ' . trim($it['descripcion']) . ((float)$it['cantidad'] != 1 ? ' (' . rtrim(rtrim(number_format((float)$it['cantidad'], 2, ',', ''), '0'), ',') . ($it['unidad'] ? ' ' . $it['unidad'] : '') . ')' : ''), cot_items($cotId));
            $o = array_merge($o, ['cotizacion_id' => (int)$cot['id'], 'cliente_nombre' => $cot['cliente_nombre'], 'cliente_nit' => $cot['cliente_nit'] ?? '', 'contacto' => $cot['atencion'] ?? '', 'cliente_email' => $cot['cliente_email'] ?? '',
                'sede' => $cot['cliente_direccion'] ?? '', 'referencia' => 'Cotización ' . $cot['consecutivo'], 'descripcion' => 'Ejecución de la cotización No. ' . $cot['consecutivo'] . ($items ? ":\n" . implode("\n", $items) : '')]);
        }
    }
}
$ticketVinc = !empty($o['ticket_id']) ? one('SELECT id, codigo, asunto FROM tickets WHERE id = ?', [(int)$o['ticket_id']]) : null;
foreach (['materiales', 'horas'] as $s) if (!$filas[$s]) $filas[$s] = [[]];

/** Cuadrícula de fotos existentes con casilla para quitarlas. */
function ord_fotos_form(array $fotos): void
{
    if (!$fotos) return; ?>
  <div class="d-flex flex-wrap gap-3 mb-3">
    <?php foreach ($fotos as $a): $img = in_array(strtolower(pathinfo($a['archivo'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true); ?>
      <div class="border rounded p-2" style="width:150px">
        <?php if ($img): ?><a href="archivo.php?id=<?= (int)$a['id'] ?>" target="_blank"><img src="archivo.php?id=<?= (int)$a['id'] ?>" alt="" style="width:100%;height:100px;object-fit:cover;border-radius:4px"></a><?php else: ?><div class="small"><i class="bi bi-paperclip"></i> <?= e($a['nombre_original']) ?></div><?php endif; ?>
        <div class="small text-truncate mt-1" title="<?= e($a['nombre_original']) ?>"><?= e($a['nombre_original']) ?></div>
        <label class="form-check small text-danger mt-1"><input class="form-check-input" type="checkbox" name="quitar_adj[]" value="<?= (int)$a['id'] ?>"> Quitar</label>
      </div>
    <?php endforeach; ?>
  </div>
<?php }
?>
<form method="post" enctype="multipart/form-data" id="ord">
  <?= csrf_field() ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <input type="hidden" name="ticket_id" value="<?= (int)($o['ticket_id'] ?? 0) ?>">
  <div class="panel">
    <div class="panel-h"><h3><i class="bi bi-tools me-2"></i>Orden de servicio</h3><?php if ($ticketVinc): ?><div class="actions small muted">Ticket <a href="<?= url('ticket', ['id' => $ticketVinc['id']]) ?>"><?= e($ticketVinc['codigo']) ?></a> · <?= e(mb_strimwidth($ticketVinc['asunto'], 0, 50, '…')) ?></div><?php endif; ?></div>
    <div class="form-grid">
      <div class="full" style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
        <div><label class="form-label req">Cliente</label><input class="form-control" name="cliente_nombre" id="cliente" list="dl-clientes" value="<?= e($o['cliente_nombre']) ?>" required autocomplete="off" maxlength="150"></div>
        <div><label class="form-label">NIT / C.C.</label><input class="form-control" name="cliente_nit" id="nit" value="<?= e($o['cliente_nit']) ?>" maxlength="30"></div>
      </div>
      <div><label class="form-label">Persona de contacto</label><input class="form-control" name="contacto" id="contacto" value="<?= e($o['contacto']) ?>" maxlength="150"></div>
      <div><label class="form-label">Teléfono</label><input class="form-control" name="telefono" id="telefono" value="<?= e($o['telefono']) ?>" maxlength="40"></div>
      <div><label class="form-label">Correo del cliente</label><input class="form-control" type="email" name="cliente_email" id="correo" value="<?= e($o['cliente_email']) ?>" maxlength="150"></div>
      <div><label class="form-label">Sede / dirección del servicio</label><input class="form-control" name="sede" id="sede" value="<?= e($o['sede']) ?>" maxlength="200"></div>
      <div><label class="form-label req">Tipo de servicio</label><select class="form-select" name="tipo"><?= options(ORD_TIPOS, $o['tipo'], false) ?></select></div>
      <div><label class="form-label">Prioridad</label><select class="form-select" name="prioridad"><?= options(ORD_PRIORIDADES, $o['prioridad'], false) ?></select></div>
      <div><label class="form-label">Fecha programada</label><input class="form-control" type="date" name="fecha_programada" value="<?= e($o['fecha_programada']) ?>"></div>
      <div><label class="form-label">Hora</label><input class="form-control" type="time" name="hora_programada" value="<?= e($o['hora_programada']) ?>"></div>
      <div><label class="form-label">Técnico responsable</label><select class="form-select" name="tecnico_id"><option value="">— Sin asignar —</option><?= options(array_column($tecnicos, 'nombre', 'id'), $o['tecnico_id']) ?></select></div>
      <div><label class="form-label">Cotización relacionada</label><select class="form-select" name="cotizacion_id"><option value="">— Ninguna —</option>
        <?php foreach ($cots as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($o['cotizacion_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>No. <?= (int)$c['consecutivo'] ?> · <?= e($c['cliente_nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="full"><label class="form-label">Referencia</label><input class="form-control" name="referencia" value="<?= e($o['referencia']) ?>" maxlength="80" placeholder="Contrato, número de ticket, orden de compra…"></div>
      <div class="full"><label class="form-label req">Trabajo a realizar</label><textarea class="form-control" name="descripcion" rows="6" required placeholder="Qué hay que hacer, dónde y con qué indicaciones. Comience la línea con «- » para que salga con viñeta."><?= e($o['descripcion']) ?></textarea></div>
    </div>
    <datalist id="dl-clientes"><?php foreach ($clientes as $c) echo '<option value="' . e($c['nombre']) . '">'; ?></datalist>
  </div>

<?php if ($editando): ?>
  <div class="panel">
    <div class="panel-h"><h3>Trabajo realizado</h3></div>
    <div class="form-grid">
      <div class="full"><label class="form-label">Descripción de lo realizado</label><textarea class="form-control" name="trabajo_realizado" rows="5" placeholder="Qué se hizo y cómo quedó."><?= e($o['trabajo_realizado'] ?? '') ?></textarea></div>
      <div class="full"><label class="form-label">Observaciones y pendientes</label><textarea class="form-control" name="observaciones" rows="3"><?= e($o['observaciones'] ?? '') ?></textarea></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Materiales y repuestos usados <small class="muted fw-normal">(opcional)</small></h3></div>
    <?php inf_tabla_form('mat', ['Cant.', 'Descripción', 'Marca / referencia', 'Observación'], $filas['materiales'], [10, 40, 25, 25]); ?>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Horas de trabajo <small class="muted fw-normal">(opcional)</small></h3></div>
    <?php inf_tabla_form('hor', ['Fecha', 'Técnico', 'Horas', 'Detalle'], $filas['horas'], [16, 24, 10, 50]); ?>
  </div>

  <div class="panel">
    <div class="panel-h"><h3><i class="bi bi-images me-2"></i>Fotos del servicio</h3></div>
    <div class="form-grid">
      <div class="full"><h6 class="sec">Antes</h6><?php ord_fotos_form($antes); ?>
        <input class="form-control" type="file" name="fotos_antes[]" accept="image/*" multiple></div>
      <div class="full"><h6 class="sec">Después</h6><?php ord_fotos_form($despues); ?>
        <input class="form-control" type="file" name="fotos_despues[]" accept="image/*" multiple>
        <div class="form-text">Las fotos JPG y PNG salen en el PDF. Máximo 10 MB por archivo.</div></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Recibe por parte del cliente</h3></div>
    <div class="form-grid">
      <div><label class="form-label">Nombre</label><input class="form-control" name="firma_nombre" value="<?= e($o['firma_nombre'] ?? '') ?>" maxlength="120"></div>
      <div><label class="form-label">Cargo</label><input class="form-control" name="firma_cargo" value="<?= e($o['firma_cargo'] ?? '') ?>" maxlength="100"></div>
    </div>
  </div>
<?php endif; ?>

  <div class="form-actions">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
    <a class="btn btn-outline-secondary" href="<?= $id ? url('orden', ['id' => $id]) : url('ordenes') ?>">Cancelar</a>
  </div>
</form>
<script>
(function () {
  var cli = <?= json_encode(array_column($clientes, null, 'nombre'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var c = document.getElementById('cliente');
  c.addEventListener('change', function () {
    var d = cli[c.value]; if (!d) return;
    [['nit', 'nit'], ['contacto', 'contacto'], ['telefono', 'telefono'], ['correo', 'email'], ['sede', 'direccion']].forEach(function (p) { var el = document.getElementById(p[0]); if (el && !el.value && d[p[1]]) el.value = d[p[1]]; });
  });
  var contador = 1000;
  document.addEventListener('click', function (e) {
    var q = e.target.closest('.inf-quitar');
    if (q) { var tr = q.closest('tr'), tb = tr.parentNode; if (tb.children.length > 1) tr.remove(); else Array.prototype.forEach.call(tr.querySelectorAll('input,select'), function (x) { x.value = ''; }); return; }
    var a = e.target.closest('.inf-agregar');
    if (a) {
      var tabla = document.querySelector('.inf-tabla[data-campo="' + a.getAttribute('data-campo') + '"]');
      var html = tabla.querySelector('template').innerHTML.replace(/__i__/g, ++contador);
      tabla.querySelector('tbody').insertAdjacentHTML('beforeend', html);
      var f = tabla.querySelector('tbody tr:last-child input'); if (f) f.focus();
    }
  });
})();
</script>
