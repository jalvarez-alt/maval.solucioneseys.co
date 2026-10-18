<?php
/**
 * MAVAL - núcleo de la aplicación: configuración, base de datos, sesión, seguridad y utilidades.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('APP_VERSION', '1.2.0');
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
    if ($v >= 2) return;
    $run = function (string $sql, bool $tolerar = false) use ($sqlite) {
        try { db()->exec($sqlite ? sql_sqlite($sql) : $sql); } catch (Throwable $e) { if (!$tolerar) throw $e; }
    };
    $eng = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $run('CREATE TABLE IF NOT EXISTS app_meta (clave VARCHAR(40) NOT NULL PRIMARY KEY, valor VARCHAR(255) NULL)' . $eng);
    foreach (['cargo VARCHAR(100) NULL', 'correos VARCHAR(500) NULL', 'procesador VARCHAR(120) NULL', 'ram VARCHAR(40) NULL', 'disco VARCHAR(80) NULL', 'renovacion VARCHAR(10) NULL'] as $col) {
        $run('ALTER TABLE equipos ADD COLUMN ' . $col, true); // ya existe: se ignora
    }
    $run('CREATE TABLE IF NOT EXISTS equipo_software (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, equipo_id INT UNSIGNED NOT NULL, categoria VARCHAR(12) NOT NULL,
        nombre VARCHAR(150) NOT NULL, version VARCHAR(60) NULL, tipo_licencia VARCHAR(40) NULL, licencia VARCHAR(10) NOT NULL DEFAULT \'Legal\',
        vence DATE NULL, FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE)' . $eng);
    $run("UPDATE equipos SET tipo = 'Escritorio' WHERE tipo = 'Computador de escritorio'");
    $run("DELETE FROM app_meta WHERE clave = 'db_version'");
    $run("INSERT INTO app_meta (clave, valor) VALUES ('db_version', '2')");
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
    $u = $id ? one('SELECT u.*, e.nombre AS empresa_nombre FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.id = ? AND u.activo = 1', [$id]) : null;
    return $u;
}
function is_admin(): bool { return (user()['rol'] ?? '') === 'admin'; }
function is_role(string ...$roles): bool { return in_array(user()['rol'] ?? '', $roles, true); }
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
function require_role(string ...$roles): void
{
    if (!is_role(...$roles)) {
        http_response_code(403);
        render_error('No tiene permiso para acceder a esta sección.');
    }
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
const TICKET_CATEGORIAS = ['Hardware', 'Software', 'Red e internet', 'Impresión', 'Correo', 'Accesos y contraseñas', 'Telefonía / celular', 'Cámaras y seguridad', 'Otro'];
const TICKET_PRIORIDADES = ['Baja', 'Media', 'Alta', 'Crítica'];
const TICKET_ESTADOS = ['Abierto', 'En proceso', 'En espera', 'Resuelto', 'Cerrado'];
const TICKET_ACTIVOS = ['Abierto', 'En proceso', 'En espera'];
const COMPUTO_TIPOS = ['Servidor', 'Escritorio', 'Portátil', 'Todo en uno'];
const OTROS_TIPOS = ['Monitor', 'Celular', 'Tablet', 'Impresora', 'Escáner', 'Cámara', 'DVR / NVR', 'Router / Switch', 'Access point', 'UPS', 'Teléfono IP', 'Otro'];
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
    if (is_admin()) return ['1=1', []];
    if ($u['rol'] === 'empresa' && $u['empresa_id']) return ["$alias.empresa_id = ?", [(int)$u['empresa_id']]];
    return ["$alias.solicitante_id = ?", [(int)$u['id']]];
}
/** Condición SQL + parámetros para limitar equipos según el rol del usuario actual. */
function equipo_scope(string $alias = 'eq'): array
{
    $u = user();
    if (is_admin()) return ['1=1', []];
    if ($u['rol'] === 'empresa' && $u['empresa_id']) return ["$alias.empresa_id = ?", [(int)$u['empresa_id']]];
    return ["$alias.usuario_id = ?", [(int)$u['id']]];
}
function empresas_list(?string $tipo = null): array
{
    $rows = $tipo ? all('SELECT id, nombre FROM empresas WHERE tipo = ? AND activo = 1 ORDER BY nombre', [$tipo])
                  : all('SELECT id, nombre FROM empresas WHERE activo = 1 ORDER BY tipo DESC, nombre');
    return array_column($rows, 'nombre', 'id');
}

const PREFIJOS = ['Servidor' => 'SRV', 'Escritorio' => 'PC', 'Portátil' => 'LAP', 'Todo en uno' => 'AIO', 'Monitor' => 'MON', 'Celular' => 'CEL', 'Tablet' => 'TAB',
    'Impresora' => 'IMP', 'Escáner' => 'ESC', 'Cámara' => 'CAM', 'DVR / NVR' => 'DVR', 'Router / Switch' => 'RED', 'Access point' => 'AP', 'UPS' => 'UPS', 'Teléfono IP' => 'TEL', 'Otro' => 'EQ'];

function siguiente_codigo(string $tipo): string
{
    $pre = PREFIJOS[$tipo] ?? 'EQ';
    $n = (int)val('SELECT COUNT(*) FROM equipos WHERE codigo LIKE ?', [$pre . '-%']) + 1;
    do { $c = $pre . '-' . str_pad((string)$n++, 4, '0', STR_PAD_LEFT); } while (val('SELECT id FROM equipos WHERE codigo = ?', [$c]));
    return $c;
}
function es_computo(?string $tipo): bool { return in_array($tipo, COMPUTO_TIPOS, true); }
