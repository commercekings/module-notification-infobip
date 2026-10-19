# Provider API reference

What this module calls on Infobip and what it expects back, read from the code and checked against Infobip's
documentation. Start with the [README](../README.md); the parts are described in [How it works](how-it-works.md).

## What is verified live

| Part | Verified |
|---|---|
| SMS send (`/sms/2/text/advanced`), opt-in confirmation | live, on an Infobip trial account |
| SMS delivery reports (report webhook) | live, on the same trial |
| Inbound replies (STOP, START) | simulated only, with Infobip's documented `MO_JSON_2` format – our carrier did not deliver texts to Infobip's shared test number |
| WhatsApp templates, Viber | from Infobip's documentation only; unit and integration tests use a stub client |

## Connection

| | |
|---|---|
| Base URL | the account's own, e.g. `https://xxxxx.api.infobip.com` (*API base URL*; `https://` is added when missing, a trailing `/` removed) |
| Auth | header `Authorization: App <API key>` (per website; the key is stored encrypted) |
| Body | JSON (Guzzle `json`), `Accept: application/json` |
| Timeouts | connect 5 s, total 15 s (`Model\Http\InfobipClient` constructor arguments `connectTimeout`, `timeout`) |
| HTTP errors | `http_errors: false`: every HTTP answer is read by the module, none throws in Guzzle |

