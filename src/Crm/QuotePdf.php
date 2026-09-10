<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Sign\Pdf;

/**
 * Renders a quote built from quote_lines into the PDF the customer signs.
 *
 * Built on Sign\Pdf, the same small writer the installation report and the
 * signature certificate use, so the signing flow seals bytes we wrote
 * ourselves — no conversion step, nothing third-party in between.
 *
 * Amounts are printed as "EUR 1.234,56" rather than with the euro glyph: the
 * base-14 fonts only reach it through an encoding detour, and a legal document
 * is the wrong place to find out a renderer disagrees.
 *
 * Every page carries the quote number and its page number in the footer, the
 * table repeats its header on every page it continues onto, and the totals and
 * the acceptance note never split from the page they start on. A product code
 * is never broken mid-token — it is an identifier, and "CASH-SELF106" over a
 * lone "0" reads as a different product.
 */
final class QuotePdf
{
    private const M    = 42.0;   // page margin
    private const FOOT = 44.0;   // keep-clear at the page bottom (the footer lives in it)
    private const RH   = 11.0;   // line height inside a table row

    private Pdf $pdf;
    private float $y = self::M;
    private int $pageNo = 1;
    private string $number;
    /** @var array<string,string> */
    private array $L;
    /** @var array<string,array<string,float>> */
    private array $cols;

    private function __construct(string $title, string $lang, string $number)
    {
        $this->pdf = new Pdf($title, (string)Config::get('app.company_name', 'CRM'));
        $this->pdf->addPage();
        $this->number = $number;

        $it = [
            'title'       => 'PREVENTIVO',
            'doc_ref'     => 'Preventivo',
            'page'        => 'Pagina %d',
            'number'      => 'N.',
            'date'        => 'Data',
            'valid'       => 'Valido fino al',
            'to'          => 'Spett.le',
            'vat_id'      => 'P.IVA',
            'code'        => 'Codice',
            'desc'        => 'Descrizione',
            'qty'         => 'Q.tà',
            'price'       => 'Prezzo',
            'disc'        => 'Sc. %',
            'vat'         => 'IVA %',
            'total'       => 'Totale',
            'service'     => 'Servizio',
            'lines_total' => 'Totale righe',
            'doc_disc'    => 'Sconto sul preventivo (%s%%)',
            'net'         => 'Imponibile',
            'vat_line'    => 'IVA %s%%',
            'grand'       => 'TOTALE',
            'notes'       => 'Note',
            'accept'      => 'Firmando questo preventivo con il codice monouso (OTP) ricevuto sul proprio '
                           . 'telefono o sulla propria email, il cliente ne accetta integralmente il contenuto. '
                           . 'Il documento firmato è sigillato con un certificato digitale che ne garantisce '
                           . 'integrità e data.',
        ];
        $en = [
            'title'       => 'QUOTE',
            'doc_ref'     => 'Quote',
            'page'        => 'Page %d',
            'number'      => 'No.',
            'date'        => 'Date',
            'valid'       => 'Valid until',
            'to'          => 'To',
            'vat_id'      => 'VAT',
            'code'        => 'Code',
            'desc'        => 'Description',
            'qty'         => 'Qty',
            'price'       => 'Price',
            'disc'        => 'Disc. %',
            'vat'         => 'VAT %',
            'total'       => 'Total',
            'service'     => 'Service',
            'lines_total' => 'Lines total',
            'doc_disc'    => 'Quote discount (%s%%)',
            'net'         => 'Taxable amount',
            'vat_line'    => 'VAT %s%%',
            'grand'       => 'TOTAL',
            'notes'       => 'Notes',
            'accept'      => 'By signing this quote with the one-time code received by phone or email, the '
                           . 'customer accepts its content in full. The signed document is sealed with a '
                           . 'digital certificate that guarantees its integrity and date.',
        ];
        $this->L = $lang === 'en' ? $en : $it;

        // Text columns carry a left x and a width; number columns a right edge.
        // Laid out from the right so every numeric column fits its widest real
        // value ("123.456,78" in bold) with a clear gap to its neighbour, and
        // the code column is wide enough for the catalogue's longest codes.
        $R = Pdf::A4_W - self::M;
        $this->cols = [
            'code'  => ['x' => self::M,        'w' => 90.0],
            'desc'  => ['x' => self::M + 94.0, 'w' => 187.0],
            'qty'   => ['r' => $R - 200.0],
            'price' => ['r' => $R - 140.0],
            'disc'  => ['r' => $R - 102.0],
            'vat'   => ['r' => $R - 66.0],
            'total' => ['r' => $R],
        ];
    }

