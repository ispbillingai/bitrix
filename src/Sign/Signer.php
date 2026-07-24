<?php
declare(strict_types=1);

namespace Glue\Sign;

use Glue\Config;

/**
 * Turns a verified signing act into the artifact you would actually hand to a
 * lawyer: one PDF that states what was signed, by whom, how they were
 * identified, and when — with the original file embedded inside it and the
 * whole thing sealed by a single CAdES signature.
 *
 * Why a container rather than a stamp on the customer's own PDF: signing an
 * arbitrary incoming PDF means rewriting its cross-reference table, and a
 * rewrite that goes subtly wrong produces a file that *looks* signed. Writing
 * our own container means we control every byte, the original travels inside it
 * untouched, and its SHA-256 is printed on the face of the certificate — so the
 * two can always be checked against each other.
 */
final class Signer
{
    private const MARGIN   = 48.0;
    private const CONTENT  = Pdf::A4_W - 2 * self::MARGIN;
    private const LABEL_W  = 118.0;

    /**
     * Build and seal the certificate for a signed document.
     *
     * @param array  $doc       the sign_documents row
     * @param array  $sig       the sign_signatures row
     * @param string $original  the original file's bytes
     * @param string $auditHead the audit chain head at signing time
     *
     * @return array{pdf:string, sha256:string, cert:array, signing_time:int, tsa_time:?int}
     */
    public static function produce(array $doc, array $sig, string $original, string $auditHead): array
    {
        $cert = Certificate::load();
        $lang = self::lang($doc['lang'] ?? null);
        $time = time();

        $pdf = self::layout($doc, $sig, $original, $auditHead, $cert, $lang, $time);
        [$sealed, $tsaTime] = self::seal($pdf, $cert, $time);

        return [
            'pdf'          => $sealed,
            'sha256'       => hash('sha256', $sealed),
            'cert'         => $cert->summary(),
            'signing_time' => $time,
            'tsa_time'     => $tsaTime,
        ];
    }

    // ---- sealing ------------------------------------------------------------------

    /**
     * Fill the /ByteRange and /Contents placeholders left by Pdf::render().
     *
     * The signature covers the whole file except the gap that holds the
     * signature itself — that is what /ByteRange says, and it is why both
     * placeholders are fixed width: writing the real values back must not move
     * one byte, or every offset in the cross-reference table would be wrong.
     *
     * @return array{0:string, 1:?int} sealed PDF, TSA time if one was obtained
     */
    public static function seal(string $pdf, Certificate $cert, int $time): array
    {
        $hexLen = Pdf::SIG_BYTES * 2;

        $marker = strpos($pdf, '/Contents <');
        if ($marker === false) {
            throw new \RuntimeException('the rendered PDF has no signature placeholder');
        }
        $gapStart = $marker + strlen('/Contents ');   // the '<'
        $gapEnd   = $gapStart + $hexLen + 2;          // one past the '>'
        $total    = strlen($pdf);

        $brStart = strpos($pdf, '/ByteRange [');
        $brEnd   = $brStart === false ? false : strpos($pdf, ']', $brStart);
        if ($brStart === false || $brEnd === false) {
            throw new \RuntimeException('the rendered PDF has no /ByteRange placeholder');
        }
        $brLen = $brEnd - $brStart + 1;
        $real  = sprintf('/ByteRange [0 %d %d %d]', $gapStart, $gapEnd, $total - $gapEnd);
        if (strlen($real) > $brLen) {
            throw new \RuntimeException('/ByteRange placeholder is too small');
        }
        // Trailing spaces sit inside the dictionary, where PDF ignores them.
        $pdf = substr_replace($pdf, str_pad($real, $brLen, ' '), $brStart, $brLen);

        $covered = substr($pdf, 0, $gapStart) . substr($pdf, $gapEnd);

        $tsaTime = null;
        $cms = Cms::sign($covered, $cert, $time, static function (string $signature) use (&$tsaTime): ?string {
            $token = Timestamp::token($signature);
            if ($token !== null) {
                $tsaTime = Timestamp::tokenTime($token);
            }
            return $token;
        });

        $hex = bin2hex($cms);
        if (strlen($hex) > $hexLen) {
            throw new \RuntimeException(sprintf(
                'signature needs %d bytes but only %d are reserved — raise Pdf::SIG_BYTES',
                strlen($cms), Pdf::SIG_BYTES
            ));
        }
        // Zero padding after the DER is normal: parsers read the declared length.
        $pdf = substr_replace($pdf, str_pad($hex, $hexLen, '0'), $gapStart + 1, $hexLen);

        return [$pdf, $tsaTime];
    }

