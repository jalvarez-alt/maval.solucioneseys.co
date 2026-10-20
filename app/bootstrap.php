<?php
/**
 * MAVAL - núcleo de la aplicación: configuración, base de datos, sesión, seguridad y utilidades.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('APP_VERSION', '1.6.0');
define('DB_VERSION', 6);
/** Versión de un archivo estático según su fecha de modificación: obliga al navegador a recargarlo cuando cambia. */
function asset(string $path): string { $f = APP_ROOT . '/' . $path; return $path . '?v=' . (is_file($f) ? filemtime($f) : APP_VERSION); }

if (!is_file(APP_DIR . '/config.php')) {
    header('Location: instalar.php');
    exit;
}
$CONFIG = require APP_DIR . '/config.php';

date_default_timezone_set($CONFIG['timezone'] ?? 'America/Bogota');
error_reporting(E_ALL);
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');

// ---------- Sesión segura ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('MAVALSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------- Base de datos ----------
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo) return $pdo;
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (($CONFIG['db']['driver'] ?? 'mysql') === 'sqlite') {
        // Solo para pruebas locales.
        $pdo = new PDO('sqlite:' . $CONFIG['db']['path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->sqliteCreateFunction('YEAR', fn($d) => $d ? (int)substr((string)$d, 0, 4) : null, 1);
        $pdo->sqliteCreateFunction('MONTH', fn($d) => $d ? (int)substr((string)$d, 5, 2) : null, 1);
        $pdo->sqliteCreateFunction('DATEDIFF', fn($a, $b) => ($a && $b) ? (int)round((strtotime(substr((string)$a, 0, 10)) - strtotime(substr((string)$b, 0, 10))) / 86400) : null, 2);
        $pdo->sqliteCreateFunction('GREATEST', fn($a, $b) => max($a, $b), 2);
        return $pdo;
    }
    $c = $CONFIG['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $i = 0;
    foreach (array_values($params) as $v) {
        $i++;
        $type = is_int($v) ? PDO::PARAM_INT : (is_bool($v) ? PDO::PARAM_BOOL : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $st->bindValue($i, is_float($v) ? (string)$v : $v, $type);
    }
    $st->execute();
    return $st;
}
function all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function one(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r ?: null; }
function val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}
function update(string $table, array $data, int $id): void
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    q("UPDATE $table SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
}

/** Convierte el esquema MySQL a SQLite (solo pruebas locales). */
function sql_sqlite(string $sql): string
{
    $sql = preg_replace('/INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/\)\s*ENGINE=[^;]+(;|$)/', ')$1', $sql);
    return preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
}

/** Actualiza la base de datos de instalaciones anteriores sin perder información. */
function migrar(): void
{
    global $CONFIG;
    $sqlite = ($CONFIG['db']['driver'] ?? 'mysql') === 'sqlite';
    try { $v = (int)val("SELECT valor FROM app_meta WHERE clave = 'db_version'"); } catch (Throwable $e) { $v = 1; }
    if ($v >= DB_VERSION) return;
    $run = function (string $sql, bool $tolerar = false) use ($sqlite) {
        try { db()->exec($sqlite ? sql_sqlite($sql) : $sql); } catch (Throwable $e) { if (!$tolerar) throw $e; }
    };
    $eng = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if ($v < 2) {
        $run('CREATE TABLE IF NOT EXISTS app_meta (clave VARCHAR(40) NOT NULL PRIMARY KEY, valor VARCHAR(255) NULL)' . $eng);
        foreach (['cargo VARCHAR(100) NULL', 'correos VARCHAR(500) NULL', 'procesador VARCHAR(120) NULL', 'ram VARCHAR(40) NULL', 'disco VARCHAR(80) NULL', 'renovacion VARCHAR(10) NULL'] as $col) {
            $run('ALTER TABLE equipos ADD COLUMN ' . $col, true); // ya existe: se ignora
        }
        $run('CREATE TABLE IF NOT EXISTS equipo_software (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, equipo_id INT UNSIGNED NOT NULL, categoria VARCHAR(12) NOT NULL,
            nombre VARCHAR(150) NOT NULL, version VARCHAR(60) NULL, tipo_licencia VARCHAR(40) NULL, licencia VARCHAR(10) NOT NULL DEFAULT \'Legal\',
            vence DATE NULL, FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE)' . $eng);
        $run("UPDATE equipos SET tipo = 'Escritorio' WHERE tipo = 'Computador de escritorio'");
    }
    if ($v < 3) migrar_v3($run, $eng);
    if ($v < 4) migrar_v4($run, $eng);
    if ($v < 5) migrar_v5($run);
    if ($v < 6) $run('ALTER TABLE cotizaciones ADD COLUMN sumar_ofertas TINYINT NOT NULL DEFAULT 0', true); // v6: sumar las dos ofertas en un total combinado
    $run("DELETE FROM app_meta WHERE clave = 'db_version'");
    $run("INSERT INTO app_meta (clave, valor) VALUES ('db_version', '" . DB_VERSION . "')");
}

/** v5: segunda oferta comercial en las propuestas. */
function migrar_v5(callable $run): void
{
    $run('ALTER TABLE cotizacion_items ADD COLUMN oferta TINYINT NOT NULL DEFAULT 1', true);
    foreach (['titulo1 VARCHAR(100) NULL', 'titulo2 VARCHAR(100) NULL', 'mano_obra2 DECIMAL(15,2) NOT NULL DEFAULT 0', 'subtotal2 DECIMAL(15,2) NOT NULL DEFAULT 0', 'iva2 DECIMAL(15,2) NOT NULL DEFAULT 0', 'total2 DECIMAL(15,2) NOT NULL DEFAULT 0'] as $col) {
        $run('ALTER TABLE cotizaciones ADD COLUMN ' . $col, true);
    }
}

/** v4: módulo de cotizaciones (encabezado e ítems). */
function migrar_v4(callable $run, string $eng): void
{
    $run('CREATE TABLE IF NOT EXISTS cotizaciones (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, consecutivo INT UNSIGNED NOT NULL, formato VARCHAR(10) NOT NULL DEFAULT \'items\',
        fecha DATE NOT NULL, ciudad VARCHAR(80) NULL, empresa_id INT UNSIGNED NULL, cliente_nombre VARCHAR(150) NOT NULL, cliente_nit VARCHAR(30) NULL,
        cliente_direccion VARCHAR(200) NULL, cliente_telefono VARCHAR(40) NULL, cliente_email VARCHAR(150) NULL, atencion VARCHAR(120) NULL,
        descripcion TEXT NULL, alcances TEXT NULL, subtotal DECIMAL(15,2) NOT NULL DEFAULT 0, mano_obra DECIMAL(15,2) NOT NULL DEFAULT 0,
        iva_pct DECIMAL(5,2) NOT NULL DEFAULT 0, iva DECIMAL(15,2) NOT NULL DEFAULT 0, total DECIMAL(15,2) NOT NULL DEFAULT 0,
        validez VARCHAR(100) NULL, tiempo_entrega VARCHAR(100) NULL, forma_pago VARCHAR(150) NULL, notas TEXT NULL,
        estado VARCHAR(10) NOT NULL DEFAULT \'Borrador\', enviada DATETIME NULL, enviada_a VARCHAR(500) NULL,
        creado_por INT UNSIGNED NULL, creado DATETIME NULL, actualizado DATETIME NULL, UNIQUE KEY uq_cot_consecutivo (consecutivo),
        FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL)' . $eng);
    $run('CREATE TABLE IF NOT EXISTS cotizacion_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, cotizacion_id INT UNSIGNED NOT NULL, orden SMALLINT NOT NULL DEFAULT 0,
        referencia VARCHAR(60) NULL, descripcion VARCHAR(500) NOT NULL, marca VARCHAR(60) NULL, unidad VARCHAR(20) NULL,
        cantidad DECIMAL(12,2) NOT NULL DEFAULT 1, valor_unitario DECIMAL(15,2) NOT NULL DEFAULT 0, valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE)' . $eng);
}

/** v3: roles y permisos, ajustes y registro de correo, planeador de mantenimiento y datos de aires acondicionados. */
function migrar_v3(callable $run, string $eng): void
{
    // Aires acondicionados (campos propios en equipos)
    foreach (['btu INT NULL', 'tipo_aire VARCHAR(30) NULL', 'tecnologia VARCHAR(12) NULL', 'refrigerante VARCHAR(12) NULL', 'voltaje VARCHAR(20) NULL', 'serial_exterior VARCHAR(120) NULL'] as $col) {
        $run('ALTER TABLE equipos ADD COLUMN ' . $col, true);
    }
    // Roles y permisos
    $run('CREATE TABLE IF NOT EXISTS roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, codigo VARCHAR(10) NULL, nombre VARCHAR(60) NOT NULL, descripcion VARCHAR(255) NULL,
        alcance VARCHAR(10) NOT NULL DEFAULT \'propios\', sistema TINYINT NOT NULL DEFAULT 0, creado DATETIME NULL, UNIQUE KEY uq_roles_nombre (nombre))' . $eng);
    $run('CREATE TABLE IF NOT EXISTS rol_permisos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, rol_id INT UNSIGNED NOT NULL, modulo VARCHAR(30) NOT NULL, accion VARCHAR(15) NOT NULL,
        UNIQUE KEY uq_rol_permiso (rol_id, modulo, accion), FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE)' . $eng);
    $run('ALTER TABLE usuarios ADD COLUMN rol_id INT UNSIGNED NULL', true);
    if (!(int)val('SELECT COUNT(*) FROM roles')) {
        $base = [
            'admin'   => ['Administrador', 'Acceso total a la aplicación.', 'todos'],
            'usuario' => ['Usuario', 'Crea y sigue sus propios tickets y consulta los equipos que tiene asignados.', 'propios'],
            'empresa' => ['Empresa', 'Ve y crea tickets de toda su empresa y consulta el inventario y los mantenimientos de su empresa.', 'empresa'],
        ];
        foreach ($base as $cod => [$nom, $desc, $alc]) {
            $rid = insert('roles', ['codigo' => $cod, 'nombre' => $nom, 'descripcion' => $desc, 'alcance' => $alc, 'sistema' => 1, 'creado' => now()]);
            if ($cod !== 'admin') {
                foreach ([['tickets', 'ver'], ['tickets', 'crear'], ['computo', 'ver'], ['inventario', 'ver'], ['mantenimiento', 'ver']] as [$m, $a]) {
                    insert('rol_permisos', ['rol_id' => $rid, 'modulo' => $m, 'accion' => $a]);
                }
            }
            $run("UPDATE usuarios SET rol_id = $rid WHERE rol = '$cod'");
        }
    }
    $run("UPDATE usuarios SET rol_id = (SELECT id FROM roles WHERE codigo = 'usuario') WHERE rol_id IS NULL");
    // Ajustes (correo, notificaciones) y registro de envíos
    $run('CREATE TABLE IF NOT EXISTS ajustes (clave VARCHAR(40) NOT NULL PRIMARY KEY, valor TEXT NULL)' . $eng);
    $run('CREATE TABLE IF NOT EXISTS correo_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, fecha DATETIME NOT NULL, destinatarios VARCHAR(500) NOT NULL, asunto VARCHAR(255) NOT NULL,
        ok TINYINT NOT NULL DEFAULT 0, error VARCHAR(500) NULL, motivo VARCHAR(30) NULL)' . $eng);
    // Planeador de mantenimiento
    $run('CREATE TABLE IF NOT EXISTS mantenimientos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, equipo_id INT UNSIGNED NOT NULL, fecha_programada DATE NOT NULL,
        estado VARCHAR(12) NOT NULL DEFAULT \'Programado\', periodicidad_meses SMALLINT NOT NULL DEFAULT 0, aviso_dias SMALLINT NOT NULL DEFAULT 7,
        tecnico_id INT UNSIGNED NULL, actividades VARCHAR(500) NULL, observaciones TEXT NULL, fecha_realizada DATE NULL,
        programacion_enviada DATETIME NULL, aviso_enviado DATETIME NULL, pendiente_enviado DATETIME NULL,
        creado_por INT UNSIGNED NULL, creado DATETIME NULL, actualizado DATETIME NULL,
        FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE)' . $eng);
    $run('CREATE INDEX idx_mant_fecha ON mantenimientos (estado, fecha_programada)', true);
}
migrar();

// ---------- Utilidades ----------
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }
function money($v): string { return '$ ' . number_format((float)$v, 0, ',', '.'); }
function num($v, int $d = 0): string { return number_format((float)$v, $d, ',', '.'); }
function fdate(?string $d, bool $time = false): string
{
    if (!$d) return '—';
    $ts = strtotime($d);
    return $time ? date('d/m/Y H:i', $ts) : date('d/m/Y', $ts);
}
function get(string $k, $def = null) { return isset($_GET[$k]) && $_GET[$k] !== '' ? trim((string)$_GET[$k]) : $def; }
function post(string $k, $def = null) { return isset($_POST[$k]) && $_POST[$k] !== '' ? (is_array($_POST[$k]) ? $_POST[$k] : trim((string)$_POST[$k])) : $def; }
/** Convierte "1.250.000,50" o "1250000.50" o "1,250,000" en float. */
function parse_money($v): float
{
    $v = preg_replace('/[^\d,.\-]/', '', (string)$v);
    if ($v === '' || $v === '-') return 0.0;
    $lastComma = strrpos($v, ',');
    $lastDot = strrpos($v, '.');
    if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
        // coma decimal (formato colombiano)
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        $v = str_replace(',', '', $v);
        // varios puntos = separadores de miles
        if (substr_count($v, '.') > 1) $v = str_replace('.', '', $v);
        elseif ($lastDot !== false && strlen($v) - $lastDot - 1 === 3 && $lastComma === false) $v = str_replace('.', '', $v);
    }
    return round((float)$v, 2);
}
function valid_date(?string $d): ?string
{
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}
function url(string $page, array $params = []): string
{
    $params = array_merge(['p' => $page], $params);
    return 'index.php?' . http_build_query($params);
}
function redirect(string $to): void { header('Location: ' . $to); exit; }
function flash(?string $msg = null, string $type = 'success')
{
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const MESES_CORTOS = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
const ROLES = ['admin' => 'Administrador', 'usuario' => 'Usuario', 'empresa' => 'Empresa'];

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['_csrf'] ?? '';
        if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
            http_response_code(419);
            exit('La sesión expiró o la solicitud no es válida. Vuelva atrás y recargue la página.');
        }
    }
}

