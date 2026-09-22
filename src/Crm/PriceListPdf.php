<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Sign\Pdf;

/**
 * A price list as a printable catalogue: one row per product — photo, code,
 * description, price — grouped under its category, A4 portrait.
 *
 * Built on Sign\Pdf like the quote and the installation report, so there is
 * no converter and nothing third-party in between. The product photo is a
 * link when the product has one (its info_url), which is what "click the
 * photo for more" means on paper.
 *
 * A category heading never sits alone at a page bottom, and a category that
 * runs onto the next page repeats its heading there, marked as continued.
 * Amounts are "EUR 1.234,56", as on the quote.
 */
final class PriceListPdf
{
    private const M     = 36.0;   // page margin
    private const FOOT  = 40.0;   // keep-clear at the page bottom (the footer lives in it)
    private const THUMB = 62.0;   // photo box, square
    private const PRICE_W = 118.0;

    private Pdf $pdf;
    private float $y = self::M;
    private int $pageNo = 1;
    /** @var array<string,string> */
    private array $L;
    private string $footLeft;
    private string $category = '';

    private function __construct(string $title, string $lang, string $footLeft)
    {
        $this->pdf = new Pdf($title, (string)Config::get('app.company_name', 'CRM'));
        $this->pdf->addPage();
        $this->footLeft = $footLeft;
        $it = [
            'title'     => 'LISTINO PREZZI',
            'page'      => 'Pagina %d',
            'printed'   => 'Stampato il %s',
            'count'     => '%d prodotti',
            'count1'    => '1 prodotto',
            'net'       => 'Prezzi IVA esclusa',
            'gross'     => 'Prezzi IVA inclusa',
            'plus_vat'  => '+ IVA %s%%',
            'incl_vat'  => 'IVA %s%% inclusa',
            'on_req'    => 'Prezzo su richiesta',
            'no_photo'  => 'Nessuna foto',
            'more'      => 'Maggiori informazioni',
            'code'      => 'Cod.',
            'ean'       => 'EAN',
            'other'     => 'Altri prodotti',
            'cont'      => '(continua)',
            'selection' => 'Selezione',
            'empty'     => 'Nessun prodotto in questa selezione.',
        ];
        $en = [
            'title'     => 'PRICE LIST',
            'page'      => 'Page %d',
            'printed'   => 'Printed %s',
            'count'     => '%d products',
            'count1'    => '1 product',
            'net'       => 'Prices excluding VAT',
            'gross'     => 'Prices including VAT',
            'plus_vat'  => '+ VAT %s%%',
            'incl_vat'  => 'incl. %s%% VAT',
            'on_req'    => 'Price on request',
            'no_photo'  => 'No photo',
            'more'      => 'More information',
            'code'      => 'Code',
            'ean'       => 'EAN',
            'other'     => 'Other products',
            'cont'      => '(continued)',
            'selection' => 'Selection',
            'empty'     => 'No products in this selection.',
        ];
        $this->L = $lang === 'en' ? $en : $it;
    }

