<?php
require_perm('inventario', get('id') ? 'editar' : 'crear');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM equipos WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
if (($r && es_computo($r['tipo'])) || es_computo(get('tipo'))) redirect(url('computo_form', array_filter(['id' => $id ?: null, 'empresa' => get('empresa')])));
$title = $r ? 'Editar equipo ' . $r['codigo'] : 'Nuevo equipo';
$errors = [];

if (get('sugerir')) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['codigo' => siguiente_codigo((string)get('sugerir'))]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'tipo' => (string)post('tipo', ''),
        'codigo' => mb_strtoupper((string)post('codigo', '')),
        'marca' => post('marca'), 'modelo' => post('modelo'), 'serial' => post('serial'),
        'empresa_id' => post('empresa_id') ? (int)post('empresa_id') : null,
        'usuario_id' => post('usuario_id') ? (int)post('usuario_id') : null,
        'responsable' => post('responsable'), 'ubicacion' => post('ubicacion'),
        'estado' => (string)post('estado', 'Activo'),
        'fecha_compra' => valid_date(post('fecha_compra')),
        'valor' => post('valor') !== null ? parse_money(post('valor')) : null,
        'garantia_hasta' => valid_date(post('garantia_hasta')),
        'imei' => post('imei'), 'linea' => post('linea'), 'ip' => post('ip'),
        'tipo_aire' => post('tipo_aire'), 'btu' => post('btu') ? (int)preg_replace('/\D/', '', (string)post('btu')) : null, 'tecnologia' => post('tecnologia'),
        'refrigerante' => post('refrigerante'), 'voltaje' => post('voltaje'), 'serial_exterior' => post('serial_exterior'),
        'especificaciones' => post('especificaciones'), 'notas' => post('notas'),
    ];
    if (!in_array($d['tipo'], OTROS_TIPOS, true)) $errors[] = 'Seleccione el tipo de equipo (los computadores se registran en Inventario de cómputo).';
    if (!in_array($d['estado'], EQUIPO_ESTADOS, true)) $d['estado'] = 'Activo';
    if ($d['codigo'] === '' && $r) $d['codigo'] = $r['codigo'];
    if ($d['codigo'] === '' && !$errors) $d['codigo'] = siguiente_codigo($d['tipo']);
    if (val('SELECT id FROM equipos WHERE codigo = ? AND id <> ?', [$d['codigo'], $id])) $errors[] = 'Ya existe otro equipo con el código ' . $d['codigo'] . '.';
    if ($d['usuario_id'] && !$d['empresa_id']) $d['empresa_id'] = val('SELECT empresa_id FROM usuarios WHERE id = ?', [$d['usuario_id']]) ?: null;

    if (!$errors) {
        $uid = (int)user()['id'];
        if ($r) {
            $cambios = [];
            $nombreEmp = fn($i) => $i ? (val('SELECT nombre FROM empresas WHERE id = ?', [$i]) ?: '—') : '—';
            $nombreUsr = fn($i) => $i ? (val('SELECT nombre FROM usuarios WHERE id = ?', [$i]) ?: '—') : '—';
            if ((int)$r['empresa_id'] !== (int)$d['empresa_id']) $cambios[] = 'Empresa: ' . $nombreEmp($r['empresa_id']) . ' → ' . $nombreEmp($d['empresa_id']);
            if ((int)$r['usuario_id'] !== (int)$d['usuario_id']) $cambios[] = 'Asignado: ' . $nombreUsr($r['usuario_id']) . ' → ' . $nombreUsr($d['usuario_id']);
            if ((string)$r['responsable'] !== (string)$d['responsable']) $cambios[] = 'Responsable: ' . ($r['responsable'] ?: '—') . ' → ' . ($d['responsable'] ?: '—');
            if ((string)$r['ubicacion'] !== (string)$d['ubicacion']) $cambios[] = 'Ubicación: ' . ($r['ubicacion'] ?: '—') . ' → ' . ($d['ubicacion'] ?: '—');
            if ($r['estado'] !== $d['estado']) $cambios[] = 'Estado: ' . $r['estado'] . ' → ' . $d['estado'];
            $d['actualizado'] = now();
            update('equipos', $d, $id);
            if ($cambios) insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => mb_substr(implode('. ', $cambios), 0, 500), 'fecha' => now()]);
            flash('Equipo actualizado.');
        } else {
            $d['creado'] = now();
            $id = insert('equipos', $d);
            insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => 'Equipo registrado en el inventario.', 'fecha' => now()]);
            flash('Equipo ' . $d['codigo'] . ' registrado.');
        }
        redirect(url('equipo', ['id' => $id]));
    }
    $r = array_merge($r ?? [], $d);
}
$r = $r ?? ['tipo' => get('tipo', ''), 'codigo' => '', 'marca' => '', 'modelo' => '', 'serial' => '', 'empresa_id' => get('empresa'), 'usuario_id' => null, 'responsable' => '', 'ubicacion' => '',
    'estado' => 'Activo', 'fecha_compra' => '', 'valor' => '', 'garantia_hasta' => '', 'imei' => '', 'linea' => '', 'ip' => '', 'especificaciones' => '', 'notas' => ''];