// ---------- Autenticación y roles ----------
function user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $id = $_SESSION['uid'] ?? null;
    $u = $id ? one('SELECT u.*, e.nombre AS empresa_nombre, r.nombre AS rol_nombre, r.codigo AS rol_codigo, r.alcance AS rol_alcance
        FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id LEFT JOIN roles r ON r.id = u.rol_id
        WHERE u.id = ? AND u.activo = 1', [$id]) : null;
    return $u;
}
/** El rol «Administrador» (de sistema) tiene todos los permisos y no se puede restringir. */
function is_admin(): bool { $u = user(); return $u && ($u['rol_codigo'] ?? $u['rol']) === 'admin'; }
/** Alcance de los datos del usuario: todos, los de su empresa o solo los propios. */
function alcance(): string
{
    $u = user();
    if (!$u) return 'propios';
    if (is_admin()) return 'todos';
    $a = $u['rol_alcance'] ?? ($u['rol'] === 'empresa' ? 'empresa' : 'propios');
    return $a === 'empresa' && !$u['empresa_id'] ? 'propios' : $a;
}
/** Permisos del usuario como conjunto "modulo.accion"; null = todos (administrador). */
function permisos(): ?array
{
    static $p = false;
    if ($p !== false) return $p;
    $u = user();
    if (!$u) return $p = [];
    if (is_admin()) return $p = null;
    $p = [];
    foreach (all('SELECT modulo, accion FROM rol_permisos WHERE rol_id = ?', [(int)$u['rol_id']]) as $r) $p[$r['modulo'] . '.' . $r['accion']] = true;
    return $p;
}
function can(string $modulo, string $accion = 'ver'): bool
{
    $p = permisos();
    return $p === null || isset($p[$modulo . '.' . $accion]);
}
function can_any(string $modulo): bool
{
    $p = permisos();
    if ($p === null) return true;
    foreach ($p as $k => $_) if (str_starts_with($k, $modulo . '.')) return true;
    return false;
}
function require_perm(string $modulo, string $accion = 'ver'): void
{
    if (!can($modulo, $accion)) {
        http_response_code(403);
        render_error('No tiene permiso para acceder a esta sección o realizar esta acción.');
    }
}
function nombre_rol(?array $u = null): string { $u ??= user(); return $u['rol_nombre'] ?? (ROLES[$u['rol'] ?? ''] ?? ''); }
function require_login(): void
{
    if (!user()) { $_SESSION = []; redirect('login.php'); }
    // Expira tras 4 horas de inactividad
    if (isset($_SESSION['last']) && time() - $_SESSION['last'] > 14400) {
        session_destroy();
        redirect('login.php?expirada=1');
    }
    $_SESSION['last'] = time();
}
function render_error(string $msg): void
{
    $title = 'Acceso denegado';
    require APP_DIR . '/layout_top.php';
    echo '<div class="empty-state"><i class="bi bi-shield-lock"></i><h2>' . e($msg) . '</h2><a class="btn btn-primary mt-3" href="index.php">Volver al inicio</a></div>';
    require APP_DIR . '/layout_bottom.php';
    exit;
}
function not_found(): void { http_response_code(404); render_error('El registro solicitado no existe o no tiene acceso a él.'); }

