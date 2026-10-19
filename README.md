# Infobip for CommerceKings Notification<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification-infobip/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification-infobip/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · CommerceKings Notification · Infobip SMS, WhatsApp and Viber

[![License](https://badgen.net/github/license/commercekings/module-notification-infobip)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification-infobip)](https://github.com/commercekings/module-notification-infobip/releases) [![Checks](https://github.com/commercekings/module-notification-infobip/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-notification-infobip/actions/workflows/checks.yml)

**Text, WhatsApp and Viber messages through Infobip** for every CommerceKings notification on those channels. This
module only talks to Infobip; consent, the stop list, quiet hours and the cooldown are Notification's.

Email is built into Notification and works out of the box; this module adds text, WhatsApp and Viber.
**Works together with:**

- [module-notification](https://github.com/commercekings/module-notification) – the base every notification runs on.
- [module-abandoned-cart-sms](https://github.com/commercekings/module-abandoned-cart-sms) – text-message cart reminders, sent through Infobip.
- [module-notification-twilio](https://github.com/commercekings/module-notification-twilio) – the alternative; one provider per channel.

- **Three channels, one account.** Infobip SMS, WhatsApp (approved templates) and Viber, per website.
- **STOP means stop.** STOP / START replies, WhatsApp's "Stop promotions" button and Viber unsubscribes keep
  Notification's stop list in step; numbers Infobip reports undeliverable for good are suppressed.
  A reply counts when it is exactly an opt-out keyword, ignoring case, spaces and punctuation ("opt out", "STOP!!").
- **Quiet hours in the recipient's time zone**, from the number (libphonenumber) – as US state laws such as Florida's
  FTSA require.
- **Opt-in confirmation.** One message to a number's first sign-up, as US carriers require – never twice.
- **Never twice, never elsewhere.** A timeout counts as sent unless the request provably never left the shop;
  Infobip's channel failover is never used.

## Installation

```sh
composer require commercekings/module-notification-infobip:^0.1
bin/magento setup:upgrade
```

Then **Stores > Configuration > CommerceKings > Notifications**:

1. **Infobip:** the API base URL and API key of your account, the senders (SMS sender ID or number, WhatsApp number,
   Viber sender), a **webhook secret** (a long random value) and, if the shop is reached under another address, the
   public URL. Save.
2. **Infobip > Inbound message URLs:** enter each URL in Infobip for the messages to your senders ("Forward to HTTP",
   JSON). The delivery report URL needs no entry: every message carries it.
3. **Infobip > WhatsApp templates:** one row per notification type and locale with the approved template's name,
   language and variables (e.g. `infobip_optin` – `store`; your own WhatsApp notification type – its variables). No
   WhatsApp cart reminder ships: the abandoned cart texts are SMS.
4. **Delivery:** select Infobip as the SMS, WhatsApp and Viber provider (automatic when it is the only one).
5. In Infobip, **turn channel failover off** for these senders.

## Webhooks and their authentication

Infobip signs no webhook. Each URL carries a token instead – an HMAC of the website's webhook secret for that webhook
(`inbound` or `report`), never the secret itself:

| URL | Called for |
|---|---|
| `{public URL}/ckinfobip/webhook/inbound?channel=sms\|whatsapp\|viber&token=…` | messages to your senders |
| `{public URL}/ckinfobip/webhook/report?channel=…&token=…` | delivery reports (set on every message) |

A request without a token of a configured account is answered 403 and does nothing. Changing the secret retires
the old URLs: enter the new ones in Infobip. Use HTTPS, and keep the URLs out of logs you share.

| Webhook | Result | Notification |
|---|---|---|
| inbound | STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT, REVOKE, OPTOUT; WhatsApp "Stop promotions"; Viber unsubscribed | `stop(…, REASON_REPLY)` – lifts only by START |
| inbound | START, UNSTOP, YES; Viber subscribed | `resume()` – removed subscriptions do not come back |
| report | an explicit opt-out (e.g. `EC_CONTENT_BLOCKED`, `EC_BLOCKED_USER`, `EC_MARKETING_OPT_OUT`; `Model\ErrorCodes`) | `stop(…, REASON_REPLY)` |
| report | the number cannot receive (unknown number, landline, no account on the channel; `Model\ErrorCodes`) | `stop(…, REASON_UNDELIVERABLE)` – keeps consents, lifts after the configured days |
| report | the shop's account, sender, credit or demo whitelist, network errors, unknown names | nothing; the status and error names are logged |
| report | delivered, pending, expired, temporary errors | nothing |

A number without "+" gets one; a number Notification cannot take is logged (without the number) and answered 200 –
Infobip would send it again in vain. HELP is Infobip's auto-reply (set it up in Infobip). Only the delivery report
webhook is registered with `commercekings/module-rate-limit`: a limited inbound request could lose a STOP.

## Before going live

- **One provider module per channel.** With another one (e.g. Twilio) installed for the same channel, quiet hours
  zones and opt-in confirmations may come from that module instead: keep one per channel.
- **US:** register your 10DLC brand and campaign (or toll-free verification) with Infobip; the opt-in confirmation text
  is part of it.
- **WhatsApp:** marketing needs an approved template per language; without a mapped template the message is not sent
  (the notification counts a failed attempt).
- **Protect against SMS pumping.** The public sign-up endpoints can be abused to trigger paid texts (e.g. opt-in
  confirmations to premium-rate numbers) – plan for it: limit them with [module-rate-limit](https://github.com/commercekings/module-rate-limit) and restrict the
  destinations to the countries you sell to; check your Infobip account for its fraud protection and turn it on.
- Send a test with your own number: the message, a STOP reply, START, and a delivery report.

## Limitations

- Infobip's request and webhook formats (SMS `/sms/2/text/advanced`, WhatsApp `/whatsapp/1/message/template`, Viber
  `/viber/2/messages`) are built from Infobip's documentation; the tests use a stub client and never call Infobip.
  Check them against your account before going live.
- Free-form WhatsApp messages (inside the 24-hour service window) are not sent: templates only.
- Opt-outs and undeliverable numbers are recognized by Infobip's documented names only (`Model\ErrorCodes`); a name
  Infobip adds later stops nobody until it is added.

## More

[How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md) ·
[Notification: Messaging](https://github.com/commercekings/module-notification/blob/main/docs/module-authors.md#messaging)

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy
and whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, TCPA, SMS marketing rules). Get legal
advice if you are unsure.

---

**Need help installing or setting it up?** **[Get a quote →](https://commercekings.net)**
