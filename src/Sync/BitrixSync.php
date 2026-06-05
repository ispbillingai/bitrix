<?php
declare(strict_types=1);

namespace Glue\Sync;

use Glue\Bitrix\Client;
use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Throwable;

/**
 * OPTIONAL, OFF BY DEFAULT. The CRM is fully standalone; this module is "the extra
 * thing" — a one-way push that mirrors local leads/deals into a Bitrix24 portal for
 * customers who also run Bitrix. Nothing here runs unless `bitrix.sync_enabled` is
 * truthy AND a real inbound webhook base_url is configured.
 *
 * Crm\Leads / Crm\Deals call the *IfEnabled wrappers after every create/change; the
 * wrappers no-op silently when sync is disabled, so the core CRM never depends on
 * Bitrix being reachable.
 */
final class BitrixSync
{
    public static function enabled(): bool
    {
        if (!Config::get('bitrix.sync_enabled', false)) {
            return false;
        }
        $url = (string)Config::get('bitrix.base_url', '');
        return $url !== '' && !str_contains($url, 'CHANGE_ME');
    }

    public static function pushLeadIfEnabled(int $leadId): void
    {
        if (self::enabled()) {
            self::pushLead($leadId);
        }
    }

    public static function pushDealIfEnabled(int $dealId): void
    {
        if (self::enabled()) {
            self::pushDeal($dealId);
        }
    }

    public static function pushLead(int $leadId): void
    {
        $lead = self::row('leads', $leadId);
        if (!$lead) {
            return;
        }
        $fields = array_filter([
            'TITLE'     => $lead['title'] ?: ('Lead ' . $leadId),
            'NAME'      => $lead['customer_name'],
            'STATUS_ID' => Config::get('bitrix.lead_status_new', 'NEW'),
            'COMMENTS'  => $lead['comments'],
            'OPENED'    => 'Y',
        ], static fn($v) => $v !== null && $v !== '');
        if (!empty($lead['customer_phone'])) {
            $fields['PHONE'] = [['VALUE' => $lead['customer_phone'], 'VALUE_TYPE' => 'WORK']];
        }
        if (!empty($lead['customer_email'])) {
            $fields['EMAIL'] = [['VALUE' => $lead['customer_email'], 'VALUE_TYPE' => 'WORK']];
        }

        $client = new Client();
        $existing = self::bitrixId('lead', $leadId);
        try {
            if ($existing) {
                $client->call('crm.lead.update', ['id' => $existing, 'fields' => $fields]);
            } else {
                $bid = $client->addLead($fields);
                self::map('lead', $leadId, $bid);
            }
            self::touch('lead', $leadId);
            Log::write('sync', 'lead_pushed', 'lead', $leadId, ['bitrix_id' => $existing ?: null]);
        } catch (Throwable $e) {
            Log::write('sync', 'lead_push_error', 'lead', $leadId, ['error' => $e->getMessage()]);
        }
    }

    public static function pushDeal(int $dealId): void
    {
        $deal = self::row('deals', $dealId);
        if (!$deal) {
            return;
        }
        $fields = array_filter([
            'TITLE'      => $deal['title'],
            'STAGE_ID'   => $deal['stage_code'],
            'OPPORTUNITY'=> $deal['amount'],
            'CURRENCY_ID'=> $deal['currency'],
            'OPENED'     => 'Y',
        ], static fn($v) => $v !== null && $v !== '');

        $client = new Client();
        $existing = self::bitrixId('deal', $dealId);
        try {
            if ($existing) {
                $client->call('crm.deal.update', ['id' => $existing, 'fields' => $fields]);
            } else {
                $bid = (int)$client->call('crm.deal.add', ['fields' => $fields, 'params' => ['REGISTER_SONET_EVENT' => 'Y']]);
                self::map('deal', $dealId, $bid);
            }
            self::touch('deal', $dealId);
            Log::write('sync', 'deal_pushed', 'deal', $dealId, ['bitrix_id' => $existing ?: null]);
        } catch (Throwable $e) {
            Log::write('sync', 'deal_push_error', 'deal', $dealId, ['error' => $e->getMessage()]);
        }
    }

    // ---- sync_map helpers -----------------------------------------------------

    private static function bitrixId(string $type, int $localId): ?int
    {
        $stmt = Db::pdo()->prepare('SELECT bitrix_id FROM sync_map WHERE local_type = ? AND local_id = ?');
        $stmt->execute([$type, $localId]);
        $v = $stmt->fetchColumn();
        return $v ? (int)$v : null;
    }

    private static function map(string $type, int $localId, int $bitrixId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO sync_map (local_type, local_id, bitrix_id, last_pushed_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE bitrix_id = VALUES(bitrix_id), last_pushed_at = NOW()'
        )->execute([$type, $localId, $bitrixId]);
    }

    private static function touch(string $type, int $localId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO sync_map (local_type, local_id, last_pushed_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_pushed_at = NOW()'
        )->execute([$type, $localId]);
    }

    private static function row(string $table, int $id): ?array
    {
        $stmt = Db::pdo()->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