    /**
     * @param array  $list   the price_lists row
     * @param array  $rows   PriceLists::allItems()
     * @param array  $covers ArticleMedia::byIds() over the rows' cover_id
     * @param string $filter what the selection was narrowed to, '' for the whole list
     */
    public static function build(array $list, array $rows, array $covers, string $lang = 'it', string $filter = ''): string
    {
        $company = (string)Config::get('app.company_name', 'CRM');
        // The footer carries the version too: a page that comes loose still says
        // which printing of which list it belongs to.
        $b = new self($company . ' - ' . $list['name'], $lang === 'en' ? 'en' : 'it',
            $company . ' - ' . $list['name'] . '  ·  ' . PriceLists::versionLabel($list, $lang === 'en' ? 'en' : 'it'));
        $L = $b->L;
        $R = Pdf::A4_W - self::M;
        $navy = [0.1, 0.13, 0.35];
        $grey = [0.4, 0.4, 0.45];

        // ---- header ----
        $b->pdf->text(self::M, $b->y + 16, $company, Pdf::FONT_BOLD, 15, $navy);
        $b->pdf->textRight($R, $b->y + 16, $L['title'], Pdf::FONT_BOLD, 12, $navy);
        $b->y += 30;
        foreach (Pdf::wrap((string)$list['name'], $R - self::M, Pdf::FONT_BOLD, 17) as $line) {
            $b->pdf->text(self::M, $b->y + 16, $line, Pdf::FONT_BOLD, 17);
            $b->y += 21;
        }
        $desc = trim((string)($list['description'] ?? ''));
        if ($desc !== '') {
            $b->y += 2;
            $b->y = $b->pdf->paragraph(self::M, $b->y + 10, $R - self::M, $desc, Pdf::FONT_REGULAR, 9.5, 0, [0.25, 0.25, 0.3]) - 10;
        }
        $n = count($rows);
        // The version and the printing moment, so two printed catalogues of the
        // same list can be told apart — the same reason a quote carries them.
        $meta = implode('  ·  ', array_filter([
            PriceLists::versionLabel($list, $lang === 'en' ? 'en' : 'it'),
            sprintf($L['printed'], date('d/m/Y H:i')),
            $n === 1 ? $L['count1'] : sprintf($L['count'], $n),
            (int)$list['vat_included'] === 1 ? $L['gross'] : $L['net'],
        ], 'strlen'));
        $b->y += 6;
        $b->pdf->text(self::M, $b->y + 9, $meta, Pdf::FONT_REGULAR, 9, $grey);
        $b->y += 14;
        if ($filter !== '') {
            foreach (Pdf::wrap($L['selection'] . ': ' . $filter, $R - self::M, Pdf::FONT_REGULAR, 9) as $line) {
                $b->pdf->text(self::M, $b->y + 9, $line, Pdf::FONT_REGULAR, 9, $grey);
                $b->y += 12;
            }
        }
        $b->y += 4;
        $b->pdf->line(self::M, $b->y, $R, $b->y, 1.2, $navy);
        $b->y += 10;

        if (!$rows) {
            $b->pdf->text(self::M, $b->y + 12, $L['empty'], Pdf::FONT_REGULAR, 11, $grey);
        }
        foreach ($rows as $r) {
            $cat = trim((string)($r['category'] ?? '')) ?: $L['other'];
            $h   = $b->rowHeight($r);
            if ($cat !== $b->category) {
                $b->ensure(24 + $h);          // a heading never ends a page on its own
                $b->category = $cat;
                $b->heading(false);
            } elseif ($b->y + $h > Pdf::A4_H - self::FOOT) {
                $b->newPage();
                $b->heading(true);
            }
            $cover = isset($r['cover_id']) ? ($covers[(int)$r['cover_id']] ?? null) : null;
            $b->row($r, $list, $cover);
        }

        $b->footer();
        return $b->pdf->render();
    }

    // ---- layout -------------------------------------------------------------------

    private function textX(): float
    {
        return self::M + self::THUMB + 12;
    }

    private function textW(): float
    {
        return Pdf::A4_W - self::M - self::PRICE_W - $this->textX() - 10;
    }

    /** @return array{desc:string[], extra:string[], meta:string} the text a row prints */
    private function rowText(array $r): array
    {
        $w    = $this->textW();
        $desc = array_slice(Pdf::wrap(trim((string)($r['description'] ?? '')) ?: (string)$r['code'], $w, Pdf::FONT_BOLD, 10), 0, 3);
        $long = trim(preg_replace('/\s+/', ' ', (string)($r['web_description'] ?? '')) ?? '');
        $extra = [];
        if ($long !== '') {
            $extra = Pdf::wrap($long, $w, Pdf::FONT_REGULAR, 8);
            if (count($extra) > 3) {
                $extra = array_slice($extra, 0, 3);
                $extra[2] = rtrim(mb_substr($extra[2], 0, max(0, mb_strlen($extra[2]) - 3))) . '...';
            }
        }
        $meta = $this->L['code'] . ' ' . $r['code']
              . (trim((string)($r['barcode'] ?? '')) !== '' ? '   ' . $this->L['ean'] . ' ' . trim((string)$r['barcode']) : '');
        while (mb_strlen($meta) > 1 && Pdf::widthOf($meta, Pdf::FONT_REGULAR, 7.5) > $w) {
            $meta = mb_substr($meta, 0, -1);   // never into the price column
        }
        return ['desc' => $desc, 'extra' => $extra, 'meta' => $meta];
    }

    private function rowHeight(array $r): float
    {
        $t = $this->rowText($r);
        $h = 12 + count($t['desc']) * 12.5 + count($t['extra']) * 10 + (!empty($r['info_url']) ? 12 : 0) + 4;
        return max(self::THUMB, $h) + 12;
    }

    private function heading(bool $continued): void
    {
        $R = Pdf::A4_W - self::M;
        $this->pdf->rect(self::M, $this->y, $R - self::M, 18, [0.92, 0.93, 0.97]);
        $label = mb_strtoupper($this->category) . ($continued ? '  ' . $this->L['cont'] : '');
        $this->pdf->text(self::M + 8, $this->y + 12.5, $label, Pdf::FONT_BOLD, 9, [0.1, 0.13, 0.35]);
        $this->y += 26;
    }

