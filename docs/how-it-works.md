# How it works

A provider module for `commercekings/module-notification`, on its `@api` and di.xml only (Notification's
[Messaging guide](https://github.com/commercekings/module-notification/blob/main/docs/module-authors.md#messaging)).
Start with the [README](../README.md).

## Parts

- **Providers** (`Model\Provider\InfobipProvider`, three virtual types in di.xml: `infobip_sms`, `infobip_whatsapp`,
  `infobip_viber`, with Notification's `Channel::SMS`, `WHATSAPP`, `VIBER`): hand the delivery to `Model\MessageSender`.
  Each channel's selector is a virtual type of Notification's `Config\Source\Provider`.
- **`Model\MessageSender`**: one message on the delivery's channel – SMS and Viber with `DeliveryContent::$text`,
  WhatsApp with the template `Model\TemplateMap` gives for `Delivery::$type` and the store's locale (else
  `Delivery::$templateId`), its placeholders from `DeliveryContent::$vars` in the mapped order. The number goes to
  Infobip without "+". Every message asks for the delivery report (its `notifyUrl` / Viber `webhooks.delivery.url`).
  No failover is ever requested. A refusal naming an opt-out (the person blocked the sender) stops the number
  (`REASON_REPLY`), one naming a number that cannot receive suppresses it (`REASON_UNDELIVERABLE`), before the error
  goes back to the sender; account, sender and demo refusals stop nobody (`Model\ErrorCodes`).
- **`Model\Http\InfobipClient`**: `Authorization: App <key>`, JSON. Infobip refusing the request, or rejecting the
  message (status group `REJECTED`) in an accepted one, is `Exception\InfobipException` (nothing sent); a failure
  cURL proves came before the request left (host not found, connection refused, TLS, a timeout before any transfer)
  is a `\RuntimeException` (nothing sent); anything else – a timeout waiting for the answer, an empty reply – is
  `MessageSentException`: it may be out, so it is never sent again.
- **Webhooks** (`Controller\Webhook\Inbound`, `Report`; route `ckinfobip`): POST, JSON, authorized by the URL's token
  (`Model\Webhook\WebhookAuth`: an HMAC of the account's webhook secret per webhook; the account whose token matches,
  tried per website), 403 otherwise; 200 for every authorized request. `Model\Webhook\InboundProcessor` and
  `ReportProcessor` map the results to Notification's `StopListInterface` (README table; a reply that is exactly an
  opt-out or START keyword, ignoring case, spaces and punctuation; other replies are logged as "unrecognized reply", without number or text); `Address` adds the "+" and
  takes the channel from the URL (else Infobip's `integrationType`). A number the stop list refuses
  (`\InvalidArgumentException`) is logged without the number.
- **Quiet hours** (`Model\NumberZones`, Notification's `QuietHoursZoneInterface` for sms, whatsapp and viber): the
  number's zones from libphonenumber; the store's zone for a number it cannot place; `[]` for the whole store.
- **Opt-in confirmation** (`Observer\QueueOptinConfirmation` on `commercekings_notification_subscription_saved`,
  `Model\Optin\Confirmation` as notification type `infobip_optin`): for a number's first subscription in the website
  (`first_for_address`) when Infobip is the channel's selected provider; queued. The claim is one delivery state row
  (type `infobip_optin`, reference = the contact id, an atomic `sent_at` update), so two sign-ups at once, a retry or a
  second worker never send it twice; a send that fails before Infobip took it releases the claim. Not stoppable, no
  cooldown. `Cron\CleanUpOptinState` (daily 03:40) removes the claims of contacts Notification no longer keeps
  (`ContactRepositoryInterface::TABLE`) – Notification's own cleanup covers subscription types only.

## Settings

`commercekings_notification/infobip/*` (website): `base_url`, `api_key` (encrypted), `sms_sender`, `whatsapp_sender`,
`viber_sender`, `webhook_secret` (encrypted), `webhook_base_url`, `template_map`; (store view) `optin_enabled`
(default Yes), `optin_text`.

## Tests

- `Test/Unit` (a mocked Guzzle handler, no network): the client's failure mapping, the payload per channel (and no
  failover), the template map, the webhook tokens, both processors, the controllers (403 / 200), the zones, the
  opt-in observer, claim and cleanup, and the wiring – every Notification class named in PHP or di.xml is `@api`, one
  provider per channel, zones for its channels, and a rate limit for the delivery reports only.
- `Test/Integration/InfobipTest.php` (a Magento development database): Notification's real sender, stop list and
  normalizer with a stub HTTP client – an SMS in Infobip's form, STOP → dropped → START, a permanent failure, a timeout.

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification-infobip/Test/Unit
MAGENTO_ROOT="$PWD" vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification-infobip/Test/Integration
```
