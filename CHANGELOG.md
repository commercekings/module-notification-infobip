# Changelog

## Unreleased (0.1.0)

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`) and `commercekings/module-notification`;
PHP 8.1–8.5.

- **Rate limits and server errors:** HTTP 429, or 503 with `Retry-After`, is Notification's
  `TemporaryFailureException` (nothing sent, try again after the wait); any other 5xx counts as maybe sent
  (`MessageSentException`, never sent twice) - the timeout/5xx rule in [docs/provider-api.md](docs/provider-api.md).
- **Providers for SMS, WhatsApp and Viber** through Infobip (`infobip_sms`, `infobip_whatsapp`, `infobip_viber`):
  one provider class with a channel, as virtual types. Built on Notification's `@api` and di.xml only.
- **Account per website:** API base URL, API key (encrypted), the sender per channel, webhook secret (encrypted).
- **WhatsApp templates** per notification type and locale, with their language and variables.
- **Webhooks** for inbound messages and delivery reports, authorized by an HMAC token in the URL (Infobip doesn't
  sign the per-message delivery reports or Forward-to-HTTP messages we use): STOP / START, WhatsApp "Stop promotions" and Viber unsubscribes keep Notification's stop list in step;
  blocked or opted-out numbers are stopped, numbers that failed for good are suppressed.
- **Quiet hours in the recipient's time zone** for sms, whatsapp and viber, from the number (the store's zone when it
  cannot be placed).
- **Opt-in confirmation:** one message per number's first sign-up in the website, claimed in Notification's delivery
  state; a daily cleanup removes the claims of numbers Notification no longer keeps. Never lost: a send refused for
  now (429/503 Retry-After) or failed before Infobip took it, a broker that is down and a lost message are retried by
  a 5-minute cron (8 attempts, then logged); one that may be out is never sent again.
- **Reply order:** each reply's `receivedAt` goes to the stop list, so a START that Infobip delivers late (it retries
  for up to two days) cannot undo a newer STOP.
- **Never twice:** a timeout after the request counts as sent; Infobip's channel failover is never requested.
- A rate limit for the delivery report webhook with `commercekings/module-rate-limit`.