    private function row(array $r, array $list, ?array $cover): void
    {
        $R   = Pdf::A4_W - self::M;
        $x   = $this->textX();
        $top = $this->y;
        $t   = $this->rowText($r);
        $url = trim((string)($r['info_url'] ?? ''));

        // ---- photo ----
        $box = self::THUMB;
        $this->pdf->rect(self::M, $top, $box, $box, [1, 1, 1], [0.85, 0.85, 0.88], 0.5);
        $drawn = false;
        $bytes = $cover !== null ? ArticleMedia::pdfBytes($cover) : null;
        if ($bytes !== null) {
            $dim = @getimagesizefromstring($bytes);
            if ($dim !== false && (int)$dim[0] > 0 && (int)$dim[1] > 0) {
                $in = $box - 4;
                $k  = min($in / (int)$dim[0], $in / (int)$dim[1]);
                $w  = (int)$dim[0] * $k;
                $h  = (int)$dim[1] * $k;
                $drawn = $this->pdf->jpeg($bytes, self::M + ($box - $w) / 2, $top + ($box - $h) / 2, $w, $h);
            }
        }
        if (!$drawn) {
            $s = $this->L['no_photo'];
            $this->pdf->text(self::M + ($box - Pdf::widthOf($s, Pdf::FONT_REGULAR, 7)) / 2, $top + $box / 2 + 2,
                $s, Pdf::FONT_REGULAR, 7, [0.6, 0.6, 0.65]);
        }
        if ($url !== '') {
            $this->pdf->link(self::M, $top, $box, $box, $url);
        }

        // ---- text ----
        $y = $top;
        $this->pdf->text($x, $y + 8, $t['meta'], Pdf::FONT_REGULAR, 7.5, [0.4, 0.4, 0.45]);
        $y += 12;
        foreach ($t['desc'] as $line) {
            $this->pdf->text($x, $y + 10, $line, Pdf::FONT_BOLD, 10);
            $y += 12.5;
        }
        foreach ($t['extra'] as $line) {
            $this->pdf->text($x, $y + 8, $line, Pdf::FONT_REGULAR, 8, [0.3, 0.3, 0.35]);
            $y += 10;
        }
        if ($url !== '') {
            $more = $this->L['more'] . ' >';
            $this->pdf->text($x, $y + 10, $more, Pdf::FONT_REGULAR, 8, [0.2, 0.3, 0.8]);
            $this->pdf->link($x, $y + 1, Pdf::widthOf($more, Pdf::FONT_REGULAR, 8), 12, $url);
        }

        // ---- price ----
        $price = PriceLists::shownPrice($r, $list);
        if ($price > 0) {
            $this->pdf->textRight($R, $top + 14, 'EUR ' . number_format($price, 2, ',', '.'), Pdf::FONT_BOLD, 12.5);
            $vat = rtrim(rtrim(number_format(PriceLists::vatRate($r), 2, ',', '.'), '0'), ',');
            $this->pdf->textRight($R, $top + 27,
                sprintf((int)$list['vat_included'] === 1 ? $this->L['incl_vat'] : $this->L['plus_vat'], $vat),
                Pdf::FONT_REGULAR, 7.5, [0.4, 0.4, 0.45]);
        } else {
            $this->pdf->textRight($R, $top + 13, $this->L['on_req'], Pdf::FONT_BOLD, 9, [0.4, 0.4, 0.45]);
        }

        $this->y = $top + $this->rowHeight($r);
        $this->pdf->line(self::M, $this->y - 6, $R, $this->y - 6, 0.3, [0.87, 0.87, 0.9]);
    }

    private function ensure(float $need): void
    {
        if ($this->y + $need > Pdf::A4_H - self::FOOT) {
            $this->newPage();
        }
    }

    private function newPage(): void
    {
        $this->footer();
        $this->pdf->addPage();
        $this->pageNo++;
        $this->y = self::M;
    }

    /** Company and list on the left, page number on the right. */
    private function footer(): void
    {
        $y   = Pdf::A4_H - 20.0;
        $rgb = [0.5, 0.5, 0.55];
        $R   = Pdf::A4_W - self::M;
        $this->pdf->line(self::M, $y - 10, $R, $y - 10, 0.3, [0.85, 0.85, 0.88]);
        $left = $this->footLeft;
        while ($left !== '' && Pdf::widthOf($left, Pdf::FONT_REGULAR, 7.5) > $R - self::M - 80) {
            $left = mb_substr($left, 0, -1);
        }
        $this->pdf->text(self::M, $y, $left, Pdf::FONT_REGULAR, 7.5, $rgb);
        $this->pdf->textRight($R, $y, sprintf($this->L['page'], $this->pageNo), Pdf::FONT_REGULAR, 7.5, $rgb);
    }
}
