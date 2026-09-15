<?php
declare(strict_types=1);

namespace Glue\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use Glue\Config;
use Glue\Event\Log;
use Glue\Team\Chat;
use Throwable;

/**
 * The AI assistant in the team chat: a staff member asks in their own words,
 * the model looks the answer up in the CRM through Tools and replies — or
 * proposes an action, which the person confirms with one tap.
 *
 * Runs on Claude through the official PHP SDK. Reads happen inside the
 * request; ACTIONS never do — the model's action calls are parked on the
 * reply as proposals (meta.actions), and confirm() runs them later, as the
 * user, when they press the button. The model is told that in the tool
 * result, so an answer never says "done" about something still waiting.
 *
 * Settings → Assistente AI: ai.api_key (the Anthropic key), ai.model.
 */
final class Assistant
{
    public const MODEL_DEFAULT = 'claude-opus-5';
    private const MAX_ROUNDS = 8;       // tool-call rounds per question
    private const HISTORY = 40;         // earlier messages the model is shown
    private const MAX_TOKENS = 4096;

    /** Test seam: a factory returning something with ->beta->messages->create(...). */
    public static $clientFactory = null;

    /** True once these two rounds proved the API rejects the fallback beta: stop sending it. */
    private static bool $noFallbacks = false;

    public static function configured(): bool
    {
        return trim((string)Config::get('ai.api_key', '')) !== '' && class_exists(Client::class);
    }

    public static function model(): string
    {
        return trim((string)Config::get('ai.model', '')) ?: self::MODEL_DEFAULT;
    }

    // ---- asking ----------------------------------------------------------------------