    /**
     * @param array $q       the quote_requests row (number, valid_until, customer_notes, ...)
     * @param array $lead    the lead it hangs off
     * @param array $contact the customer
     * @param array $totals  QuoteRequests::totals() — carries the priced lines
     */
    public static function build(array $q, array $lead, array $contact, array $totals): string
    {
        $lang   = ($lead['lang'] ?? 'it') === 'en' ? 'en' : 'it';
        $number = (string)($q['number'] ?? '');
        $b      = new self('Preventivo ' . $number, $lang, $number);
        $L      = $b->L;
        $R      = Pdf::A4_W - self::M;
        $navy   = [0.1, 0.13, 0.35];
        $grey   = [0.4, 0.4, 0.45];

        // ---- header ----
        $b->pdf->text(self::M, $b->y + 16, (string)Config::get('app.company_name', 'CRM'), Pdf::FONT_BOLD, 15, $navy);
        $b->pdf->textRight($R, $b->y + 16, $L['title'], Pdf::FONT_BOLD, 14, $navy);
        $b->y += 28;
        $top = $b->y;

        // right: number, date, validity
        $ry = $top;
        $b->pdf->textRight($R, $ry + 10, $L['number'] . ' ' . $number, Pdf::FONT_BOLD, 10);
        $ry += 14;
        $b->pdf->textRight($R, $ry + 10, $L['date'] . ' ' . date('d/m/Y'), Pdf::FONT_REGULAR, 9.5, $grey);
        $ry += 13;
        if (!empty($q['valid_until'])) {
            $b->pdf->textRight($R, $ry + 10, $L['valid'] . ' ' . date('d/m/Y', (int)strtotime((string)$q['valid_until'])),
                Pdf::FONT_REGULAR, 9.5, $grey);
            $ry += 13;
        }

        // left: who it is for. The business name leads, the person follows —
        // the quote is addressed to the company that will pay it.
        $leftW = $R - self::M - 190.0;
        $ly    = $top;
        $b->pdf->text(self::M, $ly + 9, $L['to'], Pdf::FONT_REGULAR, 8.5, $grey);
        $ly += 13;
        $name = trim((string)($contact['company'] ?? '')) ?: (trim((string)($contact['name'] ?? ''))
              ?: (string)($lead['customer_name'] ?? ''));
        foreach (Pdf::wrap($name, $leftW, Pdf::FONT_BOLD, 11) as $line) {
            $b->pdf->text(self::M, $ly + 11, $line, Pdf::FONT_BOLD, 11);
            $ly += 14;
        }
        $person = trim((string)($lead['customer_name'] ?? ''));
        $small  = [];
        if ($person !== '' && mb_strtolower($person) !== mb_strtolower($name)) {
            $small[] = $person;
        }
        $vat = trim((string)($contact['vat_number'] ?? '')) ?: trim((string)($lead['vat_number'] ?? ''));
        if ($vat !== '') {
            $small[] = $L['vat_id'] . ' ' . $vat;
        }
        $addr = trim(implode(' ', array_filter([
            (string)($contact['address'] ?? ''), (string)($contact['zip'] ?? ''), (string)($contact['city'] ?? ''),
            trim((string)($contact['province'] ?? '')) !== '' ? '(' . $contact['province'] . ')' : '',
        ], 'strlen')));
        if ($addr !== '') {
            $small[] = $addr;
        }
        $reach = implode(' · ', array_filter([
            trim((string)($contact['phone'] ?? '')) ?: trim((string)($lead['customer_phone'] ?? '')),
            trim((string)($contact['email'] ?? '')) ?: trim((string)($lead['customer_email'] ?? '')),
        ], 'strlen'));
        if ($reach !== '') {
            $small[] = $reach;
        }
        foreach ($small as $s) {
            foreach (Pdf::wrap($s, $leftW, Pdf::FONT_REGULAR, 9.5) as $line) {
                $b->pdf->text(self::M, $ly + 10, $line, Pdf::FONT_REGULAR, 9.5, [0.2, 0.2, 0.25]);
                $ly += 12.5;
            }
        }

        $b->y = max($ly, $ry) + 12;
        $b->pdf->line(self::M, $b->y, $R, $b->y, 1.2, $navy);
        $b->y += 8;

        // ---- the lines ----
        $b->tableHead();
        foreach ($totals['lines'] as $l) {
            $b->tableRow($l);
        }

        // ---- totals ----
        $b->totals($totals);

        // ---- notes printed for the customer ----
        $notes = trim((string)($q['customer_notes'] ?? ''));
        if ($notes !== '') {
            $b->ensure(34);
            $b->y += 6;
            $b->pdf->text(self::M, $b->y + 9, $L['notes'], Pdf::FONT_BOLD, 9.5, [0.35, 0.35, 0.4]);
            $b->y += 15;
            $b->paragraphPaged($notes, self::M, Pdf::A4_W - 2 * self::M, 9.5);
        }

        // ---- what signing means ----
        $b->ensure(52);
        $b->y += 10;
        $b->pdf->line(self::M, $b->y, $R, $b->y);
        $b->y += 10;
        $b->paragraphPaged($L['accept'], self::M, Pdf::A4_W - 2 * self::M, 8.5, $grey);

        $b->footer();   // the last page's footer; earlier ones were drawn at each break
        return $b->pdf->render();
    }