    // ---- layout -------------------------------------------------------------------

    private static function layout(array $doc, array $sig, string $original, string $auditHead,
                                   Certificate $cert, string $lang, int $time): string
    {
        $L = self::labels($lang);
        $company = (string)Config::get('app.company_name', 'CRM');

        $pdf = new Pdf($L['title'] . ' — ' . (string)$doc['title'], $company);
        $pdf->addPage();

        $x = self::MARGIN;
        $y = self::MARGIN;

        // ---- header
        $pdf->text($x, $y + 10, $company, Pdf::FONT_BOLD, 12, [0.10, 0.13, 0.35]);
        $pdf->textRight(Pdf::A4_W - self::MARGIN, $y + 10,
            $L['reference'] . ' ' . strtoupper((string)$doc['uid']), Pdf::FONT_MONO, 8, [0.4, 0.4, 0.45]);
        $y += 26;
        $pdf->text($x, $y + 12, $L['title'], Pdf::FONT_BOLD, 16, [0.10, 0.13, 0.35]);
        $y += 22;
        $pdf->line($x, $y, Pdf::A4_W - self::MARGIN, $y, 1.2, [0.36, 0.42, 1]);
        $y += 16;

        $y = $pdf->paragraph($x, $y + 8, self::CONTENT, $L['intro'], Pdf::FONT_REGULAR, 8.5, 11.5, [0.25, 0.25, 0.3]);
        $y += 8;

        // ---- the document
        $y = self::section($pdf, $x, $y, $L['s_document']);
        $y = self::row($pdf, $x, $y, $L['f_title'], (string)$doc['title']);
        $y = self::row($pdf, $x, $y, $L['f_file'], (string)$doc['orig_name']);
        $y = self::row($pdf, $x, $y, $L['f_size'], number_format((float)$doc['orig_bytes'], 0, ',', '.') . ' bytes');
        $y = self::row($pdf, $x, $y, $L['f_hash'], strtoupper((string)$doc['orig_sha256']), true);
        $y += 6;

        // ---- who signed
        $y = self::section($pdf, $x, $y, $L['s_signer']);
        $y = self::row($pdf, $x, $y, $L['f_name'], (string)$sig['signer_name']);
        if (!empty($sig['signer_email'])) {
            $y = self::row($pdf, $x, $y, $L['f_email'], (string)$sig['signer_email']);
        }
        if (!empty($sig['signer_phone'])) {
            $y = self::row($pdf, $x, $y, $L['f_phone'], (string)$sig['signer_phone']);
        }
        $y += 6;

        // ---- how they were identified
        $y = self::section($pdf, $x, $y, $L['s_act']);
        $y = self::row($pdf, $x, $y, $L['f_method'], $L['v_method']);
        $y = self::row($pdf, $x, $y, $L['f_sent_to'], (string)($sig['otp_sent_to'] ?? '—'));
        $y = self::row($pdf, $x, $y, $L['f_sent_at'], self::stamp($sig['otp_sent_at'] ?? null));
        $y = self::row($pdf, $x, $y, $L['f_verified'], self::stamp($sig['signed_at'] ?? null));
        $y = self::row($pdf, $x, $y, $L['f_ip'], (string)($sig['ip'] ?? '—'));
        $y = self::row($pdf, $x, $y, $L['f_device'], (string)($sig['user_agent'] ?? '—'));
        $y = self::row($pdf, $x, $y, $L['f_consent'], $sig['consent'] ? $L['v_consent'] : $L['v_no_consent']);
        $y += 6;

        // ---- the seal
        $y = self::section($pdf, $x, $y, $L['s_seal']);
        $y = self::row($pdf, $x, $y, $L['f_cert'], $cert->subjectText());
        $y = self::row($pdf, $x, $y, $L['f_issuer'], $cert->issuerText());
        $y = self::row($pdf, $x, $y, $L['f_serial'], $cert->serialHex(), true);
        $y = self::row($pdf, $x, $y, $L['f_cert_hash'], strtoupper($cert->fingerprint()), true);
        $y = self::row($pdf, $x, $y, $L['f_sealed'], self::stamp(date('Y-m-d H:i:s', $time)));
        $y = self::row($pdf, $x, $y, $L['f_tsa'], self::tsaText($L, $doc));
        $y = self::row($pdf, $x, $y, $L['f_chain'], strtoupper($auditHead), true);
        $y += 6;

        // ---- how to check it
        $y = self::section($pdf, $x, $y, $L['s_verify']);
        $verifyUrl = self::verifyUrl((string)$doc['uid']);
        $y = self::row($pdf, $x, $y, $L['f_url'], $verifyUrl, true);
        $y = $pdf->paragraph($x, $y + 4, self::CONTENT, $L['verify_help'], Pdf::FONT_REGULAR, 7.5, 10, [0.35, 0.35, 0.4]);

        if ($cert->isSelfSigned()) {
            $y += 6;
            $pdf->rect($x, $y, self::CONTENT, 30, [1, 0.97, 0.9], [0.85, 0.6, 0.1]);
            $pdf->paragraph($x + 8, $y + 12, self::CONTENT - 16, $L['self_signed_note'],
                Pdf::FONT_REGULAR, 7.5, 9.5, [0.45, 0.3, 0]);
            $y += 36;
        }

        // ---- the visible signature block, bottom right of page one
        $boxW = 210.0;
        $boxH = 62.0;
        $boxY = Pdf::A4_H - self::MARGIN - $boxH;
        $pdf->signature(
            [
                'time'     => $time,
                'name'     => $cert->commonName() ?: $company,
                'reason'   => $L['reason'] . ' ' . (string)$doc['title'],
                'location' => $company,
            ],
            [Pdf::A4_W - self::MARGIN - $boxW, $boxY, $boxW, $boxH],
            [
                'line1' => $L['ap_sealed'],
                'line2' => $cert->commonName() ?: $company,
                'line3' => date('d/m/Y H:i', $time) . ' · ' . $L['ap_ref'] . ' ' . strtoupper((string)$doc['uid']),
                'line4' => $L['ap_signer'] . ' ' . (string)$sig['signer_name'],
            ]
        );

        // ---- page two: the operation log
        self::auditPage($pdf, $L, (int)$doc['id'], $auditHead);

        // ---- the original, carried inside the sealed file
        $max = (int)Config::get('sign.embed_max_bytes', 8 * 1024 * 1024);
        if ($original !== '' && strlen($original) <= $max) {
            $pdf->attach((string)$doc['orig_name'], $original, (string)$doc['orig_mime'], $L['attach_desc']);
        }

        return $pdf->render();
    }

