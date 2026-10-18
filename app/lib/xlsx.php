<?php
/**
 * Exporta una o varias hojas a Excel (.xlsx) sin librerías externas.
 * Si el servidor no tiene la extensión ZipArchive, entrega un CSV compatible con Excel.
 *
 * $sheets = [['name' => 'Equipos', 'headers' => [...], 'rows' => [[...], ...], 'widths' => [12, 30, ...]], ...]
 */
function xlsx_download(string $filename, array $sheets): void
{
    while (ob_get_level()) ob_end_clean();

    if (!class_exists('ZipArchive')) {
        $s = $sheets[0];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/\.xlsx$/', '.csv', $filename) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $s['headers'], ';');
        foreach ($s['rows'] as $r) fputcsv($out, array_map(fn($v) => is_float($v) ? number_format($v, 2, ',', '') : $v, $r), ';');
        exit;
    }

    $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - $m, 26); } return $s; };

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $sheetEntries = $wbSheets = $wbRels = '';
    $defined = [];
    foreach (array_values($sheets) as $i => $s) {
        $s['headers'] = array_values($s['headers']);
        $n = $i + 1;
        $ncol = count($s['headers']);
        $last = $col($ncol - 1);
        $nrows = count($s['rows']) + 1;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($s['headers'] as $c => $_) $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . ($s['widths'][$c] ?? 16) . '" customWidth="1"/>';
        $xml .= '</cols><sheetData><row r="1">';
        foreach ($s['headers'] as $c => $h) $xml .= '<c r="' . $col($c) . '1" t="inlineStr" s="1"><is><t>' . $x($h) . '</t></is></c>';
        $xml .= '</row>';
        foreach ($s['rows'] as $ri => $r) {
            $rn = $ri + 2;
            $xml .= '<row r="' . $rn . '">';
            foreach (array_values($r) as $c => $v) {
                $ref = $col($c) . $rn;
                if ($v === null || $v === '') continue;
                if (is_int($v) || is_float($v)) $xml .= '<c r="' . $ref . '" s="' . (is_float($v) ? 3 : 0) . '"><v>' . $v . '</v></c>';
                elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
                    $serial = (int)((strtotime($v . ' UTC') / 86400) + 25569);
                    $xml .= '<c r="' . $ref . '" s="2"><v>' . $serial . '</v></c>';
                } else $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData><autoFilter ref="A1:' . $last . $nrows . '"/>';
        // Listas desplegables: ['col' => índice de columna, 'list' => [...]]
        if (!empty($s['validations'])) {
            $xml .= '<dataValidations count="' . count($s['validations']) . '">';
            foreach ($s['validations'] as $v) {
                $L = $col($v['col']);
                $xml .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorStyle="warning" sqref="' . $L . '2:' . $L . '3000"><formula1>"' . $x(implode(',', $v['list'])) . '"</formula1></dataValidation>';
            }
            $xml .= '</dataValidations>';
        }
        $xml .= '</worksheet>';
        $zip->addFromString("xl/worksheets/sheet$n.xml", $xml);
        $sheetEntries .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $name = $x(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $s['name']), 0, 31));
        $wbSheets .= '<sheet name="' . $name . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $defined[] = '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . $name . '\'!$A$1:$' . $last . '$' . $nrows . '</definedName>';
    }
    $ns = count($sheets) + 1;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $sheetEntries . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets><definedNames>' . implode('', $defined) . '</definedNames></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '<Relationship Id="rId' . $ns . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="#,##0"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF102130"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}