    // ---- layout helpers ------------------------------------------------------------

    private function tableHead(): void
    {
        $L = $this->L;
        $c = $this->cols;
        $g = [0.35, 0.35, 0.4];
        $this->ensure(26);
        $y = $this->y + 9;
        $this->pdf->text($c['code']['x'], $y, $L['code'], Pdf::FONT_BOLD, 8.5, $g);
        $this->pdf->text($c['desc']['x'], $y, $L['desc'], Pdf::FONT_BOLD, 8.5, $g);
        $this->pdf->textRight($c['qty']['r'], $y, $L['qty'], Pdf::FONT_BOLD, 8.5, $g);
        $this->pdf->textRight($c['price']['r'], $y, $L['price'], Pdf::FONT_BOLD, 8.5, $g);
        $this->pdf->textRight($c['disc']['r'], $y, $L['disc'], Pdf::FONT_BOLD, 8.5, $g);
        $this->pdf->textRight($c['vat']['r'], $y, $L['vat'], Pdf::FONT_BOLD, 8.5, $g);
        $this->pdf->textRight($c['total']['r'], $y, $L['total'], Pdf::FONT_BOLD, 8.5, $g);
        $this->y += 14;
        $this->pdf->line(self::M, $this->y, Pdf::A4_W - self::M, $this->y, 0.8, [0.6, 0.6, 0.65]);
        $this->y += 5;
    }

    /** One priced line; wraps the description, repeats the header on a new page. */
    private function tableRow(array $l): void
    {
        $c     = $this->cols;
        $sz    = 8.5;
        $isArt = ($l['kind'] ?? '') === 'article';
        $code  = (string)($l['code'] ?? '');

        // A product code is an identifier: shrink it until its longest token
        // fits rather than let the wrap cut it. Only a code that contains spaces
        // can still go onto a second line, and then only at a space.
        $codeSz = $sz;
        if ($isArt && $code !== '') {
            $widest = static fn(float $s): float => max(array_map(
                static fn(string $w): float => Pdf::widthOf($w, Pdf::FONT_REGULAR, $s), explode(' ', $code)));
            while ($codeSz > 6.0 && $widest($codeSz) > $c['code']['w']) {
                $codeSz -= 0.5;
            }
        }
        $codeLines = $isArt ? Pdf::wrap($code, $c['code']['w'], Pdf::FONT_REGULAR, $codeSz)
                            : [$this->L['service']];
        $descLines = Pdf::wrap((string)$l['description'], $c['desc']['w'], Pdf::FONT_REGULAR, $sz);
        $codeLines = $codeLines ?: [''];
        $descLines = $descLines ?: [''];
        $h = max(count($codeLines), count($descLines)) * self::RH + 6;

        if ($this->y + $h > Pdf::A4_H - self::FOOT) {
            $this->newPage();
            $this->tableHead();
        }

        $y = $this->y + 9;
        foreach ($codeLines as $i => $s) {
            $this->pdf->text($c['code']['x'], $y + $i * self::RH, $s, Pdf::FONT_REGULAR, $isArt ? $codeSz : $sz,
                $isArt ? [0, 0, 0] : [0.4, 0.4, 0.45]);
        }
        foreach ($descLines as $i => $s) {
            $this->pdf->text($c['desc']['x'], $y + $i * self::RH, $s, Pdf::FONT_REGULAR, $sz);
        }
        $this->pdf->textRight($c['qty']['r'], $y, self::qty($l['qty']), Pdf::FONT_REGULAR, $sz);
        $this->pdf->textRight($c['price']['r'], $y, self::eur($l['unit_price']), Pdf::FONT_REGULAR, $sz);
        if ((float)$l['discount_pct'] > 0) {
            $this->pdf->textRight($c['disc']['r'], $y, self::qty($l['discount_pct']), Pdf::FONT_REGULAR, $sz);
        }
        $this->pdf->textRight($c['vat']['r'], $y, self::qty($l['vat_rate']), Pdf::FONT_REGULAR, $sz);
        $this->pdf->textRight($c['total']['r'], $y, self::eur($l['line_total']), Pdf::FONT_BOLD, $sz);

        $this->y += $h;
        $this->pdf->line(self::M, $this->y - 2, Pdf::A4_W - self::M, $this->y - 2, 0.3, [0.85, 0.85, 0.88]);
    }

