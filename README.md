# omnisign/yousign

**Yousign** - Youtrust since the company's new name - for
[omniscient/omnisign](https://github.com/glitchr-studio/omnisign): simple, advanced and qualified
electronic signatures (eIDAS) through its public API v3, by e-mail or in the application's page.

```php
use Omnisign\Yousign\YousignGatewayFactory;

$gateway = (new YousignGatewayFactory($httpClient))->create(['api_key' => getenv('YOUSIGN_API_KEY'), 'sandbox' => true]);

$envelope = $gateway->send($gateway->create($lease));     // a signature request, its documents, signers and fields, activated
$gateway->signingUrl($envelope, 'tenant')->url;           // the signer's signature_link (embedded)
$gateway->download($gateway->fetch($envelope));           // the signed PDF and the audit trail, once done
```

Written from Yousign's public API v3 OpenAPI, read on 2026-10-08. **Not verified in real: no
account.**

[Documentation](docs/index.md): the options, how an envelope becomes a signature request, levels
and authentication, the webhooks, what was verified.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Yousign (now Youtrust). It reaches their service on behalf of whoever runs it, and it
does not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Youtrust's terms
([youtrust.com/terms](https://youtrust.com/terms)), for obtaining whatever they require (an account,
a key, a licence or subscription, an attribution or a citation, a rate limit to respect), and for
any use made of what it returns. The authors publish it free of charge, make no commercial use of
it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
