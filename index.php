<?php
require __DIR__ . '/app/bootstrap.php';
require_login();
csrf_check();

$pages = [
    'inicio', 'perfil',
    'tickets', 'ticket', 'ticket_form',
    'inventario', 'equipo', 'equipo_form', 'computo', 'computo_lista', 'computo_form', 'importar',
    'usuarios', 'usuario_form', 'empresas', 'empresa_form',
    'gastos', 'gasto_form', 'estadisticas', 'presupuesto', 'categorias',
    'cartera', 'factura', 'factura_form', 'ingresos',
];
$p = $_GET['p'] ?? 'inicio';
if (!in_array($p, $pages, true)) $p = 'inicio';

$title = '';
ob_start();
require APP_DIR . '/pages/' . $p . '.php';
$body = ob_get_clean();

require APP_DIR . '/layout_top.php';
echo $body;
require APP_DIR . '/layout_bottom.php';
