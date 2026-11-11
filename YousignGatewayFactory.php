<?php

namespace Omnisign\Yousign;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisign\Config;
use Omnisign\GatewayFactory;
use Omnisign\Model\Capabilities;
use Omnisign\Model\Level;
use Omnisign\Model\StatementLimit;
use Omnisign\Model\StatementMode;
use Omnisign\Yousign\Action\CancelAction;
use Omnisign\Yousign\Action\CreateAction;
use Omnisign\Yousign\Action\DownloadAction;
use Omnisign\Yousign\Action\FetchAction;
use Omnisign\Yousign\Action\NotifyAction;
use Omnisign\Yousign\Action\RemindAction;
use Omnisign\Yousign\Action\SendAction;
use Omnisign\Yousign\Action\SigningUrlAction;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Yousign - Youtrust since its new name: simple, advanced and qualified
 * electronic signatures, by e-mail or in the application's page.
 *
 *   options:
 *     api_key: '%env(YOUSIGN_API_KEY)%'          # required
 *     sandbox: false                             # true: api-sandbox.yousign.app - signatures without legal value
 *     webhook_secret: '%env(default::YOUSIGN_WEBHOOK_SECRET)%'   # the subscription's secret: notify() checks X-Yousign-Signature-256 with it
 *     url: ~                                     # another API root
 */
final class YousignGatewayFactory extends GatewayFactory
{
    private static ?UsagePolicy $policy = null;

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    /** Its usage policy, as policy.json declares it (read once). */
    public static function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    /** In the sandbox, its usage policy with the sandbox's own limits. */
    public static function sandboxPolicy(): UsagePolicy
    {
        return self::usagePolicy()->tighten(['rateLimits' => [['max' => 30, 'window' => 'minute', 'scope' => 'key', 'note' => 'Sandbox: 30 requests a minute'], ['max' => 200, 'window' => 'hour', 'scope' => 'key', 'note' => 'Sandbox: 200 requests an hour']]]);
    }

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnisign.factory_name' => 'yousign',
            'omnisign.factory_title' => 'Yousign',
            'omnisign.required_options' => ['api_key'],
            'sandbox' => false,
            'webhook_secret' => null,
            'url' => null,
            'omnisign.capabilities' => static fn (Config $c) => new Capabilities([Level::SIMPLE, Level::ADVANCED, Level::QUALIFIED], embedded: true, ordered: true, identityCheck: true, thirdParty: true, policy: $c->bool('sandbox') ? self::sandboxPolicy() : self::usagePolicy(), statements: self::statements()),
            'omnisign.api' => fn (Config $c) => new Api(
                $this->http ?? HttpClient::create(),
                Data::cast($c['api_key']),
                rtrim(Data::cast($c->get('url') ?: ($c->bool('sandbox') ? Api::SANDBOX_URL : Api::URL)), '/'),
                Data::cast($c->get('webhook_secret')) ?: null,
                $c->backoff(),
            ),
            'omnisign.action.create' => new CreateAction(),
            'omnisign.action.send' => new SendAction(),
            'omnisign.action.fetch' => new FetchAction(),
            'omnisign.action.remind' => new RemindAction(),
            'omnisign.action.cancel' => new CancelAction(),
            'omnisign.action.download' => new DownloadAction(),
            'omnisign.action.signing_url' => new SigningUrlAction(),
            'omnisign.action.notify' => static fn (Config $c) => $c->get('webhook_secret') ? new NotifyAction() : null,
        ]);
    }

    /**
     * The statements Yousign carries, as its API v3 OpenAPI says
     * (developers.youtrust.com/openapi/public-api-v3.json, read 2026-11-11):
     * typed in a signer's text field (max_length 1 to 32767, the statement
     * shown as its instruction, the question 255 characters at most), or
     * acknowledged as a read-only text on the document (text 5000 characters
     * at most). Its "mention" field (255 at most) is written by Yousign, not
     * typed by the signer: no statement.
     *
     * @return list<StatementLimit>
     */
    public static function statements(): array
    {
        $source = 'Yousign API v3 OpenAPI, developers.youtrust.com/openapi/public-api-v3.json, read 2026-11-11';

        return [
            new StatementLimit(StatementMode::TYPED, 32767, StatementLimit::CHARACTERS, $source.': signer field "text", max_length 1-32767'),
            new StatementLimit(StatementMode::ACKNOWLEDGED, 5000, StatementLimit::CHARACTERS, $source.': document field "read_only_text", text maxLength 5000'),
        ];
    }
}
