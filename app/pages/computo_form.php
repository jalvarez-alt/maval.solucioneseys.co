<?php
require_perm('computo', get('id') ? 'editar' : 'crear');
require_once APP_DIR . '/computo.php';
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM equipos WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
if ($r && !es_computo($r['tipo'])) redirect(url('equipo_form', ['id' => $id]));
$title = $r ? 'Editar ' . $r['codigo'] : 'Nuevo equipo de cómputo';
$errors = [];

$soft = $id ? all('SELECT * FROM equipo_software WHERE equipo_id = ? ORDER BY id', [$id]) : [];
$base = array_fill_keys(array_keys(SOFT_BASE), ['nombre' => '', 'version' => '', 'tipo_licencia' => '', 'licencia' => '', 'vence' => '']);
$otros = [];
foreach ($soft as $s) {
    if (isset($base[$s['categoria']]) && $base[$s['categoria']]['nombre'] === '') $base[$s['categoria']] = $s;
    else $otros[] = $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correos = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)post('correos', ''))))));
    foreach ($correos as $c) if (!filter_var($c, FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo «' . $c . '» no es válido.';
    $d = [
        'tipo' => (string)post('tipo', ''),
        'codigo' => mb_strtoupper((string)post('codigo', '')),
        'responsable' => post('responsable'),
        'usuario_id' => post('usuario_id') ? (int)post('usuario_id') : null,
        'cargo' => post('cargo'),
        'correos' => $correos ? mb_substr(implode(', ', $correos), 0, 500) : null,
        'empresa_id' => post('empresa_id') ? (int)post('empresa_id') : null,
        'marca' => post('marca'), 'modelo' => post('modelo'), 'serial' => post('serial'),
        'procesador' => post('procesador'), 'ram' => post('ram'), 'disco' => post('disco'),
        'fecha_compra' => valid_date(post('fecha_compra')),
        'valor' => post('valor') !== null ? parse_money(post('valor')) : null,
        'garantia_hasta' => valid_date(post('garantia_hasta')),
        'renovacion' => in_array(post('renovacion'), RENOVACION, true) ? post('renovacion') : null,
        'estado' => in_array(post('estado'), EQUIPO_ESTADOS, true) ? post('estado') : 'Activo',
        'ubicacion' => post('ubicacion'), 'ip' => post('ip'),
        'notas' => post('notas'),
    ];
    if (!es_computo($d['tipo'])) $errors[] = 'Seleccione el tipo: Servidor, Escritorio, Portátil o Todo en uno.';
    if (!$d['responsable'] && !$d['usuario_id']) $errors[] = 'Indique el usuario del equipo (o escriba el área, p. ej. «Bodega»).';
    if ($d['codigo'] === '' && $r) $d['codigo'] = $r['codigo'];
    if ($d['codigo'] === '' && !$errors) $d['codigo'] = siguiente_codigo($d['tipo']);
    if ($d['codigo'] !== '' && val('SELECT id FROM equipos WHERE codigo = ? AND id <> ?', [$d['codigo'], $id])) $errors[] = 'Ya existe otro equipo con el código ' . $d['codigo'] . '.';
    if (!$d['responsable'] && $d['usuario_id']) $d['responsable'] = val('SELECT nombre FROM usuarios WHERE id = ?', [$d['usuario_id']]);

    // Software
    $nuevoSoft = [];
    $postBase = $_POST['sw'] ?? [];
    foreach (SOFT_BASE as $cat => $lbl) {
        $row = is_array($postBase[$cat] ?? null) ? $postBase[$cat] : [];
        $nombre = trim((string)($row['nombre'] ?? ''));
        $base[$cat] = ['nombre' => $nombre, 'version' => trim((string)($row['version'] ?? '')), 'tipo_licencia' => (string)($row['tipo_licencia'] ?? ''), 'licencia' => (string)($row['licencia'] ?? ''), 'vence' => (string)($row['vence'] ?? '')];
        if ($nombre === '') continue;
        if (!in_array($row['licencia'] ?? '', LICENCIAS, true)) { $errors[] = 'Indique si la licencia de ' . mb_strtolower($lbl) . ' es Legal, No legal o Libre.'; continue; }
        $nuevoSoft[] = ['categoria' => $cat] + $base[$cat];
    }
    $otros = [];
    foreach (($_POST['otro'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $nombre = trim((string)($row['nombre'] ?? ''));
        if ($nombre === '') continue;
        $o = ['nombre' => $nombre, 'version' => trim((string)($row['version'] ?? '')), 'tipo_licencia' => (string)($row['tipo_licencia'] ?? ''), 'licencia' => in_array($row['licencia'] ?? '', LICENCIAS, true) ? $row['licencia'] : 'Legal', 'vence' => (string)($row['vence'] ?? '')];
        $otros[] = $o;
        $nuevoSoft[] = ['categoria' => 'otro'] + $o;
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $uid = (int)user()['id'];
        if ($r) {
            $cambios = [];
            $nombreEmp = fn($i) => $i ? (val('SELECT nombre FROM empresas WHERE id = ?', [$i]) ?: '—') : '—';
            if ((int)$r['empresa_id'] !== (int)$d['empresa_id']) $cambios[] = 'Propietario: ' . $nombreEmp($r['empresa_id']) . ' → ' . $nombreEmp($d['empresa_id']);
            if ((string)$r['responsable'] !== (string)$d['responsable']) $cambios[] = 'Usuario: ' . ($r['responsable'] ?: '—') . ' → ' . ($d['responsable'] ?: '—');
            if ((string)$r['ubicacion'] !== (string)$d['ubicacion']) $cambios[] = 'Ubicación: ' . ($r['ubicacion'] ?: '—') . ' → ' . ($d['ubicacion'] ?: '—');
            if ((string)$r['estado'] !== $d['estado']) $cambios[] = 'Estado: ' . $r['estado'] . ' → ' . $d['estado'];
            if ((string)$r['renovacion'] !== (string)$d['renovacion']) $cambios[] = 'Renovación: ' . ($r['renovacion'] ?: '—') . ' → ' . ($d['renovacion'] ?: '—');
            foreach (['procesador' => 'Procesador', 'ram' => 'RAM', 'disco' => 'Disco'] as $k => $lbl) {
                if ((string)$r[$k] !== (string)$d[$k]) $cambios[] = $lbl . ': ' . ($r[$k] ?: '—') . ' → ' . ($d[$k] ?: '—');
            }
            $firma = fn($list) => implode('|', array_map(fn($s) => $s['categoria'] . ':' . $s['nombre'] . ':' . $s['licencia'], $list));
            if ($firma($soft) !== $firma($nuevoSoft)) $cambios[] = 'Software actualizado (' . count($nuevoSoft) . ' registros)';
            $d['actualizado'] = now();
            update('equipos', $d, $id);
            if ($cambios) insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => mb_substr(implode('. ', $cambios), 0, 500), 'fecha' => now()]);
        } else {
            $d['creado'] = now();
            $id = insert('equipos', $d);
            insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => 'Equipo de cómputo registrado.', 'fecha' => now()]);
        }
        q('DELETE FROM equipo_software WHERE equipo_id = ?', [$id]);
        foreach ($nuevoSoft as $s) {
            insert('equipo_software', [
                'equipo_id' => $id, 'categoria' => $s['categoria'], 'nombre' => mb_substr($s['nombre'], 0, 150),
                'version' => $s['version'] !== '' ? mb_substr($s['version'], 0, 60) : null,
                'tipo_licencia' => $s['tipo_licencia'] !== '' ? mb_substr($s['tipo_licencia'], 0, 40) : null,
                'licencia' => $s['licencia'], 'vence' => valid_date($s['vence']),
            ]);
        }
        $pdo->commit();
        flash($r ? 'Equipo actualizado.' : 'Equipo ' . $d['codigo'] . ' registrado.');
        redirect(post('otro_mas') ? url('computo_form', ['empresa' => $d['empresa_id']]) : url('equipo', ['id' => $id]));
    }
    $r = array_merge($r ?? [], $d);
}
$r = $r ?? ['tipo' => get('tipo', ''), 'codigo' => '', 'responsable' => '', 'usuario_id' => null, 'cargo' => '', 'correos' => '', 'empresa_id' => get('empresa'),
    'marca' => '', 'modelo' => '', 'serial' => '', 'procesador' => '', 'ram' => '', 'disco' => '', 'fecha_compra' => '', 'valor' => '', 'garantia_hasta' => '',
    'renovacion' => '', 'estado' => 'Activo', 'ubicacion' => '', 'ip' => '', 'notas' => ''];
