<?php
/** @var string $title */
global $CONFIG;
$u = user();
$p = $_GET['p'] ?? 'inicio';
$p = [
    'ticket' => 'tickets', 'ticket_form' => 'tickets', 'equipo' => (es_computo((string)val('SELECT tipo FROM equipos WHERE id = ?', [(int)($_GET['id'] ?? 0)])) ? 'computo' : 'inventario'), 'equipo_form' => 'inventario',
    'computo_lista' => 'computo', 'computo_form' => 'computo', 'importar' => (($_GET['inv'] ?? '') === 'otros' ? 'inventario' : 'computo'),
    'gasto_form' => 'gastos', 'presupuesto' => 'estadisticas', 'factura' => 'cartera', 'factura_form' => 'cartera',
    'usuario_form' => 'usuarios', 'empresa_form' => 'empresas', 'empresas_importar' => 'empresas', 'cartera_importar' => 'cartera', 'rol_form' => 'roles', 'mantenimiento_form' => 'mantenimiento', 'cotizacion' => 'cotizaciones', 'cotizacion_form' => 'cotizaciones', 'cotizacion_config' => 'cotizaciones',
][$p] ?? $p;
$appName = $CONFIG['app_name'] ?? 'MAVAL';
$abiertos = $mantVencidos = 0;
if ($u && can('tickets', 'ver')) {
    [$ts, $tp] = ticket_scope('t');
    $abiertos = (int)val("SELECT COUNT(*) FROM tickets t WHERE $ts AND t.estado IN ('Abierto','En proceso','En espera')", $tp);
}
if ($u && can('mantenimiento', 'ver')) {
    [$es, $ep] = equipo_scope('eq');
    $mantVencidos = (int)val("SELECT COUNT(*) FROM mantenimientos m JOIN equipos eq ON eq.id = m.equipo_id WHERE $es AND m.estado = 'Programado' AND m.fecha_programada < ? AND eq.estado <> 'Dado de baja'", array_merge($ep, [today()]));
}

function nav_item(string $page, string $icon, string $label, string $current, string $extra = ''): string
{
    $active = $current === $page ? ' active' : '';
    return '<a class="nav-i' . $active . '" href="' . url($page) . '"><i class="bi bi-' . $icon . '"></i><span>' . e($label) . '</span>' . $extra . '</a>';
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') ? $title . ' · ' . $appName : $appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= asset('assets/app.css') ?>" rel="stylesheet">
</head>
<body>
<?php if ($u): ?>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php"><span class="logo"><?= e(mb_substr($appName, 0, 1)) ?></span><span><?= e($appName) ?></span></a>
    <nav>
      <?= nav_item('inicio', 'grid-1x2', 'Inicio', $p) ?>
      <div class="nav-h">Soporte</div>
      <?php if (can('tickets')) echo nav_item('tickets', 'life-preserver', 'Mesa de ayuda', $p, $abiertos ? '<em class="cnt">' . $abiertos . '</em>' : ''); ?>
      <?php if (can('computo')) echo nav_item('computo', 'pc-display', 'Inventario de cómputo', $p); ?>
      <?php if (can('inventario')) echo nav_item('inventario', 'phone', 'Otros equipos', $p); ?>
      <?php if (can('mantenimiento')) echo nav_item('mantenimiento', 'calendar-check', 'Planeador de mantenimiento', $p, $mantVencidos ? '<em class="cnt" style="background:#b91c1c" title="Mantenimientos vencidos">' . $mantVencidos . '</em>' : ''); ?>
      <?php if (can('cotizaciones')): ?>
      <div class="nav-h">Comercial</div>
      <?= nav_item('cotizaciones', 'file-earmark-text', 'Cotizaciones', $p) ?>
      <?php endif; ?>
      <?php if (can('gastos') || can('estadisticas') || can('cartera') || can('ingresos')): ?>
      <div class="nav-h">Finanzas</div>
      <?php if (can('gastos')) echo nav_item('gastos', 'wallet2', 'Gastos', $p); ?>
      <?php if (can('estadisticas')) echo nav_item('estadisticas', 'bar-chart-line', 'Estadísticas y presupuesto', $p); ?>
      <?php if (can('cartera')) echo nav_item('cartera', 'receipt', 'Cartera', $p); ?>
      <?php if (can('ingresos')) echo nav_item('ingresos', 'cash-coin', 'Entradas de dinero', $p); ?>
      <?php endif; ?>
      <?php if (can('usuarios') || can('roles') || can('empresas') || can('categorias') || can('config') || can('cotizaciones', 'administrar')): ?>
      <div class="nav-h">Administración</div>
      <?php if (can('usuarios')) echo nav_item('usuarios', 'people', 'Usuarios', $p); ?>
      <?php if (can('roles')) echo nav_item('roles', 'shield-check', 'Roles y permisos', $p); ?>
      <?php if (can('empresas')) echo nav_item('empresas', 'buildings', 'Empresas', $p); ?>
      <?php if (can('categorias')) echo nav_item('categorias', 'tags', 'Categorías de gasto', $p); ?>
      <?php if (can('cotizaciones', 'administrar')) echo nav_item('cotizacion_config', 'sliders', 'Configurar cotizaciones', $p); ?>
      <?php if (can('config')) echo nav_item('config_correo', 'envelope-at', 'Correo y notificaciones', $p); ?>
      <?php endif; ?>
    </nav>
    <div class="side-foot">v<?= APP_VERSION ?></div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="btn-icon d-lg-none" onclick="document.body.classList.toggle('nav-open')" aria-label="Menú"><i class="bi bi-list"></i></button>
      <h1 class="page-title"><?= e($title ?? '') ?></h1>
      <div class="dropdown ms-auto">
        <button class="user-chip" data-bs-toggle="dropdown">
          <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['nombre'], 0, 1))) ?></span>
          <span class="d-none d-sm-inline"><span class="un"><?= e($u['nombre']) ?></span><small><?= e(nombre_rol($u)) ?><?= $u['empresa_nombre'] ? ' · ' . e($u['empresa_nombre']) : '' ?></small></span>
          <i class="bi bi-chevron-down small"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= url('perfil') ?>"><i class="bi bi-person me-2"></i>Mi perfil</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
        </ul>
      </div>
    </header>
    <div class="content">
<?php foreach (flash() as [$t, $m]): ?>
      <div class="alert alert-<?= e($t) ?> alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
<?php endif; ?>