// ---------- Adjuntos ----------
const ADJ_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'];
const ADJ_MAX = 10 * 1024 * 1024;

/** Guarda los archivos subidos en el campo $field (múltiple). Devuelve número de archivos guardados. */
function save_uploads(string $field, string $entidad, int $entidadId): int
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return 0;
    $n = 0;
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $size = (int)$_FILES[$field]['size'][$i];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($size > ADJ_MAX || !in_array($ext, ADJ_EXT, true)) {
            flash('El archivo "' . $name . '" no se adjuntó (máx. 10 MB; tipos: ' . implode(', ', ADJ_EXT) . ').', 'warning');
            continue;
        }
        $file = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'][$i], UPLOAD_DIR . '/' . $file)) continue;
        insert('adjuntos', [
            'entidad' => $entidad, 'entidad_id' => $entidadId,
            'nombre_original' => mb_substr(basename($name), 0, 250), 'archivo' => $file,
            'tamano' => $size, 'subido_por' => user()['id'] ?? null, 'creado' => now(),
        ]);
        $n++;
    }
    return $n;
}
function adjuntos(string $entidad, int $id): array
{
    return all('SELECT * FROM adjuntos WHERE entidad = ? AND entidad_id = ? ORDER BY id', [$entidad, $id]);
}
function render_adjuntos(array $list): string
{
    if (!$list) return '';
    $h = '<div class="adjuntos">';
    foreach ($list as $a) {
        $h .= '<a class="adj" href="archivo.php?id=' . (int)$a['id'] . '" target="_blank"><i class="bi bi-paperclip"></i> ' . e($a['nombre_original']) . ' <small>(' . num($a['tamano'] / 1024) . ' KB)</small></a>';
    }
    return $h . '</div>';
}