$usuarios = all("SELECT u.id, u.nombre, u.cargo, u.email, e.nombre AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.activo = 1 ORDER BY u.nombre");
// Sugerencias para autocompletar
$sug = fn($sql) => array_column(all($sql), 'v');
$dlMarcas = $sug("SELECT DISTINCT marca v FROM equipos WHERE marca IS NOT NULL AND marca <> '' ORDER BY marca");
$dlCpu = $sug("SELECT DISTINCT procesador v FROM equipos WHERE procesador IS NOT NULL AND procesador <> '' ORDER BY procesador");
$dlSoft = [];
foreach (array_merge(array_keys(SOFT_BASE), ['otro']) as $c) $dlSoft[$c] = $sug("SELECT DISTINCT nombre v FROM equipo_software WHERE categoria = '$c' ORDER BY nombre LIMIT 200");
$dlSoft['so'] = array_values(array_unique(array_merge($dlSoft['so'], ['Windows 11 Pro', 'Windows 11 Home', 'Windows 10 Pro', 'Windows Server 2022', 'Windows Server 2019', 'Ubuntu', 'macOS'])));
$dlSoft['ofimatica'] = array_values(array_unique(array_merge($dlSoft['ofimatica'], ['Microsoft 365', 'Office 2021', 'Office 2019', 'Office 2016', 'LibreOffice', 'Google Workspace', 'WPS Office'])));
$dlSoft['antivirus'] = array_values(array_unique(array_merge($dlSoft['antivirus'], ['ESET Endpoint Security', 'Kaspersky Endpoint', 'Bitdefender GravityZone', 'Microsoft Defender', 'Sophos', 'Avast'])));
$sugerida = renovacion_sugerida($r['fecha_compra'] ?: null);
$tipoIcon = ['Servidor' => 'hdd-rack', 'Escritorio' => 'pc-display', 'Portátil' => 'laptop', 'Todo en uno' => 'display'];
?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" id="fcomp" class="computo-form" autocomplete="off">
  <?= csrf_field() ?>
  <div class="panel">
    <div class="panel-h"><h3>Usuario y propietario</h3></div>
    <div class="form-grid">
      <div><label class="form-label req" for="responsable">Usuario</label><input class="form-control" id="responsable" name="responsable" value="<?= e($r['responsable']) ?>" placeholder="Nombre de quien usa el equipo, o el área"></div>
      <div><label class="form-label" for="cargo">Cargo</label><input class="form-control" id="cargo" name="cargo" value="<?= e($r['cargo']) ?>"></div>
      <div><label class="form-label" for="empresa_id">Propietario</label><select class="form-select" id="empresa_id" name="empresa_id"><option value="">— Seleccione —</option><?= options(empresas_list(), $r['empresa_id']) ?></select></div>
      <div><label class="form-label" for="usuario_id">Usuario de la mesa de ayuda <small class="muted">(opcional: verá el equipo y podrá reportar tickets)</small></label>
        <select class="form-select" id="usuario_id" name="usuario_id"><option value="">— Ninguno —</option>
          <?php foreach ($usuarios as $us): ?><option value="<?= (int)$us['id'] ?>" data-nombre="<?= e($us['nombre']) ?>" data-cargo="<?= e($us['cargo']) ?>" data-email="<?= e($us['email']) ?>" <?= (int)$r['usuario_id'] === (int)$us['id'] ? 'selected' : '' ?>><?= e($us['nombre'] . ($us['empresa'] ? ' — ' . $us['empresa'] : '')) ?></option><?php endforeach; ?>
        </select></div>
      <div class="full">
        <label class="form-label" for="correo-in">Correos <small class="muted">(puede registrar varios: escriba y pulse Enter)</small></label>
        <div class="chips" id="chips"><input id="correo-in" type="text" inputmode="email" placeholder="correo@empresa.com"></div>
        <input type="hidden" name="correos" id="correos" value="<?= e($r['correos']) ?>">
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Equipo</h3></div>
    <fieldset class="mb-4"><legend class="form-label req">Tipo</legend>
      <div class="seg">
        <?php foreach (COMPUTO_TIPOS as $t): ?>
          <label><input type="radio" name="tipo" value="<?= e($t) ?>" <?= $r['tipo'] === $t ? 'checked' : '' ?> required><span><i class="bi bi-<?= $tipoIcon[$t] ?>"></i><?= e($t) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="form-grid">
      <div><label class="form-label" for="marca">Marca</label><input class="form-control" id="marca" name="marca" list="dl-marca" value="<?= e($r['marca']) ?>"></div>
      <div><label class="form-label" for="modelo">Modelo</label><input class="form-control" id="modelo" name="modelo" value="<?= e($r['modelo']) ?>"></div>
      <div><label class="form-label" for="serial">Serial</label><input class="form-control" id="serial" name="serial" value="<?= e($r['serial']) ?>"></div>
      <div><label class="form-label" for="codigo">Código / placa</label><input class="form-control" id="codigo" name="codigo" value="<?= e($r['codigo']) ?>" placeholder="Automático (PC-0001…)"></div>
      <div><label class="form-label" for="procesador">Procesador</label><input class="form-control" id="procesador" name="procesador" list="dl-cpu" value="<?= e($r['procesador']) ?>" placeholder="Ej.: Intel Core i5-1235U"></div>
      <div><label class="form-label" for="ram">RAM</label><input class="form-control" id="ram" name="ram" list="dl-ram" value="<?= e($r['ram']) ?>" placeholder="Ej.: 16 GB"></div>
      <div><label class="form-label" for="disco">DD (disco)</label><input class="form-control" id="disco" name="disco" list="dl-dd" value="<?= e($r['disco']) ?>" placeholder="Ej.: SSD 512 GB"></div>
      <div><label class="form-label" for="fecha_compra">Fecha de compra</label><input class="form-control" type="date" id="fecha_compra" name="fecha_compra" value="<?= e($r['fecha_compra']) ?>"></div>
      <div><label class="form-label" for="valor">Valor de compra</label><input class="form-control money" id="valor" name="valor" value="<?= e($r['valor']) ?>" inputmode="decimal"></div>
      <div><label class="form-label" for="garantia_hasta">Garantía hasta</label><input class="form-control" type="date" id="garantia_hasta" name="garantia_hasta" value="<?= e($r['garantia_hasta']) ?>"></div>
      <div><label class="form-label" for="ubicacion">Ubicación / área</label><input class="form-control" id="ubicacion" name="ubicacion" value="<?= e($r['ubicacion']) ?>"></div>
      <div><label class="form-label" for="ip">Dirección IP</label><input class="form-control" id="ip" name="ip" value="<?= e($r['ip']) ?>"></div>
      <div><label class="form-label" for="estado">Estado</label><select class="form-select" id="estado" name="estado"><?= options(EQUIPO_ESTADOS, $r['estado'], false) ?></select></div>
    </div>
    <fieldset class="mt-4"><legend class="form-label">Renovación</legend>
      <div class="seg seg-renov">
        <?php foreach (RENOVACION as $rv): ?>
          <label class="rv-<?= strtolower($rv) ?>"><input type="radio" name="renovacion" value="<?= $rv ?>" <?= $r['renovacion'] === $rv ? 'checked' : '' ?>><span><?= $rv ?></span></label>
        <?php endforeach; ?>
        <span class="hint" id="sugerencia"><?= $sugerida ? 'Sugerido por antigüedad: ' . $sugerida : '' ?></span>
      </div>
    </fieldset>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Software base y licenciamiento</h3><div class="actions"><span class="small muted">Indique si cada licencia es legal</span></div></div>
    <div class="tbl-wrap"><table class="tbl soft-tbl">
      <thead><tr><th style="width:130px">Componente</th><th>Producto</th><th style="width:120px">Versión</th><th style="width:150px">Tipo de licencia</th><th style="width:270px">Estado</th><th style="width:150px">Vence</th></tr></thead>
      <tbody>
      <?php foreach (SOFT_BASE as $cat => $lbl): $s = $base[$cat]; ?>
        <tr>
          <td class="fw-semibold"><?= e($lbl) ?></td>
          <td><input class="form-control" name="sw[<?= $cat ?>][nombre]" list="dl-<?= $cat ?>" value="<?= e($s['nombre']) ?>" aria-label="Producto <?= e($lbl) ?>"></td>
          <td><input class="form-control" name="sw[<?= $cat ?>][version]" value="<?= e($s['version']) ?>" aria-label="Versión <?= e($lbl) ?>"></td>
          <td><select class="form-select" name="sw[<?= $cat ?>][tipo_licencia]" aria-label="Tipo de licencia <?= e($lbl) ?>"><option value=""></option><?= options(TIPOS_LICENCIA, $s['tipo_licencia'], false) ?></select></td>
          <td><div class="seg seg-lic">
            <?php foreach (LICENCIAS as $lc): ?>
              <label class="lc-<?= $lc === 'No legal' ? 'no' : strtolower($lc) ?>"><input type="radio" name="sw[<?= $cat ?>][licencia]" value="<?= $lc ?>" <?= $s['licencia'] === $lc ? 'checked' : '' ?>><span><?= $lc ?></span></label>
            <?php endforeach; ?>
          </div></td>
          <td><input class="form-control" type="date" name="sw[<?= $cat ?>][vence]" value="<?= e($s['vence']) ?>" aria-label="Vencimiento <?= e($lbl) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Otro software instalado</h3><div class="actions"><button type="button" class="btn btn-outline-primary btn-sm" id="add-soft"><i class="bi bi-plus-lg me-1"></i>Agregar software</button></div></div>
    <div class="tbl-wrap"><table class="tbl soft-tbl">
      <thead><tr><th>Software</th><th style="width:120px">Versión</th><th style="width:150px">Tipo de licencia</th><th style="width:270px">Estado</th><th style="width:150px">Vence</th><th style="width:60px"></th></tr></thead>
      <tbody id="otros-body">
      <?php foreach ($otros as $i => $o): ?>
        <tr>
          <td><input class="form-control" name="otro[<?= $i ?>][nombre]" list="dl-otro" value="<?= e($o['nombre']) ?>" aria-label="Software"></td>
          <td><input class="form-control" name="otro[<?= $i ?>][version]" value="<?= e($o['version']) ?>" aria-label="Versión"></td>
          <td><select class="form-select" name="otro[<?= $i ?>][tipo_licencia]" aria-label="Tipo de licencia"><option value=""></option><?= options(TIPOS_LICENCIA, $o['tipo_licencia'], false) ?></select></td>
          <td><div class="seg seg-lic"><?php foreach (LICENCIAS as $lc): ?><label class="lc-<?= $lc === 'No legal' ? 'no' : strtolower($lc) ?>"><input type="radio" name="otro[<?= $i ?>][licencia]" value="<?= $lc ?>" <?= $o['licencia'] === $lc ? 'checked' : '' ?>><span><?= $lc ?></span></label><?php endforeach; ?></div></td>
          <td><input class="form-control" type="date" name="otro[<?= $i ?>][vence]" value="<?= e($o['vence']) ?>" aria-label="Vence"></td>
          <td><button type="button" class="btn btn-sm btn-outline-danger" data-quitar aria-label="Quitar"><i class="bi bi-x-lg"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="small muted mt-2 mb-0" id="otros-vacio" <?= $otros ? 'hidden' : '' ?>>Sin software adicional. Use «Agregar software» para registrar programas como contabilidad, diseño, acceso remoto, etc.</p>
    <template id="tpl-otro">
      <tr>
        <td><input class="form-control" name="otro[__i__][nombre]" list="dl-otro" aria-label="Software"></td>
        <td><input class="form-control" name="otro[__i__][version]" aria-label="Versión"></td>
        <td><select class="form-select" name="otro[__i__][tipo_licencia]" aria-label="Tipo de licencia"><option value=""></option><?= options(TIPOS_LICENCIA, '', false) ?></select></td>
        <td><div class="seg seg-lic"><?php foreach (LICENCIAS as $lc): ?><label class="lc-<?= $lc === 'No legal' ? 'no' : strtolower($lc) ?>"><input type="radio" name="otro[__i__][licencia]" value="<?= $lc ?>" <?= $lc === 'Legal' ? 'checked' : '' ?>><span><?= $lc ?></span></label><?php endforeach; ?></div></td>
        <td><input class="form-control" type="date" name="otro[__i__][vence]" aria-label="Vence"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" data-quitar aria-label="Quitar"><i class="bi bi-x-lg"></i></button></td>
      </tr>
    </template>
  </div>

  <div class="panel">
    <label class="form-label" for="notas">Observación</label>
    <textarea class="form-control" id="notas" name="notas" rows="3"><?= e($r['notas']) ?></textarea>
  </div>

  <div class="form-actions mt-0">
    <button class="btn btn-primary px-4">Guardar</button>
    <?php if (!$id): ?><button class="btn btn-outline-primary" name="otro_mas" value="1">Guardar y registrar otro</button><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= $id ? url('equipo', ['id' => $id]) : url('computo_lista') ?>">Cancelar</a>
  </div>
