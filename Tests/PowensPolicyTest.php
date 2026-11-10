<?php

namespace Omnibank\Powens\Tests;

use Omnibank\Exception\RateLimitedException;
use Omnibank\Exception\UsageLimitReachedException;
use Omnibank\Model\Connection;
use Omnibank\Powens\PowensGatewayFactory;
use Omnibank\Registry;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Powens' usage policy (production limits unknown: Powens publishes only its
 * sandbox's; contract-only) and a 429 to the user's creation, the one write
 * of connect(): sent once, surfaced, the next calls held unsent for the wait.
 */
final class PowensPolicyTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared);
        self::assertSame(['omnibank/powens', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0]);
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('powens', $policy->provider);
        self::assertNull($policy->rateLimits, 'not published for production: unknown');
        self::assertSame(Permission::Contract, $policy->rights->commercial);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertStringContainsString('four times in a 24-hour period', (string) $policy->provenance->notes);
    }

    public function testA429ToTheUsersCreationIsSentOnceAndHoldsTheNextCalls(): void
    {
        $answers = [new MockResponse('{"code":"tooManyRequests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '45']])];
        $http = new MockHttpClient(function (string $method, string $url) use (&$answers): MockResponse {
            $this->calls[] = $method.' '.parse_url($url, \PHP_URL_PATH);

            return array_shift($answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });
        $gateway = (new Registry([new PowensGatewayFactory($http)], ['bank' => ['factory' => 'powens', 'options' => ['domain' => 'nakaya-sandbox.biapi.pro', 'client_id' => '12345678', 'client_secret' => 'pwns_secret']]]))->guarded('bank');

        try {
            $gateway->connect(new Connection(), 'https://app.example/bank/back');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(45, $e->retryAfter);
        }
        self::assertSame(['POST /2.0/auth/init'], $this->calls, 'the user asked for once, never again');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $gateway->connect(new Connection(), 'https://app.example/bank/back');
        } finally {
            self::assertCount(1, $this->calls, 'held, not sent');
        }
    }
}