// ---------- Componentes de vista ----------
function badge(string $text, string $kind = ''): string
{
    $map = [
        'Abierto' => 'info', 'En proceso' => 'primary', 'En espera' => 'warning', 'Resuelto' => 'success', 'Cerrado' => 'secondary',
        'Baja' => 'secondary', 'Media' => 'info', 'Alta' => 'warning', 'Crítica' => 'danger',
        'Activo' => 'success', 'En reparación' => 'warning', 'Bodega' => 'info', 'Dado de baja' => 'secondary',
        'Pagada' => 'success', 'Parcial' => 'info', 'Pendiente' => 'warning', 'Vencida' => 'danger', 'Anulada' => 'secondary',
        'admin' => 'danger', 'usuario' => 'primary', 'empresa' => 'success',
        'Legal' => 'success', 'No legal' => 'danger', 'Libre' => 'secondary', 'Sin registrar' => 'secondary',
        'Bajo' => 'success', 'Medio' => 'warning', 'Alto' => 'danger',
        'Borrador' => 'secondary', 'Enviada' => 'info', 'Aceptada' => 'success', 'Rechazada' => 'danger',
    ];
    $k = $kind ?: ($map[$text] ?? 'secondary');
    return '<span class="badge-s badge-' . $k . '">' . e(ROLES[$text] ?? $text) . '</span>';
}
function options(array $items, $selected, bool $assoc = true): string
{
    $h = '';
    foreach ($items as $k => $v) {
        $value = $assoc ? $k : $v;
        $h .= '<option value="' . e($value) . '"' . ((string)$value === (string)$selected ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $h;
}
function pagination(int $total, int $per, int $page, array $params): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $h = '<nav class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 12 && $i > 2 && $i < $pages - 1 && abs($i - $page) > 2) {
            if (!str_ends_with($h, '…</span>')) $h .= '<span class="dots">…</span>';
            continue;
        }
        $params['pg'] = $i;
        $h .= '<a class="' . ($i === $page ? 'on' : '') . '" href="index.php?' . e(http_build_query($params)) . '">' . $i . '</a>';
    }
    return $h . '</nav>';
}

