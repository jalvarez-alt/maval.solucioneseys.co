<?php
/** @var string $title */
global $CONFIG;
$u = user();
$p = $_GET['p'] ?? 'inicio';
$p = [
    'ticket' => 'tickets', 'ticket_form' => 'tickets', 'equipo' => (es_computo((string)val('SELECT tipo FROM equipos WHERE id = ?', [(int)($_GET['id'] ?? 0)])) ? 'computo' : 'inventario'), 'equipo_form' => 'inventario',
    'computo_lista' => 'computo', 'computo_form' => 'computo', 'importar' => (($_GET['inv'] ?? '') === 'otros' ? 'inventario' : 'computo'),
    'gasto_form' => 'gastos', 'presupuesto' => 'estadisticas', 'factura' => 'cartera', 'factura_form' => 'cartera',
    'usuario_form' => 'usuarios', 'empresa_form' => 'empresas',
][$p] ?? $p;
$appName = $CONFIG['app_name'] ?? 'MAVAL';
$abiertos = $u ? (int)val(
    is_admin() ? "SELECT COUNT(*) FROM tickets WHERE estado IN ('Abierto','En proceso','En espera')"
        : ($u['rol'] === 'empresa' ? "SELECT COUNT(*) FROM tickets WHERE estado IN ('Abierto','En proceso','En espera') AND empresa_id = " . (int)$u['empresa_id']
        : "SELECT COUNT(*) FROM tickets WHERE estado IN ('Abierto','En proceso','En espera') AND solicitante_id = " . (int)$u['id'])
) : 0;

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
      <?= nav_item('tickets', 'life-preserver', 'Mesa de ayuda', $p, $abiertos ? '<em class="cnt">' . $abiertos . '</em>' : '') ?>
      <?= nav_item('computo', 'pc-display', 'Inventario de cómputo', $p) ?>
      <?= nav_item('inventario', 'phone', 'Otros equipos', $p) ?>
      <?php if (is_admin()): ?>
      <div class="nav-h">Finanzas</div>
      <?= nav_item('gastos', 'wallet2', 'Gastos', $p) ?>
      <?= nav_item('estadisticas', 'bar-chart-line', 'Estadísticas y presupuesto', $p) ?>
      <?= nav_item('cartera', 'receipt', 'Cartera', $p) ?>
      <?= nav_item('ingresos', 'cash-coin', 'Entradas de dinero', $p) ?>
      <div class="nav-h">Administración</div>
      <?= nav_item('usuarios', 'people', 'Usuarios', $p) ?>
      <?= nav_item('empresas', 'buildings', 'Empresas', $p) ?>
      <?= nav_item('categorias', 'tags', 'Categorías de gasto', $p) ?>
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
          <span class="d-none d-sm-inline"><span class="un"><?= e($u['nombre']) ?></span><small><?= e(ROLES[$u['rol']]) ?><?= $u['empresa_nombre'] ? ' · ' . e($u['empresa_nombre']) : '' ?></small></span>
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
