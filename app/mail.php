<?php
/**
 * Correo saliente: ajustes guardados en la BD, cliente SMTP propio (sin dependencias), plantilla HTML y registro de envíos.
 * Los fallos de envío nunca interrumpen la operación que los origina: se registran y se informan en Configuración.
 */

// ---------- Ajustes ----------
function ajuste(string $clave, $def = null)
{
    global $AJUSTES;
    if (!isset($AJUSTES)) $AJUSTES = array_column(all('SELECT clave, valor FROM ajustes'), 'valor', 'clave');
    return ($AJUSTES[$clave] ?? null) !== null && $AJUSTES[$clave] !== '' ? $AJUSTES[$clave] : $def;
}
function ajuste_set(string $clave, ?string $valor): void
{
    global $AJUSTES;
    q('DELETE FROM ajustes WHERE clave = ?', [$clave]);
    insert('ajustes', ['clave' => $clave, 'valor' => $valor]);
    $AJUSTES = null;
}

/** Cifrado simple de la contraseña SMTP en la BD (la clave se deriva de la configuración de la base de datos). */
function secreto_clave(): string
{
    global $CONFIG;
    $c = $CONFIG['db'] ?? [];
    return hash('sha256', 'maval|' . ($c['name'] ?? '') . '|' . ($c['user'] ?? '') . '|' . ($c['pass'] ?? '') . '|' . ($c['path'] ?? ''), true);
}
function secreto_cifrar(string $t): string
{
    if ($t === '' || !function_exists('openssl_encrypt')) return $t;
    $iv = random_bytes(16);
    return 'enc:' . base64_encode($iv . openssl_encrypt($t, 'aes-256-cbc', secreto_clave(), OPENSSL_RAW_DATA, $iv));
}
function secreto_descifrar(string $t): string
{
    if (!str_starts_with($t, 'enc:')) return $t;
    $raw = base64_decode(substr($t, 4), true);
    if ($raw === false || strlen($raw) < 17 || !function_exists('openssl_decrypt')) return '';
    return (string)openssl_decrypt(substr($raw, 16), 'aes-256-cbc', secreto_clave(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
}

/**
 * Cuenta de correo configurada. $cuenta '' = mesa de ayuda y mantenimiento; 'cot' = cotizaciones (cuenta distinta, mismas claves con prefijo «cot_»).
 */
function correo_config(string $cuenta = ''): array
{
    $p = $cuenta === '' ? '' : $cuenta . '_';
    return [
        'modo' => ajuste($p . 'mail_modo', 'smtp'),            // smtp | mail (función mail() del servidor)
        'host' => (string)ajuste($p . 'smtp_host', ''),
        'puerto' => (int)ajuste($p . 'smtp_puerto', 465),
        'seguridad' => ajuste($p . 'smtp_seguridad', 'ssl'),   // ssl | tls | none
        'usuario' => (string)ajuste($p . 'smtp_usuario', ''),
        'clave' => secreto_descifrar((string)ajuste($p . 'smtp_clave', '')),
        'desde' => (string)ajuste($p . 'mail_desde', ''),
        'desde_nombre' => (string)ajuste($p . 'mail_desde_nombre', ''),
        'responder' => (string)ajuste($p . 'mail_responder', ''),
        'sin_verificar' => (bool)ajuste($p . 'smtp_sin_verificar', 0),
    ];
}
/** ¿Está habilitado el envío y hay una cuenta configurada? */
function correo_activo(string $cuenta = ''): bool
{
    if (!ajuste(($cuenta === '' ? '' : $cuenta . '_') . 'mail_activo', 0)) return false;
    $c = correo_config($cuenta);
    return filter_var($c['desde'], FILTER_VALIDATE_EMAIL) && ($c['modo'] === 'mail' || $c['host'] !== '');
}
function app_url(): string
{
    $u = (string)ajuste('app_url', '');
    if ($u === '' && !empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $u = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $dir;
    }
    return rtrim($u, '/');
}
function app_link(string $page, array $params = []): string { return app_url() . '/' . url($page, $params); }

// ---------- Envío ----------
function limpiar_cabecera(string $s): string { return trim(preg_replace('/[\r\n]+/', ' ', $s)); }
function cabecera_utf8(string $s): string { $s = limpiar_cabecera($s); return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }

/**
 * Envía un correo HTML. $para: dirección o lista. Opciones: motivo (para el registro), responder (Reply-To).
 * Devuelve ['ok' => bool, 'error' => ?string, 'enviados' => int]. No lanza excepciones.
 */
function enviar_correo($para, string $asunto, string $html, array $o = []): array
{
    $dest = [];
    foreach ((array)$para as $p) {
        foreach (preg_split('/[,;\s]+/', (string)$p) as $d) {
            $d = mb_strtolower(trim($d));
            if ($d !== '' && filter_var($d, FILTER_VALIDATE_EMAIL)) $dest[$d] = $d;
        }
    }
    $dest = array_values($dest);
    $asunto = mb_substr(limpiar_cabecera($asunto), 0, 200);
    $motivo = $o['motivo'] ?? null;
    if (!$dest) return ['ok' => false, 'error' => 'Sin destinatarios válidos.', 'enviados' => 0];
    $cuenta = (string)($o['cuenta'] ?? '');
    $cfg = correo_config($cuenta);
    if (empty($o['forzar']) && !correo_activo($cuenta)) return ['ok' => false, 'error' => 'El envío de correo está desactivado o sin configurar.', 'enviados' => 0];
    if (!filter_var($cfg['desde'], FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Falta el correo remitente.', 'enviados' => 0];

    $texto = preg_replace('/<\/t[dh]>/i', ' · ', $html);
    $texto = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/tr|\/h\d|\/li|\/div)[^>]*>/i', "\n", $texto)), ENT_QUOTES, 'UTF-8'));
    $texto = preg_replace('/ · \n/', "\n", $texto);
    $texto = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $texto));
    $error = null;
    try {
        $ok = $cfg['modo'] === 'mail' ? correo_mail_nativo($cfg, $dest, $asunto, $html, $texto, $o, $error) : correo_smtp($cfg, $dest, $asunto, $html, $texto, $o, $error);
    } catch (Throwable $e) {
        $ok = false; $error = $e->getMessage();
    }
    try {
        insert('correo_log', ['fecha' => now(), 'destinatarios' => mb_substr(implode(', ', $dest), 0, 500), 'asunto' => $asunto, 'ok' => $ok ? 1 : 0, 'error' => $ok ? null : mb_substr((string)$error, 0, 500), 'motivo' => $motivo]);
        q('DELETE FROM correo_log WHERE fecha < ?', [date('Y-m-d H:i:s', strtotime('-90 days'))]);
    } catch (Throwable $e) { /* el registro es secundario */ }
    if (!$ok) error_log('MAVAL correo: ' . $error . ' (' . $asunto . ')');
    return ['ok' => $ok, 'error' => $ok ? null : $error, 'enviados' => $ok ? count($dest) : 0];
}

function correo_mensaje(array $cfg, array $dest, string $asunto, string $html, string $texto, array $o): array
{
    $b = 'mv' . bin2hex(random_bytes(12));
    $adj = $o['adjuntos'] ?? [];
    $bm = 'mx' . bin2hex(random_bytes(12));
    $dominio = substr(strrchr($cfg['desde'], '@'), 1);
    $de = ($cfg['desde_nombre'] !== '' ? cabecera_utf8($cfg['desde_nombre']) . ' ' : '') . '<' . $cfg['desde'] . '>';
    $resp = $o['responder'] ?? ($cfg['responder'] !== '' ? $cfg['responder'] : null);
    $h = [
        'Date: ' . date('r'),
        'From: ' . $de,
        'Reply-To: ' . ($resp && filter_var($resp, FILTER_VALIDATE_EMAIL) ? $resp : $cfg['desde']),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $dominio . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
        'X-Mailer: MAVAL ' . APP_VERSION,
        'Content-Type: ' . ($adj ? 'multipart/mixed; boundary="' . $bm . '"' : 'multipart/alternative; boundary="' . $b . '"'),
    ];
    $cuerpo = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto)) . "\r\n"
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$b--\r\n";
    if ($adj) {
        // Con adjuntos: multipart/mixed { alternativa texto/HTML, archivos }
        $mixto = "--$bm\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $cuerpo;
        foreach ($adj as $a) {
            $nom = cabecera_utf8(str_replace(['"', '\\'], '', $a['nombre']));
            $mixto .= "--$bm\r\nContent-Type: " . ($a['tipo'] ?? 'application/octet-stream') . "; name=\"$nom\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nom\"\r\n\r\n"
                . chunk_split(base64_encode($a['datos'])) . "\r\n";
        }
        $cuerpo = $mixto . "--$bm--\r\n";
    }
    return [$h, $cuerpo];
}