// ---------- Cartera: estado calculado de factura ----------
function factura_estado(array $f): string
{
    if (!empty($f['anulada'])) return 'Anulada';
    $saldo = (float)$f['total'] - (float)($f['pagado'] ?? 0);
    if ($saldo <= 0.5) return 'Pagada';
    if ($f['fecha_vencimiento'] < today()) return 'Vencida';
    if ((float)($f['pagado'] ?? 0) > 0) return 'Parcial';
    return 'Pendiente';
}

// ---------- Catálogos ----------
const TICKET_CATEGORIAS = ['Hardware', 'Software', 'Red e internet', 'Impresión', 'Correo', 'Accesos y contraseñas', 'Telefonía / celular', 'Cámaras y seguridad', 'Climatización', 'Otro'];
const TICKET_PRIORIDADES = ['Baja', 'Media', 'Alta', 'Crítica'];
const TICKET_ESTADOS = ['Abierto', 'En proceso', 'En espera', 'Resuelto', 'Cerrado'];
const TICKET_ACTIVOS = ['Abierto', 'En proceso', 'En espera'];
const COMPUTO_TIPOS = ['Servidor', 'Escritorio', 'Portátil', 'Todo en uno'];
const OTROS_TIPOS = ['Monitor', 'Celular', 'Tablet', 'Impresora', 'Escáner', 'Cámara', 'DVR / NVR', 'Router / Switch', 'Access point', 'UPS', 'Teléfono IP', 'Aire acondicionado', 'Otro'];
const AIRE_TIPOS = ['Aire acondicionado'];
const AIRE_TIPO_EQUIPO = ['Split pared (mini split)', 'Split piso techo', 'Cassette', 'Ventana', 'Portátil', 'Multi split', 'Central / chiller', 'Otro'];
const AIRE_TECNOLOGIA = ['Inverter', 'On/Off'];
const AIRE_REFRIGERANTE = ['R410A', 'R32', 'R22', 'R134a', 'R407C', 'R290', 'Otro'];
const AIRE_VOLTAJE = ['110 V', '208-230 V', '220 V', '220 V trifásico', '380-440 V trifásico', 'Otro'];

