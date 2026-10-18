<?php
require __DIR__ . '/app/bootstrap.php';

if (user()) redirect('index.php');
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $desde = date('Y-m-d H:i:s', time() - 900);
    $fallos = (int)val('SELECT COUNT(*) FROM login_intentos WHERE (email = ? OR ip = ?) AND fecha > ?', [$email, client_ip(), $desde]);
    if ($fallos >= 8) {
        $error = 'Demasiados intentos fallidos. Espere 15 minutos e intente de nuevo.';
    } else {
        $u = one('SELECT * FROM usuarios WHERE email = ?', [$email]);
        if ($u && $u['activo'] && password_verify($pass, $u['password'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['last'] = time();
            q('DELETE FROM login_intentos WHERE email = ?', [$email]);
            update('usuarios', ['ultimo_acceso' => now()], (int)$u['id']);
            if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
                update('usuarios', ['password' => password_hash($pass, PASSWORD_DEFAULT)], (int)$u['id']);
            }
            redirect('index.php');
        }
        insert('login_intentos', ['email' => $email, 'ip' => client_ip(), 'fecha' => now()]);
        $error = ($u && !$u['activo']) ? 'Su usuario está inactivo. Contacte al administrador.' : 'Correo o contraseña incorrectos.';
    }
}
$appName = $CONFIG['app_name'] ?? 'MAVAL';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingresar · <?= e($appName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= asset('assets/app.css') ?>" rel="stylesheet">
</head>
<body class="login-page">
  <div class="login-card">
    <div class="brand big"><span class="logo"><?= e(mb_substr($appName, 0, 1)) ?></span><span><?= e($appName) ?></span></div>
    <p class="text-muted mb-4">Mesa de ayuda, inventario, gastos y cartera</p>
    <?php if (isset($_GET['expirada'])): ?><div class="alert alert-warning py-2">Su sesión expiró por inactividad.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <label class="form-label">Correo electrónico</label>
      <input class="form-control mb-3" type="email" name="email" value="<?= e($email) ?>" required autofocus>
      <label class="form-label">Contraseña</label>
      <input class="form-control mb-4" type="password" name="password" required>
      <button class="btn btn-primary w-100 py-2">Ingresar</button>
    </form>
  </div>
</body>
</html>
