<?php
declare(strict_types=1);

namespace Glue\Install;

use Glue\Config;
use Glue\Sign\Pdf;

/**
 * Renders an installation report to the PDF the customer is asked to sign —
 * the same field-per-row sheet the technicians know from Jotform, followed by
 * the photos. Built on Sign\Pdf, so the bytes are fully ours and the signing
 * flow can seal them without a conversion step in between.
 */
final class ReportPdf
{
    private const M      = 48.0;                 // page margin
    private const LABEL_W = 168.0;               // label column
    private const FOOT   = 40.0;                 // keep-clear at the page bottom
    /** Photo cell: two per row, capped height, aspect kept. */
    private const CELL_GAP  = 10.0;
    private const CELL_MAX_H = 190.0;

    private Pdf $pdf;
    private float $y = self::M;
    /** @var array<string,string> */
    private array $L;

    private function __construct(string $title, string $lang)
    {
        $it = [
            'title'      => 'RAPPORTO DI INSTALLAZIONE',
            'report_n'   => 'Rapporto n.',
            'customer'   => 'Cliente',
            'address'    => 'Indirizzo',
            'date'       => 'Data invio',
            'start'      => 'Inizio installazione',
            'end'        => 'Fine installazione',
            'model'      => 'Modello macchina',
            'serial'     => 'Numero di serie',
            'ground'     => 'Valore messa a terra',
            'local_ip'   => 'IP locale del cassetto',
            'public_ip'  => 'IP pubblico del cliente',
            'adsl'       => 'Gestore ADSL',
            'vpn'        => 'Indirizzo VPN macchina',
            'remote'     => 'ID assistenza remota',
            'ups'        => 'UPS installato',
            'cash'       => 'Incasso ritirato',
            'tech'       => 'Tecnico installatore',
            'notes'      => 'Note libere e considerazioni',
            'ph_ground'  => 'Foto della messa a terra',
            'ph_final'   => 'Foto fine installazione',
            'no_embed'   => 'Foto allegata (non incorporabile): ',
            'sign_note'  => 'Documento sottoposto a firma elettronica del cliente con verifica tramite codice '
                          . 'monouso (OTP). Il certificato di firma sigillato ne attesta contenuto e integrità.',
            'ups_present' => 'presente', 'ups_absent' => 'non presente',
            'cash_none' => 'nessun incasso', 'cash_checks' => 'assegni',
            'cash_card' => 'carta di credito', 'cash_cash' => 'contanti',
            'type'      => 'Tipo installazione',
            'type_test' => 'Installazione di test (noleggio di prova)',
            'test_end'  => 'Fine periodo di test',
        ];
        $en = [
            'title'      => 'INSTALLATION REPORT',
            'report_n'   => 'Report no.',
            'customer'   => 'Customer',
            'address'    => 'Address',
            'date'       => 'Date sent',
            'start'      => 'Installation start',
            'end'        => 'Installation end',
            'model'      => 'Machine model',
            'serial'     => 'Serial number',
            'ground'     => 'Grounding value',
            'local_ip'   => 'Drawer local IP',
            'public_ip'  => 'Customer public IP',
            'adsl'       => 'ADSL provider',
            'vpn'        => 'Machine VPN address',
            'remote'     => 'Remote assistance ID',
            'ups'        => 'UPS installed',
            'cash'       => 'Cash collected',
            'tech'       => 'Installing technician',
            'notes'      => 'Notes and remarks',
            'ph_ground'  => 'Grounding photo',
            'ph_final'   => 'End-of-installation photos',
            'no_embed'   => 'Attached photo (not embeddable): ',
            'sign_note'  => 'This document is submitted for the customer\'s electronic signature with one-time '
                          . 'code (OTP) verification. The sealed signing certificate attests its content and integrity.',
            'ups_present' => 'present', 'ups_absent' => 'not present',
            'cash_none' => 'no cash collected', 'cash_checks' => 'cheques',
            'cash_card' => 'credit card', 'cash_cash' => 'cash',
            'type'      => 'Installation type',
            'type_test' => 'Test installation (trial rental)',
            'test_end'  => 'End of test period',
        ];
        $this->L = $lang === 'en' ? $en : $it;
        $this->pdf = new Pdf($title, (string)Config::get('app.company_name', 'CRM'));
        $this->pdf->addPage();
    }

