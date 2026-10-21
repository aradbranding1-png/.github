<?php

declare(strict_types=1);

namespace App\Core\Export;

use ZipArchive;

/**
 * Minimal streaming XLSX writer (no dependencies): one sheet, inline strings, right-to-left view.
 * Rows are streamed to a temp XML file, then zipped — memory stays flat for large exports.
 */
final class XlsxWriter
{
    private $sheet;
    private string $sheetPath;
    private int $row = 0;

    public function __construct(private string $target, private bool $rtl = true)
    {
        $this->sheetPath = $target . '.sheet.xml';
        $this->sheet = fopen($this->sheetPath, 'wb');
        fwrite($this->sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($this->rtl ? ' rightToLeft="1"' : '') . '/></sheetViews><sheetData>');
    }

    /** @param list<scalar|null> $cells */
    public function addRow(array $cells): void
    {
        $this->row++;
        $xml = '<row r="' . $this->row . '">';
        foreach (array_values($cells) as $i => $v) {
            $ref = self::col($i) . $this->row;
            if (is_int($v) || is_float($v)) {
                $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } elseif ($v !== null && $v !== '') {
                $text = htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
        }
        fwrite($this->sheet, $xml . '</row>');
    }

    public function close(): void
    {
        fwrite($this->sheet, '</sheetData></worksheet>');
        fclose($this->sheet);
        $zip = new ZipArchive();
        $zip->open($this->target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($this->sheetPath);
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }
}