    /** The audit trail, printed so the paper and the database can be compared. */
    private static function auditPage(Pdf $pdf, array $L, int $documentId, string $auditHead): void
    {
        $rows = Audit::forDocument($documentId);
        if (!$rows) {
            return;
        }
        $pdf->addPage();
        $x = self::MARGIN;
        $y = self::MARGIN;

        $pdf->text($x, $y + 10, $L['s_log'], Pdf::FONT_BOLD, 13, [0.10, 0.13, 0.35]);
        $y += 20;
        $pdf->line($x, $y, Pdf::A4_W - self::MARGIN, $y, 1.2, [0.36, 0.42, 1]);
        $y += 14;
        $y = $pdf->paragraph($x, $y + 8, self::CONTENT, $L['log_intro'], Pdf::FONT_REGULAR, 8, 10.5, [0.25, 0.25, 0.3]);
        $y += 8;

        $cols = [$x, $x + 26, $x + 118, $x + 236, $x + 330];
        $pdf->rect($x, $y - 2, self::CONTENT, 15, [0.94, 0.95, 1]);
        foreach ([$L['c_seq'], $L['c_when'], $L['c_event'], $L['c_actor'], $L['c_hash']] as $i => $head) {
            $pdf->text($cols[$i] + 3, $y + 9, $head, Pdf::FONT_BOLD, 7.5, [0.2, 0.24, 0.5]);
        }
        $y += 17;

        foreach ($rows as $r) {
            if ($y > Pdf::A4_H - self::MARGIN - 20) {
                $pdf->addPage();
                $y = self::MARGIN;
            }
            $actor = trim((string)($r['actor_label'] ?? '')) ?: (string)$r['actor_type'];
            $pdf->text($cols[0] + 3, $y + 8, (string)$r['seq'], Pdf::FONT_MONO, 7);
            $pdf->text($cols[1] + 3, $y + 8, substr((string)$r['occurred_at'], 0, 19), Pdf::FONT_MONO, 7);
            $pdf->text($cols[2] + 3, $y + 8, (string)$r['event'], Pdf::FONT_REGULAR, 7);
            $pdf->text($cols[3] + 3, $y + 8, mb_substr($actor, 0, 18), Pdf::FONT_REGULAR, 7);
            $pdf->text($cols[4] + 3, $y + 8, strtoupper(substr((string)$r['hash'], 0, 32)) . '…', Pdf::FONT_MONO, 6.5,
                [0.35, 0.35, 0.4]);
            $pdf->line($x, $y + 11, Pdf::A4_W - self::MARGIN, $y + 11, 0.3, [0.88, 0.88, 0.92]);
            $y += 13;
        }

        $y += 8;
        $pdf->text($x, $y + 8, $L['f_chain'], Pdf::FONT_BOLD, 7.5, [0.2, 0.24, 0.5]);
        $pdf->paragraph($x, $y + 20, self::CONTENT, strtoupper($auditHead), Pdf::FONT_MONO, 7.5, 10);
    }

