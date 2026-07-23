# API Lead — come inviarci un lead

Documento da consegnare a chi deve inviare lead al nostro CRM dal proprio
gestionale o sito. Una richiesta HTTP per ogni lead: entra nel CRM come un lead
inserito a mano e il cliente riceve il consueto messaggio di benvenuto.

## Endpoint

```
POST https://crm.upgradesrls.com/webhooks/lead.php
Authorization: Bearer <TOKEN>
Content-Type: application/json
```

`<TOKEN>` è il token che vi forniamo noi. In alternativa, per client che non
possono impostare gli header, è accettato anche come `X-Api-Key: <TOKEN>` oppure
`?secret=<TOKEN>` nell'URL.

Solo da server a server: il token non va mai messo nel JavaScript del sito.

## Corpo della richiesta

```json
{
  "source_url":  "https://www.michaeltech.it/contatti",
  "external_id": "4711",
  "name":        "Mario Rossi",
  "phone":       "+393331234567",
  "email":       "mario.rossi@example.com",
  "company":     "Rossi SRL",
  "vat_number":  "01234567890",
  "zone":        "Lombardia",
  "message":     "Vorrei informazioni sul modello X",
  "lang":        "it"
}
```

| Campo | Obbligatorio | Note |
|---|---|---|
| `phone` / `email` | **almeno uno dei due** | Meglio in formato internazionale `+39…`; numeri con `00` o con spazi vengono normalizzati. |
| `source_url` | fortemente consigliato | Il sito/pagina da cui arriva la richiesta. Viene mostrato sul lead ed è ciò che distingue un mittente dall'altro. |
| `external_id` | consigliato | Il vostro identificativo della richiesta: deve essere univoco solo all'interno del vostro sistema. Vedi *Rinvii e duplicati*. |
| `name` | no | Oppure `first_name` + `last_name`. |
| `company`, `vat_number`, `zone`, `title`, `message`, `lang` | no | `lang` = `it` (predefinito) o `en`: è la lingua dei messaggi **verso il cliente**. |

Non serve inviare alcun campo `origine`/`source`: la categoria del lead la
impostiamo noi dalla nostra parte. A voi basta indicare, con `source_url`, il
sito da cui arriva la richiesta.

I nomi dei campi non sono case-sensitive e accettiamo gli alias più comuni:
`nome`/`cognome`, `telefono`, `messaggio`, `azienda`, `partita_iva`, `sito`,
`zona`. Se il vostro modulo invia già questi nomi non dovete rinominare nulla.
Oltre al JSON è accettato anche il classico form-encoded.

## Risposte

| Stato | Corpo | Significato |
|---|---|---|
| `201` | `{"ok":true,"lead_id":42,"status":"created"}` | Lead creato. |
| `200` | `{"ok":true,"lead_id":42,"status":"duplicate"}` | Già ricevuto: non è stato creato nulla di nuovo. |
| `401` | `{"ok":false,"error":"unauthorized"}` | Token mancante o errato. |
| `422` | `{"ok":false,"error":"validation_failed","fields":{…}}` | In `fields` trovate il campo da correggere. |
| `500` | `{"ok":false,"error":"intake_failed"}` | Problema dalla nostra parte: si può ritentare. |

In caso di errore 5xx o di timeout, considerate l'esito sconosciuto e ritentate
con lo stesso `external_id`.

## Rinvii e duplicati

Due protezioni, così un rinvio non crea mai un secondo lead né un secondo
messaggio al cliente:

1. **`external_id`** — corrisponde sempre al primo lead creato con quel valore.
   È il metodo affidabile: inviatelo. Lo abbiniamo internamente al dominio di
   `source_url`, quindi vi basta che sia univoco nel vostro sistema: due
   mittenti che numerano entrambi le richieste da 1 non entrano in conflitto.
2. Senza `external_id`, lo stesso telefono o la stessa email che arriva di nuovo
   entro **15 minuti** viene considerata lo stesso lead.

## Prova dell'integrazione

Una `GET` con token valido restituisce l'elenco dei campi: serve a verificare
che le credenziali funzionino prima ancora di scrivere la POST.

```bash
curl "https://crm.upgradesrls.com/webhooks/lead.php" -H "Authorization: Bearer <TOKEN>"
```

Invio di prova:

```bash
curl -i -X POST "https://crm.upgradesrls.com/webhooks/lead.php" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"source_url":"https://www.michaeltech.it/contatti","external_id":"test-1","name":"Mario Rossi","phone":"+393331234567","email":"mario@example.com","message":"Prova"}'
```

## Esempio in PHP

```php
$ch = curl_init('https://crm.upgradesrls.com/webhooks/lead.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    CURLOPT_POSTFIELDS     => json_encode([
        'source_url'  => 'https://www.michaeltech.it/contatti',
        'external_id' => (string)$richiesta->id,
        'name'        => $richiesta->nome,
        'phone'       => $richiesta->telefono,
        'email'       => $richiesta->email,
        'message'     => $richiesta->messaggio,
    ]),
    CURLOPT_TIMEOUT        => 15,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);   // 201 creato, 200 duplicato
```