// ---------- Mantenimiento preventivo ----------
const MANT_ESTADOS = ['Programado', 'Realizado', 'Cancelado'];
const MANT_PERIODOS = [0 => 'Una sola vez', 1 => 'Cada mes', 2 => 'Cada 2 meses', 3 => 'Cada 3 meses (trimestral)', 4 => 'Cada 4 meses', 6 => 'Cada 6 meses (semestral)', 12 => 'Cada año'];
/** Actividades sugeridas al programar, según el tipo de equipo. */
function mant_actividades_base(?string $tipo): string
{
    return match (true) {
        $tipo === 'Aire acondicionado' => 'Limpieza de filtros y serpentines, revisión de presión de gas, drenaje, estado eléctrico y funcionamiento general.',
        es_computo($tipo) => 'Limpieza interna y externa, revisión de discos y temperatura, actualizaciones del sistema, revisión de antivirus y respaldo de información.',
        $tipo === 'Impresora' || $tipo === 'Escáner' => 'Limpieza de rodillos y cabezales, revisión de consumibles y prueba de impresión.',
        $tipo === 'UPS' => 'Revisión de baterías, carga y transferencia, limpieza y prueba de autonomía.',
        default => 'Limpieza, revisión de funcionamiento y estado general del equipo.',
    };
}

