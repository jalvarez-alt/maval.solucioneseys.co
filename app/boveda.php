<?php
/**
 * Bóvedas de claves de los clientes: una bóveda por cliente, cada una con su propia frase maestra.
 *
 * Diseño de seguridad:
 *  - Cada usuario, contraseña y nota se cifra con AES-256-GCM (cifrado autenticado) con la clave de datos (DEK) de SU bóveda: una DEK aleatoria de 32 bytes.
 *    El «aad» liga cada dato a su registro y campo: no se puede cambiar un secreto de fila sin que el descifrado falle.
 *  - La DEK nunca se guarda en claro: queda envuelta (cifrada) con una clave derivada de la FRASE MAESTRA de esa bóveda (PBKDF2-HMAC-SHA256, 600 000 iteraciones,
 *    sal aleatoria) y con una segunda copia derivada de su CÓDIGO DE RECUPERACIÓN. La frase y el código no se guardan en ningún sitio.
 *    Quien obtenga la base de datos o los archivos del servidor solo ve texto cifrado, y la frase de un cliente no abre la bóveda de otro.
 *  - Además, solo ven una bóveda los administradores de la bóveda y los usuarios a quienes se les dio acceso (tabla boveda_acceso).
 *  - Al desbloquear, la DEK pasa a la sesión cifrada con una clave aleatoria que solo viaja en una cookie (HttpOnly, SameSite=Strict):
 *    el archivo de sesión por sí solo no sirve. Cada bóveda se bloquea sola tras BOV_INACTIVIDAD segundos sin usarla.
 *  - Las contraseñas no van en el HTML de ninguna página: se piden por POST (con CSRF) y quedan registradas en el registro de accesos.
 */

const BOV_TIPOS = ['Router / Firewall', 'Switch / Access point', 'Wi-Fi', 'Servidor', 'Correo', 'VPN', 'Cámaras / DVR', 'Aplicación / Web', 'Base de datos', 'Dominio / Hosting', 'Equipo (Windows / Mac)', 'Impresora', 'Otro'];
const BOV_ITERACIONES = 600000;
const BOV_INACTIVIDAD = 600;     // segundos sin usar una bóveda antes de bloquearla
const BOV_FRASE_MIN = 12;
const BOV_COOKIE = 'MAVALBOV';

// ---------- Cifrado ----------
function bov_enc(string $texto, string $clave, string $aad): string
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($texto, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($ct === false) throw new RuntimeException('No se pudo cifrar.');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function bov_dec(?string $blob, string $clave, string $aad): ?string
{
    if (!$blob || !str_starts_with($blob, 'v1:')) return null;
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 28) return null;
    $r = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
    return $r === false ? null : $r;
}
function bov_kdf(string $secreto, string $sal): string { return hash_pbkdf2('sha256', $secreto, $sal, BOV_ITERACIONES, 32, true); }

