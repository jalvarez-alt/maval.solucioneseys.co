<?php
/**
 * Lectura de hojas de cálculo sin librerías: .xlsx (Excel 2007+) y .csv.
 * Devuelve una lista de filas; cada fila es una lista de valores (string, int o float).
 */
function leer_tabla(string $path, string $ext, ?string $hojaPreferida = null): array
{
    $ext = strtolower($ext);
    if ($ext === 'csv' || $ext === 'txt') return leer_csv($path);
    if ($ext !== 'xlsx') throw new RuntimeException('Formato no soportado. Guarde el archivo como Excel (.xlsx) o CSV.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no puede leer .xlsx (falta la extensión zip de PHP). Guarde el archivo como CSV.');

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('El archivo no es un Excel válido.');
    // Se quita el espacio de nombres por defecto para leer el XML de forma simple y uniforme.
    $load = function (string $name) use ($zip) {
        $x = $zip->getFromName($name);
        if ($x === false) return null;
        $x = preg_replace('/\sxmlns="[^"]*"/', '', $x, 1);
        return simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE) ?: null;
    };

    // Hojas del libro y sus archivos
    $wb = $load('xl/workbook.xml');
    $rels = $load('xl/_rels/workbook.xml.rels');
    if (!$wb || !$rels) throw new RuntimeException('El archivo no es un Excel válido.');
    $targets = [];
    foreach ($rels->Relationship as $r) $targets[(string)$r['Id']] = (string)$r['Target'];
    $hojas = [];
    foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $t = $targets[$rid] ?? '';
        $t = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        $hojas[(string)$sh['name']] = $t;
    }
    if (!$hojas) throw new RuntimeException('El libro no tiene hojas.');
    $archivo = ($hojaPreferida && isset($hojas[$hojaPreferida])) ? $hojas[$hojaPreferida] : reset($hojas);

    // Textos compartidos
    $shared = [];
    if ($sx = $load('xl/sharedStrings.xml')) {
        foreach ($sx->si as $si) {
            $txt = '';
            if (isset($si->t)) $txt = (string)$si->t;
            if (isset($si->r)) foreach ($si->r as $run) $txt .= (string)$run->t;
            $shared[] = $txt;
        }
    }

    $sx = $load($archivo);
    $zip->close();
    if (!$sx) throw new RuntimeException('No se pudo leer la hoja del archivo.');
    $filas = [];
    foreach ($sx->sheetData->row as $row) {
        $vals = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $idx = $ref !== '' ? col_index(preg_replace('/\d+/', '', $ref)) : count($vals);
            $t = (string)$c['t'];
            if ($t === 's') $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') { $v = (string)($c->is->t ?? ''); if (isset($c->is->r)) foreach ($c->is->r as $run) $v .= (string)$run->t; }
            elseif ($t === 'b') $v = (string)$c->v === '1' ? 'SI' : 'NO';
            elseif ($t === 'str' || $t === 'e') $v = (string)$c->v;
            else {
                $raw = (string)$c->v;
                $v = $raw === '' ? '' : (preg_match('/^-?\d+$/', $raw) ? (int)$raw : (float)$raw);
            }
            $vals[$idx] = $v;
        }
        if ($vals) {
            $max = max(array_keys($vals));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $vals[$i] ?? '';
            $filas[] = $line;
        } else {
            $filas[] = [];
        }
    }
    return $filas;
}

function col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function leer_csv(string $path): array
{
    $raw = (string)file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $primera = strtok($raw, "\n") ?: '';
    $sep = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
    if (substr_count($primera, "\t") > substr_count($primera, $sep)) $sep = "\t";
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $filas = [];
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) $filas[] = array_map(fn($v) => trim((string)$v), $r);
    fclose($fh);
    return $filas;
}
