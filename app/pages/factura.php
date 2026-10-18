<?php
require_perm('cartera', 'ver');
$id = (int)get('id', 0);
$f = one('SELECT f.*, e.nombre AS empresa, e.nit AS empresa_nit, u.nombre AS creador FROM facturas f JOIN empresas e ON e.id = f.empresa_id LEFT JOIN usuarios u ON u.id = f.creado_por WHERE f.id = ?', [$id]);
if (!$f) not_found();
$f['pagado'] = (float)val('SELECT COALESCE(SUM(valor),0) FROM pagos WHERE factura_id = ?', [$id]);
$saldo = round((float)$f['total'] - $f['pagado'], 2);
$title = 'Factura ' . $f['numero'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    require_perm('cartera', ['pago' => 'crear', 'borrar_pago' => 'eliminar', 'anular' => 'aprobar', 'eliminar' => 'eliminar'][$acc] ?? 'editar');
    if ($acc === 'pago') {
        $v = parse_money(post('valor', '0'));
        $fecha = valid_date(post('fecha'));
        if ($f['anulada']) flash('La factura está anulada.', 'warning');
        elseif (!$fecha) flash('Fecha de pago no válida.', 'danger');
        elseif ($v <= 0) flash('El valor del abono debe ser mayor que cero.', 'danger');
        elseif ($v - $saldo > 0.5) flash('El abono (' . money($v) . ') supera el saldo pendiente (' . money($saldo) . ').', 'danger');
        else {
            insert('pagos', ['factura_id' => $id, 'fecha' => $fecha, 'valor' => $v, 'metodo' => post('metodo'), 'referencia' => post('referencia'), 'notas' => post('notas'), 'creado_por' => user()['id'], 'creado' => now()]);
            flash(abs($v - $saldo) <= 0.5 ? 'Pago registrado. La factura quedó pagada.' : 'Abono de ' . money($v) . ' registrado. Saldo: ' . money($saldo - $v) . '.');
        }
    } elseif ($acc === 'borrar_pago') {
        q('DELETE FROM pagos WHERE id = ? AND factura_id = ?', [(int)post('pago'), $id]);
        flash('Pago eliminado.');
    } elseif ($acc === 'anular') {
        update('facturas', ['anulada' => $f['anulada'] ? 0 : 1], $id);
        flash($f['anulada'] ? 'Factura reactivada.' : 'Factura anulada. No suma en cartera.');
    } elseif ($acc === 'eliminar') {
        if ($f['pagado'] > 0) flash('No se puede eliminar una factura con pagos. Elimine primero los pagos o anúlela.', 'warning');
        else {
            foreach (adjuntos('factura', $id) as $a) @unlink(UPLOAD_DIR . '/' . $a['archivo']);
            q("DELETE FROM adjuntos WHERE entidad = 'factura' AND entidad_id = ?", [$id]);
            q('DELETE FROM facturas WHERE id = ?', [$id]);
            flash('Factura eliminada.');
            redirect(url('cartera'));
        }
    }
    redirect(url('factura', ['id' => $id]));
}

$pagos = all('SELECT p.*, u.nombre AS usuario FROM pagos p LEFT JOIN usuarios u ON u.id = p.creado_por WHERE p.factura_id = ? ORDER BY p.fecha, p.id', [$id]);
$est = factura_estado($f);
$dv = (int)floor((strtotime(today()) - strtotime($f['fecha_vencimiento'])) / 86400);
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('cartera', ['empresa' => $f['empresa_id']]) ?>"><i class="bi bi-arrow-left"></i> Cartera</a>
  <h2 class="h5 m-0 ms-1"><?= e($f['cliente_nombre']) ?></h2>
  <?= badge($est) ?>
  <div class="ms-auto d-flex gap-2">
    <?php if (can('cartera', 'editar')): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('factura_form', ['id' => $id]) ?>"><i class="bi bi-pencil"></i> Editar</a><?php endif; ?>
    <?php if (can('cartera', 'aprobar')): ?><form method="post" data-confirm="<?= $f['anulada'] ? '¿Reactivar la factura?' : '¿Anular la factura? Dejará de contar en la cartera.' ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="anular"><button class="btn btn-sm btn-outline-secondary"><?= $f['anulada'] ? 'Reactivar' : 'Anular' ?></button></form><?php endif; ?>
    <?php if (!$f['pagado'] && can('cartera', 'eliminar')): ?><form method="post" data-confirm="¿Eliminar definitivamente la factura?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </div>
