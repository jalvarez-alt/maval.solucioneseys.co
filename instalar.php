<?php
/**
 * Instalador web de MAVAL. Crea app/config.php, las tablas y el usuario administrador.
 * Después de instalar, ELIMINE este archivo del servidor.
 */
declare(strict_types=1);
date_default_timezone_set('America/Bogota');
$configFile = __DIR__ . '/app/config.php';
$dev = getenv('MAVAL_DEV') === '1';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function sqlite_schema(string $sql): string
{
    $sql = preg_replace('/INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/\)\s*ENGINE=[^;]+;/', ');', $sql);
    $sql = preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
    $sql = preg_replace('/^\s*KEY \w+ \([^)]*\),?\s*$/m', '', $sql);
    return $sql;
}

function run_install(array $in, bool $dev): array
{
    $driver = ($dev && ($in['driver'] ?? '') === 'sqlite') ? 'sqlite' : 'mysql';
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($driver === 'sqlite') {
        $path = $in['db_path'] ?: (__DIR__ . '/app/dev.sqlite');
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $db = ['driver' => 'sqlite', 'path' => $path];
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $in['db_host'], (int)$in['db_port'], $in['db_name']);
        $pdo = new PDO($dsn, $in['db_user'], $in['db_pass'], $opts);
        $db = ['driver' => 'mysql', 'host' => $in['db_host'], 'port' => (int)$in['db_port'], 'name' => $in['db_name'], 'user' => $in['db_user'], 'pass' => $in['db_pass']];
    }

    $schema = file_get_contents(__DIR__ . '/sql/schema.sql');
    $schema = preg_replace('/^--.*$/m', '', $schema);
    if ($driver === 'sqlite') $schema = sqlite_schema($schema);
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
        $pdo->exec($stmt);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) {
        throw new RuntimeException('La base de datos ya tiene usuarios. Use una base vacía o elimine las tablas existentes.');
    }

    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO empresas (nombre, nit, tipo, activo, creado) VALUES (?, ?, ?, 1, ?)');
    foreach ([1, 2] as $i) {
        $ins->execute([$in["emp{$i}_nombre"], $in["emp{$i}_nit"] ?: null, 'propia', $now]);
    }
    $cat = $pdo->prepare('INSERT INTO gasto_categorias (nombre) VALUES (?)');
    foreach (['Energía', 'Agua y alcantarillado', 'Gas', 'Internet y telefonía', 'Víveres y cafetería', 'Pago a proveedores', 'Arriendo', 'Nómina', 'Transporte', 'Papelería y aseo', 'Mantenimiento', 'Impuestos', 'Otros'] as $c) {
        $cat->execute([$c]);
    }
    $pdo->prepare('INSERT INTO usuarios (nombre, email, password, rol, cargo, activo, creado) VALUES (?, ?, ?, ?, ?, 1, ?)')
        ->execute([$in['adm_nombre'], mb_strtolower($in['adm_email']), password_hash($in['adm_pass'], PASSWORD_DEFAULT), 'admin', 'Administrador', $now]);
    $pdo->exec("INSERT INTO app_meta (clave, valor) VALUES ('db_version', '2')");
    $pdo->commit();

    $config = [
        'app_name' => $in['app_name'] ?: 'MAVAL',
        'timezone' => 'America/Bogota',
        'debug' => false,
        'db' => $db,
    ];
    $php = "<?php\n// Generado por el instalador el " . $now . "\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents(__DIR__ . '/app/config.php', $php) === false) {
        throw new RuntimeException('No se pudo escribir app/config.php. Verifique permisos de escritura de la carpeta app/.');
    }
    @chmod(__DIR__ . '/app/config.php', 0640);
    return ['ok' => true];
}

$errors = [];
$done = false;
$in = [
    'app_name' => 'MAVAL', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'adm_nombre' => '', 'adm_email' => '', 'adm_pass' => '', 'adm_pass2' => '',
    'emp1_nombre' => '', 'emp1_nit' => '', 'emp2_nombre' => '', 'emp2_nit' => '', 'driver' => 'mysql', 'db_path' => '',
];

