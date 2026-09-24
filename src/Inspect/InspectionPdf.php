<?php
declare(strict_types=1);

namespace Glue\Inspect;

use Glue\Config;
use Glue\Install\ReportPdf;
use Glue\Sign\Pdf;

/**
 * The survey sheet the customer is asked to sign.
 *
 * Extends the installation report's builder rather than repeating it: the page
 * furniture — the label/value rows, the photo grid, the page breaks that keep a
 * section heading with its first image — is the same sheet and was already
 * proved on real reports. Only the fields and the wording differ, which is what
 * this class is.
 */
final class InspectionPdf extends ReportPdf
{
    /** @var array<string,string> */
    private array $T;

    private function __construct(string $title, string $lang)
    {
        parent::__construct($title, $lang);
        $it = [
            'title'    => 'RAPPORTO DI SOPRALLUOGO',
            'report_n' => 'Sopralluogo n.',
            'customer' => 'Cliente',
            'address'  => 'Indirizzo',
            'site'     => 'Luogo del sopralluogo',
            'date'     => 'Data del sopralluogo',
            'system'   => 'Tipo di impianto',
            'model'    => 'Modello macchina',
            'serial'   => 'Numero di serie',
            'tech'     => 'Tecnico',
            'findings' => 'Rilievi sullo stato dell\'impianto',
            'works'    => 'Interventi necessari',
            'notes'    => 'Note',
            'photos'   => 'Foto del sopralluogo',
            'sign_note' => 'Documento sottoposto a firma elettronica del cliente con verifica tramite codice '
                         . 'monouso (OTP). Il certificato di firma sigillato ne attesta contenuto e integrità. '
                         . 'Il parere tecnico sullo stato dell\'impianto viene trasmesso separatamente.',
        ];
        $en = [
            'title'    => 'SITE SURVEY REPORT',
            'report_n' => 'Survey no.',
            'customer' => 'Customer',
            'address'  => 'Address',
            'site'     => 'Survey location',
            'date'     => 'Survey date',
            'system'   => 'System type',
            'model'    => 'Machine model',
            'serial'   => 'Serial number',
            'tech'     => 'Technician',
            'findings' => 'Findings on the state of the system',
            'works'    => 'Work required',
            'notes'    => 'Notes',
            'photos'   => 'Survey photos',
            'sign_note' => 'This document is submitted for the customer\'s electronic signature with one-time '
                         . 'code (OTP) verification. The sealed signing certificate attests its content and '
                         . 'integrity. The technical opinion on the state of the system is sent separately.',
        ];
        $this->T = $lang === 'en' ? $en : $it;
    }

    /**
     * @param array $r       the survey row
     * @param array $photos  photosWithBytes(): bytes + name per shot
     * @param array $contact the customer row
     */
    public static function build(array $r, array $photos, array $contact, string $lang = 'it'): string
    {
        $company = (string)Config::get('app.company_name', 'CRM');
        $b = new self('Rapporto di sopralluogo #' . (int)$r['id'], $lang);
        $T = $b->T;

        // ---- header ----
        $b->pdf->text(self::M, $b->y + 14, $company, Pdf::FONT_BOLD, 15, [0.1, 0.13, 0.35]);
        $b->pdf->textRight(Pdf::A4_W - self::M, $b->y + 14,
            $T['report_n'] . ' ' . (int)$r['id'], Pdf::FONT_REGULAR, 10, [0.4, 0.4, 0.45]);
        $b->y += 24;
        $b->pdf->text(self::M, $b->y + 12, $T['title'], Pdf::FONT_BOLD, 12, [0, 0, 0]);
        $b->pdf->textRight(Pdf::A4_W - self::M, $b->y + 12, date('d/m/Y'), Pdf::FONT_REGULAR, 10, [0.4, 0.4, 0.45]);
        $b->y += 22;
        $b->pdf->line(self::M, $b->y, Pdf::A4_W - self::M, $b->y, 1.2, [0.1, 0.13, 0.35]);
        $b->y += 14;

        // ---- fields ----
        $addr = trim(implode(' ', array_filter([
            (string)($contact['address'] ?? ''), (string)($contact['zip'] ?? ''),
            (string)($contact['city'] ?? ''),
            trim((string)($contact['province'] ?? '')) !== '' ? '(' . $contact['province'] . ')' : '',
        ], 'strlen')));

        $b->row($T['customer'], (string)($contact['name'] ?? ''));
        $b->row($T['address'], $addr);
        // Only when it differs from the registry address: a survey is often at a
        // second site, and repeating the same line twice reads like a mistake.
        $site = trim((string)($r['site_address'] ?? ''));
        if ($site !== '' && $site !== $addr) {
            $b->row($T['site'], $site);
        }
        $b->row($T['date'], !empty($r['inspected_at']) ? date('d/m/Y H:i', strtotime((string)$r['inspected_at'])) : '');
        $b->row($T['system'], (string)($r['system_type'] ?? ''));
        $b->row($T['model'], (string)($r['machine_model'] ?? ''));
        $b->row($T['serial'], (string)($r['serial_number'] ?? ''));
        $b->row($T['tech'], (string)($r['technician_name'] ?? ''));

        // ---- the body of the survey ----
        foreach (['findings' => 'findings', 'works_needed' => 'works', 'notes' => 'notes'] as $field => $key) {
            $b->block($T[$key], (string)($r[$field] ?? ''));
        }

        $b->photoSection($T['photos'], $photos);

        // ---- signing note ----
        $b->ensure(46);
        $b->y += 10;
        $b->pdf->line(self::M, $b->y, Pdf::A4_W - self::M, $b->y);
        $b->y += 10;
        $b->y = $b->paragraphPaged($T['sign_note'], self::M, Pdf::A4_W - 2 * self::M, 8.5, [0.4, 0.4, 0.45]);

        return $b->pdf->render();
    }

    /** A headed paragraph — the survey is mostly prose, unlike an install sheet. */
    private function block(string $title, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->ensure(44);
        $this->pdf->text(self::M, $this->y + 9, $title, Pdf::FONT_BOLD, 9.5, [0.35, 0.35, 0.4]);
        $this->y += 16;
        $this->y = $this->paragraphPaged($text, self::M, Pdf::A4_W - 2 * self::M, 10);
        $this->y += 6;
        $this->pdf->line(self::M, $this->y, Pdf::A4_W - self::M, $this->y);
        $this->y += 10;
    }
}
