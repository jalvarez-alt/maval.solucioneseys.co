<?php
$u = user();
$id = (int)get('id', 0);
$r = null;
if (!$id) require_perm('tickets', 'crear');
if ($id) {
    require_perm('tickets', 'editar'); // editar los datos de un ticket
    $r = one('SELECT * FROM tickets WHERE id = ?', [$id]);
    if (!$r) not_found();
}
$title = $r ? 'Editar ticket ' . $r['codigo'] : 'Nuevo ticket';
$errors = [];
$staff = can('tickets', 'administrar'); // puede elegir solicitante, empresa y técnico

// Equipos que el usuario puede vincular
[$es, $ep] = equipo_scope('eq');
$equipos = all("SELECT eq.id, eq.codigo, eq.tipo, eq.marca, eq.modelo, eq.empresa_id, emp.nombre AS empresa, COALESCE(NULLIF(eq.responsable, ''), us.nombre) AS usuario
    FROM equipos eq LEFT JOIN empresas emp ON emp.id = eq.empresa_id LEFT JOIN usuarios us ON us.id = eq.usuario_id
    WHERE $es AND eq.estado <> 'Dado de baja' ORDER BY emp.nombre, eq.codigo", $ep);
$equipoIds = array_map('intval', array_column($equipos, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'asunto' => (string)post('asunto', ''),
        'descripcion' => (string)post('descripcion', ''),
        'categoria' => (string)post('categoria', ''),
        'prioridad' => (string)post('prioridad', 'Media'),
        'equipo_id' => post('equipo_id') ? (int)post('equipo_id') : null,
    ];
    if ($d['asunto'] === '') $errors[] = 'Escriba un asunto.';
    if ($d['descripcion'] === '') $errors[] = 'Describa el problema o la solicitud.';
    if (!in_array($d['categoria'], TICKET_CATEGORIAS, true)) $errors[] = 'Seleccione una categoría.';
    if (!in_array($d['prioridad'], TICKET_PRIORIDADES, true)) $d['prioridad'] = 'Media';
    if ($d['equipo_id'] && !in_array($d['equipo_id'], $equipoIds, true)) $errors[] = 'El equipo seleccionado no es válido.';

    if ($staff) {
        $d['empresa_id'] = post('empresa_id') ? (int)post('empresa_id') : null;
        $d['solicitante_id'] = post('solicitante_id') ? (int)post('solicitante_id') : ($r['solicitante_id'] ?? $u['id']);
        $d['asignado_id'] = post('asignado_id') ? (int)post('asignado_id') : null;
        if (!$d['empresa_id'] && $d['solicitante_id']) $d['empresa_id'] = val('SELECT empresa_id FROM usuarios WHERE id = ?', [$d['solicitante_id']]);
    } else {
        $d['empresa_id'] = $u['empresa_id'];
        $d['solicitante_id'] = $u['id'];
    }
    if (!$d['empresa_id'] && $d['equipo_id']) $d['empresa_id'] = val('SELECT empresa_id FROM equipos WHERE id = ?', [$d['equipo_id']]);

    if (!$errors) {
        if ($r) {
            $d['actualizado'] = now();
            update('tickets', $d, $id);
            if ((int)$r['asignado_id'] !== (int)$d['asignado_id']) {
                $nom = $d['asignado_id'] ? val('SELECT nombre FROM usuarios WHERE id = ?', [$d['asignado_id']]) : 'nadie';
                insert('ticket_comentarios', ['ticket_id' => $id, 'usuario_id' => $u['id'], 'mensaje' => 'Ticket asignado a ' . $nom . '.', 'interno' => 0, 'creado' => now()]);
                if ($d['asignado_id']) notificar_ticket($id, 'asignado');
            }
            save_uploads('adjuntos', 'ticket', $id);
            flash('Ticket actualizado.');
        } else {
            $d['estado'] = 'Abierto';
            $d['creado'] = now();
            $id = insert('tickets', $d);
            update('tickets', ['codigo' => 'TK-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT)], $id);
            save_uploads('adjuntos', 'ticket', $id);
            if ($d['equipo_id']) {
                insert('equipo_movimientos', ['equipo_id' => $d['equipo_id'], 'usuario_id' => $u['id'], 'descripcion' => 'Se reportó el ticket TK-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT) . ': ' . mb_substr($d['asunto'], 0, 200), 'fecha' => now()]);
            }
            notificar_ticket($id, 'creado');
            flash('Ticket creado. Le daremos respuesta lo antes posible.');
        }
        redirect(url('ticket', ['id' => $id]));
    }
    $r = array_merge($r ?? [], $d);
}
$r = $r ?? ['asunto' => '', 'descripcion' => '', 'categoria' => '', 'prioridad' => 'Media', 'equipo_id' => (int)get('equipo', 0) ?: null, 'empresa_id' => null, 'solicitante_id' => null, 'asignado_id' => null];
if (!$r['categoria'] && $r['equipo_id']) {
    $tipoEq = val('SELECT tipo FROM equipos WHERE id = ?', [$r['equipo_id']]);
    $r['categoria'] = match ($tipoEq) {
        'Impresora', 'Escáner' => 'Impresión',
        'Celular', 'Teléfono IP' => 'Telefonía / celular',
        'Cámara', 'DVR / NVR' => 'Cámaras y seguridad',
        'Router / Switch', 'Access point' => 'Red e internet',
        'Aire acondicionado' => 'Climatización',
        default => 'Hardware', // computadores y demás
    };
}
?>
<div class="panel" style="max-width:900px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="full"><label class="form-label req">Asunto</label><input class="form-control" name="asunto" maxlength="200" value="<?= e($r['asunto']) ?>" placeholder="Ej.: La impresora de contabilidad no imprime" required></div>
      <div><label class="form-label req">Categoría</label><select class="form-select" name="categoria" required><option value="">Seleccione…</option><?= options(TICKET_CATEGORIAS, $r['categoria'], false) ?></select></div>
      <div><label class="form-label">Prioridad</label><select class="form-select" name="prioridad"><?= options(TICKET_PRIORIDADES, $r['prioridad'], false) ?></select></div>
      <div class="full"><label class="form-label">Equipo relacionado</label>
        <select class="form-select" name="equipo_id"><option value="">— Ninguno / no aplica —</option>
          <?php foreach ($equipos as $q): ?>
            <option value="<?= (int)$q['id'] ?>" <?= (int)$r['equipo_id'] === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['codigo'] . ' · ' . trim($q['tipo'] . ' ' . ($q['marca'] ?? '')) . ($q['usuario'] ? ' — ' . $q['usuario'] : '') . ($q['empresa'] && alcance() !== 'empresa' ? ' — ' . $q['empresa'] : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($staff):
        $usuarios = all('SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre');
        $admins = all("SELECT id, nombre FROM usuarios WHERE activo = 1 AND rol = 'admin' ORDER BY nombre"); ?>
      <div><label class="form-label">Solicitante</label><select class="form-select" name="solicitante_id"><option value="">(yo)</option><?= options(array_column($usuarios, 'nombre', 'id'), $r['solicitante_id']) ?></select></div>
      <div><label class="form-label">Empresa</label><select class="form-select" name="empresa_id"><option value="">(la del solicitante / equipo)</option><?= options(empresas_list(), $r['empresa_id']) ?></select></div>
      <div><label class="form-label">Asignar a</label><select class="form-select" name="asignado_id"><option value="">Sin asignar</option><?= options(array_column($admins, 'nombre', 'id'), $r['asignado_id']) ?></select></div>
      <?php endif; ?>
      <div class="full"><label class="form-label req">Descripción</label><textarea class="form-control" name="descripcion" rows="7" required placeholder="¿Qué ocurre? ¿Desde cuándo? ¿Aparece algún mensaje de error?"><?= e($r['descripcion']) ?></textarea></div>
      <div class="full"><label class="form-label">Adjuntos (capturas, fotos, documentos — máx. 10 MB c/u)</label><input class="form-control" type="file" name="adjuntos[]" multiple></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary"><?= isset($r['id']) ? 'Guardar cambios' : 'Crear ticket' ?></button>
      <a class="btn btn-outline-secondary" href="<?= isset($r['id']) ? url('ticket', ['id' => $r['id']]) : url('tickets') ?>">Cancelar</a>
    </div>
  </form>
</div>
