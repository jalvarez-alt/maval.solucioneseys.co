<?php
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM por_facturar WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
require_perm('porfacturar', $id ? 'editar' : 'crear');
$title = $r ? 'Editar pendiente' : 'Agregar pendiente por facturar';
$errors = [];
$propias = empresas_list('propia');

// Precarga desde una cotización aceptada
$cotId = (int)get('cotizacion', 0);
$cot = (!$r && $cotId) ? one('SELECT * FROM cotizaciones WHERE id = ?', [$cotId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'fecha' => valid_date(post('fecha')), 'empresa_id' => post('empresa_id') && isset($propias[(int)post('empresa_id')]) ? (int)post('empresa_id') : null,
        'cliente_nombre' => mb_substr((string)post('cliente_nombre', ''), 0, 150), 'cliente_nit' => mb_substr((string)post('cliente_nit', ''), 0, 30) ?: null,
        'concepto' => mb_substr((string)post('concepto', ''), 0, 255), 'detalle' => post('detalle'), 'valor' => parse_money(post('valor', '0')),
        'referencia' => mb_substr((string)post('referencia', ''), 0, 80) ?: null, 'fecha_prevista' => valid_date(post('fecha_prevista')),
    ];
    if (!$d['fecha']) $errors[] = 'Indique una fecha válida.';
    if ($d['cliente_nombre'] === '') $errors[] = 'Indique el cliente.';
    if ($d['concepto'] === '') $errors[] = 'Escriba qué hay que facturar (concepto).';
    if ($d['valor'] <= 0) $errors[] = 'El valor debe ser mayor que cero.';
    if ($d['fecha_prevista'] && $d['fecha_prevista'] < $d['fecha'] && !$r) $errors[] = 'La fecha prevista de facturación no puede ser anterior a la fecha del pendiente.';
    if (!$errors) {
        if ($r) {
            if (can('porfacturar', 'aprobar') && in_array(post('estado'), PF_ESTADOS, true)) $d['estado'] = post('estado');
            $d['actualizado'] = now();
            update('por_facturar', $d, $id);
            flash('Pendiente actualizado.');
        } else {
            $d += ['estado' => 'Pendiente', 'cotizacion_id' => $cot['id'] ?? null, 'creado_por' => (int)user()['id'], 'creado' => now()];
            insert('por_facturar', $d);
            flash('Pendiente agregado: ' . $d['cliente_nombre'] . ' por ' . money($d['valor']) . '.');
        }
        redirect(url('por_facturar'));
    }
    $r = array_merge($r ?? [], $d);
}
if (!$r) {
    $r = ['fecha' => today(), 'empresa_id' => count($propias) === 1 ? array_key_first($propias) : null, 'cliente_nombre' => '', 'cliente_nit' => '', 'concepto' => '', 'detalle' => '', 'valor' => '',
        'referencia' => '', 'fecha_prevista' => date('Y-m-d', strtotime('+7 days')), 'estado' => 'Pendiente'];
    if ($cot) {
        $suma = (float)$cot['total'] + (!empty($cot['sumar_ofertas']) ? (float)$cot['total2'] : 0);
        $r = array_merge($r, ['cliente_nombre' => $cot['cliente_nombre'], 'cliente_nit' => $cot['cliente_nit'], 'concepto' => 'Cotización No. ' . $cot['consecutivo'] . ' aceptada',
            'valor' => $suma, 'referencia' => 'Cotización ' . $cot['consecutivo'], 'empresa_id' => $cot['empresa_id'] && isset($propias[(int)$cot['empresa_id']]) ? $cot['empresa_id'] : $r['empresa_id']]);
    }
}
// Clientes conocidos (empresas cliente, facturas y pendientes anteriores)
$clientes = [];
foreach (all("SELECT nombre, nit FROM empresas WHERE tipo = 'cliente' AND activo = 1") as $c) $clientes[$c['nombre']] = $c['nit'] ?? '';
foreach (all('SELECT cliente_nombre, MAX(cliente_nit) nit FROM facturas GROUP BY cliente_nombre') as $c) $clientes[$c['cliente_nombre']] = $c['nit'] ?? ($clientes[$c['cliente_nombre']] ?? '');
foreach (all('SELECT cliente_nombre, MAX(cliente_nit) nit FROM por_facturar GROUP BY cliente_nombre') as $c) $clientes[$c['cliente_nombre']] = $c['nit'] ?? ($clientes[$c['cliente_nombre']] ?? '');
ksort($clientes);
?>
<div class="panel" style="max-width:900px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <?php if ($cot): ?><div class="alert alert-info py-2 small"><i class="bi bi-file-earmark-text me-1"></i>Precargado desde la cotización No. <?= (int)$cot['consecutivo'] ?>. Ajuste el valor si va a facturar solo una parte.</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="full" style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
        <div><label class="form-label req">Cliente</label><input class="form-control" name="cliente_nombre" id="cliente" list="dl-clientes" value="<?= e($r['cliente_nombre']) ?>" required autocomplete="off" maxlength="150"></div>
        <div><label class="form-label">NIT / C.C.</label><input class="form-control" name="cliente_nit" id="nit" value="<?= e($r['cliente_nit']) ?>" maxlength="30"></div>
      </div>
      <div class="full"><label class="form-label req">Qué hay que facturar</label><input class="form-control" name="concepto" value="<?= e($r['concepto']) ?>" required maxlength="255" placeholder="Ej.: Instalación de 20 puntos de red, soporte de octubre…"></div>
      <div><label class="form-label req">Valor a facturar</label><input class="form-control money" name="valor" value="<?= e($r['valor']) ?>" inputmode="decimal" required><div class="form-text">Antes de IVA y retenciones; se ajustan al crear la factura.</div></div>
      <div><label class="form-label">Referencia</label><input class="form-control" name="referencia" value="<?= e($r['referencia']) ?>" maxlength="80" placeholder="N.º de cotización, orden de compra, ticket…"></div>
      <div><label class="form-label req">Fecha del trabajo o acuerdo</label><input class="form-control" type="date" name="fecha" value="<?= e($r['fecha']) ?>" required></div>
      <div><label class="form-label">Facturar a más tardar el</label><input class="form-control" type="date" name="fecha_prevista" value="<?= e($r['fecha_prevista']) ?>"></div>
      <div><label class="form-label">Empresa que factura</label><select class="form-select" name="empresa_id"><option value="">— Por definir —</option><?= options($propias, $r['empresa_id']) ?></select></div>
      <?php if (!empty($r['id']) && can('porfacturar', 'aprobar')): ?><div><label class="form-label">Estado</label><select class="form-select" name="estado"><?= options(PF_ESTADOS, $r['estado'], false) ?></select></div><?php endif; ?>
      <div class="full"><label class="form-label">Detalle / notas</label><textarea class="form-control" name="detalle" rows="3" placeholder="Qué se hizo, condiciones de pago, a quién enviar la factura…"><?= e($r['detalle']) ?></textarea></div>
    </div>
    <datalist id="dl-clientes"><?php foreach ($clientes as $n => $_) echo '<option value="' . e($n) . '">'; ?></datalist>
    <div class="form-actions">
      <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= url('por_facturar') ?>">Cancelar</a>
    </div>
  </form>
</div>
<script>
(function () {
  var nits = <?= json_encode($clientes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var c = document.getElementById('cliente'), n = document.getElementById('nit');
  c.addEventListener('change', function () { if (nits[c.value] && !n.value) n.value = nits[c.value]; });
})();
</script>
