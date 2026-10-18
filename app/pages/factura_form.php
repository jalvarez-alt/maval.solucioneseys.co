<?php
require_role('admin');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM facturas WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$title = $r ? 'Editar factura ' . $r['numero'] : 'Registrar factura';
$propias = empresas_list('propia');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'empresa_id' => (int)post('empresa_id', 0),
        'numero' => mb_strtoupper((string)post('numero', '')),
        'cliente_nombre' => (string)post('cliente_nombre', ''),
        'cliente_nit' => post('cliente_nit'),
        'fecha_emision' => valid_date(post('fecha_emision')),
        'fecha_vencimiento' => valid_date(post('fecha_vencimiento')),
        'concepto' => post('concepto'),
        'subtotal' => parse_money(post('subtotal', '0')),
        'iva' => parse_money(post('iva', '0')),
        'retenciones' => parse_money(post('retenciones', '0')),
        'notas' => post('notas'),
    ];
    $d['total'] = round($d['subtotal'] + $d['iva'] - $d['retenciones'], 2);
    if (!isset($propias[$d['empresa_id']])) $errors[] = 'Seleccione la empresa que emite la factura.';
    if ($d['numero'] === '') $errors[] = 'Indique el número de factura.';
    if ($d['cliente_nombre'] === '') $errors[] = 'Indique el cliente.';
    if (!$d['fecha_emision']) $errors[] = 'Fecha de emisión no válida.';
    if (!$d['fecha_vencimiento']) $d['fecha_vencimiento'] = $d['fecha_emision'];
    if ($d['fecha_emision'] && $d['fecha_vencimiento'] < $d['fecha_emision']) $errors[] = 'El vencimiento no puede ser anterior a la emisión.';
    if ($d['total'] <= 0) $errors[] = 'El total de la factura debe ser mayor que cero.';
    if (val('SELECT id FROM facturas WHERE empresa_id = ? AND numero = ? AND id <> ?', [$d['empresa_id'], $d['numero'], $id])) $errors[] = 'Esa empresa ya tiene registrada la factura ' . $d['numero'] . '.';
    if ($r) {
        $pagado = (float)val('SELECT COALESCE(SUM(valor),0) FROM pagos WHERE factura_id = ?', [$id]);
        if ($pagado - $d['total'] > 0.5) $errors[] = 'El nuevo total (' . money($d['total']) . ') es menor que lo ya abonado (' . money($pagado) . ').';
    }
    if (!$errors) {
        if ($r) { update('facturas', $d, $id); flash('Factura actualizada.'); }
        else { $d['creado_por'] = user()['id']; $d['creado'] = now(); $id = insert('facturas', $d); flash('Factura ' . $d['numero'] . ' registrada por ' . money($d['total']) . '.'); }
        save_uploads('soportes', 'factura', $id);
        redirect(post('otro') ? url('factura_form', ['empresa' => $d['empresa_id']]) : url('factura', ['id' => $id]));
    }
    $r = array_merge($r ?? [], $d);
}
$emp = (int)get('empresa', 0) ?: (count($propias) === 1 ? array_key_first($propias) : 0);
$r = $r ?? ['empresa_id' => $emp, 'numero' => '', 'cliente_nombre' => '', 'cliente_nit' => '', 'fecha_emision' => today(), 'fecha_vencimiento' => date('Y-m-d', strtotime('+30 days')),
    'concepto' => '', 'subtotal' => '', 'iva' => '', 'retenciones' => '', 'notas' => ''];

