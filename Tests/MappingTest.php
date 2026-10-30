<?php

namespace Omnibank\Powens\Tests;

use Omnibank\Exception\ProviderException;
use Omnibank\GatewayInterface;
use Omnibank\Model\AccountKind;
use Omnibank\Model\Account;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use Omnibank\Model\TransactionCategory;
use Omnibank\Powens\Api;
use Omnibank\Powens\Mapper;
use Omnibank\Powens\PowensGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Powens' own words - account types, categories and transaction types,
 * connection states - read as Omnibank's kinds, categories and states, on
 * recorded answers: what docs.powens.com publishes, and a word it does not.
 */
final class MappingTest extends TestCase
{
    private const SECRET = 'pwns_whsec';

    /** @var list<string> */
    private array $urls = [];

    private function gateway(array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->urls[] = $url;
            $path = (string) parse_url($url, \PHP_URL_PATH);

            return match ($path) {
                '/2.0/users/me/accounts' => new MockResponse(self::fixture('accounts-kinds.json')),
                '/2.0/users/me/accounts/5101/transactions' => new MockResponse(self::fixture('transactions-categorized.json')),
                '/2.0/users/me/accounts/5199/transactions' => new MockResponse('{"code":"wrongpass","description":"Wrong password"}', ['http_code' => 400]),
                default => new MockResponse('{"code":"notFound"}', ['http_code' => 404]),
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

    private static function connected(): Connection
    {
        return new Connection(['auth_token' => 'pwns_test_not_a_real_token'], Consent::active());
    }

    public function testEachAccountTypeHasItsKindAndTheWordStays(): void
    {
        $accounts = [];
        foreach ($this->gateway()->accounts(self::connected()) as $account) {
            $accounts[$account->name] = $account;
        }

        $kinds = array_map(static fn (Account $a) => $a->kind, $accounts);
        self::assertSame([
            'Compte courant' => AccountKind::CHECKING,
            'Livret A' => AccountKind::SAVINGS,
            'Carte Visa Premier' => AccountKind::CARD,
            'Prêt immobilier' => AccountKind::LOAN,
            'Crédit conso' => AccountKind::CONSUMER_CREDIT,
            'Réserve' => AccountKind::REVOLVING,
            'PEA' => AccountKind::SAVINGS_PLAN,
            'Assurance vie' => AccountKind::LIFE_INSURANCE,
            'Compte-titres' => AccountKind::MARKET,
            'PER' => AccountKind::RETIREMENT,
            'Nouveau produit' => AccountKind::UNKNOWN,
        ], $kinds);
        self::assertSame('livret_a', $accounts['Livret A']->type, 'the provider\'s word kept');
        self::assertSame('spaceship', $accounts['Nouveau produit']->type);
    }

    public function testAnAccountsErrorIsItsConnectionsState(): void
    {
        $states = [];
        foreach ($this->gateway()->accounts(self::connected()) as $account) {
            $states[$account->name] = $account->state;
        }

        self::assertSame(ConnectionState::OK, $states['Compte courant'], 'error null: the last sync went through');
        self::assertSame(ConnectionState::ACTION_NEEDED, $states['Assurance vie']);
        self::assertSame(ConnectionState::BANK_UNAVAILABLE, $states['Compte-titres']);
        self::assertSame(ConnectionState::UNKNOWN, $states['PER'], 'a word Powens has not published: unknown, not an error');
        self::assertNull($states['Nouveau produit'], 'no error field: the account says nothing of its connection');
    }

    public function testCategoriesThenTypesThenNothing(): void
    {
        $gateway = $this->gateway();
        $account = $gateway->accounts(self::connected())[0];
        $categories = [];
        foreach ($gateway->transactions(self::connected(), $account) as $transaction) {
            $categories[$transaction->label] = $transaction->category;
        }

        self::assertSame([
            'VIR SEPA SALAIRE OCTOBRE' => TransactionCategory::INCOME,
            'CB CARREFOUR 01/10' => TransactionCategory::FOOD,
            'PRLV LOYER' => TransactionCategory::HOUSING,
            'PRLV MAAF' => TransactionCategory::INSURANCE,
            'RETRAIT DAB' => TransactionCategory::CASH,
            'COTISATION CARTE' => TransactionCategory::BANK_FEES,
            'CB SPOTIFY' => TransactionCategory::SUBSCRIPTIONS,
            'CB ???' => TransactionCategory::UNKNOWN,
            'CB LIBRAIRIE' => null,
            'CB PHARMACIE' => TransactionCategory::HEALTH,
        ], $categories);
        self::assertStringNotContainsString('expand=categories', end($this->urls), 'not asked where categorisation is not enabled');
    }

    public function testTheCategoriesOptionAsksForThem(): void
    {
        $gateway = $this->gateway(['categories' => true]);
        $gateway->transactions(self::connected(), $gateway->accounts(self::connected())[0]);

        self::assertStringContainsString('expand=categories', end($this->urls));
    }

    public function testARefusalCarriesTheConnectionsState(): void
    {
        $account = new Account('5199', null, null, 'Compte', 'EUR', 'checking');
        try {
            $this->gateway()->transactions(self::connected(), $account);
            self::fail('Powens refused');
        } catch (ProviderException $e) {
            self::assertSame('wrongpass', $e->providerCode);
            self::assertSame(ConnectionState::WRONG_CREDENTIALS, $e->connectionState);
            self::assertSame(ConnectionState::WRONG_CREDENTIALS, ConnectionState::ofFailure($e));
        }
    }

    public function testAWebhookSaysTheConnectionsState(): void
    {
        $body = self::fixture('webhook-connection-wrongpass.json');
        $date = gmdate('D, d M Y H:i:s').' GMT';
        $signature = Api::signatures($body, self::SECRET, $date, 'https://app.example/bank/webhook/powens')[0];
        $notification = $this->gateway()->notify($body, ['BI-Signature' => $signature, 'BI-Signature-Date' => $date]);

        self::assertSame(ConnectionState::WRONG_CREDENTIALS, $notification->state);
        self::assertTrue($notification->consent->status->value === 'needs_renewal');

        $synced = self::fixture('webhook-connection-synced.json');
        $signature = Api::signatures($synced, self::SECRET, $date, 'https://app.example/bank/webhook/powens')[0];
        self::assertSame(ConnectionState::ACTION_NEEDED, $this->gateway()->notify($synced, ['BI-Signature' => $signature, 'BI-Signature-Date' => $date])->state, 'SCARequired');
    }

    public function testEveryPublishedWordIsMapped(): void
    {
        foreach (['article83', 'capitalisation', 'card', 'cat', 'cel', 'checking', 'consumercredit', 'crowdlending', 'csl', 'deposit', 'joint', 'ldds', 'lifeinsurance', 'livret_a', 'livret_b', 'loan', 'madelin', 'market', 'pea', 'pee', 'per', 'perco', 'perp', 'pel', 'real_estate', 'revolvingcredit', 'rsp', 'savings'] as $type) {
            self::assertNotSame(AccountKind::UNKNOWN, Mapper::kind($type), $type);
        }
        foreach (['SCARequired', 'webauthRequired', 'additionalInformationNeeded', 'decoupled', 'validating', 'actionNeeded', 'passwordExpired', 'wrongpass', 'rateLimiting', 'websiteUnavailable', 'bug'] as $state) {
            self::assertNotSame(ConnectionState::UNKNOWN, Mapper::connectionState($state), $state);
        }
        foreach (['administration_and_taxes', 'bank_insurances', 'food_and_restaurants', 'household', 'healthcare', 'housing', 'leisure', 'loans', 'media_and_telecommunications', 'others', 'revenue', 'shopping', 'travel_and_transport'] as $parent) {
            self::assertNotSame(TransactionCategory::UNKNOWN, Mapper::category(['categories' => [['code' => 'x_'.$parent, 'parent_code' => $parent]]]), $parent);
        }
    }
}
