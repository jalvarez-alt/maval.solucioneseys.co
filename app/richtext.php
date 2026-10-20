<?php
/**
 * Texto con formato (negrillas, tamaño, tipo de letra, viñetas y numeración) para los campos largos de las cotizaciones.
 * El editor del navegador (contenteditable) envía HTML; aquí se reduce a un subconjunto seguro (cot_rich_normalizar),
 * se muestra en pantalla (cot_rich_html) y se dibuja en el PDF (cot_rich_pdf).
 */

const RICH_TAM = 10.5;
const RICH_FUENTES = ['helvetica' => 'Arial,Helvetica,sans-serif', 'times' => 'Times New Roman,Times,serif', 'courier' => 'Courier New,Courier,monospace'];
const RICH_FONT_SIZE = [1 => 8.0, 2 => 9.0, 3 => 10.5, 4 => 12.0, 5 => 14.0, 6 => 16.0, 7 => 20.0];   // escala de <font size>

function rich_es_html(string $s): bool { return (bool)preg_match('/<\/?(p|div|br|ul|ol|li|b|strong|span|font|i|em|u|h\d)\b/i', $s); }

function rich_familia(string $css): string
{
    $css = mb_strtolower($css);
    if (str_contains($css, 'times') || str_contains($css, 'serif') && !str_contains($css, 'sans')) return 'times';
    if (str_contains($css, 'courier') || str_contains($css, 'mono')) return 'courier';
    return 'helvetica';
}