Docs: [API authorization](https://www.infobip.com/docs/essentials/api-essentials/api-authorization),
[base URL](https://www.infobip.com/docs/essentials/api-essentials/base-url).

## Outbound: one message per delivery

`Model\MessageSender::send()` sends one `POST` with `{"messages": [ <message> ]}` on the delivery's channel. The
number goes without `+` (Infobip takes international digits). Infobip's channel failover is never requested: a
message falling back to another channel would bypass that channel's stop list, consent and quiet hours.

| Channel | Path | Message fields sent |
|---|---|---|
| SMS | `/sms/2/text/advanced` | `destinations: [{to}]`, `from` (*SMS sender*: number or alphanumeric ID), `text`, `notifyUrl`, `notifyContentType: application/json` |
| WhatsApp | `/whatsapp/1/message/template` | `from` (*WhatsApp sender*, `+` removed), `to`, `content: {templateName, templateData: {body: {placeholders: [...]}}, language}`, `notifyUrl` |
| Viber | `/viber/2/messages` | `sender` (*Viber sender* name), `destinations: [{to}]`, `content: {type: "TEXT", text}`, `webhooks: {delivery: {url}}` |

- `text` is `DeliveryContent::$text`, trimmed; an empty text is not sent.
- The delivery report URL (`notifyUrl` / `webhooks.delivery.url`) is
  `{public URL}/ckinfobip/webhook/report?channel=<channel>&token=<token>`; it is left out while no webhook secret is set.
- No sender configured for the channel: nothing is sent (`\RuntimeException`).

Docs: [SMS over API](https://www.infobip.com/docs/sms/api),
[WhatsApp over API](https://www.infobip.com/docs/whatsapp/send-whatsapp-over-api),
[Viber Business Messages over API](https://www.infobip.com/docs/viber/business-messages/api),
[API reference](https://www.infobip.com/docs/api).

**Response read.** On 2xx: `messages[].status.groupName`; a message in group `REJECTED` was not sent, and its
`status.name` is the refusal. On non-2xx: the error name from `requestError.serviceException.messageId`, else
`requestError.policyException.messageId`, else a top-level `errorCode` (the newer APIs' error shape). `bulkId` and
`messageId` are not stored.

### What a failure means

| Situation | Exception | Sent? |
|---|---|---|
| 2xx, no message in group `REJECTED` | – | yes |
| 2xx, a message in group `REJECTED` (e.g. `REJECTED_DESTINATION`) | `InfobipException` (`status.name`) | no |
| HTTP 429, or 503 with `Retry-After` | `TemporaryFailureException` (`getRetryAfter()`: the header's seconds) | no – sent again after the wait |
| any other 5xx (500, 502, 503 without `Retry-After`, 504, …) | `MessageSentException` | maybe – never sent again |
| any other non-2xx | `InfobipException` (error name, HTTP status) | no |
| cURL proves the request never left: errno 5, 6 (proxy/host not resolved), 7 (connection refused), 35 (TLS) | `\RuntimeException` | no |
| cURL timeout (28) **with** `pretransfer_time` 0, `request_size` 0 and `size_upload` 0 | `\RuntimeException` | no |
| any other timeout, empty reply (52), or other transport error | `MessageSentException` | maybe – never sent again, so a shopper is never messaged (or charged) twice |
| no base URL or API key | `\RuntimeException` | no |

cURL reports a connect timeout and a read timeout with the same errno 28, so only the transfer counters prove that
nothing left; without that proof a timeout counts as maybe sent.

**Timeout/5xx rule:** whenever the request may have reached Infobip and no clear refusal came back – a timeout without
cURL's proof that nothing left, an empty reply, or a 5xx other than 503 with `Retry-After` – the message counts as
maybe sent (`MessageSentException`): it is never sent again, so a shopper is never messaged (or charged) twice; a lost
message is the accepted cost. A clear "not now" (429, or 503 with `Retry-After`) is a `TemporaryFailureException`:
nothing was sent, and a feature sends it again after the wait (abandoned cart: `Retry-After`, else 15 minutes, without
counting a failed attempt).

An `InfobipException` whose name `Model\ErrorCodes` knows also updates the stop list (below) before the send fails;
any other name stops nobody.

## Error names → stop list

`Model\ErrorCodes` matches exact names only (case-insensitive), from Infobip's
[status and error codes](https://www.infobip.com/docs/essentials/api-essentials/response-status-and-error-codes).
Used for refused sends and for delivery reports alike.

| Name (Infobip id) | Meaning | Effect |
|---|---|---|
| `REJECTED_DND` (status 10) | recipient subscribed to Do Not Disturb | `stop(…, REASON_REPLY)` |
| `EC_SC_BLOCKED_BY_END_USER` (573) | end user asked the operator to block the sender | `stop(…, REASON_REPLY)` |
| `EC_CONTENT_BLOCKED` (603) | blocked by the user's opt-out (MO: STOP) | `stop(…, REASON_REPLY)` |
| `EC_BLOCKED_USER` (7003) | the user blocked the business (documented under RCS) | `stop(…, REASON_REPLY)` |
| `EC_TEMPLATE_BLOCKED_BY_USER` (7030) | the user blocked this template | `stop(…, REASON_REPLY)` |
| `EC_MARKETING_OPT_OUT` (7033) | the user turned off marketing messages (WhatsApp) | `stop(…, REASON_REPLY)` |
| `REJECTED_PREFIX_MISSING` (status 8) | not a number (prefix or length) | `stop(…, REASON_UNDELIVERABLE)` |
| `REJECTED_DESTINATION` (status 52) | invalid destination | `stop(…, REASON_UNDELIVERABLE)` |
| `EC_UNKNOWN_SUBSCRIBER` (1) | unknown to the network | `stop(…, REASON_UNDELIVERABLE)` |
| `EC_TELESERVICE_NOT_PROVISIONED` (11) | subscription has no SMS | `stop(…, REASON_UNDELIVERABLE)` |
| `EC_INVALID_REQUEST_DESTINATION` (352) | country code not recognized | `stop(…, REASON_UNDELIVERABLE)` |
| `EC_DESTINATION_NOT_SMS_PROVISIONED` (568) | not provisioned for SMS | `stop(…, REASON_UNDELIVERABLE)` |
| `EC_DEST_ADDRESS_UNABLE_TO_RECEIVE_SMS` (629) | cannot receive SMS (e.g. a landline) | `stop(…, REASON_UNDELIVERABLE)` |
| `EC_DEACTIVATED_LIST` (3041) | deactivated number | `stop(…, REASON_UNDELIVERABLE)` |
| `EC_UNKNOWN_USER` (7002) | the user is unknown on the channel (documented under RCS) | `stop(…, REASON_UNDELIVERABLE)` |
| anything else (account, sender, credit, demo whitelist, routes, network, unknown names) | the shop has to fix it | nothing |

`REASON_REPLY` lifts only when the number sends START; `REASON_UNDELIVERABLE` keeps the consents and lifts after the
configured days.

## Webhooks

| Webhook | URL | Set where |
|---|---|---|
| Inbound messages | `POST {public URL}/ckinfobip/webhook/inbound?channel=sms\|whatsapp\|viber&token=…` | Infobip: the sender's inbound configuration, "Forward to HTTP", JSON (`MO_JSON_2` for SMS); the admin shows the three URLs |
| Delivery reports | `POST {public URL}/ckinfobip/webhook/report?channel=…&token=…` | on every message (`notifyUrl` / `webhooks.delivery.url`) |

**Authentication.** Infobip doesn't sign the per-message delivery reports or Forward-to-HTTP messages we use; its
webhook subscriptions can (Basic, HMAC, OAuth, mTLS), but we don't use them. So the URL carries the proof
(`Model\Webhook\WebhookAuth`): `token` is the first 40 hex characters of
`HMAC-SHA256("commercekings_infobip_webhook|<inbound|report>", webhook secret)`. The secret never travels; an inbound
token does not open the report webhook and vice versa; changing the secret retires the old URLs. The token is
checked against each website's account (`hash_equals`); without a secret every request is refused.

**Our answers.**

| Case | Answer |
|---|---|
| token missing or matches no account | `403`, `Not authorized.`, nothing done |
| authorized | `200`, empty body – also when a result could not be used (bad JSON, unknown channel, a number Notification refuses), since a retry would not change that |

Infobip retries a webhook while the endpoint is unavailable, up to 20 times over about 41 hours
([reporting webhooks](https://www.infobip.com/docs/reporting/webhooks)).

**Channel.** The URL's `channel` parameter; else the result's `integrationType` or `channel` (`sms`, `whatsapp`,
`viber`, case-insensitive). A result with no known channel is ignored.

**Numbers.** `from` (inbound) or `to` (report), else `sender` / `destination`; a `whatsapp:`, `viber:` or `tel:`
prefix is removed and `+` added to digits.

### Inbound messages – fields read

The body is `{"results": [ … ]}`; each result is handled on its own.

| Field | Use |
|---|---|
| `from` (else `sender`) | the number |
| `integrationType` / `channel` | the channel when the URL names none |
| `event`, `message.event`, `message.type` | Viber subscription events: `UNSUBSCRIBE`/`UNSUBSCRIBED` → stop, `SUBSCRIBE`/`SUBSCRIBED` → resume |
| `message.payload`, `message.text`, the text | exactly `stop promotions` (ignoring case and outer spaces) → stop: WhatsApp's marketing opt-out button |
| `text`, `message.text`, `keyword` + `cleanText`, `cleanText` | the keyword rule below, each checked whole; the first that matches decides |

`cleanText` is the text after the keyword the forwarding is set up for. Checking all of them keeps a reply whatever
the setup: a bare "STOP" with forwarding for the keyword STOP arrives with an empty `cleanText` (the full `text`
matches); "SHOP STOP" with forwarding for SHOP arrives with `cleanText` "STOP" (that matches). SMS `MO_JSON_2`
results also carry `messageId`, `to` and `receivedAt`; they are not read.
Docs: [Receive inbound SMS](https://www.infobip.com/docs/api/channels/sms/inbound-sms/receive-inbound-sms-messages),
[Keywords and actions](https://www.infobip.com/docs/numbers/keywords-and-actions).

**Keyword rule** (`InboundProcessor::keyword()`): the whole reply, upper-cased, with every character that is not a
letter removed (spaces, hyphens, punctuation, digits, emoji), must equal a keyword. "Stop!!", " stop ", "Opt-out"
and "stop all" match; "stop please" does not.

| Keywords | Effect |
|---|---|
| `STOP`, `STOPALL`, `UNSUBSCRIBE`, `CANCEL`, `END`, `QUIT`, `REVOKE`, `OPTOUT` | `stop(channel, number, REASON_REPLY)` – lifts only with START |
| `START`, `UNSTOP`, `YES` | `resume(channel, number)` – removed subscriptions do not come back |
| anything else | nothing; logged as "unrecognized reply" (no number, no text) |

### Delivery reports – fields read

The body is `{"results": [ … ]}`.

| Field | Use |
|---|---|
| `status.groupName` | only `UNDELIVERABLE` and `REJECTED` are acted on; `DELIVERED`, `PENDING`, `EXPIRED` change nothing |
| `status.name`, `error.name` | looked up in `Model\ErrorCodes` (table above); an unknown pair is logged (names only, no number) |
| `to` (else `destination`) | the number |
| `integrationType` / `channel` | the channel when the URL names none |

Docs: [SMS delivery report tutorial](https://www.infobip.com/docs/tutorials/receive-sms-delivery-report-using-infobip-api),
[WhatsApp delivery reports](https://www.infobip.com/docs/api/channels/whatsapp/whatsapp-message-status-reports/receive-whatsapp-delivery-reports).

## WhatsApp templates

Configured under *Infobip > WhatsApp templates* (`Model\TemplateMap`): rows of notification type, locale, template
name, template language and variable names. The row for the store's locale wins, then its language (`de` for
`de_CH`), then an empty locale; the row's template language defaults to the locale's language. Without a row,
`Delivery::$templateId` is used as the template name with the locale's language and no placeholders. Variables are
`DeliveryContent::$vars` names in template order and become `templateData.body.placeholders` (`{{1}}`, `{{2}}`, …);
a missing or non-scalar value is sent as an empty string. Free-form WhatsApp messages are never sent. The opt-in
confirmation uses type `infobip_optin` (variable `store`).

## Viber

Text only (`content.type: TEXT`), from the registered Viber business sender name; the delivery report URL goes in
`webhooks.delivery.url`. Subscription events on the inbound webhook map to stop/resume as above.

## Not verified

From Infobip's documentation and our tests only, not checked against a live account:

- WhatsApp templates and Viber: requests, delivery reports and inbound messages.
- The inbound schemas Infobip's public pages do not show: Viber subscription events (`event`, `message.type`
  `SUBSCRIBED`/`UNSUBSCRIBED`), the WhatsApp button reply (`message.type` `BUTTON` with `payload`), and
  `integrationType` on inbound results.
- `EC_BLOCKED_USER` (7003) and `EC_UNKNOWN_USER` (7002) on WhatsApp and Viber: Infobip documents them under RCS; we
  apply them on the assumption that Infobip uses them there too.
- A real inbound STOP from a phone (simulated only), including keyword-based forwarding setups.
- The SMS API version: we use `/sms/2/text/advanced`; Infobip now points simple sends to `/sms/3/messages`. We found
  no deprecation notice for v2; a live response's `Deprecation`/`Sunset` headers would tell.