// ---------- Módulos y permisos ----------
const ACCIONES = ['ver' => 'Consultar', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar', 'aprobar' => 'Aprobar', 'administrar' => 'Administrar'];
/** módulo => [nombre, icono, acciones aplicables => descripción de lo que autoriza]. */
const MODULOS = [
    'tickets' => ['Mesa de ayuda', 'life-preserver', [
        'ver' => 'Ver tickets (según el alcance del rol)', 'crear' => 'Crear tickets', 'editar' => 'Editar los datos de un ticket',
        'eliminar' => 'Eliminar tickets', 'aprobar' => 'Marcar tickets como resueltos o cerrarlos', 'administrar' => 'Atender: cambiar estado, asignar, notas internas y recibir avisos del equipo']],
    'computo' => ['Inventario de cómputo', 'pc-display', [
        'ver' => 'Consultar equipos de cómputo', 'crear' => 'Registrar equipos', 'editar' => 'Editar equipos y su hoja de vida', 'eliminar' => 'Eliminar equipos', 'administrar' => 'Importar, exportar y acciones masivas']],
    'inventario' => ['Otros equipos', 'phone', [
        'ver' => 'Consultar otros equipos (incluye aires acondicionados)', 'crear' => 'Registrar equipos', 'editar' => 'Editar equipos y su hoja de vida', 'eliminar' => 'Eliminar equipos', 'administrar' => 'Importar, exportar y acciones masivas']],
    'mantenimiento' => ['Planeador de mantenimiento', 'calendar-check', [
        'ver' => 'Consultar la programación', 'crear' => 'Programar mantenimientos', 'editar' => 'Reprogramar y editar', 'eliminar' => 'Eliminar programaciones',
        'aprobar' => 'Registrar mantenimientos como realizados o cancelarlos', 'administrar' => 'Enviar recordatorios manuales y exportar']],
    'cotizaciones' => ['Cotizaciones', 'file-earmark-text', [
        'ver' => 'Consultar cotizaciones y descargar el PDF', 'crear' => 'Crear y duplicar cotizaciones', 'editar' => 'Editar cotizaciones', 'eliminar' => 'Eliminar cotizaciones',
        'aprobar' => 'Enviar por correo y cambiar el estado (aceptada, rechazada, anulada)', 'administrar' => 'Configurar la cuenta de correo, los datos de la empresa, el logo y el consecutivo']],
    'gastos' => ['Gastos', 'wallet2', ['ver' => 'Consultar gastos', 'crear' => 'Registrar gastos', 'editar' => 'Editar gastos', 'eliminar' => 'Eliminar gastos']],
    'estadisticas' => ['Estadísticas y presupuesto', 'bar-chart-line', ['ver' => 'Consultar estadísticas y presupuesto', 'editar' => 'Definir el presupuesto']],
    'cartera' => ['Cartera', 'receipt', ['ver' => 'Consultar facturas y abonos', 'crear' => 'Crear facturas y registrar abonos', 'editar' => 'Editar facturas', 'eliminar' => 'Eliminar facturas y abonos', 'aprobar' => 'Anular o reactivar facturas']],
    'ingresos' => ['Entradas de dinero', 'cash-coin', ['ver' => 'Consultar entradas de dinero']],
    'empresas' => ['Empresas', 'buildings', ['ver' => 'Consultar empresas', 'crear' => 'Crear empresas', 'editar' => 'Editar empresas']],
    'categorias' => ['Categorías de gasto', 'tags', ['ver' => 'Consultar categorías', 'crear' => 'Crear categorías', 'editar' => 'Renombrar y activar', 'eliminar' => 'Eliminar categorías']],
    'usuarios' => ['Usuarios', 'people', ['ver' => 'Consultar usuarios', 'crear' => 'Crear usuarios', 'editar' => 'Editar usuarios y activarlos o desactivarlos']],
    'roles' => ['Roles y permisos', 'shield-check', ['ver' => 'Consultar roles', 'crear' => 'Crear roles', 'editar' => 'Editar permisos de los roles', 'eliminar' => 'Eliminar roles']],
    'config' => ['Correo y notificaciones', 'envelope-at', ['ver' => 'Consultar la configuración', 'administrar' => 'Cambiar la configuración y enviar pruebas']],
];
// ---------- Cotizaciones ----------
const COT_FORMATOS = ['items' => 'Cotización con ítems', 'propuesta' => 'Propuesta (descripción, alcances, oferta y condiciones)'];
const COT_ESTADOS = ['Borrador', 'Enviada', 'Aceptada', 'Rechazada', 'Anulada'];
const COT_UNIDADES = ['UND', 'M', 'ML', 'M2', 'KG', 'GLB', 'HORA', 'DIA', 'MES', 'SERV', 'PAQ', 'CAJA', 'ROLLO'];
/** Siguiente número de cotización (único para todos los formatos). */
function cot_siguiente(): int
{
    return max((int)val('SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM cotizaciones'), (int)ajuste('cot_inicio', 1));
}
/** Fecha larga en español: «diciembre 21 de 2022». */
function fecha_larga(string $f): string
{
    $t = strtotime($f);
    return mb_strtolower(MESES[(int)date('n', $t)]) . ' ' . date('j', $t) . ' de ' . date('Y', $t);
}

const ALCANCES =['todos' => 'Todos los registros', 'empresa' => 'Solo los de su empresa', 'propios' => 'Solo los propios (sus tickets y equipos asignados)'];
const EQUIPO_TIPOS = [...COMPUTO_TIPOS, ...OTROS_TIPOS];
const RENOVACION = ['Bajo', 'Medio', 'Alto'];
const LICENCIAS = ['Legal', 'No legal', 'Libre'];
const SOFT_BASE = ['so' => 'Sistema operativo', 'ofimatica' => 'Ofimática', 'antivirus' => 'Antivirus'];
const TIPOS_LICENCIA = ['OEM', 'Retail / caja', 'Volumen', 'Suscripción', 'Gratuita', 'Sin licencia', 'Otra'];
const EQUIPO_ESTADOS = ['Activo', 'En reparación', 'Bodega', 'Dado de baja'];
const METODOS_PAGO = ['Transferencia', 'Efectivo', 'Tarjeta', 'Consignación', 'Cheque', 'Débito automático', 'Otro'];

/** Condición SQL + parámetros para limitar tickets según el rol del usuario actual. */
function ticket_scope(string $alias = 't'): array
{
    $u = user();
    return match (alcance()) {
        'todos' => ['1=1', []],
        'empresa' => ["$alias.empresa_id = ?", [(int)$u['empresa_id']]],
        default => ["$alias.solicitante_id = ?", [(int)$u['id']]],
    };
}
/** Condición SQL + parámetros para limitar equipos según el rol del usuario actual. */
function equipo_scope(string $alias = 'eq'): array
{
    $u = user();
    return match (alcance()) {
        'todos' => ['1=1', []],
        'empresa' => ["$alias.empresa_id = ?", [(int)$u['empresa_id']]],
        default => ["$alias.usuario_id = ?", [(int)$u['id']]],
    };
}
function empresas_list(?string $tipo = null): array
{
    $rows = $tipo ? all('SELECT id, nombre FROM empresas WHERE tipo = ? AND activo = 1 ORDER BY nombre', [$tipo])
                  : all('SELECT id, nombre FROM empresas WHERE activo = 1 ORDER BY tipo DESC, nombre');
    return array_column($rows, 'nombre', 'id');
}

const PREFIJOS = ['Servidor' => 'SRV', 'Escritorio' => 'PC', 'Portátil' => 'LAP', 'Todo en uno' => 'AIO', 'Monitor' => 'MON', 'Celular' => 'CEL', 'Tablet' => 'TAB',
    'Impresora' => 'IMP', 'Escáner' => 'ESC', 'Cámara' => 'CAM', 'DVR / NVR' => 'DVR', 'Router / Switch' => 'RED', 'Access point' => 'AP', 'UPS' => 'UPS', 'Teléfono IP' => 'TEL', 'Aire acondicionado' => 'AAC', 'Otro' => 'EQ'];

function siguiente_codigo(string $tipo): string
{
    $pre = PREFIJOS[$tipo] ?? 'EQ';
    $n = (int)val('SELECT COUNT(*) FROM equipos WHERE codigo LIKE ?', [$pre . '-%']) + 1;
    do { $c = $pre . '-' . str_pad((string)$n++, 4, '0', STR_PAD_LEFT); } while (val('SELECT id FROM equipos WHERE codigo = ?', [$c]));
    return $c;
}
function es_computo(?string $tipo): bool { return in_array($tipo, COMPUTO_TIPOS, true); }

require_once APP_DIR . "/mail.php";
require_once APP_DIR . "/notificaciones.php";

/** Roles disponibles (para listas y formularios). */
function roles_todos(): array
{
    return all('SELECT r.*, (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = r.id AND u.activo = 1) AS n_usuarios FROM roles r ORDER BY r.sistema DESC, r.id');
}
/** Valor de la columna heredada usuarios.rol según el rol asignado (admin / empresa / usuario). */
function rol_legacy(array $rol): string
{
    return $rol['codigo'] === 'admin' ? 'admin' : ($rol['alcance'] === 'empresa' ? 'empresa' : 'usuario');
}
