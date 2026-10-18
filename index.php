<?php
require __DIR__ . '/app/bootstrap.php';
require_login();
csrf_check();
recordatorios_diarios(); // una vez al día: avisos de mantenimientos próximos y pendientes

$pages = [
    'inicio', 'perfil',
    'tickets', 'ticket', 'ticket_form',
    'inventario', 'equipo', 'equipo_form', 'computo', 'computo_lista', 'computo_form', 'importar',
    'mantenimiento', 'mantenimiento_form',
    'usuarios', 'usuario_form', 'roles', 'rol_form', 'empresas', 'empresa_form', 'config_correo',
    'gastos', 'gasto_form', 'estadisticas', 'presupuesto', 'categorias',
    'cartera', 'factura', 'factura_form', 'ingresos',
];
$p = $_GET['p'] ?? 'inicio';
if (!in_array($p, $pages, true)) $p = 'inicio';

// Módulo que protege cada página (además de las verificaciones de acción dentro de cada una)
$modulo = [
    'tickets' => 'tickets', 'ticket' => 'tickets', 'ticket_form' => 'tickets', 'computo' => 'computo', 'computo_lista' => 'computo', 'inventario' => 'inventario',
    'mantenimiento' => 'mantenimiento', 'mantenimiento_form' => 'mantenimiento', 'usuarios' => 'usuarios', 'roles' => 'roles', 'rol_form' => 'roles', 'empresas' => 'empresas',
    'config_correo' => 'config', 'gastos' => 'gastos', 'estadisticas' => 'estadisticas', 'categorias' => 'categorias', 'cartera' => 'cartera', 'factura' => 'cartera', 'ingresos' => 'ingresos',
];
if (isset($modulo[$p])) require_perm($modulo[$p], 'ver');

$title = '';
ob_start();
require APP_DIR . '/pages/' . $p . '.php';
$body = ob_get_clean();

require APP_DIR . '/layout_top.php';
echo $body;
require APP_DIR . '/layout_bottom.php';