function correo_mail_nativo(array $cfg, array $dest, string $asunto, string $html, string $texto, array $o, ?string &$error): bool
{
    [$h, $cuerpo] = correo_mensaje($cfg, $dest, $asunto, $html, $texto, $o);
    $ok = @mail(implode(', ', $dest), cabecera_utf8($asunto), $cuerpo, implode("\r\n", $h), '-f' . $cfg['desde']);
    if (!$ok) $error = 'La función mail() del servidor rechazó el mensaje.';
    return $ok;
}

function smtp_leer($fp): array
{
    $resp = ''; $code = 0;
    while (($line = fgets($fp, 1024)) !== false) {
        $resp .= $line;
        $code = (int)substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return [$code, trim($resp)];
}
function smtp_cmd($fp, string $cmd, array $esperado, string $oculto = ''): string
{
    fwrite($fp, $cmd . "\r\n");
    [$code, $resp] = smtp_leer($fp);
    if (!in_array($code, $esperado, true)) throw new RuntimeException('SMTP «' . ($oculto ?: strtok($cmd, ' ')) . '»: ' . ($resp ?: 'sin respuesta del servidor'));
    return $resp;
}

function correo_smtp(array $cfg, array $dest, string $asunto, string $html, string $texto, array $o, ?string &$error): bool
{
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => !$cfg['sin_verificar'], 'verify_peer_name' => !$cfg['sin_verificar'], 'allow_self_signed' => $cfg['sin_verificar'],
    ]]);
    $dir = ($cfg['seguridad'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['puerto'];
    $fp = @stream_socket_client($dir, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $error = "No se pudo conectar a {$cfg['host']}:{$cfg['puerto']} ($errstr)"; return false; }
    stream_set_timeout($fp, 12);
    try {
        [$code, $resp] = smtp_leer($fp);
        if ($code !== 220) throw new RuntimeException('El servidor SMTP no respondió: ' . $resp);
        $yo = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'localhost';
        smtp_cmd($fp, "EHLO $yo", [250]);
        if ($cfg['seguridad'] === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('No se pudo establecer la conexión segura (STARTTLS).');
            smtp_cmd($fp, "EHLO $yo", [250]);
        }
        if ($cfg['usuario'] !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode($cfg['usuario']), [334], 'usuario');
            smtp_cmd($fp, base64_encode($cfg['clave']), [235], 'AUTH (usuario o contraseña incorrectos)');
        }
        smtp_cmd($fp, 'MAIL FROM:<' . $cfg['desde'] . '>', [250]);
        foreach ($dest as $d) smtp_cmd($fp, "RCPT TO:<$d>", [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);
        [$h, $cuerpo] = correo_mensaje($cfg, $dest, $asunto, $html, $texto, $o);
        $h[] = 'To: ' . implode(', ', $dest);
        $h[] = 'Subject: ' . cabecera_utf8($asunto);
        $data = implode("\r\n", $h) . "\r\n\r\n" . $cuerpo;
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
        fwrite($fp, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
        [$code, $resp] = smtp_leer($fp);
        if ($code !== 250) throw new RuntimeException('El servidor rechazó el mensaje: ' . $resp);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return false;
    }
}

// ---------- Envío diferido (después de responder al navegador) ----------
/** Ejecuta $fn al terminar la petición, cuando el usuario ya recibió la página, para que el envío no la retrase. */
function despues(callable $fn): void
{
    global $DIFERIDOS;
    if (!isset($DIFERIDOS)) {
        $DIFERIDOS = [];
        register_shutdown_function(function () {
            global $DIFERIDOS;
            ignore_user_abort(true);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // no bloquear otras peticiones del usuario
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            @set_time_limit(120);
            foreach ($DIFERIDOS as $f) {
                try { $f(); } catch (Throwable $e) { error_log('MAVAL diferido: ' . $e->getMessage()); }
            }
        });
    }
    $DIFERIDOS[] = $fn;
}

// ---------- Plantilla ----------
function correo_plantilla(string $titulo, string $contenido, ?string $url = null, ?string $boton = null): string
{
    global $CONFIG;
    $app = e($CONFIG['app_name'] ?? 'MAVAL');
    $btn = $url ? '<p style="margin:22px 0 4px"><a href="' . e($url) . '" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:600;display:inline-block">' . e($boton ?: 'Abrir en ' . ($CONFIG['app_name'] ?? 'MAVAL')) . '</a></p>'
        . '<p style="margin:6px 0 0;font-size:12px;color:#64748b">Enlace: ' . e($url) . '</p>' : '';
    return '<!doctype html><html lang="es"><body style="margin:0;padding:0;background:#f3f5f7;font-family:Segoe UI,Arial,sans-serif;color:#1f2933">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f7;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="background:#0f766e;color:#ffffff;padding:16px 24px;font-size:18px;font-weight:700">' . $app . '</td></tr>'
        . '<tr><td style="padding:24px"><h2 style="margin:0 0 14px;font-size:19px;color:#0f172a">' . e($titulo) . '</h2>'
        . '<div style="font-size:14px;line-height:1.55">' . $contenido . '</div>' . $btn . '</td></tr>'
        . '<tr><td style="padding:14px 24px;background:#f8fafc;color:#64748b;font-size:12px">Mensaje automático de ' . $app . '. Por favor no responda a este correo si no es necesario.</td></tr>'
        . '</table></td></tr></table></body></html>';
}
/** Tabla de datos clave/valor para los correos. */
function correo_ficha(array $filas): string
{
    $h = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0">';
    foreach ($filas as $k => $v) {
        if ($v === null || $v === '') continue;
        $h .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #eef2f6;color:#64748b;width:34%;font-size:13px">' . e($k) . '</td><td style="padding:6px 10px;border-bottom:1px solid #eef2f6;font-size:13px">' . $v . '</td></tr>';
    }
    return $h . '</table>';
}