// ---------- Bóvedas y acceso ----------
/** Bóvedas que el usuario puede ver: todas si administra el módulo; si no, solo aquellas a las que se le dio acceso. */
function bov_lista(): array
{
    $sql = 'SELECT b.id, b.nombre, b.empresa_id, b.creado, (SELECT COUNT(*) FROM boveda r WHERE r.boveda_id = b.id) AS n FROM bovedas b';
    if (can('boveda', 'administrar')) return all($sql . ' ORDER BY b.nombre');
    return all($sql . ' JOIN boveda_acceso a ON a.boveda_id = b.id AND a.usuario_id = ? ORDER BY b.nombre', [(int)(user()['id'] ?? 0)]);
}
/** La bóveda (fila completa) si existe y el usuario tiene acceso a ella; si no, null. */
function bov_obtener(int $id): ?array
{
    $b = $id ? one('SELECT * FROM bovedas WHERE id = ?', [$id]) : null;
    if (!$b) return null;
    if (can('boveda', 'administrar')) return $b;
    return val('SELECT COUNT(*) FROM boveda_acceso WHERE boveda_id = ? AND usuario_id = ?', [$id, (int)(user()['id'] ?? 0)]) ? $b : null;
}
function bov_norm_codigo(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); }
/** Guarda la DEK de la bóveda envuelta con la frase maestra. */
function bov_guardar_frase(int $vid, string $dek, string $frase): void
{
    $sal = random_bytes(16);
    update('bovedas', ['sal_frase' => base64_encode($sal), 'dek_frase' => bov_enc($dek, bov_kdf($frase, $sal), 'frase|' . $vid)], $vid);
}
/** Guarda la DEK envuelta con un código de recuperación nuevo y lo devuelve (se muestra una sola vez). */
function bov_guardar_recuperacion(int $vid, string $dek): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $plano = '';   // 32 símbolos x 24 = 120 bits de entropía
    for ($i = 0; $i < 24; $i++) $plano .= $abc[random_int(0, 31)];
    $codigo = implode('-', str_split($plano, 4));
    $sal = random_bytes(16);
    update('bovedas', ['sal_rec' => base64_encode($sal), 'dek_rec' => bov_enc($dek, bov_kdf(bov_norm_codigo($codigo), $sal), 'recuperacion|' . $vid)], $vid);
    return $codigo;
}
/** Crea la bóveda de un cliente: DEK aleatoria, frase maestra y código de recuperación. Devuelve [id, dek, codigo]. */
function bov_crear(?int $empresaId, string $nombre, string $frase): array
{
    $vid = (int)insert('bovedas', ['nombre' => mb_substr($nombre, 0, 150), 'empresa_id' => $empresaId, 'creado_por' => (int)(user()['id'] ?? 0) ?: null, 'creado' => now()]);
    $dek = random_bytes(32);
    bov_guardar_frase($vid, $dek, $frase);
    return [$vid, $dek, bov_guardar_recuperacion($vid, $dek)];
}
function bov_dek_con_frase(array $b, string $frase): ?string
{
    $sal = base64_decode((string)$b['sal_frase'], true);
    return $sal ? bov_dec((string)$b['dek_frase'], bov_kdf($frase, $sal), 'frase|' . $b['id']) : null;
}
function bov_dek_con_codigo(array $b, string $codigo): ?string
{
    $sal = base64_decode((string)$b['sal_rec'], true);
    return $sal ? bov_dec((string)$b['dek_rec'], bov_kdf(bov_norm_codigo($codigo), $sal), 'recuperacion|' . $b['id']) : null;
}