</div>

<div class="kpis">
  <div class="kpi"><div class="lbl">Total factura</div><div class="v"><?= money($f['total']) ?></div></div>
  <div class="kpi"><div class="lbl">Abonado</div><div class="v"><?= money($f['pagado']) ?></div><div class="sub"><?= count($pagos) ?> pago(s)</div></div>
  <div class="kpi <?= $est === 'Vencida' ? 'danger bad' : 'accent' ?>"><div class="lbl">Saldo</div><div class="v"><?= $f['anulada'] ? '—' : money(max(0, $saldo)) ?></div><div class="sub"><?= $est === 'Vencida' ? 'Vencida hace ' . $dv . ' días' : ($saldo > 0.5 && !$f['anulada'] ? 'Vence en ' . (-$dv) . ' días' : '') ?></div></div>
</div>

<div class="detail-grid">
  <div>
    <div class="panel">
      <div class="panel-h"><h3>Pagos recibidos</h3></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th>Notas</th><th class="r">Valor</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pagos as $p): ?>
          <tr><td class="nowrap"><?= fdate($p['fecha']) ?></td><td><?= e($p['metodo'] ?: '—') ?></td><td><?= e($p['referencia'] ?: '—') ?></td><td class="muted"><?= e($p['notas']) ?></td><td class="r"><?= money($p['valor']) ?></td>
            <td class="r"><?php if (can('cartera', 'eliminar')): ?><form method="post" data-confirm="¿Eliminar este pago?"><?= csrf_field() ?><input type="hidden" name="accion" value="borrar_pago"><input type="hidden" name="pago" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$pagos): ?><tr><td colspan="6" class="empty">Aún no hay pagos registrados.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <?php if (!$f['anulada'] && $saldo > 0.5 && can('cartera', 'crear')): ?>
    <div class="panel" id="pago">
      <div class="panel-h"><h3>Registrar pago o abono</h3></div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="pago">
        <div class="form-grid">
          <div><label class="form-label req">Fecha</label><input class="form-control" type="date" name="fecha" value="<?= today() ?>" required></div>
          <div><label class="form-label req">Valor</label><input class="form-control money" name="valor" value="<?= e($saldo) ?>" inputmode="decimal" required></div>
          <div><label class="form-label">Método</label><select class="form-select" name="metodo"><?= options(METODOS_PAGO, 'Transferencia', false) ?></select></div>
          <div><label class="form-label">Referencia</label><input class="form-control" name="referencia" placeholder="N.º transacción"></div>
          <div class="full"><label class="form-label">Notas</label><input class="form-control" name="notas" maxlength="255"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary"><i class="bi bi-cash me-1"></i>Registrar pago</button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <div>
    <div class="panel meta">
      <div class="panel-h"><h3>Datos de la factura</h3></div>
      <dl>
        <dt>Número</dt><dd><strong><?= e($f['numero']) ?></strong></dd>
        <dt>Emite</dt><dd><?= e($f['empresa']) ?></dd>
        <dt>Cliente</dt><dd><?= e($f['cliente_nombre']) ?><?= $f['cliente_nit'] ? '<br><small class="muted">NIT ' . e($f['cliente_nit']) . '</small>' : '' ?></dd>
        <dt>Emisión</dt><dd><?= fdate($f['fecha_emision']) ?></dd>
        <dt>Vencimiento</dt><dd><?= fdate($f['fecha_vencimiento']) ?></dd>
        <dt>Concepto</dt><dd><?= e($f['concepto'] ?: '—') ?></dd>
        <dt>Subtotal</dt><dd><?= money($f['subtotal']) ?></dd>
        <dt>IVA</dt><dd><?= money($f['iva']) ?></dd>
        <dt>Retenciones</dt><dd><?= money($f['retenciones']) ?></dd>
        <dt>Total</dt><dd><strong><?= money($f['total']) ?></strong></dd>
        <?php if ($f['notas']): ?><dt>Notas</dt><dd><?= e($f['notas']) ?></dd><?php endif; ?>
        <dt>Registrada</dt><dd><?= fdate($f['creado'], true) ?><?= $f['creador'] ? ' · ' . e($f['creador']) : '' ?></dd>
      </dl>
      <?= render_adjuntos(adjuntos('factura', $id)) ?>
    </div>
  </div>
</div>
