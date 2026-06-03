<?php
declare(strict_types=1);

namespace Glue\Lead;

use Glue\Bitrix\Client;
use Glue\Config;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Tracking\Repo;

/**
 * Requirement #1 + #2: take a normalised lead from ANY external source
 * (Jotform, website, trade-show app, partner email parser) and:
 *   1. create it in Bitrix24 in the first ("To Work") status,
 *   2. mirror it locally for the inactivity timer,
 *   3. schedule the immediate welcome (WhatsApp + email),
 *   4. schedule the inactivity reminder to the agent.
 *
 * Welcome is enqueued (not sent inline) so a slow WhatsApp call never blocks the
 * form response; the scheduler picks it up within a minute.
 */
final class Intake
{
    /**
     * @param array $lead Normalised: name, phone, email, source, title, comments
     * @return int Bitrix lead id
     */
    public static function create(array $lead): int
    {
        $client = new Client();

        $name   = trim((string)($lead['name'] ?? ''));
        $phone  = trim((string)($lead['phone'] ?? ''));
        $email  = trim((string)($lead['email'] ?? ''));
        $source = trim((string)($lead['source'] ?? 'EXTERNAL'));
        $title  = trim((string)($lead['title'] ?? ($name !== '' ? "Lead: $name" : 'New lead')));

        $fields = [
            'TITLE'       => $title,
            'NAME'        => $name,
            'SOURCE_ID'   => self::mapSource($source),
            'STATUS_ID'   => Config::get('bitrix.lead_status_new', 'NEW'),
            'COMMENTS'    => (string)($lead['comments'] ?? ''),
            'OPENED'      => 'Y',
        ];
        if ($phone !== '') {
            $fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
        }
        if ($email !== '') {
            $fields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
        }

        $leadId = $client->addLead($fields);

        $repo = new Repo();
        $repo->upsert('lead', $leadId, [
            'stage_id'        => $fields['STATUS_ID'],
            'customer_phone'  => $phone ?: null,
            'customer_email'  => $email ?: null,
            'customer_name'   => $name ?: null,
            'received_at'     => date('Y-m-d H:i:s'),
            'stage_changed_at'=> date('Y-m-d H:i:s'),
            'last_synced_at'  => date('Y-m-d H:i:s'),
        ]);

        $sched = new Scheduler();

        // #2 welcome — immediate (due now), customer, both channels.
        $sched->enqueue([
            'entity_type'   => 'lead',
            'bitrix_id'     => $leadId,
            'rule_key'      => 'welcome',
            'recipient_type'=> 'customer',
            'channel'       => 'both',
            'due_at'        => date('Y-m-d H:i:s'),
            'dedupe_key'    => "welcome:lead:$leadId",
        ]);

        // #4 inactivity — agent reminded if the lead hasn't moved out of NEW.
        $hours = (int)Config::get('reminders.lead_inactivity_hours', 2);
        $sched->enqueue([
            'entity_type'   => 'lead',
            'bitrix_id'     => $leadId,
            'rule_key'      => 'lead_inactivity',
            'recipient_type'=> 'agent',
            'channel'       => 'both',
            'due_at'        => date('Y-m-d H:i:s', time() + $hours * 3600),
            'skip_if_stage_changed_from' => $fields['STATUS_ID'],
            'dedupe_key'    => "inactivity:lead:$leadId",
        ]);

        Log::write('form_intake', 'lead_created', 'lead', $leadId,
            ['source' => $source, 'name' => $name, 'phone' => $phone, 'email' => $email]);

        return $leadId;
    }

    /** Map our free-text source to a Bitrix SOURCE_ID. Tune to your portal. */
    private static function mapSource(string $source): string
    {
        return match (strtolower($source)) {
            'website', 'web'        => 'WEB',
            'jotform', 'form'       => 'WEBFORM',
            'tradeshow', 'fair'     => 'TRADE_SHOW',
            'partner', 'email'      => 'PARTNER',
            'call', 'phone'         => 'CALL',
            default                  => 'OTHER',
        };
    }
}
