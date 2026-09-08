<?php
declare(strict_types=1);

namespace Glue\Crm;

use RuntimeException;
use ZipArchive;

/**
 * Streams the gestionale's .xlsx exports without a spreadsheet library.
 *
 * An .xlsx is a zip of XML; for one known sheet that's all there is to it.
 * Extracted from CustomerImport when the ARTICO (articles) export arrived —
 * both importers read their file through here.
 */
final class Xlsx
{
    /**
     * Stream the first worksheet, one row at a time, header row first.
     *
     * XMLReader, not simplexml_load_string on the whole sheet: the newer export
     * layout is ~15 MB of XML, and a full DOM of it OOM-killed the import on
     * the production box (848 MB total RAM). Only one <row> fragment is ever
     * materialised at a time, so memory stays flat however large the file gets.
     *
     * @return \Generator<array<int,string>>
     */
    public static function rows(string $path): \Generator
    {
        // ZipArchive just to validate + confirm the parts exist; reading itself
        // goes through the zip:// stream wrapper so nothing is inflated whole.
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open the xlsx (is it a real Excel file?).');
        }
        $hasShared = $zip->locateName('xl/sharedStrings.xml') !== false;
        $hasSheet  = $zip->locateName('xl/worksheets/sheet1.xml') !== false;
        $zip->close();
        if (!$hasSheet) {
            throw new RuntimeException('No worksheet found in the file.');
        }

        $shared = [];
        if ($hasShared) {
            $r = new \XMLReader();
            if ($r->open('zip://' . $path . '#xl/sharedStrings.xml')) {
                $ok = $r->read();
                while ($ok) {
                    if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                        $si = simplexml_load_string($r->readOuterXml());
                        // A cell string is either one <t> or a run of <r><t> pieces.
                        $txt = '';
                        if (isset($si->t)) {
                            $txt = (string)$si->t;
                        } else {
                            foreach ($si->r as $run) {
                                $txt .= (string)$run->t;
                            }
                        }
                        $shared[] = $txt;
                        $ok = $r->next();
                        continue;
                    }
                    $ok = $r->read();
                }
                $r->close();
            }
        }

        $r = new \XMLReader();
        if (!$r->open('zip://' . $path . '#xl/worksheets/sheet1.xml')) {
            throw new RuntimeException('No worksheet found in the file.');
        }
        $ok = $r->read();
        while ($ok) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'row') {
                $row = simplexml_load_string($r->readOuterXml());
                $out = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    preg_match('/^([A-Z]+)/', $ref, $m);
                    $col = 0;
                    foreach (str_split($m[1]) as $ch) {
                        $col = $col * 26 + (ord($ch) - 64);
                    }
                    $col--;
                    $t = (string)$c['t'];
                    if ($t === 'inlineStr') {
                        $val = (string)($c->is->t ?? '');
                    } elseif ($t === 's') {
                        $val = $shared[(int)$c->v] ?? '';
                    } else {
                        $val = (string)$c->v;
                    }
                    $out[$col] = $val;
                }
                if ($out) {
                    yield $out;
                }
                $ok = $r->next();
                continue;
            }
            $ok = $r->read();
        }
        $r->close();
    }

    /** Excel serial ("42204") or textual date -> Y-m-d, else null. */
    public static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4,6}$/', $v)) { // serial days since 1899-12-30
            return gmdate('Y-m-d', (int)(((int)$v - 25569) * 86400));
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $v, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }
        return null;
    }
}