$usuarios = all("SELECT u.id, u.nombre, e.nombre AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.activo = 1 ORDER BY u.nombre");
$tiposCel = 'Celular,Tablet,Teléfono IP';
$tiposRed = 'Impresora,Cámara,DVR / NVR,Router / Switch,Access point,Teléfono IP';
?>
<div class="panel" style="max-width:1000px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <h6 class="sec mt-0">Identificación</h6>
    <div class="form-grid">
      <div><label class="form-label req">Tipo</label><select class="form-select" name="tipo" id="tipo" required><option value="">Seleccione…</option><?= options(OTROS_TIPOS, $r['tipo'], false) ?></select></div>
      <div><label class="form-label">Código / placa</label><input class="form-control" name="codigo" id="codigo" value="<?= e($r['codigo']) ?>" placeholder="Se genera automáticamente"></div>
      <div><label class="form-label">Estado</label><select class="form-select" name="estado"><?= options(EQUIPO_ESTADOS, $r['estado'], false) ?></select></div>
      <div><label class="form-label">Marca</label><input class="form-control" name="marca" value="<?= e($r['marca']) ?>"></div>
      <div><label class="form-label">Modelo</label><input class="form-control" name="modelo" value="<?= e($r['modelo']) ?>"></div>
      <div><label class="form-label">Serial</label><input class="form-control" name="serial" value="<?= e($r['serial']) ?>"></div>
      <div data-show-when="tipo:<?= e($tiposCel) ?>"><label class="form-label">IMEI</label><input class="form-control" name="imei" value="<?= e($r['imei']) ?>"></div>
      <div data-show-when="tipo:<?= e($tiposCel) ?>"><label class="form-label">Línea / número</label><input class="form-control" name="linea" value="<?= e($r['linea']) ?>"></div>
      <div data-show-when="tipo:<?= e($tiposRed) ?>"><label class="form-label">Dirección IP</label><input class="form-control" name="ip" value="<?= e($r['ip']) ?>"></div>
    </div>
    <div data-show-when="tipo:Aire acondicionado">
      <h6 class="sec">Datos del aire acondicionado</h6>
      <div class="form-grid">
        <div><label class="form-label">Tipo de aire</label><select class="form-select" name="tipo_aire"><option value="">— Seleccione —</option><?= options(AIRE_TIPO_EQUIPO, $r['tipo_aire'] ?? '', false) ?></select></div>
        <div><label class="form-label">Capacidad (BTU/h)</label><input class="form-control" name="btu" inputmode="numeric" value="<?= e($r['btu'] ?? '') ?>" placeholder="Ej.: 12000, 18000, 24000"><div class="form-text">12.000 BTU/h ≈ 1 tonelada de refrigeración.</div></div>
        <div><label class="form-label">Tecnología</label><select class="form-select" name="tecnologia"><option value="">— Seleccione —</option><?= options(AIRE_TECNOLOGIA, $r['tecnologia'] ?? '', false) ?></select></div>
        <div><label class="form-label">Refrigerante</label><select class="form-select" name="refrigerante"><option value="">— Seleccione —</option><?= options(AIRE_REFRIGERANTE, $r['refrigerante'] ?? '', false) ?></select></div>
        <div><label class="form-label">Voltaje</label><select class="form-select" name="voltaje"><option value="">— Seleccione —</option><?= options(AIRE_VOLTAJE, $r['voltaje'] ?? '', false) ?></select></div>
        <div><label class="form-label">Serial unidad exterior (condensadora)</label><input class="form-control" name="serial_exterior" value="<?= e($r['serial_exterior'] ?? '') ?>"><div class="form-text">El campo «Serial» de arriba corresponde a la unidad interior (evaporadora).</div></div>
      </div>
    </div>
    <h6 class="sec">Asignación</h6>
    <div class="form-grid">
      <div><label class="form-label">Empresa</label><select class="form-select" name="empresa_id"><option value="">— Sin empresa —</option><?= options(empresas_list(), $r['empresa_id']) ?></select></div>
      <div><label class="form-label">Usuario de la mesa de ayuda</label><select class="form-select" name="usuario_id"><option value="">— Ninguno —</option>
        <?php foreach ($usuarios as $us): ?><option value="<?= (int)$us['id'] ?>" <?= (int)$r['usuario_id'] === (int)$us['id'] ? 'selected' : '' ?>><?= e($us['nombre'] . ($us['empresa'] ? ' — ' . $us['empresa'] : '')) ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="form-label">Responsable (si no tiene usuario)</label><input class="form-control" name="responsable" value="<?= e($r['responsable']) ?>"></div>
      <div><label class="form-label">Ubicación / área</label><input class="form-control" name="ubicacion" value="<?= e($r['ubicacion']) ?>" placeholder="Ej.: Contabilidad, piso 2"></div>
    </div>
    <h6 class="sec">Compra y garantía</h6>
    <div class="form-grid">
      <div><label class="form-label">Fecha de compra</label><input class="form-control" type="date" name="fecha_compra" value="<?= e($r['fecha_compra']) ?>"></div>
      <div><label class="form-label">Valor de compra</label><input class="form-control money" name="valor" value="<?= e($r['valor']) ?>" inputmode="decimal"></div>
      <div><label class="form-label">Garantía hasta</label><input class="form-control" type="date" name="garantia_hasta" value="<?= e($r['garantia_hasta']) ?>"></div>
    </div>
    <h6 class="sec">Detalle técnico</h6>
    <div class="form-grid">
      <div class="full"><label class="form-label">Especificaciones</label><textarea class="form-control" name="especificaciones" rows="4" placeholder="<?= e("Procesador, RAM, disco, sistema operativo, licencias, resolución, tóner…") ?>"><?= e($r['especificaciones']) ?></textarea></div>
      <div class="full"><label class="form-label">Notas</label><textarea class="form-control" name="notas" rows="2"><?= e($r['notas']) ?></textarea></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= isset($r['id']) ? url('equipo', ['id' => $r['id']]) : url('inventario') ?>">Cancelar</a>
    </div>
  </form>
</div>
<?php if (!isset($r['id'])): ?>
<script>
document.getElementById('tipo').addEventListener('change', function () {
  var c = document.getElementById('codigo');
  if (!this.value || (c.value && !c.dataset.auto)) return;
  fetch('<?= url('equipo_form') ?>&sugerir=' + encodeURIComponent(this.value)).then(function (r) { return r.json(); }).then(function (j) { c.value = j.codigo; c.dataset.auto = '1'; });
});
</script>
<?php endif; ?>
