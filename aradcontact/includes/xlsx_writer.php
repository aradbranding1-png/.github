<?php
/**
 * نوشتنِ فایلِ اکسلِ واقعی (xlsx) بدونِ کتابخانه‌ی بیرونی — فقط با ZipArchive.
 * اگر ZipArchive روی هاست نباشد، خروجیِ CSV (UTF-8 با BOM) داده می‌شود که اکسل هم بازش می‌کند.
 *
 * $sheets: [
 *   ['name' => 'نام شیت', 'header' => [...], 'rows' => [[...], ...], 'footer' => [[...]] (ردیف‌های پررنگ/جمع),
 *    'widths' => [عرضِ ستون‌ها], 'text_cols' => [اندیسِ ستون‌هایی که باید «متن» باشند (موبایل، شماره پیگیری، تاریخ شمسی)]],
 * ]
 * مقدارهای int/float عدد (با جداکننده‌ی هزارگان) و بقیه متن نوشته می‌شوند.
 */

if (!function_exists('xlsx_output')) {
    function xlsx_output(string $fileBase, array $sheets): void
    {
        $fileBase = preg_replace('/[^0-9A-Za-z_\-]/', '_', $fileBase) ?: 'export';
        if (!class_exists('ZipArchive')) {
            xlsx_output_csv($fileBase, $sheets[0] ?? []);
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            xlsx_output_csv($fileBase, $sheets[0] ?? []);
            return;
        }
        $sheets = array_values($sheets);
        $n = count($sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        // راست‌به‌چپ روی هر sheetView است؛ workbookView این ویژگی را ندارد (وگرنه اکسل پیغامِ «تعمیرِ فایل» می‌دهد)
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $used = [];
        foreach ($sheets as $i => $s) {
            $name = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', (string) ($s['name'] ?? ('Sheet' . ($i + 1)))), 0, 31);
            while (isset($used[$name])) {
                $name = mb_substr($name, 0, 28) . ' ' . ($i + 1);
            }
            $used[$name] = true;
            $wb .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsx_sheet_xml($s));
        }
        $wb .= '</sheets></workbook>';
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        // سبک‌ها: 0 عادی، 1 سرستون (پررنگ + زمینه)، 2 عدد با هزارگان، 3 عددِ پررنگ، 4 متنِ پررنگ، 5 متن (@)
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFDE68A"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD6D3D1"/></left><right style="thin"><color rgb="FFD6D3D1"/></right><top style="thin"><color rgb="FFD6D3D1"/></top><bottom style="thin"><color rgb="FFD6D3D1"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="3" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $fileBase . '.xlsx"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: private, no-store');
        }
        readfile($tmp);
        @unlink($tmp);
    }

    function xlsx_esc(string $s): string
    {
        // کاراکترهای کنترلیِ نامعتبر در XML حذف می‌شوند
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    function xlsx_col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }

    function xlsx_sheet_xml(array $s): string
    {
        $textCols = array_flip(array_map('intval', (array) ($s['text_cols'] ?? [])));
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>';
        $header = array_values((array) ($s['header'] ?? []));
        $widths = array_values((array) ($s['widths'] ?? []));
        $nCols = max(count($header), count($widths));
        if ($nCols > 0) {
            $x .= '<cols>';
            for ($c = 0; $c < $nCols; $c++) {
                $w = (float) ($widths[$c] ?? 16);
                $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"' . (isset($textCols[$c]) ? ' style="5"' : '') . '/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $r = 0;
        $emit = static function (array $row, string $kind) use (&$r, &$x, $textCols): void {
            $r++;
            $x .= '<row r="' . $r . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = xlsx_col($c) . $r;
                if ($v === null || $v === '') {
                    if (isset($textCols[$c]) && $kind === 'row') {
                        $x .= '<c r="' . $ref . '" s="5"/>';
                    }
                    continue;
                }
                if ((is_int($v) || is_float($v)) && $kind !== 'header') {
                    $x .= '<c r="' . $ref . '" s="' . ($kind === 'footer' ? 3 : 2) . '"><v>' . (is_float($v) ? rtrim(rtrim(sprintf('%.4F', $v), '0'), '.') : $v) . '</v></c>';
                } else {
                    $style = $kind === 'header' ? 1 : ($kind === 'footer' ? 4 : (isset($textCols[$c]) ? 5 : 0));
                    $x .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string) $v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        };
        if ($header) $emit($header, 'header');
        foreach ((array) ($s['rows'] ?? []) as $row) $emit((array) $row, 'row');
        foreach ((array) ($s['footer'] ?? []) as $row) $emit((array) $row, 'footer');
        $x .= '</sheetData>';
        if ($header) {
            $x .= '<autoFilter ref="A1:' . xlsx_col(count($header) - 1) . max(1, $r) . '"/>';
        }
        return $x . '</worksheet>';
    }

    function xlsx_output_csv(string $fileBase, array $s): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
        }
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach (array_merge([(array) ($s['header'] ?? [])], (array) ($s['rows'] ?? []), (array) ($s['footer'] ?? [])) as $row) {
            if ($row) fputcsv($out, array_values($row));
        }
        fclose($out);
    }
}