// ---------- Sesión: bóvedas desbloqueadas ----------
function bov_cookie_opts(int $expira = 0): array
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    return ['expires' => $expira, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict'];
}
function bov_cookie_clave(): ?string
{
    $c = $_COOKIE[BOV_COOKIE] ?? '';
    $sk = $c !== '' ? base64_decode(strtr($c, '-_', '+/'), true) : false;
    return $sk && strlen($sk) === 32 ? $sk : null;
}
/** Deja la bóveda $vid desbloqueada en esta sesión (puede haber varias abiertas a la vez). */
function bov_abrir(int $vid, string $dek): void
{
    $sk = !empty($_SESSION['bov']) ? bov_cookie_clave() : null;
    if ($sk === null) {   // primera bóveda abierta en esta sesión: clave nueva
        $sk = random_bytes(32); $_SESSION['bov'] = [];
        $_COOKIE[BOV_COOKIE] = rtrim(strtr(base64_encode($sk), '+/', '-_'), '=');
        setcookie(BOV_COOKIE, $_COOKIE[BOV_COOKIE], bov_cookie_opts());
    }
    $_SESSION['bov'][$vid] = ['t' => time(), 'k' => bov_enc($dek, $sk, 'sesion|' . $vid)];
}
function bov_bloquear(?int $vid = null): void
{
    if ($vid !== null) unset($_SESSION['bov'][$vid]);
    if ($vid === null || empty($_SESSION['bov'])) {
        unset($_SESSION['bov'], $_COOKIE[BOV_COOKIE]);
        setcookie(BOV_COOKIE, '', bov_cookie_opts(time() - 3600));
    }
}
/** La DEK de la bóveda si está desbloqueada (y renueva su plazo de inactividad); null si está bloqueada. */
function bov_dek(int $vid): ?string
{
    $s = $_SESSION['bov'][$vid] ?? null;
    $sk = bov_cookie_clave();
    if (!is_array($s) || $sk === null) return null;
    if (time() - (int)($s['t'] ?? 0) > BOV_INACTIVIDAD) { bov_bloquear($vid); return null; }
    $dek = bov_dec((string)($s['k'] ?? ''), $sk, 'sesion|' . $vid);
    if ($dek === null) { bov_bloquear($vid); return null; }
    $_SESSION['bov'][$vid]['t'] = time();
    return $dek;
}
function bov_segundos_restantes(int $vid): int { return isset($_SESSION['bov'][$vid]['t']) ? max(0, BOV_INACTIVIDAD - (time() - (int)$_SESSION['bov'][$vid]['t'])) : 0; }
/** ¿Está desbloqueada? (sin renovar el plazo; para los listados) */
function bov_abierta(int $vid): bool
{
    return isset($_SESSION['bov'][$vid]['t']) && bov_cookie_clave() !== null && time() - (int)$_SESSION['bov'][$vid]['t'] <= BOV_INACTIVIDAD;
}

// ---------- Datos ----------
function bov_aad(int $id, string $campo): string { return 'boveda|' . $id . '|' . $campo; }
function bov_secreto(string $dek, array $fila, string $campo): ?string
{
    $v = $fila[$campo . '_enc'] ?? null;
    if ($v === null || $v === '') return '';
    return bov_dec((string)$v, $dek, bov_aad((int)$fila['id'], $campo));
}

// ---------- Registro de accesos e intentos ----------
function bov_log(string $accion, ?array $boveda = null, ?int $entradaId = null, string $detalle = ''): void
{
    $u = user();
    insert('boveda_log', ['fecha' => now(), 'usuario_id' => $u['id'] ?? null, 'usuario_nombre' => mb_substr((string)($u['nombre'] ?? ''), 0, 120), 'accion' => $accion,
        'boveda_id' => $boveda['id'] ?? null, 'boveda_nombre' => isset($boveda['nombre']) ? mb_substr((string)$boveda['nombre'], 0, 150) : null,
        'entrada_id' => $entradaId, 'detalle' => mb_substr($detalle, 0, 200), 'ip' => client_ip()]);
}
/** ¿Demasiados intentos fallidos de desbloqueo (por usuario o por IP) en los últimos 15 minutos? */
function bov_bloqueado_por_intentos(): bool
{
    $desde = date('Y-m-d H:i:s', time() - 900);
    return (int)val("SELECT COUNT(*) FROM boveda_log WHERE accion = 'fallo' AND fecha > ? AND (usuario_id = ? OR ip = ?)", [$desde, (int)(user()['id'] ?? 0), client_ip()]) >= 5;
}
function bov_accion_txt(string $a): string
{
    return ['desbloqueo' => 'Desbloqueó la bóveda', 'bloqueo' => 'Bloqueó la bóveda', 'fallo' => 'Intento fallido de desbloqueo', 'ver' => 'Vio un dato', 'copiar' => 'Copió un dato', 'crear' => 'Creó un registro',
        'editar' => 'Editó un registro', 'eliminar' => 'Eliminó un registro', 'configurar' => 'Creó la bóveda', 'cambio_frase' => 'Cambió la frase maestra', 'nuevo_codigo' => 'Generó un código de recuperación nuevo',
        'recuperacion' => 'Recuperó el acceso con el código', 'acceso' => 'Cambió quién tiene acceso', 'eliminar_boveda' => 'Eliminó la bóveda'][$a] ?? $a;
}
