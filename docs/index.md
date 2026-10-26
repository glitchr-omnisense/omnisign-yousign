# omnisign/yousign

Yousign (Youtrust since its new name) for [omnisense/omnisign](https://github.com/glitchr-omnisense/omnisign):
simple, advanced and qualified electronic signatures, by e-mail or in the application's page,
through Yousign's public API v3 on `https://api.yousign.app/v3` (`Api::URL`), or
`https://api-sandbox.yousign.app/v3` with `sandbox: true` (`Api::SANDBOX_URL`; signatures without
legal value). Every call carries `Authorization: Bearer <api key>`; JSON both ways, a multipart
upload for documents.

## Installation

```sh
composer require omnisign/yousign
```

PHP 8.2 or later, `omnisense/omnisign` and `symfony/http-client`. An API key from Yousign's app
(sandbox: free, without legal value).

## The sources

Yousign's developer site, now `developers.youtrust.com` ("Youtrust Public API v3"), read on
2026-10-08: the OpenAPI its reference pages carry (signature requests, documents, signers, activate,
cancel, send_reminder, documents/download, audit_trails/download), and the guides "Webhooks" and
"Handling Webhooks". The API keeps Yousign's hosts (`api.yousign.app/v3`,
`api-sandbox.yousign.app/v3`) and headers (`X-Yousign-Signature-256`).

## Credentials

- An **API key** from Yousign's app (the sandbox's is free; its signatures have no legal value).
- For webhooks, a **subscription** created in Yousign's app, and its secret (`webhook_secret`).

## Options

| Option | Default | |
|---|---|---|
| `api_key` | required | a bearer key |
| `sandbox` | `false` | `api-sandbox.yousign.app` |
| `webhook_secret` | | the subscription's secret; without it, `notify()` is not supported |
| `url` | | another API root |

## From an envelope to a signature request