    // ---- small layout helpers -------------------------------------------------------

    private static function section(Pdf $pdf, float $x, float $y, string $title): float
    {
        $pdf->text($x, $y + 9, strtoupper($title), Pdf::FONT_BOLD, 8.5, [0.36, 0.42, 1]);
        $pdf->line($x, $y + 13, Pdf::A4_W - self::MARGIN, $y + 13, 0.5, [0.85, 0.87, 0.95]);
        return $y + 22;
    }

    /** A label/value line; $mono is for hashes and URLs, which wrap mid-token. */
    private static function row(Pdf $pdf, float $x, float $y, string $label, string $value, bool $mono = false): float
    {
        $pdf->text($x, $y + 8, $label, Pdf::FONT_REGULAR, 8, [0.42, 0.42, 0.48]);
        $font = $mono ? Pdf::FONT_MONO : Pdf::FONT_REGULAR;
        $size = $mono ? 7.5 : 8.5;
        $end  = $pdf->paragraph($x + self::LABEL_W, $y + 8, self::CONTENT - self::LABEL_W,
            $value !== '' ? $value : '—', $font, $size, 10.5, [0.08, 0.08, 0.12]);
        return max($y + 13, $end + 1);
    }

    private static function stamp(?string $sqlTime): string
    {
        if (!$sqlTime) {
            return '—';
        }
        $ts = strtotime($sqlTime);
        return $ts ? date('d/m/Y H:i:s', $ts) . ' (' . date('T', $ts) . ')' : $sqlTime;
    }

    private static function tsaText(array $L, array $doc): string
    {
        if (empty($doc['tsa_time'])) {
            return $L['v_no_tsa'];
        }
        return self::stamp((string)$doc['tsa_time']) . ' — ' . (string)($doc['tsa_url'] ?? '');
    }

    public static function verifyUrl(string $uid): string
    {
        $base = rtrim((string)Config::get('app.base_url', ''), '/');
        return $base . '/verify.php?c=' . $uid;
    }

    private static function lang(?string $code): string
    {
        $code = strtolower(substr((string)$code, 0, 2));
        return $code === 'en' ? 'en' : 'it';
    }