/** Recorre el DOM y llena $S['bloques'] con bloques [tipo p|ul|ol, n, runs[ [t, b, s, f] ]]. */
function rich_recorrer(DOMNode $nodo, array $st, array &$S, ?string $lista): void
{
    foreach ($nodo->childNodes as $h) {
        if ($h instanceof DOMText) {
            $t = preg_replace('/\s+/u', ' ', $h->nodeValue);
            if ($t === '' || ($t === ' ' && ($S['cur'] === null || !$S['cur']['runs']))) continue;
            if ($S['cur'] === null) $S['cur'] = ['tipo' => 'p', 'n' => 0, 'runs' => [], 'blank' => false];
            $S['cur']['runs'][] = ['t' => $t, 'b' => $st['b'], 's' => $st['s'], 'f' => $st['f']];
            continue;
        }
        if (!($h instanceof DOMElement)) continue;
        $e = strtolower($h->nodeName);
        $st2 = $st;
        switch ($e) {
            case 'script': case 'style': case 'head':
                continue 2;
            case 'br':
                if ($S['cur'] !== null && $S['cur']['runs']) { rich_cerrar($S); $S['cur'] = ['tipo' => 'p', 'n' => 0, 'runs' => [], 'blank' => false]; }
                else { if ($S['cur'] === null) $S['cur'] = ['tipo' => 'p', 'n' => 0, 'runs' => [], 'blank' => false]; $S['cur']['blank'] = true; }
                continue 2;
            case 'b': case 'strong': $st2['b'] = true; break;
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6': $st2['b'] = true; $st2['s'] = max($st['s'], 12.0); break;
            case 'font':
                if ($h->hasAttribute('face')) $st2['f'] = rich_familia($h->getAttribute('face'));
                if ($h->hasAttribute('size') && isset(RICH_FONT_SIZE[(int)$h->getAttribute('size')])) $st2['s'] = RICH_FONT_SIZE[(int)$h->getAttribute('size')];
                break;
            case 'span':
                $css = $h->getAttribute('style');
                if (preg_match('/font-size\s*:\s*([\d.]+)\s*(pt|px)/i', $css, $m)) $st2['s'] = max(7.0, min(30.0, (float)$m[1] * (strtolower($m[2]) === 'px' ? 0.75 : 1)));
                if (preg_match('/font-family\s*:\s*([^;]+)/i', $css, $m)) $st2['f'] = rich_familia($m[1]);
                if (preg_match('/font-weight\s*:\s*(bold|[6-9]00)/i', $css)) $st2['b'] = true;
                break;
        }
        if (in_array($e, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            rich_cerrar($S);
            $S['cur'] = ['tipo' => 'p', 'n' => 0, 'runs' => [], 'blank' => false];
            rich_recorrer($h, $st2, $S, null);
            rich_cerrar($S);
        } elseif ($e === 'ul' || $e === 'ol') {
            rich_cerrar($S);
            $S['cont'][$e] = 0;
            rich_recorrer($h, $st2, $S, $e);
            rich_cerrar($S);
        } elseif ($e === 'li') {
            rich_cerrar($S);
            $tipo = $lista ?? 'ul';
            $S['cont'][$tipo] = ($S['cont'][$tipo] ?? 0) + 1;
            $S['cur_n'] = $S['cont'][$tipo];
            $S['cur'] = ['tipo' => $tipo, 'n' => $S['cont'][$tipo], 'runs' => [], 'blank' => false];
            rich_recorrer($h, $st2, $S, $lista);
            rich_cerrar($S);
        } else {
            rich_recorrer($h, $st2, $S, $lista);
        }
    }
}
function rich_cerrar(array &$S): void
{
    if ($S['cur'] === null) return;
    $c = $S['cur'];
    $S['cur'] = null;
    if ($c['runs']) {
        $c['runs'][0]['t'] = ltrim($c['runs'][0]['t']);
        $u = count($c['runs']) - 1;
        $c['runs'][$u]['t'] = rtrim($c['runs'][$u]['t']);
        $c['runs'] = array_values(array_filter($c['runs'], fn($r) => $r['t'] !== ''));
    }
    if ($c['runs'] || $c['blank']) $S['bloques'][] = $c;
}

/** Bloques del texto (acepta HTML del editor o texto plano antiguo). */
function cot_rich_bloques(?string $texto): array
{
    $texto = (string)$texto;
    if (trim($texto) === '') return [];
    $base = ['b' => false, 's' => RICH_TAM, 'f' => 'helvetica'];
    if (!rich_es_html($texto) || !class_exists('DOMDocument')) {
        $plano = rich_es_html($texto) ? html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8') : $texto;
        $bl = [];
        foreach (preg_split('/\R/u', $plano) as $ln) {
            $ln = trim($ln);
            $bl[] = ['tipo' => 'p', 'n' => 0, 'runs' => $ln === '' ? [] : [['t' => $ln, 'b' => false, 's' => RICH_TAM, 'f' => 'helvetica']], 'blank' => $ln === ''];
        }
        return $bl;
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $texto . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $body = $dom->getElementsByTagName('body')->item(0);
    $S = ['bloques' => [], 'cur' => null, 'cont' => []];
    if ($body) { rich_recorrer($body, $base, $S, null); rich_cerrar($S); }
    return $S['bloques'];
}

/** HTML limpio para guardar y para volver a abrir en el editor (solo etiquetas permitidas). */
function cot_rich_normalizar(?string $texto): string
{
    $out = ''; $lista = null;
    $cerrarLista = function () use (&$out, &$lista) { if ($lista) { $out .= '</' . $lista . '>'; $lista = null; } };
    foreach (cot_rich_bloques($texto) as $b) {
        // une tramos contiguos con el mismo estilo y arma el HTML
        $runs = [];
        foreach ($b['runs'] as $r) {
            $u = count($runs) - 1;
            if ($u >= 0 && $runs[$u]['b'] === $r['b'] && $runs[$u]['s'] === $r['s'] && $runs[$u]['f'] === $r['f']) $runs[$u]['t'] .= $r['t'];
            else $runs[] = $r;
        }
        $html = '';
        foreach ($runs as $r) {
            $x = e($r['t']);
            if ($r['b']) $x = "<b>$x</b>";
            if ($r['s'] != RICH_TAM || $r['f'] !== 'helvetica') $x = '<span style="font-size:' . rtrim(rtrim(number_format($r['s'], 1, '.', ''), '0'), '.') . 'pt;font-family:' . RICH_FUENTES[$r['f']] . '">' . $x . '</span>';
            $html .= $x;
        }
        if ($b['tipo'] === 'p') { $cerrarLista(); $out .= '<p>' . ($html === '' ? '<br>' : $html) . '</p>'; continue; }
        if ($lista !== $b['tipo']) { $cerrarLista(); $lista = $b['tipo']; $out .= '<' . $lista . '>'; }
        $out .= '<li>' . $html . '</li>';
    }
    $cerrarLista();
    return $out;
}

/** HTML listo para mostrar en pantalla (el texto antiguo en plano también se admite). */
function cot_rich_html(?string $texto): string
{
    return cot_rich_normalizar($texto);
}

/**
 * Dibuja el texto con formato en el PDF a partir de $y (línea base). Justifica los párrafos y las viñetas.
 * $asegurar(altura) debe pasar de página cuando no quepa y actualizar $y.
 */
function cot_rich_pdf(SimplePdf $pdf, ?string $texto, float &$y, float $x, float $w, callable $asegurar, string $color = '#17212b'): void
{
    foreach (cot_rich_bloques($texto) as $b) {
        $lista = $b['tipo'] !== 'p';
        $sangria = $lista ? 18.0 : 0.0;
        $ancho = $w - $sangria;
        if (!$b['runs']) { $asegurar(15); $y += 8; continue; }
        // palabras con su estilo
        $pal = [];
        foreach ($b['runs'] as $r) {
            foreach (preg_split('/ /u', $r['t']) as $i => $p) {
                if ($p === '') { if ($i > 0 && $pal) $pal[count($pal) - 1]['esp'] = true; continue; }
                if ($i > 0 && $pal) $pal[count($pal) - 1]['esp'] = true;
                $pal[] = ['w' => $p, 'b' => $r['b'], 's' => $r['s'], 'f' => $r['f'], 'esp' => false];
            }
            if (str_ends_with($r['t'], ' ') && $pal) $pal[count($pal) - 1]['esp'] = true;
        }
        // líneas
        $lineas = []; $cur = []; $ancho_cur = 0.0;
        foreach ($pal as $p) {
            $aw = $pdf->widthF($p['w'], $p['s'], $p['f'], $p['b']);
            $sp = $cur ? $pdf->widthF(' ', $cur[count($cur) - 1]['s'], $cur[count($cur) - 1]['f'], $cur[count($cur) - 1]['b']) : 0.0;
            $pegado = $cur && !$cur[count($cur) - 1]['esp'];   // sin espacio entre dos estilos de una misma palabra
            if ($cur && $ancho_cur + ($pegado ? 0 : $sp) + $aw > $ancho) { $lineas[] = $cur; $cur = []; $ancho_cur = 0.0; $sp = 0.0; $pegado = true; }
            $p['aw'] = $aw;
            $cur[] = $p;
            $ancho_cur += ($cur && count($cur) > 1 && !$pegado ? $sp : 0) + $aw;
        }
        if ($cur) $lineas[] = $cur;
        foreach ($lineas as $li => $ln) {
            $tam = max(array_column($ln, 's'));
            $lh = $tam * 1.4;
            $asegurar($lh + 1);
            if ($lista && $li === 0) {
                $marca = $b['tipo'] === 'ol' ? $b['n'] . '.' : "\u{2022}";
                $pdf->textF($x + 3, $y, $marca, $ln[0]['s'], 'helvetica', true, $color);
            }
            // anchos y espacios
            $total = 0.0; $huecos = 0;
            foreach ($ln as $k => $p) { $total += $p['aw']; if ($k < count($ln) - 1 && $p['esp']) { $total += $pdf->widthF(' ', $p['s'], $p['f'], $p['b']); $huecos++; } }
            $extra = ($li < count($lineas) - 1 && $huecos > 0 && $total >= $ancho * 0.6 && $total < $ancho) ? ($ancho - $total) / $huecos : 0.0;
            $cx = $x + $sangria;
            foreach ($ln as $k => $p) {
                $pdf->textF($cx, $y, $p['w'], $p['s'], $p['f'], $p['b'], $color);
                $cx += $p['aw'];
                if ($k < count($ln) - 1 && $p['esp']) $cx += $pdf->widthF(' ', $p['s'], $p['f'], $p['b']) + $extra;
            }
            $y += $lh;
        }
        $y += $lista ? 2 : 3;
    }
}