    /**
     * @param array $r       the report row (find(): joined with contact columns)
     * @param array $photos  photosWithBytes(): bytes + kind + name per shot
     * @param array $contact the customer row
     */
    public static function build(array $r, array $photos, array $contact, string $lang = 'it'): string
    {
        $company = (string)Config::get('app.company_name', 'CRM');
        $b = new self('Rapporto di installazione #' . (int)$r['id'], $lang);
        $L = $b->L;

        // ---- header ----
        $b->pdf->text(self::M, $b->y + 14, $company, Pdf::FONT_BOLD, 15, [0.1, 0.13, 0.35]);
        $b->pdf->textRight(Pdf::A4_W - self::M, $b->y + 14,
            $L['report_n'] . ' ' . (int)$r['id'], Pdf::FONT_REGULAR, 10, [0.4, 0.4, 0.45]);
        $b->y += 24;
        $b->pdf->text(self::M, $b->y + 12, $L['title'], Pdf::FONT_BOLD, 12, [0, 0, 0]);
        $b->pdf->textRight(Pdf::A4_W - self::M, $b->y + 12, date('d/m/Y'), Pdf::FONT_REGULAR, 10, [0.4, 0.4, 0.45]);
        $b->y += 22;
        $b->pdf->line(self::M, $b->y, Pdf::A4_W - self::M, $b->y, 1.2, [0.1, 0.13, 0.35]);
        $b->y += 14;

        // ---- fields, one per row like the Jotform sheet ----
        $addr = trim(implode(' ', array_filter([
            (string)($contact['address'] ?? ''), (string)($contact['zip'] ?? ''),
            (string)($contact['city'] ?? ''),
            trim((string)($contact['province'] ?? '')) !== '' ? '(' . $contact['province'] . ')' : '',
        ], 'strlen')));
        $dtf = fn(?string $v): string => $v ? date('d/m/Y H:i', strtotime($v)) : '';

        $b->row($L['customer'], (string)($contact['name'] ?? ''));
        if ($addr !== '') {
            $b->row($L['address'], $addr);
        }
        if (($r['report_type'] ?? '') === 'test') {
            $b->row($L['type'], $L['type_test']);
            if (!empty($r['test_end_date'])) {
                $b->row($L['test_end'], date('d/m/Y', strtotime((string)$r['test_end_date'])));
            }
        }
        $b->row($L['start'], $dtf($r['started_at'] ?? null));
        $b->row($L['end'], $dtf($r['finished_at'] ?? null));
        $b->row($L['model'], (string)($r['machine_model'] ?? ''));
        $b->row($L['serial'], (string)($r['serial_number'] ?? ''));
        $b->row($L['ground'], (string)($r['ground_value'] ?? ''));
        $b->row($L['local_ip'], (string)($r['local_ip'] ?? ''));
        $b->row($L['public_ip'], (string)($r['public_ip'] ?? ''));
        $b->row($L['adsl'], (string)($r['adsl_provider'] ?? ''));
        $b->row($L['vpn'], (string)($r['vpn_address'] ?? ''));
        $b->row($L['remote'], (string)($r['remote_assist_id'] ?? ''));
        $b->row($L['ups'], $L[($r['ups_installed'] ?? 'absent') === 'present' ? 'ups_present' : 'ups_absent']);
        $b->row($L['cash'], $L['cash_' . (in_array($r['cash_collected'] ?? '', Reports::CASH_VALUES, true)
            ? $r['cash_collected'] : 'none')]);
        $b->row($L['tech'], (string)($r['technician_name'] ?? ''));

        $notes = trim((string)($r['notes'] ?? ''));
        if ($notes !== '') {
            $b->ensure(40);
            $b->pdf->text(self::M, $b->y + 9, $L['notes'], Pdf::FONT_BOLD, 9.5, [0.35, 0.35, 0.4]);
            $b->y += 16;
            $b->y = $b->paragraphPaged($notes, self::M, Pdf::A4_W - 2 * self::M, 10);
            $b->y += 6;
            $b->pdf->line(self::M, $b->y, Pdf::A4_W - self::M, $b->y);
            $b->y += 10;
        }

        // ---- photos ----
        $ground = array_values(array_filter($photos, fn($p) => $p['kind'] === 'ground'));
        $final  = array_values(array_filter($photos, fn($p) => $p['kind'] !== 'ground'));
        $b->photoSection($L['ph_ground'], $ground);
        $b->photoSection($L['ph_final'], $final);

        // ---- signing note ----
        $b->ensure(46);
        $b->y += 10;
        $b->pdf->line(self::M, $b->y, Pdf::A4_W - self::M, $b->y);
        $b->y += 10;
        $b->y = $b->paragraphPaged($L['sign_note'], self::M, Pdf::A4_W - 2 * self::M, 8.5, [0.4, 0.4, 0.45]);

        return $b->pdf->render();
    }

    // ---- layout helpers --------------------------------------------------------------

