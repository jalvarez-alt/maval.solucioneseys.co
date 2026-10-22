<?php
require_perm('empresas', 'crear');
require_once APP_DIR . '/empresas_importar.php';
$title = 'Importar empresas';

if (get('plantilla')) emp_plantilla();

// Limpieza de análisis abandonados (más de un día)
foreach (glob(UPLOAD_DIR . '/imp_emp_*.json') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);

$token = preg_replace('/[^a-f0-9]/', '', (string)get('t', ''));
$tmpFile = $token ? UPLOAD_DIR . '/imp_emp_' . $token . '.json' : '';
$sesion = $token ? ($_SESSION['imp_emp'][$token] ?? null) : null;
if ($token && (!$sesion || !is_file($tmpFile))) { flash('La vista previa expiró. Suba el archivo de nuevo.', 'warning'); redirect(url('empresas_importar')); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    if ($acc === 'analizar') {
        $f = $_FILES['archivo'] ?? null;
        $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
        try {
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Seleccione el archivo a importar.');
            if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('El archivo supera 5 MB.');
            if ($ext === 'xls') throw new RuntimeException('El formato .xls antiguo no es compatible: en Excel use «Guardar como» → «Libro de Excel (.xlsx)».');
            require_once APP_DIR . '/lib/xlsx_read.php';
            $filas = leer_tabla($f['tmp_name'], $ext, 'Empresas');
            // Sin permiso de edición no se pueden actualizar empresas existentes
            $actualizar = post('duplicados') !== 'omitir' && can('empresas', 'editar');
            $an = emp_analizar($filas, $actualizar);
            if (!$an['items']) throw new RuntimeException('El archivo no tiene empresas (las filas de EJEMPLO se ignoran).');
            $an['archivo'] = mb_substr(basename((string)$f['name']), 0, 120);
            $token = bin2hex(random_bytes(12));
            file_put_contents(UPLOAD_DIR . '/imp_emp_' . $token . '.json', json_encode($an, JSON_UNESCAPED_UNICODE));
            $_SESSION['imp_emp'][$token] = ['creado' => time()];
            redirect(url('empresas_importar', ['t' => $token]));
        } catch (Throwable $e) {
            flash($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo leer el archivo: ' . $e->getMessage(), 'danger');
            redirect(url('empresas_importar'));
        }
    }
    if ($acc === 'confirmar' && $sesion) {
        $an = json_decode((string)file_get_contents($tmpFile), true);
        try {
            $c = emp_ejecutar($an);
            @unlink($tmpFile); unset($_SESSION['imp_emp'][$token]);
            flash('Importación terminada: ' . $c['nuevo'] . ' empresa(s) creada(s), ' . $c['actualizar'] . ' actualizada(s)'
                . ($c['omitir'] ? ', ' . $c['omitir'] . ' omitida(s)' : '') . ($c['error'] ? ', ' . $c['error'] . ' fila(s) con error no importada(s)' : '') . '.');
            redirect(url('empresas'));
        } catch (Throwable $e) {
            flash('No se importó nada: ' . $e->getMessage(), 'danger');
            redirect(url('empresas_importar', ['t' => $token]));
        }
    }
    if ($acc === 'cancelar' && $sesion) {
        @unlink($tmpFile); unset($_SESSION['imp_emp'][$token]);
        flash('Importación cancelada.', 'info');
    }
    redirect(url('empresas_importar'));
}

// Descargar las filas con error para corregirlas
if ($sesion && get('errores')) {
    require_once APP_DIR . '/lib/xlsx.php';
    $an = json_decode((string)file_get_contents($tmpFile), true);
    $rows = [];
    foreach ($an['items'] as $it) if ($it['accion'] === 'error') $rows[] = array_merge([implode(' | ', $it['errores'])], $it['original']);
    xlsx_download('empresas_con_error.xlsx', [['name' => 'Empresas', 'headers' => array_merge(['ERROR (borre esta columna antes de reimportar)'], $an['encabezados']), 'rows' => $rows, 'widths' => [60]]]);
}

$an = $sesion ? json_decode((string)file_get_contents($tmpFile), true) : null;
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('empresas') ?>"><i class="bi bi-arrow-left"></i> Empresas</a>
  <span class="muted small">Cargue varias empresas a la vez desde un Excel (.xlsx) o CSV.</span>
</div>

<?php if (!$an): ?>
<div class="steps"><span class="on">1 · Subir archivo</span><span>2 · Revisar</span><span>3 · Confirmar</span></div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel h-100 mb-0">
      <div class="panel-h"><h3>Subir archivo de empresas</h3></div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="accion" value="analizar">
        <label class="dropzone" for="archivo">
          <i class="bi bi-file-earmark-arrow-up"></i>
          <strong>Seleccione el archivo Excel (.xlsx) o CSV</strong>
          <span class="muted small" id="archivo-nombre">Máximo 5 MB y 2.000 empresas</span>
          <input type="file" id="archivo" name="archivo" accept=".xlsx,.csv" required onchange="document.getElementById('archivo-nombre').textContent = this.files[0] ? this.files[0].name : ''">
        </label>
        <?php if (can('empresas', 'editar')): ?>
        <fieldset class="mt-3">
          <legend class="form-label">Si una empresa ya existe (mismo NIT o mismo nombre)</legend>
          <div class="form-check"><input class="form-check-input" type="radio" name="duplicados" value="actualizar" id="d1" checked><label class="form-check-label" for="d1">Actualizarla con los datos del archivo (las celdas vacías no borran nada)</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="duplicados" value="omitir" id="d2"><label class="form-check-label" for="d2">Dejarla como está</label></div>
        </fieldset>
        <?php else: ?><p class="small muted mt-3 mb-0">Las empresas que ya existen se dejarán como están (su rol no puede editarlas).</p><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary"><i class="bi bi-search me-1"></i>Revisar archivo</button></div>
        <p class="small muted mb-0">Nada se guarda en este paso: primero verá una vista previa con lo que se va a crear y los errores encontrados.</p>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100 mb-0">
      <div class="panel-h"><h3>Plantilla</h3></div>
      <p>Descargue la plantilla con las columnas, listas desplegables (tipo y estado) y una hoja de instrucciones.</p>
      <a class="btn btn-outline-primary" href="<?= url('empresas_importar', ['plantilla' => 1]) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Descargar plantilla</a>
      <h4 class="sec">Columnas</h4>
      <p class="small mb-2"><?= e(implode(' · ', array_map(fn($c) => $c[0], emp_campos()))) ?></p>
      <ul class="small muted ps-3 mb-0">
        <li>Obligatoria para empresas nuevas: <strong>Razón social</strong>.</li>
        <li><strong>Tipo:</strong> Cliente o Propia (las propias emiten facturas en Cartera). Vacío = Cliente.</li>
        <li>El <strong>NIT</strong> sirve para reconocer las que ya existen.</li>
        <li>También puede importar el Excel exportado desde el listado de empresas, después de editarlo.</li>
      </ul>
    </div>
  </div>
</div>

<?php else:
    $cnt = array_count_values(array_column($an['items'], 'accion')) + ['nuevo' => 0, 'actualizar' => 0, 'omitir' => 0, 'error' => 0];
    $filtro = get('ver', 'todos');
    $items = $filtro === 'todos' ? $an['items'] : array_values(array_filter($an['items'], fn($it) => $it['accion'] === $filtro || ($filtro === 'avisos' && $it['avisos'])));
    $lbl = ['nuevo' => ['Nueva', 'success'], 'actualizar' => ['Actualizar', 'info'], 'omitir' => ['Omitir', 'secondary'], 'error' => ['Error', 'danger']];
    $importables = $cnt['nuevo'] + $cnt['actualizar']; ?>
<div class="steps"><span class="done">1 · Subir archivo</span><span class="on">2 · Revisar</span><span>3 · Confirmar</span></div>
<div class="kpis">
  <a class="kpi accent text-decoration-none text-reset" href="<?= url('empresas_importar', ['t' => $token, 'ver' => 'nuevo']) ?>"><div class="lbl"><i class="bi bi-plus-circle"></i>Empresas nuevas</div><div class="v"><?= $cnt['nuevo'] ?></div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('empresas_importar', ['t' => $token, 'ver' => 'actualizar']) ?>"><div class="lbl"><i class="bi bi-arrow-repeat"></i>Se actualizarán</div><div class="v"><?= $cnt['actualizar'] ?></div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('empresas_importar', ['t' => $token, 'ver' => 'omitir']) ?>"><div class="lbl"><i class="bi bi-skip-forward"></i>Omitidas</div><div class="v"><?= $cnt['omitir'] ?></div></a>
  <a class="kpi <?= $cnt['error'] ? 'danger bad' : '' ?> text-decoration-none text-reset" href="<?= url('empresas_importar', ['t' => $token, 'ver' => 'error']) ?>"><div class="lbl"><i class="bi bi-x-octagon"></i>Filas con error</div><div class="v"><?= $cnt['error'] ?></div><div class="sub">No se importan</div></a>
</div>
<div class="panel">
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <div class="me-auto">
      <strong><?= e($an['archivo']) ?></strong> · <?= count($an['items']) ?> filas
      <div class="small muted">Columnas reconocidas: <?= e(implode(', ', $an['columnas'])) ?></div>
      <?php if ($an['desconocidas']): ?><div class="small text-warning-emphasis">Columnas ignoradas (no reconocidas): <?= e(implode(', ', $an['desconocidas'])) ?></div><?php endif; ?>
    </div>
    <?php if ($cnt['error']): ?><a class="btn btn-outline-danger" href="<?= url('empresas_importar', ['t' => $token, 'errores' => 1]) ?>"><i class="bi bi-download me-1"></i>Descargar filas con error</a><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><button class="btn btn-outline-secondary">Cancelar</button></form>
    <form method="post" data-confirm="¿Importar <?= $importables ?> empresa(s)?<?= $cnt['error'] ? ' Las ' . $cnt['error'] . ' filas con error no se importarán.' : '' ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="confirmar">
      <button class="btn btn-primary" <?= $importables ? '' : 'disabled' ?>><i class="bi bi-check2-circle me-1"></i>Importar <?= $importables ?> empresa(s)</button></form>
  </div>
</div>
<div class="panel">
  <div class="tabs mb-3">
    <?php foreach (['todos' => 'Todas', 'nuevo' => 'Nuevas', 'actualizar' => 'Actualizar', 'error' => 'Con error', 'avisos' => 'Con avisos'] as $k => $l): ?>
      <a class="<?= $filtro === $k ? 'on' : '' ?>" href="<?= url('empresas_importar', ['t' => $token, 'ver' => $k]) ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th class="c">Fila</th><th>Resultado</th><th>Razón social</th><th>NIT</th><th>Tipo</th><th>Contacto</th><th>Correo</th><th>Mensajes</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($items, 0, 500) as $it): $d = $it['datos']; ?>
      <tr>
        <td class="c muted"><?= (int)$it['fila'] ?></td>
        <td><?= badge($lbl[$it['accion']][0], $lbl[$it['accion']][1]) ?></td>
        <td><?= e($d['nombre'] ?? ($it['existente'] ?? '—')) ?><?= $it['existente'] && isset($d['nombre']) && $d['nombre'] !== $it['existente'] ? '<br><small class="muted">Actual: ' . e($it['existente']) . '</small>' : '' ?></td>
        <td class="nowrap"><?= e($d['nit'] ?? '—') ?></td>
        <td><?= isset($d['tipo']) ? ($d['tipo'] === 'propia' ? badge('Propia', 'primary') : badge('Cliente', 'info')) : '—' ?></td>
        <td><?= e($d['contacto'] ?? '—') ?></td>
        <td class="small"><?= e($d['email'] ?? '—') ?></td>
        <td class="small">
          <?php foreach ($it['errores'] as $m): ?><div class="text-danger"><i class="bi bi-x-circle me-1"></i><?= e($m) ?></div><?php endforeach; ?>
          <?php foreach ($it['avisos'] as $m): ?><div class="text-warning-emphasis"><i class="bi bi-info-circle me-1"></i><?= e($m) ?></div><?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="8" class="empty">No hay filas en esta vista.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if (count($items) > 500): ?><p class="small muted mt-2 mb-0">Se muestran las primeras 500 filas de <?= count($items) ?>.</p><?php endif; ?>
</div>
<?php endif; ?>
