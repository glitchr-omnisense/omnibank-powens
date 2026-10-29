<?php

namespace Omnibank\Powens\Tests;

use Omnibank\Exception\InvalidNotificationException;
use Omnibank\Exception\ProviderException;
use Omnibank\Exception\RequestNotSupportedException;
use Omnibank\Exception\UnavailableException;
use Omnibank\GatewayInterface;
use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use Omnibank\Model\ConsentStatus;
use Omnibank\Model\Money;
use Omnibank\Model\Transfer as TransferModel;
use Omnibank\Powens\Api;
use Omnibank\Powens\PowensGatewayFactory;
use Omnibank\Request\Transfer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PowensGatewayTest extends TestCase
{
    private const SECRET = 'pwns_whsec';
    private const TOKEN = 'pwns_test_not_a_real_token';

    /** @var list<array{string, string, array}> method, url, options */
    private array $calls = [];

    private function gateway(?\Closure $answer = null, array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->calls[] = [$method, $url, $options];
            if ($answer) {
                return $answer($method, $url);
            }
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return match (true) {
                'POST' === $method && '/2.0/auth/init' === $path => new MockResponse(self::fixture('auth-init.json')),
                'GET' === $method && '/2.0/auth/token/code' === $path => new MockResponse(self::fixture('token-code.json')),
                'GET' === $method && '/2.0/users/me/accounts' === $path => new MockResponse(self::fixture('accounts.json')),
                'GET' === $method && '/2.0/users/me/accounts/4211' === $path => new MockResponse(self::fixture('account-4211.json')),
                'GET' === $method && '/2.0/users/me/accounts/4211/transactions' === $path => new MockResponse(self::fixture('transactions-page-'.('2' === ($query['offset'] ?? '0') ? 2 : 1).'.json')),
                default => new MockResponse('{"code":"notFound","description":"Not found"}', ['http_code' => 404]),
            };
        });

        return (new PowensGatewayFactory($http))->create($options + [
            'domain' => 'nakaya-sandbox.biapi.pro',
            'client_id' => '12345678',
            'client_secret' => 'pwns_secret',
            'webhook_secret' => self::SECRET,
            'webhook_url' => 'https://app.example/bank/webhook/powens',
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }

    private static function connected(?Consent $consent = null): Connection
    {
        return new Connection(['auth_token' => self::TOKEN, 'user_id' => 1987], $consent ?? Consent::active());
    }

    public function testTheFirstConnectCreatesTheUserAndOpensTheWebview(): void
    {
        $result = $this->gateway()->connect(new Connection(['mine' => 'kept']), 'https://app.example/bank/back');

        self::assertSame(self::TOKEN, $result->connection->get('auth_token'), 'the permanent token kept in the state');
        self::assertSame(1987, $result->connection->get('user_id'));
        self::assertSame('kept', $result->connection->get('mine'), 'the application\'s own keys stay');
        self::assertSame('https://webview.powens.com/connect?domain=nakaya-sandbox.biapi.pro&client_id=12345678&redirect_uri=https%3A%2F%2Fapp.example%2Fbank%2Fback&code=tmp_8fR2mWq1ZpL0', $result->url);

        [$method, $url, $options] = $this->calls[0];
        self::assertSame(['POST', 'https://nakaya-sandbox.biapi.pro/2.0/auth/init'], [$method, $url]);
        self::assertSame(['client_id' => '12345678', 'client_secret' => 'pwns_secret'], json_decode($options['body'], true));
        [$method, $url, $options] = $this->calls[1];
        self::assertSame(['GET', 'https://nakaya-sandbox.biapi.pro/2.0/auth/token/code'], [$method, $url]);
        self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
    }

    public function testAConsentToRenewOpensTheReconnectPage(): void
    {
        $connection = self::connected(Consent::needsRenewal())->withState(['auth_token' => self::TOKEN, 'connection_id' => 77]);
        $result = $this->gateway(options: ['language' => 'fr'])->connect($connection, 'https://app.example/bank/back');

        self::assertCount(1, $this->calls, 'the user exists: only a code');
        self::assertStringStartsWith('https://webview.powens.com/fr/reconnect?', $result->url);
        parse_str((string) parse_url((string) $result->url, \PHP_URL_QUERY), $query);
        self::assertSame('77', $query['connection_id']);
        self::assertSame('tmp_8fR2mWq1ZpL0', $query['code']);
    }

    public function testConnectWithoutAReturnUrlNeedsTheOption(): void
    {
        $result = $this->gateway(options: ['redirect_uri' => 'https://app.example/powens'])->connect(self::connected(), '');
        parse_str((string) parse_url((string) $result->url, \PHP_URL_QUERY), $query);
        self::assertSame('https://app.example/powens', $query['redirect_uri']);
    }

    public function testTheUsersAccountsAndBalances(): void
    {
        $gateway = $this->gateway();
        $accounts = $gateway->accounts(self::connected());

        self::assertCount(2, $accounts, 'a deleted account left out');
        self::assertSame('4211', $accounts[0]->id);
        self::assertSame('FR6010278060410002051020134', $accounts[0]->iban);
        self::assertSame('CMCIFR2A', $accounts[0]->bic);
        self::assertSame('Compte chèque', $accounts[0]->name);
        self::assertSame('EUR', $accounts[0]->currency);
        self::assertSame('checking', $accounts[0]->type);
        self::assertSame(77, $accounts[0]->raw['id_connection']);
        self::assertNull($accounts[1]->iban);

        $balances = $gateway->balances(self::connected(), $accounts[0]);
        self::assertSame(['booked', 'expected'], array_map(static fn ($b) => $b->type, $balances));
        self::assertSame([917844, 905794], array_map(static fn ($b) => $b->amount->amount, $balances));
        self::assertSame('2026-09-30', $balances[0]->at->format('Y-m-d'));
    }

    public function testTransactionsEveryPageBookedOnly(): void
    {
        $gateway = $this->gateway();
        $account = $gateway->accounts(self::connected())[0];
        $transactions = $gateway->transactions(self::connected(), $account, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        self::assertSame(['880101', '880102', '880103'], array_map(static fn ($t) => $t->id, $transactions), 'the next link followed, what is coming left out');
        [$rent, $salary, $coffee] = $transactions;
        self::assertTrue($rent->amount->equals(Money::of(-123456, 'EUR')));
        self::assertSame('PRLV SEPA SCI DES TILLEULS LOYER SEPTEMBRE', $rent->label, 'the bank\'s own wording');
        self::assertSame('SCI DES TILLEULS', $rent->counterpartyName);
        self::assertSame('FR7630004000031234567890143', $rent->counterpartyIban);
        self::assertSame(250000, $salary->amount->amount);
        self::assertSame('2026-09-03', $salary->valueOn?->format('Y-m-d'));
        self::assertSame(-350, $coffee->amount->amount);
        self::assertNull($coffee->counterpartyName);

        $first = array_values(array_filter($this->calls, static fn ($c) => str_ends_with((string) parse_url($c[1], \PHP_URL_PATH), '/transactions')));
        self::assertCount(2, $first);
        self::assertStringContainsString('min_date=2026-09-01', $first[0][1]);
        self::assertStringContainsString('max_date=2026-09-30', $first[0][1]);
        self::assertStringContainsString('offset=2', $first[1][1]);
    }

    public function testNotConnectedSaysSo(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('connect() first');
        $this->gateway()->accounts(new Connection());
    }

    /**
     * The headers Powens sends: BI-Signature, the base64 of the HMAC-SHA256 of
     * "POST.<webhook path>.<BI-Signature-Date>.<body>" (Powens' API documentation, its webhooks'
     * signature: the string signed, the BI-Signature and BI-Signature-Date headers), signed at
     * $date (now by default).
     *
     * @return array<string, string>
     */
    private static function signed(string $body, ?string $date = null): array
    {
        $date ??= gmdate(\DATE_RFC7231);

        return ['BI-Signature' => base64_encode(hash_hmac('sha256', 'POST./bank/webhook/powens.'.$date.'.'.$body, self::SECRET, true)), 'BI-Signature-Date' => $date];
    }

    public function testAConnectionSyncedWebhookCheckedThenRead(): void
    {
        $body = self::fixture('webhook-connection-synced.json');
        $headers = self::signed($body);

        $notification = $this->gateway()->notify($body, ['bi-signature' => $headers['BI-Signature'], 'BI-Signature-Date' => $headers['BI-Signature-Date']]);

        self::assertSame('CONNECTION_SYNCED', $notification->type);
        self::assertSame('77', $notification->connectionId);
        self::assertSame(ConsentStatus::NEEDS_RENEWAL, $notification->consent->status, 'SCARequired: the user must go through the Webview again');
        self::assertSame('2026-12-01', $notification->consent->expiresAt?->format('Y-m-d'));
        self::assertSame(1987, $notification->raw['user']['id']);

        $fine = json_encode(['connection' => ['id' => 78, 'state' => null, 'error' => null]]);
        self::assertSame(ConsentStatus::ACTIVE, $this->gateway()->notify($fine, self::signed($fine))->consent->status);

        foreach (['webauthRequired', 'decoupled', 'wrongpass'] as $state) {
            $body = json_encode(['connection' => ['id' => 79, 'state' => $state]]);
            self::assertSame(ConsentStatus::NEEDS_RENEWAL, $this->gateway()->notify($body, self::signed($body))->consent->status, $state);
        }
        $body = json_encode(['connection' => ['id' => 80, 'state' => 'websiteUnavailable']]);
        self::assertSame(ConsentStatus::ACTIVE, $this->gateway()->notify($body, self::signed($body))->consent->status, 'the bank\'s outage is not the consent\'s');
    }

    public function testAWebhookNotSignedWithTheSecretIsRefused(): void
    {
        $body = self::fixture('webhook-connection-synced.json');
        $this->expectException(InvalidNotificationException::class);
        $this->gateway()->notify($body, ['BI-Signature' => base64_encode(hash_hmac('sha256', $body, 'another', true)), 'BI-Signature-Date' => 'x']);
    }

    /** A webhook captured and sent again later: its signed date is too old. */
    public function testAWebhookSignedTooLongAgoIsRefused(): void
    {
        $body = self::fixture('webhook-connection-synced.json');

        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('too old');
        $this->gateway()->notify($body, self::signed($body, gmdate(\DATE_RFC7231, time() - 3600)));
    }

    public function testTheToleranceIsAnOption(): void
    {
        $body = self::fixture('webhook-connection-synced.json');
        $headers = self::signed($body, gmdate(\DATE_RFC7231, time() - 3600));

        self::assertSame('77', $this->gateway(null, ['webhook_tolerance' => 7200])->notify($body, $headers)->connectionId);
    }

    /** The HMAC of the body alone covers no date: anyone who saw one webhook could send it again. */
    public function testAnUndatedSignatureIsRefused(): void
    {
        $body = self::fixture('webhook-connection-synced.json');
        foreach ([base64_encode(hash_hmac('sha256', $body, self::SECRET, true)), hash_hmac('sha256', $body, self::SECRET)] as $signature) {
            try {
                $this->gateway()->notify($body, ['BI-Signature' => $signature, 'BI-Signature-Date' => gmdate(\DATE_RFC7231)]);
                self::fail('the body\'s own HMAC is taken');
            } catch (InvalidNotificationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidNotificationException::class);
        $this->gateway()->notify($body, ['BI-Signature' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true))]);
    }

    /** base64 is case-sensitive: a signature with its letters' case changed is another signature. */
    public function testABase64SignatureIsComparedExactly(): void
    {
        $body = self::fixture('webhook-connection-synced.json');
        $headers = self::signed($body);
        $headers['BI-Signature'] = strtolower($headers['BI-Signature']);

        $this->expectException(InvalidNotificationException::class);
        $this->gateway()->notify($body, $headers);
    }

    public function testAnOutageIsNeverNoData(): void
    {
        $gateway = $this->gateway(static fn () => new MockResponse('{"code":"internalError"}', ['http_code' => 503]));
        $this->expectException(UnavailableException::class);
        $gateway->accounts(self::connected());
    }

    public function testNoTransfersInThisVersion(): void
    {
        $gateway = $this->gateway();
        self::assertFalse($gateway->supports(Transfer::class));
        $this->expectException(RequestNotSupportedException::class);
        $gateway->transfer(self::connected(), new TransferModel('FR6010278060410002051020134', 'X', 'FR7630004000031234567890143', null, Money::of(100, 'EUR'), 'x'));
    }
}