if (is_file($configFile)) {
    $locked = true;
} else {
    $locked = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($in as $k => $v) $in[$k] = trim((string)($_POST[$k] ?? $v));
        $in['db_pass'] = (string)($_POST['db_pass'] ?? '');
        $in['adm_pass'] = (string)($_POST['adm_pass'] ?? '');
        $in['adm_pass2'] = (string)($_POST['adm_pass2'] ?? '');
        if ($in['driver'] !== 'sqlite' && ($in['db_name'] === '' || $in['db_user'] === '')) $errors[] = 'Indique el nombre y el usuario de la base de datos.';
        if ($in['adm_nombre'] === '' || !filter_var($in['adm_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Indique el nombre y un correo válido para el administrador.';
        if (strlen($in['adm_pass']) < 8) $errors[] = 'La contraseña del administrador debe tener al menos 8 caracteres.';
        if ($in['adm_pass'] !== $in['adm_pass2']) $errors[] = 'Las contraseñas no coinciden.';
        if ($in['emp1_nombre'] === '' || $in['emp2_nombre'] === '') $errors[] = 'Indique el nombre de las dos empresas para la cartera.';
        if (!$errors) {
            try {
                run_install($in, $dev);
                $done = true;
            } catch (Throwable $ex) {
                $errors[] = 'Error: ' . $ex->getMessage();
            }
        }
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación · MAVAL</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card wide">
  <div class="brand big"><span class="logo">M</span><span>Instalación</span></div>
<?php if ($locked && !$done): ?>
  <div class="alert alert-info">La aplicación ya está instalada. Por seguridad, <strong>elimine el archivo instalar.php</strong> del servidor.</div>
  <a class="btn btn-primary" href="login.php">Ir al inicio de sesión</a>
<?php elseif ($done): ?>
  <div class="alert alert-success"><strong>¡Instalación completa!</strong> Ya puede ingresar con el correo del administrador.</div>
  <div class="alert alert-warning">Importante: elimine ahora el archivo <code>instalar.php</code> desde el Administrador de archivos de cPanel.</div>
  <a class="btn btn-primary" href="login.php">Ir al inicio de sesión</a>
<?php else: ?>
  <p class="text-muted">Complete los datos. La base de datos MySQL debe existir y estar vacía (créela en cPanel → <em>MySQL Databases</em> y asígnele un usuario con <em>ALL PRIVILEGES</em>).</p>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= h($er) ?></div><?php endforeach; ?>
  <form method="post">
    <?php if ($dev): ?>
      <div class="row g-2 mb-2"><div class="col"><select name="driver" class="form-select"><option value="mysql">mysql</option><option value="sqlite" <?= $in['driver'] === 'sqlite' ? 'selected' : '' ?>>sqlite (dev)</option></select></div><div class="col"><input name="db_path" class="form-control" placeholder="ruta sqlite" value="<?= h($in['db_path']) ?>"></div></div>
    <?php endif; ?>
    <h6 class="sec">Aplicación</h6>
    <input class="form-control mb-2" name="app_name" value="<?= h($in['app_name']) ?>" placeholder="Nombre de la aplicación">
    <h6 class="sec">Base de datos MySQL</h6>
    <div class="row g-2">
      <div class="col-8"><input class="form-control" name="db_host" value="<?= h($in['db_host']) ?>" placeholder="Servidor"></div>
      <div class="col-4"><input class="form-control" name="db_port" value="<?= h($in['db_port']) ?>" placeholder="Puerto"></div>
      <div class="col-12"><input class="form-control" name="db_name" value="<?= h($in['db_name']) ?>" placeholder="Nombre de la base (ej. cpaneluser_maval)"></div>
      <div class="col-6"><input class="form-control" name="db_user" value="<?= h($in['db_user']) ?>" placeholder="Usuario"></div>
      <div class="col-6"><input class="form-control" type="password" name="db_pass" placeholder="Contraseña"></div>
    </div>
    <h6 class="sec">Empresas propias (cartera y gastos)</h6>
    <div class="row g-2">
      <div class="col-7"><input class="form-control" name="emp1_nombre" value="<?= h($in['emp1_nombre']) ?>" placeholder="Empresa 1"></div>
      <div class="col-5"><input class="form-control" name="emp1_nit" value="<?= h($in['emp1_nit']) ?>" placeholder="NIT"></div>
      <div class="col-7"><input class="form-control" name="emp2_nombre" value="<?= h($in['emp2_nombre']) ?>" placeholder="Empresa 2"></div>
      <div class="col-5"><input class="form-control" name="emp2_nit" value="<?= h($in['emp2_nit']) ?>" placeholder="NIT"></div>
    </div>
    <h6 class="sec">Administrador</h6>
    <div class="row g-2">
      <div class="col-12"><input class="form-control" name="adm_nombre" value="<?= h($in['adm_nombre']) ?>" placeholder="Nombre completo"></div>
      <div class="col-12"><input class="form-control" type="email" name="adm_email" value="<?= h($in['adm_email']) ?>" placeholder="Correo"></div>
      <div class="col-6"><input class="form-control" type="password" name="adm_pass" placeholder="Contraseña (mín. 8)"></div>
      <div class="col-6"><input class="form-control" type="password" name="adm_pass2" placeholder="Repetir contraseña"></div>
    </div>
    <button class="btn btn-primary w-100 mt-4 py-2">Instalar</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