// Sugerir siguiente número según la última factura de cada empresa
$sig = [];
foreach ($propias as $pid => $_) {
    $ult = val('SELECT numero FROM facturas WHERE empresa_id = ? ORDER BY id DESC LIMIT 1', [$pid]);
    if ($ult && preg_match('/^(.*?)(\d+)$/', $ult, $mm)) $sig[$pid] = $mm[1] . str_pad((string)((int)$mm[2] + 1), strlen($mm[2]), '0', STR_PAD_LEFT);
}
// Clientes conocidos (facturas previas + empresas cliente)
$clientes = [];
foreach (all("SELECT nombre, nit FROM empresas WHERE tipo = 'cliente' AND activo = 1") as $c) $clientes[$c['nombre']] = $c['nit'] ?? '';
foreach (all('SELECT cliente_nombre, MAX(cliente_nit) nit FROM facturas GROUP BY cliente_nombre') as $c) $clientes[$c['cliente_nombre']] = $c['nit'] ?? ($clientes[$c['cliente_nombre']] ?? '');
ksort($clientes);
?>
<div class="panel" style="max-width:900px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" id="ffac">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><label class="form-label req">Empresa que factura</label><select class="form-select" name="empresa_id" id="empresa_id" required><option value="">Seleccione…</option><?= options($propias, $r['empresa_id']) ?></select></div>
      <div><label class="form-label req">N.º de factura</label><input class="form-control" name="numero" id="numero" value="<?= e($r['numero']) ?>" required></div>
      <div></div>
      <div class="full" style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
        <div><label class="form-label req">Cliente</label><input class="form-control" name="cliente_nombre" id="cliente" list="dl-clientes" value="<?= e($r['cliente_nombre']) ?>" required autocomplete="off"></div>
        <div><label class="form-label">NIT / C.C.</label><input class="form-control" name="cliente_nit" id="nit" value="<?= e($r['cliente_nit']) ?>"></div>
      </div>
      <div><label class="form-label req">Fecha de emisión</label><input class="form-control" type="date" name="fecha_emision" id="femi" value="<?= e($r['fecha_emision']) ?>" required></div>
      <div><label class="form-label">Plazo</label><select class="form-select" id="plazo"><option value="">Personalizado</option><?= options(['0' => 'Contado', '15' => '15 días', '30' => '30 días', '45' => '45 días', '60' => '60 días', '90' => '90 días'], '') ?></select></div>
      <div><label class="form-label req">Fecha de vencimiento</label><input class="form-control" type="date" name="fecha_vencimiento" id="fven" value="<?= e($r['fecha_vencimiento']) ?>" required></div>
      <div class="full"><label class="form-label">Concepto</label><input class="form-control" name="concepto" maxlength="255" value="<?= e($r['concepto']) ?>" placeholder="Ej.: Soporte técnico mensual octubre"></div>
    </div>
    <h6 class="sec">Valores</h6>
    <div class="form-grid">
      <div><label class="form-label req">Subtotal</label><input class="form-control money" name="subtotal" id="sub" value="<?= e($r['subtotal']) ?>" inputmode="decimal" required></div>
      <div><label class="form-label">IVA <select id="ivapct" class="form-select form-select-sm d-inline-block ms-1" style="width:auto;padding-top:0;padding-bottom:0"><option value="">manual</option><option value="0">0%</option><option value="5">5%</option><option value="19">19%</option></select></label><input class="form-control money" name="iva" id="iva" value="<?= e($r['iva']) ?>" inputmode="decimal"></div>
      <div><label class="form-label">Retenciones</label><input class="form-control money" name="retenciones" id="ret" value="<?= e($r['retenciones']) ?>" inputmode="decimal"></div>
      <div><label class="form-label">Total a cobrar</label><div class="form-control fw-bold money bg-light" id="tot">$ 0</div></div>
      <div class="full"><label class="form-label">Notas</label><textarea class="form-control" name="notas" rows="2"><?= e($r['notas']) ?></textarea></div>
      <div class="full"><label class="form-label">Archivo de la factura (PDF)</label><input class="form-control" type="file" name="soportes[]" multiple></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <?php if (!$id): ?><button class="btn btn-outline-primary" name="otro" value="1">Guardar y registrar otra</button><?php endif; ?>
      <a class="btn btn-outline-secondary" href="<?= $id ? url('factura', ['id' => $id]) : url('cartera') ?>">Cancelar</a>
    </div>
  </form>
</div>
<datalist id="dl-clientes"><?php foreach ($clientes as $n => $_): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var nits = <?= json_encode($clientes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var sig = <?= json_encode($sig, JSON_HEX_TAG) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var nuevo = <?= $id ? 'false' : 'true' ?>;
  function calc() {
    var s = parseMoney($('sub').value);
    var pct = $('ivapct').value;
    if (pct !== '') { var iv = Math.round(s * parseFloat(pct) / 100); $('iva').value = iv ? iv.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : ''; }
    $('tot').textContent = fmtMoney(s + parseMoney($('iva').value) - parseMoney($('ret').value));
  }
  ['sub', 'iva', 'ret'].forEach(function (k) { $(k).addEventListener('input', calc); });
  $('ivapct').addEventListener('change', calc);
  $('iva').addEventListener('input', function () { $('ivapct').value = ''; calc(); });
  $('cliente').addEventListener('change', function () { if (nits[this.value] && !$('nit').value) $('nit').value = nits[this.value]; });
  function venc() {
    var d = $('plazo').value; if (d === '' || !$('femi').value) return;
    var f = new Date($('femi').value + 'T12:00:00'); f.setDate(f.getDate() + parseInt(d, 10));
    $('fven').value = f.toISOString().slice(0, 10);
  }
  $('plazo').addEventListener('change', venc); $('femi').addEventListener('change', venc);
  function sugerir() { if (nuevo && sig[$('empresa_id').value] && (!$('numero').value || $('numero').dataset.auto)) { $('numero').value = sig[$('empresa_id').value]; $('numero').dataset.auto = '1'; } }
  $('empresa_id').addEventListener('change', sugerir); sugerir();
  calc();
});
</script>