    /**
     * Certificate wording. It lives here rather than in lang/ because every
     * string is positioned by the layout right next to it — moving them apart
     * makes both harder to change.
     */
    private static function labels(string $lang): array
    {
        $en = [
            'title'      => 'CERTIFICATE OF ELECTRONIC SIGNATURE',
            'reference'  => 'REF',
            'intro'      => 'This certificate records an electronic signature applied to the document described below. '
                          . 'The signer was identified by a one-time code sent to the contact details held for them, and the '
                          . 'document was sealed immediately afterwards. The original file is embedded in this PDF and its '
                          . 'SHA-256 fingerprint is printed below: if either changes, the seal on this certificate breaks.',
            's_document' => 'Document',
            's_signer'   => 'Signer',
            's_act'      => 'How the signer was identified',
            's_seal'     => 'Seal',
            's_verify'   => 'Verification',
            's_log'      => 'Operation log',
            'f_title'    => 'Title', 'f_file' => 'File', 'f_size' => 'Size', 'f_hash' => 'SHA-256 (original)',
            'f_name'     => 'Name', 'f_email' => 'Email', 'f_phone' => 'Phone',
            'f_method'   => 'Method', 'f_sent_to' => 'Code sent to', 'f_sent_at' => 'Code sent at',
            'f_verified' => 'Code verified at', 'f_ip' => 'IP address', 'f_device' => 'Device',
            'f_consent'  => 'Consent',
            'f_cert'     => 'Certificate', 'f_issuer' => 'Issuer', 'f_serial' => 'Serial',
            'f_cert_hash' => 'Certificate SHA-256', 'f_sealed' => 'Sealed at', 'f_tsa' => 'Time stamp',
            'f_chain'    => 'Log chain hash', 'f_url' => 'Verify at',
            'v_method'   => 'One-time code (OTP) delivered to the signer',
            'v_consent'  => 'The signer confirmed they had read the document and intended to sign it.',
            'v_no_consent' => 'Not recorded',
            'v_no_tsa'   => 'Not applied — the sealing time is this system\'s own clock',
            'verify_help' => 'Open the address above, or enter the reference at the verification page, to re-check the '
                           . 'fingerprint of the original file and the operation log behind this signature.',
            'self_signed_note' => 'This seal was made with a certificate issued by this system itself. It proves the '
                                . 'document has not been altered since sealing, but it carries no external accreditation. '
                                . 'Install a qualified (eIDAS) certificate to add one.',
            'reason'     => 'Electronic signature of',
            'ap_sealed'  => 'Electronically sealed',
            'ap_ref'     => 'ref',
            'ap_signer'  => 'Signed by',
            'attach_desc' => 'The original document, exactly as it was presented for signature',
            'log_intro'  => 'Every step is recorded in an append-only log. Each entry carries the hash of the entry '
                          . 'before it, so a changed, inserted or deleted entry breaks the chain and can be located.',
            'c_seq'      => '#', 'c_when' => 'When (UTC offset applies)', 'c_event' => 'Event',
            'c_actor'    => 'Actor', 'c_hash' => 'Entry hash',
        ];

        $it = [
            'title'      => 'CERTIFICATO DI FIRMA ELETTRONICA',
            'reference'  => 'RIF',
            'intro'      => 'Questo certificato attesta una firma elettronica apposta al documento descritto di seguito. '
                          . 'Il firmatario è stato identificato tramite un codice monouso inviato ai recapiti registrati e '
                          . 'il documento è stato sigillato subito dopo. Il file originale è incorporato in questo PDF e la '
                          . 'sua impronta SHA-256 è riportata qui sotto: se l\'uno o l\'altra cambiano, il sigillo di questo '
                          . 'certificato non è più valido.',
            's_document' => 'Documento',
            's_signer'   => 'Firmatario',
            's_act'      => 'Come è stato identificato il firmatario',
            's_seal'     => 'Sigillo',
            's_verify'   => 'Verifica',
            's_log'      => 'Registro delle operazioni',
            'f_title'    => 'Titolo', 'f_file' => 'File', 'f_size' => 'Dimensione', 'f_hash' => 'SHA-256 (originale)',
            'f_name'     => 'Nome', 'f_email' => 'Email', 'f_phone' => 'Telefono',
            'f_method'   => 'Metodo', 'f_sent_to' => 'Codice inviato a', 'f_sent_at' => 'Codice inviato il',
            'f_verified' => 'Codice verificato il', 'f_ip' => 'Indirizzo IP', 'f_device' => 'Dispositivo',
            'f_consent'  => 'Consenso',
            'f_cert'     => 'Certificato', 'f_issuer' => 'Emittente', 'f_serial' => 'Numero di serie',
            'f_cert_hash' => 'SHA-256 del certificato', 'f_sealed' => 'Sigillato il', 'f_tsa' => 'Marca temporale',
            'f_chain'    => 'Hash della catena del registro', 'f_url' => 'Verifica su',
            'v_method'   => 'Codice monouso (OTP) inviato al firmatario',
            'v_consent'  => 'Il firmatario ha confermato di aver letto il documento e di volerlo firmare.',
            'v_no_consent' => 'Non registrato',
            'v_no_tsa'   => 'Non applicata — l\'ora del sigillo è quella di questo sistema',
            'verify_help' => 'Apri l\'indirizzo qui sopra, oppure inserisci il riferimento nella pagina di verifica, per '
                           . 'ricontrollare l\'impronta del file originale e il registro delle operazioni di questa firma.',
            'self_signed_note' => 'Questo sigillo è stato apposto con un certificato emesso dal sistema stesso. Dimostra '
                                . 'che il documento non è stato alterato dopo il sigillo, ma non ha alcun accreditamento '
                                . 'esterno. Installa un certificato qualificato (eIDAS) per aggiungerlo.',
            'reason'     => 'Firma elettronica di',
            'ap_sealed'  => 'Sigillato elettronicamente',
            'ap_ref'     => 'rif',
            'ap_signer'  => 'Firmato da',
            'attach_desc' => 'Il documento originale, esattamente come presentato alla firma',
            'log_intro'  => 'Ogni passaggio è registrato in un log a sola aggiunta. Ogni voce contiene l\'hash della voce '
                          . 'precedente: una voce modificata, inserita o cancellata spezza la catena e viene individuata.',
            'c_seq'      => '#', 'c_when' => 'Quando', 'c_event' => 'Evento',
            'c_actor'    => 'Attore', 'c_hash' => 'Hash della voce',
        ];

        return $lang === 'en' ? $en : $it;
    }
}