    /** Label left, wrapped value right, hairline underneath — skipped when empty. */
    private function row(string $label, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $valX = self::M + self::LABEL_W + 8;
        $valW = Pdf::A4_W - self::M - $valX;
        $lines = Pdf::wrap($value, $valW, Pdf::FONT_REGULAR, 10);
        $rowH = max(18.0, count($lines) * 13.5 + 5);
        $this->ensure($rowH + 2);
        $this->pdf->text(self::M, $this->y + 10, $label, Pdf::FONT_REGULAR, 9.5, [0.35, 0.35, 0.4]);
        $ly = $this->y + 10;
        foreach ($lines as $line) {
            $this->pdf->text($valX, $ly, $line, Pdf::FONT_BOLD, 10);
            $ly += 13.5;
        }
        $this->y += $rowH;
        $this->pdf->line(self::M, $this->y, Pdf::A4_W - self::M, $this->y);
        $this->y += 4;
    }

    /** A titled grid of photos, two per row; unembeddable files listed by name. */
    private function photoSection(string $title, array $photos): void
    {
        if ($photos === []) {
            return;
        }
        // Title + a full-height image row stay together: an orphaned section
        // heading at a page bottom reads like the photos are missing.
        $this->ensure(30 + self::CELL_MAX_H + 8);
        $this->y += 8;
        $this->pdf->text(self::M, $this->y + 10, $title, Pdf::FONT_BOLD, 10.5, [0.1, 0.13, 0.35]);
        $this->y += 18;

        $cellW = (Pdf::A4_W - 2 * self::M - self::CELL_GAP) / 2;
        $col = 0;
        $rowH = 0.0;
        foreach ($photos as $p) {
            $jpeg = self::toJpeg((string)$p['bytes']);
            $dim  = $jpeg !== null ? @getimagesizefromstring($jpeg) : false;
            if ($jpeg === null || $dim === false || (int)$dim[0] < 1 || (int)$dim[1] < 1) {
                // Can't draw it — say it exists, so the sheet is still complete.
                // Close an open image row first or the note lands on the photo.
                if ($col === 1) {
                    $this->y += $rowH + self::CELL_GAP;
                    $col = 0;
                    $rowH = 0.0;
                }
                $this->ensure(16);
                $this->pdf->text(self::M, $this->y + 9, $this->L['no_embed'] . (string)$p['name'],
                    Pdf::FONT_REGULAR, 8.5, [0.5, 0.5, 0.55]);
                $this->y += 14;
                continue;
            }
            $scale = min($cellW / $dim[0], self::CELL_MAX_H / $dim[1], 1.0);
            $w = $dim[0] * $scale;
            $h = $dim[1] * $scale;
            if ($col === 0) {
                $this->ensure($h + 8);
            } elseif ($this->y + $h > Pdf::A4_H - self::FOOT) {
                // Second column doesn't fit beside the first — wrap early.
                $this->y += $rowH + self::CELL_GAP;
                $col = 0;
                $rowH = 0.0;
                $this->ensure($h + 8);
            }
            $x = self::M + $col * ($cellW + self::CELL_GAP);
            $this->pdf->rect($x - 1, $this->y - 1, $w + 2, $h + 2, null, [0.85, 0.85, 0.88], 0.5);
            $this->pdf->jpeg($jpeg, $x, $this->y, $w, $h);
            $rowH = max($rowH, $h);
            if ($col === 1) {
                $this->y += $rowH + self::CELL_GAP;
                $rowH = 0.0;
            }
            $col = 1 - $col;
        }
        if ($col === 1) {
            $this->y += $rowH + self::CELL_GAP;
        }
    }

    /** Wrapped text that survives page breaks (Pdf::paragraph doesn't paginate). */
    private function paragraphPaged(string $s, float $x, float $w, float $size, array $rgb = [0, 0, 0]): float
    {
        $lead = $size * 1.35;
        foreach (Pdf::wrap($s, $w, Pdf::FONT_REGULAR, $size) as $line) {
            $this->ensure($lead + 2);
            $this->pdf->text($x, $this->y + $size, $line, Pdf::FONT_REGULAR, $size, $rgb);
            $this->y += $lead;
        }
        return $this->y;
    }

    private function ensure(float $need): void
    {
        if ($this->y + $need > Pdf::A4_H - self::FOOT) {
            $this->pdf->addPage();
            $this->y = self::M;
        }
    }

    /**
     * The bytes Sign\Pdf can embed: an RGB/gray JPEG. Stored photos already are
     * one when GD ran at upload; anything else is converted here if GD exists
     * now, and otherwise reported as unembeddable.
     */
    private static function toJpeg(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info !== false && $info[2] === IMAGETYPE_JPEG && (int)($info['channels'] ?? 3) !== 4) {
            return $bytes;
        }
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }
        ob_start();
        imagejpeg($img, null, 80);
        $jpeg = (string)ob_get_clean();
        imagedestroy($img);
        return $jpeg !== '' ? $jpeg : null;
    }
}
