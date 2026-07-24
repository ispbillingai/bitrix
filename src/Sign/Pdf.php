<?php
declare(strict_types=1);

namespace Glue\Sign;

/**
 * A small PDF writer, purpose-built for the signature certificate.
 *
 * It does not try to be a PDF library. It writes exactly what this feature
 * needs — Helvetica/Courier text, rules and boxes, one embedded file, and one
 * signature field with a /ByteRange gap — and nothing else. That is deliberate:
 * signing a PDF means controlling its bytes, and the reliable way to control
 * them is to have written them.
 *
 * Layout is PostScript-style: the origin is bottom-left, y grows upwards. The
 * helpers here take a "cursor" y that moves *down* the page, because that is how
 * the certificate reads, and convert at the last moment.
 *
 * The signature is prepared, not applied: render() leaves a fixed-width
 * /ByteRange placeholder and a zero-filled /Contents gap. Signer fills both.
 */
final class Pdf
{
    public const A4_W = 595.28;
    public const A4_H = 841.89;

    /** Bytes reserved for the CMS blob. RSA-3072 + chain + TSA token fits easily. */
    public const SIG_BYTES = 20000;

    public const FONT_REGULAR = 'F1';
    public const FONT_BOLD    = 'F2';
    public const FONT_MONO    = 'F3';

    /** @var array<int,string> object id => body */
    private array $obj = [];
    /** @var array<int,string> page index => content stream operators */
    private array $ops = [];
    /** @var array{name:string, bytes:string, mime:string, desc:string}|null */
    private ?array $attachment = null;
    private ?array $signature = null;
    private array $info = [];
    private int $page = -1;

    public function __construct(string $title = '', string $author = '')
    {
        $this->info = ['Title' => $title, 'Author' => $author];
    }

    // ---- page + drawing -----------------------------------------------------------

    public function addPage(): void
    {
        $this->page++;
        $this->ops[$this->page] = '';
    }

    public function pageCount(): int
    {
        return $this->page + 1;
    }

    /** Draw text at (x, yDown) where yDown is measured from the top of the page. */
    public function text(float $x, float $yDown, string $s, string $font = self::FONT_REGULAR,
                         float $size = 10, array $rgb = [0, 0, 0]): void
    {
        if ($s === '') {
            return;
        }
        $this->ops[$this->page] .= sprintf(
            "BT %s %s %s rg /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n",
            $this->n($rgb[0]), $this->n($rgb[1]), $this->n($rgb[2]),
            $font, $this->n($size), $this->n($x), $this->n(self::A4_H - $yDown),
            self::escape(self::winAnsi($s))
        );
    }

    /** Right-align text so its last glyph ends at $xRight. */
    public function textRight(float $xRight, float $yDown, string $s, string $font = self::FONT_REGULAR,
                              float $size = 10, array $rgb = [0, 0, 0]): void
    {
        $this->text($xRight - self::widthOf($s, $font, $size), $yDown, $s, $font, $size, $rgb);
    }

    /**
     * Word-wrap $s into $width points and draw it. Returns the y just below the
     * last line, so callers can keep stacking blocks without counting lines.
     */
    public function paragraph(float $x, float $yDown, float $width, string $s,
                              string $font = self::FONT_REGULAR, float $size = 10,
                              float $leading = 0, array $rgb = [0, 0, 0]): float
    {
        $leading = $leading ?: $size * 1.35;
        foreach (self::wrap($s, $width, $font, $size) as $line) {
            $this->text($x, $yDown, $line, $font, $size, $rgb);
            $yDown += $leading;
        }
        return $yDown;
    }