</form>

<datalist id="dl-marca"><?php foreach (array_unique(array_merge($dlMarcas, ['Lenovo', 'HP', 'Dell', 'Asus', 'Acer', 'Apple', 'Samsung', 'Huawei'])) as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
<datalist id="dl-cpu"><?php foreach ($dlCpu as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
<datalist id="dl-ram"><?php foreach (['4 GB', '8 GB', '12 GB', '16 GB', '32 GB', '64 GB'] as $v): ?><option value="<?= $v ?>"><?php endforeach; ?></datalist>
<datalist id="dl-dd"><?php foreach (['SSD 256 GB', 'SSD 512 GB', 'SSD 1 TB', 'HDD 500 GB', 'HDD 1 TB', 'HDD 2 TB'] as $v): ?><option value="<?= $v ?>"><?php endforeach; ?></datalist>
<?php foreach ($dlSoft as $c => $list): ?><datalist id="dl-<?= $c ?>"><?php foreach ($list as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist><?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Correos como etiquetas
  var hidden = document.getElementById('correos'), box = document.getElementById('chips'), inp = document.getElementById('correo-in');
  var list = hidden.value ? hidden.value.split(/[,;\s]+/).filter(Boolean) : [];
  function render() {
    box.querySelectorAll('.chip').forEach(function (c) { c.remove(); });
    list.forEach(function (m, i) {
      var c = document.createElement('span'); c.className = 'chip'; c.textContent = m;
      var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Quitar ' + m); b.innerHTML = '&times;';
      b.onclick = function () { list.splice(i, 1); render(); };
      c.appendChild(b); box.insertBefore(c, inp);
    });
    hidden.value = list.join(', ');
  }
  function addMail() {
    inp.value.split(/[,;\s]+/).forEach(function (v) { v = v.trim(); if (v && list.indexOf(v) === -1) list.push(v); });
    inp.value = ''; render();
  }
  inp.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ',' || e.key === ';') { e.preventDefault(); addMail(); } });
  inp.addEventListener('blur', addMail);
  box.addEventListener('click', function () { inp.focus(); });
  render();

  // Vincular usuario del sistema: completa nombre, cargo y correo si están vacíos
  document.getElementById('usuario_id').addEventListener('change', function () {
    var o = this.selectedOptions[0]; if (!o || !o.value) return;
    var resp = document.getElementById('responsable'), cargo = document.getElementById('cargo');
    if (!resp.value) resp.value = o.dataset.nombre || '';
    if (!cargo.value) cargo.value = o.dataset.cargo || '';
    if (o.dataset.email && list.indexOf(o.dataset.email) === -1) { list.push(o.dataset.email); render(); }
  });

  // Renovación sugerida según fecha de compra
  var fc = document.getElementById('fecha_compra'), sug = document.getElementById('sugerencia');
  fc.addEventListener('change', function () {
    if (!this.value) { sug.textContent = ''; return; }
    var anios = (Date.now() - new Date(this.value + 'T12:00:00')) / (365.25 * 86400000);
    var nivel = anios < 3 ? 'Bajo' : (anios < 5 ? 'Medio' : 'Alto');
    sug.textContent = 'Sugerido por antigüedad (' + anios.toFixed(1).replace('.', ',') + ' años): ' + nivel;
    if (!document.querySelector('input[name=renovacion]:checked')) document.querySelector('input[name=renovacion][value="' + nivel + '"]').checked = true;
  });

  // Filas de software adicional
  var body = document.getElementById('otros-body'), tpl = document.getElementById('tpl-otro'), vacio = document.getElementById('otros-vacio');
  var n = <?= count($otros) ?> + 1000;
  document.getElementById('add-soft').addEventListener('click', function () {
    var html = tpl.innerHTML.replace(/__i__/g, String(n++));
    body.insertAdjacentHTML('beforeend', html);
    vacio.hidden = true;
    body.lastElementChild.querySelector('input').focus();
  });
  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-quitar]'); if (!b) return;
    b.closest('tr').remove(); vacio.hidden = body.children.length > 0;
  });
});
</script>
