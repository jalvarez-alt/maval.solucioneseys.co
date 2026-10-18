<?php
/** Descarga protegida de adjuntos: valida que el usuario tenga acceso a la entidad. */
require __DIR__ . '/app/bootstrap.php';
require_login();

$a = one('SELECT * FROM adjuntos WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$a) { http_response_code(404); exit('Archivo no encontrado'); }

$ok = false;
$u = user();
if (is_admin()) {
    $ok = true;
} elseif ($a['entidad'] === 'ticket') {
    $t = one('SELECT solicitante_id, empresa_id FROM tickets WHERE id = ?', [$a['entidad_id']]);
    if ($t) {
        $ok = ($u['rol'] === 'empresa' && $u['empresa_id'] && (int)$t['empresa_id'] === (int)$u['empresa_id'])
            || (int)$t['solicitante_id'] === (int)$u['id'];
    }
}
if (!$ok) { http_response_code(403); exit('Sin acceso'); }

$path = UPLOAD_DIR . '/' . basename($a['archivo']);
if (!is_file($path)) { http_response_code(404); exit('Archivo no disponible'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$inline = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$mime = $inline[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (isset($inline[$ext]) ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $a['nombre_original']) . '"');
header('Cache-Control: private, max-age=0');
readfile($path);