    /**
     * Wrap long unbroken runs too — hashes are 64 characters with no spaces and
     * would otherwise run off the page.
     *
     * @return string[]
     */
    public static function wrap(string $s, float $width, string $font, float $size): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $s) ?: [$s] as $para) {
            $line = '';
            foreach (explode(' ', $para) as $word) {
                while (self::widthOf($word, $font, $size) > $width) {
                    // Hard-break an over-long token one character at a time.
                    $cut = '';
                    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($chars as $i => $ch) {
                        if (self::widthOf($cut . $ch, $font, $size) > $width) {
                            break;
                        }
                        $cut .= $ch;
                        unset($chars[$i]);
                    }
                    if ($cut === '') {
                        break;
                    }
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }
                    $lines[] = $cut;
                    $word = implode('', $chars);
                }
                $probe = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && self::widthOf($probe, $font, $size) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $probe;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function line(float $x1, float $yDown1, float $x2, float $yDown2,
                         float $w = 0.6, array $rgb = [0.8, 0.8, 0.8]): void
    {
        $this->ops[$this->page] .= sprintf(
            "%s %s %s RG %s w %s %s m %s %s l S\n",
            $this->n($rgb[0]), $this->n($rgb[1]), $this->n($rgb[2]), $this->n($w),
            $this->n($x1), $this->n(self::A4_H - $yDown1),
            $this->n($x2), $this->n(self::A4_H - $yDown2)
        );
    }

    public function rect(float $x, float $yDown, float $w, float $h, ?array $fill = null,
                         ?array $stroke = null, float $lw = 0.6): void
    {
        $y = self::A4_H - $yDown - $h;
        $op = '';
        if ($fill !== null) {
            $op .= sprintf("%s %s %s rg ", $this->n($fill[0]), $this->n($fill[1]), $this->n($fill[2]));
        }
        if ($stroke !== null) {
            $op .= sprintf("%s %s %s RG %s w ", $this->n($stroke[0]), $this->n($stroke[1]),
                $this->n($stroke[2]), $this->n($lw));
        }
        $op .= sprintf("%s %s %s %s re %s\n", $this->n($x), $this->n($y), $this->n($w), $this->n($h),
            $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S'));
        $this->ops[$this->page] .= $op;
    }

    // ---- attachment + signature ---------------------------------------------------

    /**
     * Embed the original file verbatim. It becomes part of the byte stream the
     * signature covers, so the sealed PDF carries the document it certifies —
     * you can hand over one file and nothing is missing.
     */
    public function attach(string $name, string $bytes, string $mime = 'application/pdf', string $desc = ''): void
    {
        $this->attachment = ['name' => $name, 'bytes' => $bytes, 'mime' => $mime, 'desc' => $desc];
    }

    /**
     * Place the signature field. $rect is [x, yDown, w, h] on the current page;
     * a zero width makes the signature invisible (still fully valid).
     */
    public function signature(array $meta, array $rect, array $appearance = []): void
    {
        $this->signature = ['meta' => $meta, 'rect' => $rect, 'page' => $this->page, 'ap' => $appearance];
    }

    // ---- serialisation ------------------------------------------------------------

    /**
     * Assemble the file. The result is a complete, parseable PDF whose signature
     * dictionary still holds placeholders — Signer::seal() replaces them without
     * changing a single offset.
     */
    public function render(): string
    {
        $this->obj = [];
        $catalog = $this->reserve();
        $pagesId = $this->reserve();

        $fonts = [
            self::FONT_REGULAR => $this->add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'),
            self::FONT_BOLD    => $this->add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'),
            self::FONT_MONO    => $this->add('<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>'),
        ];
        $fontRes = '<< /Font << ';
        foreach ($fonts as $name => $id) {
            $fontRes .= '/' . $name . ' ' . $id . ' 0 R ';
        }
        $fontRes .= '>> >>';

        // The signature widget and its page reference each other, so the field id
        // is reserved before the pages are written and filled in afterwards.
        $fieldId = $this->signature !== null ? $this->reserve() : 0;
        $sigPage = $this->signature['page'] ?? -1;

        $pageIds = [];
        foreach ($this->ops as $i => $stream) {
            $contentId = $this->add("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream");
            $annots = ($fieldId && $i === $sigPage) ? sprintf(' /Annots [%d 0 R]', $fieldId) : '';
            $pageIds[$i] = $this->add(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %s /Contents %d 0 R%s >>',
                $pagesId, $this->n(self::A4_W), $this->n(self::A4_H), $fontRes, $contentId, $annots
            ));
        }

        $acroForm = '';
        if ($fieldId) {
            $this->buildSignature($fieldId, $pageIds, $fontRes);
            $acroForm = sprintf(' /AcroForm << /Fields [%d 0 R] /SigFlags 3 >>', $fieldId);
        }

        $names = '';
        if ($this->attachment !== null) {
            $spec = $this->buildAttachment();
            $names = sprintf(' /Names << /EmbeddedFiles << /Names [(%s) %d 0 R] >> >>',
                self::escape(self::winAnsi($this->attachment['name'])), $spec);
        }

        $this->set($pagesId, sprintf('<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', array_map(fn($id) => $id . ' 0 R', $pageIds)), count($pageIds)));
        $this->set($catalog, sprintf('<< /Type /Catalog /Pages %d 0 R%s%s >>', $pagesId, $acroForm, $names));

        $infoId = $this->add(sprintf(
            '<< /Title (%s) /Author (%s) /Producer (%s) /Creator (%s) /CreationDate (%s) >>',
            self::escape(self::winAnsi((string)($this->info['Title'] ?? ''))),
            self::escape(self::winAnsi((string)($this->info['Author'] ?? ''))),
            'Glue CRM Sign', 'Glue CRM Sign', self::pdfDate(time())
        ));

        return $this->assemble($catalog, $infoId);
    }

    /** Fill in the reserved signature field, its appearance and its /V dictionary. */
    private function buildSignature(int $fieldId, array $pageIds, string $fontRes): void
    {
        $meta = $this->signature['meta'];
        [$x, $yDown, $w, $h] = $this->signature['rect'];
        $pageId = $pageIds[$this->signature['page']] ?? reset($pageIds);

        // The gap the signature will occupy. Both placeholders are fixed width so
        // that filling them in later cannot shift a single byte of the file.
        $byteRange = '/ByteRange [0 ' . str_repeat('0', 10) . ' ' . str_repeat('0', 10)
                   . ' ' . str_repeat('0', 10) . ']';
        $contents  = '/Contents <' . str_repeat('0', self::SIG_BYTES * 2) . '>';

        $sigDictId = $this->add(sprintf(
            "<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached\n%s\n%s\n"
            . "/M (%s) /Name (%s) /Reason (%s) /Location (%s) >>",
            $byteRange, $contents, self::pdfDate((int)($meta['time'] ?? time())),
            self::escape(self::winAnsi((string)($meta['name'] ?? ''))),
            self::escape(self::winAnsi((string)($meta['reason'] ?? ''))),
            self::escape(self::winAnsi((string)($meta['location'] ?? '')))
        ));

        $apRef = '';
        if ($w > 0 && $h > 0) {
            $ap = $this->appearanceStream($this->signature['ap'], $w, $h);
            $apId = $this->add(sprintf(
                "<< /Type /XObject /Subtype /Form /BBox [0 0 %s %s] /Resources %s /Length %d >>\nstream\n%sendstream",
                $this->n($w), $this->n($h), $fontRes, strlen($ap), $ap
            ));
            $apRef = sprintf(' /AP << /N %d 0 R >>', $apId);
        }

        $y = self::A4_H - $yDown - $h;
        $this->set($fieldId, sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /Ff 0 /F 132'
            . ' /Rect [%s %s %s %s] /P %d 0 R /V %d 0 R%s >>',
            $this->n($x), $this->n($y), $this->n($x + $w), $this->n($y + $h),
            $pageId, $sigDictId, $apRef
        ));
    }

    /** The visible signature block: a framed panel with the signer and the date. */
    private function appearanceStream(array $ap, float $w, float $h): string
    {
        $pad = 6.0;
        $s = sprintf("0.98 0.98 1 rg 0.36 0.42 1 RG 0.8 w 0.4 0.4 %s %s re B\n",
            $this->n($w - 0.8), $this->n($h - 0.8));

        $lines = array_values(array_filter([
            (string)($ap['line1'] ?? ''), (string)($ap['line2'] ?? ''),
            (string)($ap['line3'] ?? ''), (string)($ap['line4'] ?? ''),
        ], fn($l) => $l !== ''));

        $size = 7.5;
        $lead = $size * 1.3;
        $y = $h - $pad - $size;
        foreach ($lines as $i => $line) {
            $font = $i === 0 ? self::FONT_BOLD : self::FONT_REGULAR;
            $s .= sprintf("BT 0.1 0.13 0.35 rg /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n",
                $font, $this->n($i === 0 ? $size + 0.5 : $size), $this->n($pad), $this->n($y),
                self::escape(self::winAnsi($line)));
            $y -= $lead;
        }
        return $s;
    }

    private function buildAttachment(): int
    {
        $a = $this->attachment;
        $embId = $this->add(sprintf(
            "<< /Type /EmbeddedFile /Subtype /%s /Length %d /Params << /Size %d /CheckSum <%s> /ModDate (%s) >> >>\nstream\n%s\nendstream",
            self::nameEscape($a['mime']), strlen($a['bytes']), strlen($a['bytes']),
            md5($a['bytes']), self::pdfDate(time()), $a['bytes']
        ));
        $name = self::escape(self::winAnsi($a['name']));
        return $this->add(sprintf(
            '<< /Type /Filespec /F (%s) /UF (%s) /Desc (%s) /AFRelationship /Source /EF << /F %d 0 R /UF %d 0 R >> >>',
            $name, $name, self::escape(self::winAnsi($a['desc'])), $embId, $embId
        ));
    }

    /** Header, body, cross-reference table, trailer. */
    private function assemble(int $catalog, int $infoId): string
    {
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($this->obj as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($out);
        $count   = count($this->obj) + 1;
        $xref    = "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        // A file /ID is required for a signed document; both halves are the same
        // because the file is written once and never incrementally updated.
        $fileId = strtoupper(bin2hex(random_bytes(16)));
        $out .= $xref . sprintf(
            "trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R /ID [<%s> <%s>] >>\nstartxref\n%d\n%%%%EOF\n",
            $count, $catalog, $infoId, $fileId, $fileId, $xrefPos
        );
        return $out;
    }

    // ---- object table -------------------------------------------------------------

    private function reserve(): int
    {
        $id = count($this->obj) + 1;
        $this->obj[$id] = '<< >>';
        return $id;
    }

    private function add(string $body): int
    {
        $id = count($this->obj) + 1;
        $this->obj[$id] = $body;
        return $id;
    }

    private function set(int $id, string $body): void
    {
        $this->obj[$id] = $body;
    }

    // ---- text helpers -------------------------------------------------------------

    /** Width of $s in points. Metrics for the standard-14 fonts, no embedding. */
    public static function widthOf(string $s, string $font, float $size): float
    {
        $w = self::metrics($font);
        $total = 0;
        foreach (str_split(self::winAnsi($s)) as $ch) {
            $c = ord($ch);
            $total += $w[$c] ?? 556;
        }
        return $total * $size / 1000;
    }

    /** @return array<int,int> character code => width in 1/1000 em */
    private static function metrics(string $font): array
    {
        static $cache = [];
        if (isset($cache[$font])) {
            return $cache[$font];
        }
        if ($font === self::FONT_MONO) {
            return $cache[$font] = array_fill(32, 224, 600);
        }
        $packed = $font === self::FONT_BOLD
            ? '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 333 333 584 584 584 611 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778 667 778 722 667 611 722 667 944 667 667 611 333 278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611 611 611 389 556 333 611 556 778 556 556 500 389 280 389 584'
            : '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 278 278 584 584 584 556 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778 667 778 722 667 611 722 667 944 667 667 611 278 278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556 556 556 333 500 278 556 500 722 500 500 500 334 260 334 584';

        $w = [];
        foreach (explode(' ', $packed) as $i => $v) {
            $w[32 + $i] = (int)$v;
        }
        // Latin-1 accents carry their base letter's width — enough for Italian
        // and every other Western European name we put on a certificate.
        $base = ['À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ç' => 'C',
                 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I',
                 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
                 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y',
                 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
                 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
                 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
                 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y'];
        foreach ($base as $accented => $plain) {
            $code = ord(self::winAnsi($accented));
            $w[$code] = $w[ord($plain)] ?? 556;
        }
        return $cache[$font] = $w;
    }

    /** UTF-8 in, WinAnsi (CP1252) out — the encoding the standard-14 fonts use. */
    public static function winAnsi(string $s): string
    {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        if ($out === false) {
            $out = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
        }
        return $out;
    }

    private static function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']);
    }

    /** PDF name objects escape anything outside the regular character set as #xx. */
    private static function nameEscape(string $s): string
    {
        return preg_replace_callback('/[^A-Za-z0-9._-]/',
            fn($m) => sprintf('#%02X', ord($m[0])), $s) ?? $s;
    }

    public static function pdfDate(int $ts): string
    {
        $off = (int)date('Z', $ts);
        $sign = $off < 0 ? '-' : '+';
        $off = abs($off);
        return sprintf('D:%s%s%02d\'%02d\'', date('YmdHis', $ts), $sign, intdiv($off, 3600), intdiv($off % 3600, 60));
    }

    /** Numbers with no exponent and no trailing noise — PDF has no float syntax. */
    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }
}
