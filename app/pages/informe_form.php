<?php
require_once APP_DIR . '/informes.php';
$id = (int)get('id', 0);
$i = $id ? inf_obtener($id) : null;
if ($id && !$i) not_found();
require_perm('informes', $id ? 'editar' : 'crear');
if ($i && $i['estado'] === 'Finalizado' && !can('informes', 'aprobar')) { flash('El informe está finalizado; para modificarlo hay que reabrirlo.', 'warning'); redirect(url('informe', ['id' => $id])); }
$tipo = $i['tipo'] ?? get('tipo', '');
if (!isset(INF_TIPOS[$tipo])) { flash('Elija el tipo de informe.', 'warning'); redirect(url('informes')); }
$t = INF_TIPOS[$tipo];
$title = ($i ? 'Editar informe ' . inf_codigo($i) : 'Nuevo informe') . ' · ' . $t['corto'];
$errors = [];
$u = user();

$clientes = all('SELECT id, nombre, nit, contacto, email, direccion FROM empresas WHERE tipo = ? AND activo = 1 ORDER BY nombre', ['cliente']);
$tecnicos = all('SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre');
$cots = all("SELECT id, consecutivo, cliente_nombre FROM cotizaciones WHERE estado IN ('Enviada','Aceptada') ORDER BY consecutivo DESC LIMIT 150");
$fotos = $id ? adjuntos('informe', $id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'empresa_id' => null,
        'cliente_nombre' => mb_substr((string)post('cliente_nombre', ''), 0, 150), 'cliente_nit' => mb_substr((string)post('cliente_nit', ''), 0, 30) ?: null,
        'contacto' => mb_substr((string)post('contacto', ''), 0, 150) ?: null, 'cliente_email' => mb_substr((string)post('cliente_email', ''), 0, 150) ?: null,
        'sede' => mb_substr((string)post('sede', ''), 0, 200) ?: null, 'tecnico_id' => (int)post('tecnico_id', 0) ?: null,
        'cotizacion_id' => (int)post('cotizacion_id', 0) ?: null, 'referencia' => mb_substr((string)post('referencia', ''), 0, 80) ?: null,
        'fecha_inicio' => valid_date(post('fecha_inicio')), 'fecha_fin' => valid_date(post('fecha_fin')),
        'resumen' => post('resumen'), 'actividades' => post('actividades'), 'recomendaciones' => post('recomendaciones'),
        'firma_nombre' => mb_substr((string)post('firma_nombre', ''), 0, 120) ?: null, 'firma_cargo' => mb_substr((string)post('firma_cargo', ''), 0, 100) ?: null,
    ];
    foreach ($clientes as $c) if (mb_strtolower($c['nombre']) === mb_strtolower($d['cliente_nombre'])) $d['empresa_id'] = (int)$c['id'];
    if ($d['cotizacion_id'] && !val('SELECT COUNT(*) FROM cotizaciones WHERE id = ?', [$d['cotizacion_id']])) $d['cotizacion_id'] = null;
    if ($d['cotizacion_id'] && !$d['referencia']) $d['referencia'] = 'Cotización ' . val('SELECT consecutivo FROM cotizaciones WHERE id = ?', [$d['cotizacion_id']]);
    $d['tecnico_nombre'] = $d['tecnico_id'] ? val('SELECT nombre FROM usuarios WHERE id = ?', [$d['tecnico_id']]) : null;
    $dat = [];
    foreach ($t['datos'] as $k => $_) $dat[$k] = trim((string)($_POST['dato_' . $k] ?? ''));
    $d['datos'] = json_encode($dat, JSON_UNESCAPED_UNICODE);
    $eq = inf_filas_post('eq', 6); $mat = inf_filas_post('mat', 4); $pru = inf_filas_post('pr', 3);

    if ($d['cliente_nombre'] === '') $errors[] = 'Indique el cliente.';
    if (!$d['fecha_inicio']) $errors[] = 'Indique la fecha de inicio del servicio.';
    if ($d['fecha_fin'] && $d['fecha_inicio'] && $d['fecha_fin'] < $d['fecha_inicio']) $errors[] = 'La fecha de finalización no puede ser anterior al inicio.';
    if ($d['cliente_email'] && !filter_var($d['cliente_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo del cliente no es válido.';
    if (!$errors) {
        $pdo = db(); $pdo->beginTransaction();
        try {
            if ($i) { $d['actualizado'] = now(); update('informes', $d, $id); q('DELETE FROM informe_filas WHERE informe_id = ?', [$id]); }
            else { $d += ['tipo' => $tipo, 'estado' => 'Borrador', 'consecutivo' => inf_siguiente(), 'creado_por' => (int)$u['id'], 'creado' => now(), 'actualizado' => now()]; $id = insert('informes', $d); }
            foreach (['equipos' => $eq, 'materiales' => $mat, 'pruebas' => $pru] as $sec => $lista) {
                foreach ($lista as $n => $f) {
                    $row = ['informe_id' => $id, 'seccion' => $sec, 'orden' => $n + 1];
                    foreach ($f as $k => $v) $row['c' . $k] = $v === '' ? null : $v;
                    insert('informe_filas', $row);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        // Fotos: quitar las marcadas y subir las nuevas
        foreach ((array)($_POST['quitar_adj'] ?? []) as $aid) {
            $a = one("SELECT * FROM adjuntos WHERE id = ? AND entidad = 'informe' AND entidad_id = ?", [(int)$aid, $id]);
            if ($a) { @unlink(UPLOAD_DIR . '/' . $a['archivo']); q('DELETE FROM adjuntos WHERE id = ?', [$a['id']]); }
        }
        save_uploads('fotos', 'informe', $id);
        flash($i ? 'Informe actualizado.' : 'Informe ' . inf_codigo(['consecutivo' => val('SELECT consecutivo FROM informes WHERE id = ?', [$id])]) . ' creado.');
        redirect(url('informe', ['id' => $id]));
    }
    $i = array_merge($i ?? [], $d, ['tipo' => $tipo]);
    $filas = ['equipos' => array_map(fn($f) => ['c1' => $f[1], 'c2' => $f[2], 'c3' => $f[3], 'c4' => $f[4], 'c5' => $f[5], 'c6' => $f[6]], $eq),
        'materiales' => array_map(fn($f) => ['c1' => $f[1], 'c2' => $f[2], 'c3' => $f[3], 'c4' => $f[4]], $mat), 'pruebas' => array_map(fn($f) => ['c1' => $f[1], 'c2' => $f[2], 'c3' => $f[3]], $pru)];
    $datos = $dat;
} elseif ($i) {
    $filas = inf_filas($id); $datos = inf_datos($i);
} else {
    // Informe nuevo: precarga desde una cotización, si se pidió, y pruebas sugeridas del tipo
    $cotId = (int)get('cotizacion', 0);
    $cot = $cotId ? cot_obtener($cotId) : null;
    $i = ['tipo' => $tipo, 'estado' => 'Borrador', 'cliente_nombre' => $cot['cliente_nombre'] ?? '', 'cliente_nit' => $cot['cliente_nit'] ?? '', 'contacto' => $cot['atencion'] ?? '', 'cliente_email' => $cot['cliente_email'] ?? '',
        'sede' => $cot['cliente_direccion'] ?? '', 'tecnico_id' => (int)$u['id'], 'cotizacion_id' => $cot['id'] ?? null, 'referencia' => $cot ? 'Cotización ' . $cot['consecutivo'] : '',
        'fecha_inicio' => today(), 'fecha_fin' => '', 'resumen' => '', 'actividades' => '', 'recomendaciones' => '', 'firma_nombre' => '', 'firma_cargo' => ''];
    $filas = ['equipos' => [], 'materiales' => [], 'pruebas' => array_map(fn($p) => ['c1' => $p, 'c2' => '', 'c3' => ''], $t['pruebas'])];
    $datos = [];
}
foreach (['equipos', 'materiales', 'pruebas'] as $s) if (!$filas[$s]) $filas[$s] = [[]];   // al menos una fila vacía

/** Tabla editable: $campo es el nombre del arreglo; cada columna es un campo de texto (o lista en $listas[columna]). */
function inf_tabla_form(string $campo, array $cab, array $filas, array $anchos, array $listas = []): void
{
    $celda = function (int $k, string $idx, $v) use ($campo, $listas) {
        $n = $campo . '[' . $idx . '][' . $k . ']';
        if (isset($listas[$k])) {
            $ops = $listas[$k]; if ($v !== '' && $v !== null && !in_array($v, $ops, true)) $ops[] = $v;
            return '<select class="form-select form-select-sm" name="' . $n . '"><option value="">—</option>' . options($ops, $v, false) . '</select>';
        }
        return '<input class="form-control form-control-sm" name="' . $n . '" value="' . e($v) . '" maxlength="250">';
    };
    ?>
  <div class="tbl-wrap"><table class="tbl inf-tabla" data-campo="<?= e($campo) ?>">
    <thead><tr><?php foreach ($cab as $k => $h): ?><th style="width:<?= (int)$anchos[$k] ?>%"><?= e($h) ?></th><?php endforeach; ?><th style="width:36px"></th></tr></thead>
    <tbody>
    <?php foreach ($filas as $n => $f): ?>
      <tr><?php foreach ($cab as $k => $_): ?><td><?= $celda($k + 1, (string)$n, $f['c' . ($k + 1)] ?? '') ?></td><?php endforeach; ?>
        <td><button type="button" class="btn btn-sm btn-outline-danger inf-quitar" title="Quitar fila"><i class="bi bi-x-lg"></i></button></td></tr>
    <?php endforeach; ?>
    </tbody>
    <template><tr><?php foreach ($cab as $k => $_): ?><td><?= $celda($k + 1, '__i__', '') ?></td><?php endforeach; ?>
      <td><button type="button" class="btn btn-sm btn-outline-danger inf-quitar" title="Quitar fila"><i class="bi bi-x-lg"></i></button></td></tr></template>
  </table></div>
  <button type="button" class="btn btn-sm btn-outline-primary mt-2 inf-agregar" data-campo="<?= e($campo) ?>"><i class="bi bi-plus-lg me-1"></i>Agregar fila</button>
<?php }
?>
<form method="post" enctype="multipart/form-data" id="inf">
  <?= csrf_field() ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <div class="panel">
    <div class="panel-h"><h3><i class="bi bi-<?= e($t['icono']) ?> me-2"></i><?= e($t['nombre']) ?></h3></div>
    <div class="form-grid">
      <div class="full" style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
        <div><label class="form-label req">Cliente</label><input class="form-control" name="cliente_nombre" id="cliente" list="dl-clientes" value="<?= e($i['cliente_nombre']) ?>" required autocomplete="off" maxlength="150"></div>
        <div><label class="form-label">NIT / C.C.</label><input class="form-control" name="cliente_nit" id="nit" value="<?= e($i['cliente_nit']) ?>" maxlength="30"></div>
      </div>
      <div><label class="form-label">Persona de contacto</label><input class="form-control" name="contacto" id="contacto" value="<?= e($i['contacto']) ?>" maxlength="150"></div>
      <div><label class="form-label">Correo del cliente</label><input class="form-control" type="email" name="cliente_email" id="correo" value="<?= e($i['cliente_email']) ?>" maxlength="150"></div>
      <div class="full"><label class="form-label">Sede / dirección del servicio</label><input class="form-control" name="sede" id="sede" value="<?= e($i['sede']) ?>" maxlength="200"></div>
      <div><label class="form-label req">Fecha de inicio</label><input class="form-control" type="date" name="fecha_inicio" value="<?= e($i['fecha_inicio']) ?>" required></div>
      <div><label class="form-label">Fecha de finalización</label><input class="form-control" type="date" name="fecha_fin" value="<?= e($i['fecha_fin']) ?>"></div>
      <div><label class="form-label">Técnico responsable</label><select class="form-select" name="tecnico_id"><option value="">—</option><?= options(array_column($tecnicos, 'nombre', 'id'), $i['tecnico_id']) ?></select></div>
      <div><label class="form-label">Cotización relacionada</label><select class="form-select" name="cotizacion_id"><option value="">— Ninguna —</option>
        <?php foreach ($cots as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($i['cotizacion_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>No. <?= (int)$c['consecutivo'] ?> · <?= e($c['cliente_nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label">Referencia</label><input class="form-control" name="referencia" value="<?= e($i['referencia']) ?>" maxlength="80" placeholder="Orden de servicio, contrato…"></div>
    </div>
    <datalist id="dl-clientes"><?php foreach ($clientes as $c) echo '<option value="' . e($c['nombre']) . '">'; ?></datalist>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Resumen y actividades</h3></div>
    <div class="form-grid">
      <div class="full"><label class="form-label">Resumen del trabajo</label><textarea class="form-control" name="resumen" rows="4" placeholder="Qué se hizo, en pocas líneas, y cómo quedó el servicio."><?= e($i['resumen']) ?></textarea></div>
      <div class="full"><label class="form-label">Actividades realizadas</label><textarea class="form-control" name="actividades" rows="5" placeholder="Una por línea. Comience la línea con «- » para que salga con viñeta."><?= e($i['actividades']) ?></textarea></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-h"><h3><?= e($t['equipos']) ?></h3></div>
    <?php inf_tabla_form('eq', $t['cols'], $filas['equipos'], $t['anchos'], [6 => $t['estados']]); ?>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Materiales y equipos entregados <small class="muted fw-normal">(opcional)</small></h3></div>
    <?php inf_tabla_form('mat', ['Ítem', 'Marca', 'Serial / referencia', 'Cant.'], $filas['materiales'], [40, 18, 30, 12]); ?>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Datos técnicos</h3></div>
    <div class="form-grid">
      <?php foreach ($t['datos'] as $k => [$lbl, $tipoCampo]): ?>
        <div class="<?= $tipoCampo === 'area' ? 'full' : '' ?>"><label class="form-label"><?= e($lbl) ?></label>
        <?php if ($tipoCampo === 'area'): ?><textarea class="form-control" name="dato_<?= e($k) ?>" rows="3"><?= e($datos[$k] ?? '') ?></textarea>
        <?php else: ?><input class="form-control" name="dato_<?= e($k) ?>" value="<?= e($datos[$k] ?? '') ?>" maxlength="250"><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Pruebas y resultados</h3></div>
    <?php inf_tabla_form('pr', ['Prueba', 'Resultado', 'Observación'], $filas['pruebas'], [42, 18, 40], [2 => INF_RESULTADOS]); ?>
  </div>

  <div class="panel">
    <div class="panel-h"><h3>Recomendaciones, pendientes y aceptación</h3></div>
    <div class="form-grid">
      <div class="full"><label class="form-label">Recomendaciones y pendientes</label><textarea class="form-control" name="recomendaciones" rows="4" placeholder="Zonas con señal débil, mantenimiento sugerido, garantías, trabajos por hacer…"><?= e($i['recomendaciones']) ?></textarea></div>
      <div><label class="form-label">Recibe por parte del cliente</label><input class="form-control" name="firma_nombre" value="<?= e($i['firma_nombre']) ?>" maxlength="120"></div>
      <div><label class="form-label">Cargo de quien recibe</label><input class="form-control" name="firma_cargo" value="<?= e($i['firma_cargo']) ?>" maxlength="100"></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-h"><h3><i class="bi bi-images me-2"></i>Evidencia fotográfica</h3></div>
    <?php if ($fotos): ?>
      <div class="d-flex flex-wrap gap-3 mb-3">
        <?php foreach ($fotos as $a): $img = in_array(strtolower(pathinfo($a['archivo'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true); ?>
          <div class="border rounded p-2" style="width:150px">
            <?php if ($img): ?><a href="archivo.php?id=<?= (int)$a['id'] ?>" target="_blank"><img src="archivo.php?id=<?= (int)$a['id'] ?>" alt="" style="width:100%;height:100px;object-fit:cover;border-radius:4px"></a><?php else: ?><div class="small"><i class="bi bi-paperclip"></i> <?= e($a['nombre_original']) ?></div><?php endif; ?>
            <div class="small text-truncate mt-1" title="<?= e($a['nombre_original']) ?>"><?= e($a['nombre_original']) ?></div>
            <label class="form-check small text-danger mt-1"><input class="form-check-input" type="checkbox" name="quitar_adj[]" value="<?= (int)$a['id'] ?>"> Quitar</label>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <label class="form-label">Agregar fotos</label>
    <input class="form-control" type="file" name="fotos[]" accept="image/*" multiple>
    <div class="form-text">Las fotos JPG y PNG salen en el PDF, con el nombre del archivo como leyenda. Máximo 10 MB por archivo.</div>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
    <a class="btn btn-outline-secondary" href="<?= $id ? url('informe', ['id' => $id]) : url('informes') ?>">Cancelar</a>
  </div>
</form>
<script>
(function () {
  var cli = <?= json_encode(array_column($clientes, null, 'nombre'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var c = document.getElementById('cliente');
  c.addEventListener('change', function () {
    var d = cli[c.value]; if (!d) return;
    [['nit', 'nit'], ['contacto', 'contacto'], ['correo', 'email'], ['sede', 'direccion']].forEach(function (p) { var el = document.getElementById(p[0]); if (el && !el.value && d[p[1]]) el.value = d[p[1]]; });
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