| | |
|---|---|
| `create()` | `POST /signature_requests` (`name`, `delivery_mode`: `none` when embedded, `email` otherwise; `ordered_signers`, `expiration_date`, `timezone`, `external_id`: the envelope's key) - each document `POST .../documents` (multipart: `file`, `nature`: `signable_document` or `attachment`) - each signer `POST .../signers` (`info`: first and last name, e-mail, phone, locale; `signature_level`; `signature_authentication_mode`; `fields`; `redirect_urls.success`). A `DRAFT` |
| `send()` | `POST .../activate`: `ongoing`, each signer's `signature_link` |
| `fetch()` | `GET /signature_requests/{id}` |
| `remind()` | `POST .../signers/{id}/send_reminder` for each signer notified who has not signed |
| `cancel()` | `POST .../cancel`, reason `other`, the application's words as the note |
| `download()` | `GET .../documents/download?version=completed` (a ZIP with `archive=true` when several documents) and `GET .../audit_trails/download?merge=true` |
| `signingUrl()` | the signer's `signature_link`, from `GET .../signers` |
| `notify()` | `X-Yousign-Signature-256`: `sha256=` and the HMAC-SHA-256 of the raw body under the secret; `event_name` read |

| Level | `signature_level` |
|---|---|
| `SIMPLE` | `electronic_signature` - a code by e-mail unless the signer says otherwise |
| `ADVANCED` | `advanced_electronic_signature` - Yousign verifies the signer's identity |
| `QUALIFIED` | `qualified_electronic_signature` |

`Signer::$authentication`: `email` → `otp_email`, `sms` → `otp_sms` (a phone needed), `none` →
`no_otp`. Fields: `SIGNATURE`, `MENTION` (`mention`: "Lu et approuvé" unless the field's label),
`TEXT`; Yousign places no `INITIALS` nor `DATE` field with a signer - such an envelope is refused.

| Yousign | Status | | Signer | SignerStatus |
|---|---|---|---|---|
| `draft` | `DRAFT` | | `initiated` | `WAITING` |
| `ongoing`, `approval`, `paused` | `SENT` | | `notified` | `NOTIFIED` |
| `done` | `COMPLETED` | | `verified`, `processing`, `consent_given` | `OPENED` |
| `declined`, `rejected` | `DECLINED` | | `signed` | `SIGNED` |
| `expired` | `EXPIRED` | | `declined` | `DECLINED` |
| `canceled`, `deleted` | `CANCELED` | | `aborted`, `error` | `FAILED` |

## Capabilities

| Request | Supported |
|---|---|
| `Create`, `Send`, `Fetch`, `Remind`, `Cancel`, `Download`, `SigningUrl` | yes |
| `Notify` | with `webhook_secret` |

`capabilities()`: simple, advanced, qualified; in the page; ordered; identity check; third party;
documents; no templates. `Envelope::$message` and `Signer::$role` are not sent; `SigningUrl`'s
`returnUrl` is not read (the envelope's `redirectUrl` is each signer's success redirection). A
signer's `signedAt` is not read from Yousign's answers: it stays null.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnisign\Model\Document;
use Omnisign\Model\Envelope;
use Omnisign\Model\Field;
use Omnisign\Model\FieldType;
use Omnisign\Model\File;
use Omnisign\Model\Level;
use Omnisign\Model\Signer;
use Omnisign\Yousign\YousignGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$yousign = (new YousignGatewayFactory(HttpClient::create()))->create(['api_key' => getenv('YOUSIGN_API_KEY'), 'sandbox' => true]);

$envelope = $yousign->create(new Envelope(
    'Bail - 12 rue des Juifs',
    documents: [new Document('lease', File::fromPath('lease.pdf'))],
    signers: [new Signer('landlord', 'Marie-Anne de La Tour', 'landlord@example.org', '+33612345678')],
    fields: [new Field('landlord', 'lease', FieldType::SIGNATURE, page: 2, x: 77, y: 581)],
    level: Level::ADVANCED,
    redirectUrl: 'https://site.example/lease/42/signed',
    key: 'lease-42',
));
$envelope = $yousign->send($envelope);        // ongoing: the signer is notified
$envelope = $yousign->fetch($envelope);       // later: done
$download = $yousign->download($envelope);    // the signed PDF and the audit trail
```

The same calls were run on 2026-10-26 on a `MockHttpClient` serving this package's fixtures (the
requests, their bodies and the answers: [Envelopes](https://github.com/glitchr-omnisense/omnisign/blob/1.x/docs/usage-envelopes.md#create-then-send));
Yousign itself was not called. In Symfony:

```yaml
omnisign:
    gateways:
        qualified:
            factory: yousign
            options:
                api_key: '%env(YOUSIGN_API_KEY)%'
                sandbox: '%env(bool:YOUSIGN_SANDBOX)%'
                webhook_secret: '%env(default::YOUSIGN_WEBHOOK_SECRET)%'
```

## Errors

| Answer | Exception |
|---|---|
| 401, 403 | `InvalidKeyException` (`The API key was refused (HTTP 403): ...`) |
| other 4xx | `ProviderException` (`<METHOD> <path>: HTTP 400, <detail> (<param>: <reason>; ...)`, `providerCode` the status) |
| 5xx, 429, no answer, a timeout (10 s) | `UnreachableException` |
| no signature link for the signer | `ProviderException` |
| no document or no signer, an `authentication` other than email, sms, none, a field type other than signature, mention, text, a field on no document | `InvalidConfigException`, before any call |
| a webhook that does not hold | `InvalidNotificationException` |

## Verified, and not

| | |
|---|---|
| Against Yousign | **not verified in real: no account** (the sandbox needs one). The answers in `Tests/Fixtures` are written from the OpenAPI's schemas |
| The calls, their bodies, the multipart upload, the webhook's signature | by the tests, against the OpenAPI and the webhook guide |
| Advanced and qualified signatures | the level is sent; the identity checks happen on Yousign's side, not observed |

The package's tests (`Tests/YousignGatewayTest.php`, 6 tests) pass on 2026-10-26 (PHP 8.4).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Yousign (Youtrust). Whoever runs it complies with
[youtrust.com/terms](https://youtrust.com/terms); see the README's Responsibility section.
