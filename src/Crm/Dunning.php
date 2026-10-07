<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Notify\Pec;
use Glue\Reminder\Scheduler;

/**
 * The sollecito: what a customer owes, and the letter that says so (079).
 *
 * "Ho bisogno di inviare una mail certificata nel caso il cliente non paghi una
 *  rata di assistenza, ad esempio."
 *
 * Two kinds of debt end up in the same letter, because to the customer they are
 * one conversation: invoices Sibill still reports as unpaid, and the rates of a
 * support contract SmallPay could not collect. This class gathers both and
 * drafts the text; sending is Notify\Pec's job.
 *
 * The draft is a DRAFT. A sollecito by PEC is a legal act — it is what puts the
 * customer in mora and starts the interest running — so nothing here goes out
 * on a timer: the office reads it, edits it, and presses send. What the CRM
 * does is make sure the numbers in it are right, which is the part a person
 * gets wrong at eight in the evening.
 */
final class Dunning
{
    /** How long the letter gives them, unless the office changes it. */
    public const DAYS = 15;

    /**
     * The edited text as the message that actually leaves. Plain is what the
     * office typed; the HTML part only keeps the line breaks, because a PEC
     * read in webmail should look like the letter it is.
     */
    public static function html(string $text): string
    {
        return '<div style="font:14px/1.6 system-ui,Segoe UI,Arial,sans-serif;white-space:pre-wrap">'
             . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /**
     * What this customer owes us right now.
     *
     * @return array{invoices:array<int,array>, rates:array<int,array>, total:float,
     *               oldest_due:?string, currency:string}
     */
    public static function debt(int $contactId): array
    {
        $db  = Db::pdo();
        $out = ['invoices' => [], 'rates' => [], 'total' => 0.0, 'oldest_due' => null, 'currency' => 'EUR'];

        // ---- invoices we issued and are still open ----
        $c = Contacts::find($contactId);
        if ($c) {
            $vat  = trim((string)($c['vat_number'] ?? ''));
            $cond = 'contact_id = ?';
            $args = [$contactId];
            if ($vat !== '') {
                // Same reach as the customer card: linked invoices, plus any
                // issued against this VAT number before the link existed.
                $keys = \Glue\Sibill\Invoices::vatKeys($vat);
                if ($keys) {
                    $cond .= ' OR counterpart_vat IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
                    array_push($args, ...$keys);
                }
            }
            $q = $db->prepare(
                "SELECT * FROM sibill_invoices
                  WHERE ($cond) AND direction = 'ISSUED' AND pay_state IN ('unpaid','partial','unknown')
                    AND open_amount > 0
                  ORDER BY due_date IS NULL, due_date ASC, id ASC LIMIT 100"
            );
            $q->execute($args);
            foreach ($q->fetchAll() ?: [] as $i) {
                $out['invoices'][] = $i;
                $out['total'] += (float)$i['open_amount'];
                $due = (string)($i['due_date'] ?? '');
                if ($due !== '' && ($out['oldest_due'] === null || $due < $out['oldest_due'])) {
                    $out['oldest_due'] = $due;
                }
            }
        }

        // ---- support-contract rates that bounced ----
        $q = $db->prepare(
            "SELECT ch.*, ct.description AS contract_desc, ct.currency AS contract_currency
               FROM payment_charges ch
               JOIN payment_contracts ct ON ct.id = ch.contract_id
              WHERE ct.contact_id = ? AND ch.status IN ('failed','pending')
                AND (ch.due_date IS NULL OR ch.due_date <= CURDATE())
              ORDER BY ch.due_date ASC, ch.id ASC LIMIT 50"
        );
        $q->execute([$contactId]);
        foreach ($q->fetchAll() ?: [] as $r) {
            $out['rates'][] = $r;
            $out['total']  += ((int)$r['amount_cents']) / 100;
            $due = (string)($r['due_date'] ?? '');
            if ($due !== '' && ($out['oldest_due'] === null || $due < $out['oldest_due'])) {
                $out['oldest_due'] = $due;
            }
            $cur = (string)($r['currency'] ?: ($r['contract_currency'] ?? ''));
            if ($cur !== '') {
                $out['currency'] = $cur;
            }
        }

        return $out;
    }

    /**
     * The letter, ready to be read and edited.
     *
     * Italian by default because a sollecito is addressed to an Italian company
     * and will be read by its accountant; the customer's own language decides,
     * like everywhere else.
     *
     * @return array{to:string, subject:string, body:string, debt:array, can_send:bool, why:?string}
     */
    public static function draft(int $contactId, int $days = self::DAYS): array
    {
        $c    = Contacts::find($contactId) ?? [];
        $debt = self::debt($contactId);
        $to   = trim((string)($c['pec'] ?? ''));
        $it   = \Glue\Reminder\Templates::lang((string)($c['lang'] ?? 'it')) !== 'en';

        $why = null;
        if (!Pec::enabled()) {
            $why = 'pec_off';
        } elseif ($to === '') {
            $why = 'no_pec';
        } elseif (!Pec::isPec($to)) {
            $why = 'not_a_pec';
        }

        $money = static fn(float $n): string => number_format($n, 2, ',', '.');
        $date  = static fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
        $who   = trim((string)($c['company'] ?? '')) ?: trim((string)($c['name'] ?? ''));
        $cur   = $debt['currency'];

        $lines = [];
        foreach ($debt['invoices'] as $i) {
            $lines[] = ($it ? 'Fattura n. ' : 'Invoice no. ') . (string)($i['number'] ?? $i['id'])
                . ($i['creation_date'] ? ($it ? ' del ' : ' of ') . $date((string)$i['creation_date']) : '')
                . ' — ' . $cur . ' ' . $money((float)$i['open_amount'])
                . ($i['due_date'] ? ($it ? ', scaduta il ' : ', due ') . $date((string)$i['due_date']) : '');
        }
        foreach ($debt['rates'] as $r) {
            $lines[] = ($it ? 'Rata assistenza' : 'Support instalment')
                . ($r['seq'] ?? null ? ' n. ' . (int)$r['seq'] : '')
                . ($r['contract_desc'] ? ' (' . (string)$r['contract_desc'] . ')' : '')
                . ' — ' . $cur . ' ' . $money(((int)$r['amount_cents']) / 100)
                . ($r['due_date'] ? ($it ? ', scaduta il ' : ', due ') . $date((string)$r['due_date']) : '');
        }

        $bank    = Scheduler::bankVars($it ? 'it' : 'en');
        $company = trim((string)Config::get('app.company_name', ''));
        $vatUs   = trim((string)Config::get('app.vat_number', ''));

        $subject = $it
            ? 'Sollecito di pagamento' . ($who !== '' ? ' — ' . $who : '')
            : 'Payment reminder' . ($who !== '' ? ' — ' . $who : '');

        $p = [];
        $p[] = $it ? 'Spett.le ' . ($who ?: 'Cliente') . ',' : 'Dear ' . ($who ?: 'Customer') . ',';
        $p[] = $it
            ? 'dalle nostre scritture contabili risultano ad oggi non saldate le seguenti posizioni:'
            : 'our records show the following amounts as still unpaid:';
        $p[] = $lines
            ? '- ' . implode("\n- ", $lines)
            : ($it ? '(nessun importo aperto: controllare prima di inviare)'
                   : '(nothing open: check before sending)');
        $p[] = ($it ? 'Totale dovuto: ' : 'Total due: ') . $cur . ' ' . $money($debt['total']);
        $p[] = $it
            ? 'La invitiamo a provvedere al pagamento entro ' . $days
              . ' giorni dal ricevimento della presente'
              . ($bank['bank_details'] !== '' ? ', mediante bonifico — ' . $bank['bank_details'] : '') . '.'
            : 'Please settle within ' . $days . ' days of receiving this message'
              . ($bank['bank_details'] !== '' ? ', by bank transfer — ' . $bank['bank_details'] : '') . '.';
        $p[] = $it
            ? 'Decorso inutilmente tale termine, ci vedremo costretti a sospendere il servizio di assistenza e ad '
              . 'attivare le procedure di recupero del credito, con addebito degli interessi di mora e delle spese '
              . 'ai sensi del D.Lgs. 231/2002.'
            : 'If the deadline passes, we will have to suspend the support service and start debt recovery, with '
              . 'late-payment interest and costs charged as the law provides.';
        $p[] = $it
            ? 'Qualora il pagamento sia già stato effettuato, La preghiamo di considerare la presente come non '
              . 'inviata e di trasmetterci la contabile.'
            : 'If you have already paid, please disregard this message and send us the transfer receipt.';
        $p[] = ($it ? 'Distinti saluti,' : 'Kind regards,') . "\n"
             . trim($company . ($vatUs !== '' ? ' — P.IVA ' . $vatUs : ''));

        return [
            'to'       => $to,
            'subject'  => $subject,
            'body'     => implode("\n\n", $p),
            'debt'     => $debt,
            'can_send' => $why === null,
            'why'      => $why,
        ];
    }
}
