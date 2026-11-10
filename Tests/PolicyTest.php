<?php

namespace Omnisign\Yousign\Tests;

use Omnisign\Exception\UsageLimitReachedException;
use Omnisign\Model\Envelope;
use Omnisign\Registry;
use Omnisign\Yousign\YousignGatewayFactory;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Yousign's usage policy: declared in policy.json, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsDeclaredAndRead(): void
    {
        $declared = UsagePolicy::fromFile(__DIR__.'/../policy.json');
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

        self::assertIsArray($composer);
        $extra = $composer['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertSame(['policy' => 'policy.json'], $extra['omnisense'] ?? null);
        self::assertSame('yousign', $declared->provider);
        self::assertSame('2026-11-10', $declared->provenance->verifiedAt);
        self::assertSame($declared->toArray(), YousignGatewayFactory::usagePolicy()->toArray());
        self::assertNotNull($declared->rateLimits);
        self::assertSame([[60, 'minute', 1], [1200, 'hour', 1]], array_map(static fn ($limit) => [$limit->max, $limit->window->value, $limit->every], $declared->rateLimits));
        $sandbox = YousignGatewayFactory::sandboxPolicy();
        self::assertNotNull($sandbox->rateLimits);
        self::assertCount(4, $sandbox->rateLimits, 'the sandbox\'s limits beside production\'s, the strictest counting');

        $registry = new Registry([new YousignGatewayFactory(new MockHttpClient())], ['yousign' => ['factory' => 'yousign', 'options' => self::options()]]);
        self::assertSame(YousignGatewayFactory::sandboxPolicy()->toArray(), $registry->policy('yousign')->toArray());
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"error":"too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30', 'content-type' => 'application/json']]);
        });
        $registry = new Registry([new YousignGatewayFactory($http)], ['yousign' => ['factory' => 'yousign', 'options' => self::options()]]);

        try {
            $registry->get('yousign')->fetch(new Envelope('Bail', reference: 'sr-1'));
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        try {
            $registry->get('yousign')->fetch(new Envelope('Bail', reference: 'sr-1'));
            self::fail('A call went out while Yousign asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertSame(1, $requests, 'the wait held the next request back');
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return ['api_key' => 'ys-api-key', 'sandbox' => true];
    }
}