    /** The summary block, right-aligned, kept together on one page. */
    private function totals(array $t): void
    {
        $L  = $this->L;
        $R  = Pdf::A4_W - self::M;
        $lx = $R - 110.0;
        $this->ensure(40 + 14.0 * (3 + count($t['vat'])));
        $this->y += 8;

        $row = function (string $label, string $value, bool $bold = false) use ($lx, $R): void {
            $f  = $bold ? Pdf::FONT_BOLD : Pdf::FONT_REGULAR;
            $sz = $bold ? 11.0 : 9.5;
            $this->pdf->textRight($lx, $this->y + 10, $label, $f, $sz, $bold ? [0, 0, 0] : [0.35, 0.35, 0.4]);
            $this->pdf->textRight($R, $this->y + 10, $value, $f, $sz);
            $this->y += $bold ? 18 : 14;
        };

        $row($L['lines_total'], 'EUR ' . self::eur($t['after_lines']));
        if ((float)$t['doc_discount_pct'] > 0) {
            $row(sprintf($L['doc_disc'], self::qty($t['doc_discount_pct'])),
                '- EUR ' . self::eur((float)$t['after_lines'] - (float)$t['net']));
        }
        $row($L['net'], 'EUR ' . self::eur($t['net']));
        foreach ($t['vat'] as $rate => $v) {
            $row(sprintf($L['vat_line'], self::qty($rate)), 'EUR ' . self::eur($v['tax']));
        }
        $this->pdf->line($lx - 70, $this->y + 2, $R, $this->y + 2, 0.8);
        $this->y += 6;
        $row($L['grand'], 'EUR ' . self::eur($t['total']), true);
    }

    private function paragraphPaged(string $s, float $x, float $w, float $size, array $rgb = [0, 0, 0]): void
    {
        $lead = $size * 1.35;
        foreach (Pdf::wrap($s, $w, Pdf::FONT_REGULAR, $size) as $line) {
            $this->ensure($lead + 2);
            $this->pdf->text($x, $this->y + $size, $line, Pdf::FONT_REGULAR, $size, $rgb);
            $this->y += $lead;
        }
    }

    private function ensure(float $need): void
    {
        if ($this->y + $need > Pdf::A4_H - self::FOOT) {
            $this->newPage();
        }
    }

    /** Close the current page with its footer and start the next one. */
    private function newPage(): void
    {
        $this->footer();
        $this->pdf->addPage();
        $this->pageNo++;
        $this->y = self::M;
    }

    /**
     * Quote number left, page number right, under a hairline — so a page that
     * comes loose from the rest (page 2 is often nothing but the totals) still
     * says which quote it belongs to.
     */
    private function footer(): void
    {
        $y   = Pdf::A4_H - 22.0;
        $rgb = [0.5, 0.5, 0.55];
        $this->pdf->line(self::M, $y - 10, Pdf::A4_W - self::M, $y - 10, 0.3, [0.85, 0.85, 0.88]);
        $this->pdf->text(self::M, $y, $this->L['doc_ref'] . ' ' . $this->number, Pdf::FONT_REGULAR, 7.5, $rgb);
        $this->pdf->textRight(Pdf::A4_W - self::M, $y, sprintf($this->L['page'], $this->pageNo),
            Pdf::FONT_REGULAR, 7.5, $rgb);
    }

    private static function eur($n): string
    {
        return number_format((float)$n, 2, ',', '.');
    }

    /** 2 -> "2", 2.5 -> "2,5", 22.00 -> "22". */
    private static function qty($n): string
    {
        return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
    }
}