    /**
     * Post the question, run the model, post the reply.
     *
     * @param array $ctx ['uid','role','name','lang']
     * @return array{ok:bool, ids:int[], error:?string}  ids = message ids written (question + reply)
     */
    public static function ask(int $chatId, array $ctx, string $question): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'ids' => [], 'error' => 'empty'];
        }
        $qid = Chat::post($chatId, (int)$ctx['uid'], (string)$ctx['name'], $question);
        if (!self::configured()) {
            $rid = Chat::post($chatId, null, 'Assistente', $ctx['lang'] === 'en'
                ? 'The assistant is not configured yet: an administrator must enter the API key in Settings → AI assistant.'
                : 'L\'assistente non è ancora configurato: un amministratore deve inserire la chiave API in Impostazioni → Assistente AI.',
                null, 'assistant');
            return ['ok' => false, 'ids' => [$qid, $rid], 'error' => 'not_configured'];
        }

        $tools = Tools::definitions($ctx);
        $messages = self::history($chatId, $qid);
        $messages[] = ['role' => 'user', 'content' => $question];
        $actions = [];
        $used = [];
        $usage = ['in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $respModel = ''; // the model that actually answered: a server-side fallback may differ from the setting
        $finalText = '';
        $stop = '';

        try {
            $client = self::client();
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                $resp = self::create($client, $ctx, $tools, $messages);
                $usage['in'] += (int)($resp->usage->inputTokens ?? 0);
                $usage['out'] += (int)($resp->usage->outputTokens ?? 0);
                $usage['cache_read'] += (int)($resp->usage->cacheReadInputTokens ?? 0);
                $usage['cache_write'] += (int)($resp->usage->cacheCreationInputTokens ?? 0);
                $stop = (string)($resp->stopReason ?? '');
                $respModel = (string)($resp->model ?? '') ?: $respModel;

                $texts = [];
                $calls = [];
                foreach ($resp->content as $block) {
                    $type = (string)($block->type ?? '');
                    if ($type === 'text') {
                        $texts[] = (string)$block->text;
                    } elseif ($type === 'tool_use') {
                        $calls[] = $block;
                    }
                }
                if ($stop !== 'tool_use' || !$calls) {
                    $finalText = trim(implode("\n\n", $texts));
                    break;
                }
                // Every result goes back in ONE user turn, in call order.
                $results = [];
                foreach ($calls as $call) {
                    $name = (string)$call->name;
                    $input = is_array($call->input) ? $call->input : [];
                    $used[] = $name;
                    if (Tools::isAction($name)) {
                        $aid = 'a' . (count($actions) + 1);
                        $actions[] = ['id' => $aid, 'tool' => $name, 'input' => $input,
                                      'label' => Tools::describe($name, $input, $ctx), 'status' => 'pending'];
                        $text = "Azione registrata come proposta (id $aid) e mostrata all'utente con i pulsanti Conferma / Annulla. "
                              . "NON è ancora stata eseguita e non verrà eseguita finché l'utente non conferma. "
                              . "Non ripetere la chiamata. Nella risposta spiega in breve cosa succederà alla conferma.";
                    } else {
                        $text = Tools::read($name, $input, $ctx);
                    }
                    $results[] = ['type' => 'tool_result', 'toolUseID' => (string)$call->id, 'content' => $text];
                }
                $messages[] = ['role' => 'assistant', 'content' => $resp->content];
                $messages[] = ['role' => 'user', 'content' => $results];
                if ($round === self::MAX_ROUNDS - 1) {
                    $finalText = trim(implode("\n\n", $texts)) ?: ($ctx['lang'] === 'en'
                        ? 'I ran out of steps for this question — try asking for a smaller piece.'
                        : 'Ho esaurito i passaggi per questa domanda: prova a chiedere una parte alla volta.');
                }
            }
        } catch (AuthenticationException $e) {
            return self::fail($chatId, $qid, $ctx, 'auth', $e);
        } catch (RateLimitException $e) {
            return self::fail($chatId, $qid, $ctx, 'rate', $e);
        } catch (APIStatusException $e) {
            return self::fail($chatId, $qid, $ctx, 'api', $e);
        } catch (APIConnectionException $e) {
            return self::fail($chatId, $qid, $ctx, 'network', $e);
        } catch (Throwable $e) {
            return self::fail($chatId, $qid, $ctx, 'other', $e);
        }

        if ($stop === 'refusal') {
            $finalText = $ctx['lang'] === 'en' ? 'I can\'t help with that request.' : 'Non posso aiutarti con questa richiesta.';
        }
        if ($finalText === '' && $actions) {
            $finalText = $ctx['lang'] === 'en' ? 'Confirm the action below to proceed.' : 'Conferma l\'azione qui sotto per procedere.';
        }
        if ($finalText === '') {
            $finalText = $ctx['lang'] === 'en' ? '(no answer)' : '(nessuna risposta)';
        }
        $usedModel = $respModel !== '' ? $respModel : self::model();
        // What this answer cost, kept with it (Ai\Pricing): shown under the answer to admins, summed in Settings.
        $meta = ['actions' => $actions, 'tools' => array_values(array_unique($used)), 'usage' => $usage, 'model' => $usedModel,
                 'cost_usd' => Pricing::cost($usage, $usedModel)];
        $rid = Chat::post($chatId, null, 'Assistente', $finalText, null, 'assistant', $meta);
        Log::write('ai', 'assistant_reply', 'team_chat', $chatId, ['user' => $ctx['uid'], 'tools' => $meta['tools'],
            'actions' => count($actions), 'usage' => $usage, 'stop' => $stop]);
        return ['ok' => true, 'ids' => [$qid, $rid], 'error' => null];
    }

    private static function fail(int $chatId, int $qid, array $ctx, string $kind, Throwable $e): array
    {
        $en = $ctx['lang'] === 'en';
        $msg = match ($kind) {
            'auth'    => $en ? 'The API key is not valid: check Settings → AI assistant.' : 'La chiave API non è valida: controlla Impostazioni → Assistente AI.',
            'rate'    => $en ? 'The assistant is busy right now — try again in a minute.' : 'L\'assistente è occupato in questo momento: riprova tra un minuto.',
            'network' => $en ? 'Could not reach the assistant (network). Try again.' : 'Impossibile raggiungere l\'assistente (rete). Riprova.',
            default   => $en ? 'The assistant hit an error: ' : 'L\'assistente ha incontrato un errore: ',
        };
        if ($kind === 'api' || $kind === 'other') {
            $msg .= mb_substr($e->getMessage(), 0, 300);
        }
        Log::write('ai', 'assistant_error', 'team_chat', $chatId, ['kind' => $kind, 'error' => mb_substr($e->getMessage(), 0, 500), 'class' => get_class($e)]);
        $rid = Chat::post($chatId, null, 'Assistente', '⚠️ ' . $msg, null, 'assistant', ['error' => $kind]);
        return ['ok' => false, 'ids' => [$qid, $rid], 'error' => $kind];
    }

    /** One API call. The refusal fallback rides along unless this API already said it does not know it. */
    private static function create(object $client, array $ctx, array $tools, array $messages): object
    {
        $args = [
            'model'     => self::model(),
            'maxTokens' => self::MAX_TOKENS,
            'system'    => [['type' => 'text', 'text' => self::system($ctx), 'cacheControl' => ['type' => 'ephemeral']]],
            'tools'     => $tools,
            'messages'  => $messages,
            'outputConfig' => ['effort' => 'medium'],
            'requestOptions' => ['timeout' => 120.0, 'maxRetries' => 1],
        ];
        if (!self::$noFallbacks) {
            // A safety refusal is routed to a fallback model instead of stopping the answer.
            $args['betas'] = ['server-side-fallback-2026-07-01'];
            $args['fallbacks'] = 'default';
        }
        try {
            return $client->beta->messages->create(...$args);
        } catch (BadRequestException $e) {
            if (!self::$noFallbacks && stripos($e->getMessage(), 'fallback') !== false) {
                self::$noFallbacks = true;
                unset($args['betas'], $args['fallbacks']);
                return $client->beta->messages->create(...$args);
            }
            throw $e;
        }
    }

    private static function client(): object
    {
        if (self::$clientFactory !== null) {
            return (self::$clientFactory)();
        }
        return new Client(apiKey: trim((string)Config::get('ai.api_key', '')));
    }

    // ---- what the model is told ------------------------------------------------------

    private static function system(array $ctx): string
    {
        $company = (string)Config::get('app.company_name', '') ?: 'l\'azienda';
        $role = ['admin' => 'amministratore (ufficio): vede tutto il CRM', 'agent' => 'agente di vendita: vede SOLO i propri lead, trattative, clienti, ticket, attività e appuntamenti',
                 'tech' => 'tecnico: vede i clienti, i ticket presi in carico da lui, le installazioni'][$ctx['role']] ?? $ctx['role'];
        $lang = $ctx['lang'] === 'en' ? 'English' : 'italiano';
        return <<<TXT
Sei l'assistente interno del CRM di {$company}. Parli con il personale dell'azienda, non con i clienti.

Chi ti parla: {$ctx['name']} (utente #{$ctx['uid']}), ruolo: {$role}.
Oggi è {$ctx['today']} (fuso Europe/Rome). Rispondi in {$lang}.

Cosa sai fare:
- rispondere a domande sui clienti (ultimo contatto, problemi aperti, ticket, trattative, appuntamenti, fatture se autorizzato);
- cercare tra lead, trattative, ticket, attività, appuntamenti e note;
- scrivere bozze di email e messaggi personalizzati per un cliente;
- riassumere conversazioni (ticket) e cronologie;
- suggerire il prossimo passo di vendita;
- compilare il CRM: creare attività, note, lead, spostare fasi, aggiornare campi;
- scrivere ai clienti (chat del CRM o WhatsApp), aprire/aggiornare ticket, fissare appuntamenti;
- produrre report e analisi con i dati del CRM;
- consultare il magazzino: articoli, giacenze, sotto scorta, ordinati (search_articles, stock_report);
- seguire le richieste di preventivo alla sede, le provvigioni, i pagamenti SmallPay, i partner e le installazioni, secondo il tuo ruolo.

Regole:
1. I fatti vengono SOLO dagli strumenti. Non inventare mai clienti, date, importi o numeri di ticket. Se non trovi qualcosa, dillo. Se nessuno dei tuoi strumenti copre un argomento, di' che da qui non puoi consultarlo: non concludere mai che il dato non esiste.
2. Prima di parlare di un cliente, trovalo con search_customers e poi leggi la scheda con get_customer. Se il nome è ambiguo (più risultati plausibili), elenca i candidati e chiedi quale.
3. Cita sempre gli id (#) di lead, ticket, trattative e attività, così l'utente può aprirli.
4. Le AZIONI (creare, modificare, inviare) non vengono eseguite da te: la tua chiamata diventa una proposta che l'utente conferma con un pulsante. Non dire mai che un'azione è stata fatta se non lo è; dopo aver proposto, spiega cosa succederà alla conferma. Se in cronologia un'azione risulta "annullata", non riproporla senza che l'utente lo chieda.
5. Per una bozza (email, WhatsApp, risposta a un ticket) scrivi il testo completo e pronto, nella lingua del cliente, con il tono di un'azienda seria e cordiale. Proponi l'invio solo se l'utente lo chiede o lo lascia intendere.
6. Rispetta lo scopo dell'utente: un agente vede solo i suoi record; se uno strumento risponde "non visibile", dillo senza aggirarlo.
7. Sii conciso: elenchi puntati e tabelle Markdown brevi, niente premesse. Date in formato giorno/mese/anno, importi in euro.
8. Quando ti chiedono "cosa fare adesso" con un cliente, basati sulla cronologia reale (ultimo contatto, ticket aperti, fase, tempi) e proponi un passo concreto, eventualmente come azione.
TXT;
    }

    /**
     * The earlier turns of this chat as API messages: people as 'user', the
     * assistant as 'assistant' (with what became of its proposals appended, so
     * it knows what was confirmed), CRM notes as bracketed user text. Consecutive
     * same-role turns are merged; the transcript always ends before the new question.
     */
    private static function history(int $chatId, int $beforeId): array
    {
        $rows = array_filter(Chat::recent($chatId, self::HISTORY + 1), fn($m) => (int)$m['id'] < $beforeId);
        $out = [];
        foreach ($rows as $m) {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            $text = (string)$m['body'];
            if ($m['role'] === 'assistant' && !empty($m['meta']['actions'])) {
                $states = [];
                foreach ($m['meta']['actions'] as $a) {
                    $st = match ($a['status'] ?? 'pending') {
                        'done' => 'eseguita' . (!empty($a['result']) ? ' — ' . $a['result'] : ''),
                        'failed' => 'fallita' . (!empty($a['result']) ? ' — ' . $a['result'] : ''),
                        'cancelled' => 'annullata dall\'utente',
                        default => 'in attesa di conferma',
                    };
                    $states[] = ($a['label'] ?? $a['tool']) . ': ' . $st;
                }
                $text .= "\n\n[Azioni proposte in questo messaggio → " . implode(' | ', $states) . ']';
            } elseif ($m['role'] === 'system') {
                $text = '[Nota del CRM: ' . $text . ']';
            } elseif ($m['role'] === 'user' && !empty($m['attachment_name'])) {
                $text = trim($text . "\n[allegato: " . $m['attachment_name'] . ']');
            }
            if ($text === '') {
                continue;
            }
            if ($out && $out[count($out) - 1]['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n" . $text;
            } else {
                $out[] = ['role' => $role, 'content' => $text];
            }
        }
        // The transcript the model sees must start with a user turn.
        while ($out && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        return $out;
    }

    // ---- confirming proposals --------------------------------------------------------

    /**
     * The user pressed Confirm on a proposal: run it now, as them, and record
     * the outcome on the message and as a note in the thread.
     *
     * @return array{ok:bool, text:string, note_id:int}
     */
    public static function confirm(int $messageId, string $actionId, array $ctx): array
    {
        [$m, $i] = self::pending($messageId, $actionId, $ctx);
        if ($m === null) {
            return ['ok' => false, 'text' => 'Proposta non trovata o già gestita.', 'note_id' => 0];
        }
        $a = $m['meta']['actions'][$i];
        $res = Tools::execute((string)$a['tool'], (array)$a['input'], $ctx);
        $m['meta']['actions'][$i]['status'] = $res['ok'] ? 'done' : 'failed';
        $m['meta']['actions'][$i]['result'] = $res['text'];
        $m['meta']['actions'][$i]['at'] = date('Y-m-d H:i:s');
        Chat::setMeta($messageId, $m['meta']);
        $note = ($res['ok'] ? '✅ ' : '❌ ') . $res['text'];
        $nid = Chat::post((int)$m['chat_id'], null, null, $note, null, 'system');
        Log::write('ai', $res['ok'] ? 'action_done' : 'action_failed', 'team_chat', (int)$m['chat_id'],
            ['tool' => $a['tool'], 'by' => $ctx['uid'], 'result' => $res['text']]);
        return ['ok' => $res['ok'], 'text' => $res['text'], 'note_id' => $nid];
    }

    public static function cancel(int $messageId, string $actionId, array $ctx): array
    {
        [$m, $i] = self::pending($messageId, $actionId, $ctx);
        if ($m === null) {
            return ['ok' => false, 'text' => 'Proposta non trovata o già gestita.', 'note_id' => 0];
        }
        $m['meta']['actions'][$i]['status'] = 'cancelled';
        $m['meta']['actions'][$i]['at'] = date('Y-m-d H:i:s');
        Chat::setMeta($messageId, $m['meta']);
        Log::write('ai', 'action_cancelled', 'team_chat', (int)$m['chat_id'], ['tool' => $m['meta']['actions'][$i]['tool'], 'by' => $ctx['uid']]);
        return ['ok' => true, 'text' => 'Annullata.', 'note_id' => 0];
    }

    /** The message and the index of a still-pending proposal on it — only in a chat this user is in. */
    private static function pending(int $messageId, string $actionId, array $ctx): array
    {
        $m = Chat::message($messageId);
        if (!$m || $m['role'] !== 'assistant' || empty($m['meta']['actions']) || !Chat::isMember((int)$m['chat_id'], (int)$ctx['uid'])) {
            return [null, -1];
        }
        foreach ($m['meta']['actions'] as $i => $a) {
            if (($a['id'] ?? '') === $actionId && ($a['status'] ?? 'pending') === 'pending') {
                return [$m, $i];
            }
        }
        return [null, -1];
    }

    // ---- settings test ---------------------------------------------------------------

    /** One tiny round-trip for the Settings "test" button. ['ok' => bool, 'text' => model or error]. */
    public static function test(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'text' => 'chiave API mancante'];
        }
        try {
            $r = self::client()->beta->messages->create(model: self::model(), maxTokens: 20,
                messages: [['role' => 'user', 'content' => 'Rispondi solo: OK']], requestOptions: ['timeout' => 30.0, 'maxRetries' => 0]);
            $txt = '';
            foreach ($r->content as $b) {
                if (($b->type ?? '') === 'text') {
                    $txt .= $b->text;
                }
            }
            return ['ok' => true, 'text' => self::model() . ' → ' . trim($txt)];
        } catch (AuthenticationException $e) {
            return ['ok' => false, 'text' => 'chiave API non valida'];
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
