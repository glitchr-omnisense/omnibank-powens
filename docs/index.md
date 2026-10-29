# omnibank/powens

[Powens](https://www.powens.com) (ex Budget Insight) for
[omnisense/omnibank](https://github.com/glitchr-omnisense/omnibank): the user connects their banks
in Powens' Webview; their accounts, balances and transactions are read with the user's permanent
token; CONNECTION_SYNCED webhooks say when the data is fresh or the consent must be renewed. The
gateway calls Powens' API 2.0 on the client's domain (`https://<domain>/2.0/`):

| Operation | Path |
|---|---|
| a new user (first `connect()`) | `POST /2.0/auth/init` (client id and secret in the body) |
| a temporary code (each `connect()`) | `GET /2.0/auth/token/code` |
| accounts | `GET /2.0/users/me/accounts` |
| balances | `GET /2.0/users/me/accounts/{id}` |
| transactions | `GET /2.0/users/me/accounts/{id}/transactions` |

and opens the Webview at `https://webview.powens.com[/<language>]/connect` (or `/reconnect`). A
user's calls send `Authorization: Bearer <permanent token>`, with a 30-second timeout.

> [!WARNING]
> Unverified: written from Powens' published documentation and recorded answers (`Tests/Fixtures`); not yet run against a real Powens sandbox - the webhook signature above all.

## Installation

```sh
composer require omnisense/omnibank omnibank/powens
```

Requires PHP 8.2, `omnisense/omnibank` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnibank\Bridge\Symfony\OmnibankBundle`, the factory is registered on the
application's `http_client` as soon as the package is installed.

## Credentials

In the Powens console (console.powens.com): a **domain** (`<x>-sandbox.biapi.pro` for the
sandbox), a client application's **client_id** and **client_secret** with the return URL
whitelisted, and a webhook on CONNECTION_SYNCED with its **secret**.

## Options

`new PowensGatewayFactory(?HttpClientInterface $http = null)` (none given: `HttpClient::create()`);
options of `create()`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `domain` | string | - | yes | the client's domain; a scheme or a path given with it is cut off |
| `client_id` | string | - | yes | |
| `client_secret` | string | - | yes | |
| `redirect_uri` | ?string | `null` | no | the return URL when `connect()` is given an empty one |
| `webhook_secret` | ?string | `null` | for `notify()` | |
| `webhook_url` | ?string | `null` | for `notify()` | the webhook's URL as registered: its path is part of the signed string |
| `webhook_tolerance` | int | `300` | no | the seconds a webhook's `BI-Signature-Date` may be from now |
| `webview` | string | `https://webview.powens.com` | no | |
| `language` | ?string | `null` | no | `fr`, `en`...: the Webview's, in its path |

The connection's state (kept by the application): `auth_token` and `user_id` (set by the first
`connect()`), `connection_id` (put there by the application from the Webview's return, or from a
webhook).

## Capabilities

| Request | Supported |
|---|---|
| `Connect` | yes: the Webview's page |
| `FetchAccounts` | yes |
| `FetchBalances` | yes |
| `FetchTransactions` | yes |
| `Transfer` | no, in this version |
| `Notify` | yes: CONNECTION_SYNCED |

## Behaviour

**Connect.** Without an `auth_token` in the state, `POST /auth/init` creates the Powens user: its
`auth_token` and `id_user` go in the state (`auth_token`, `user_id`). Each time,
`GET /auth/token/code` gives a temporary code and the result's URL is the Webview:
`<webview>[/<language>]/connect?domain=…&client_id=…&redirect_uri=…&code=…`. When the connection's
consent is NEEDS_RENEWAL and the state has a `connection_id`, it is
`/reconnect?…&connection_id=<id>` for that bank connection. `connect()` does not change the
consent: the application marks it active on the return (the Webview adds `connection_id` to the
return URL's query). An empty return URL takes `redirect_uri`; without either, an
`InvalidConfigException`.

**Accounts.** The user's accounts, every bank connected; those with `deleted` set left out. `id`,
`iban`, `bic` (empty strings read as none), `name` (else `original_name`, `number`, the id),
`currency` (an object's `id`, or the string; EUR by default), `type`; the account whole in `raw`.

**Balances.** `balance` as `booked`, and `balance + coming` as `expected` when `coming` is not 0,
both at `last_update` (now when absent). Amounts read from Powens' decimals.

**Transactions.** `min_date` / `max_date` the days asked, `limit=1000`, `offset=0`; the next page
is Powens' `_links.next.href` when it gives one, else the next offset while a page comes full (up to
1000 pages). A link to another host than the client's domain is refused (`ProviderException`), so
the user's token is never sent elsewhere. Lines with `coming` or `deleted` set, or without `date`
and `value`, are left out; the others kept between the days asked, each once by id, oldest first:

- `bookedOn` `date`, `valueOn` `vdate`; `amount` `value` (signed by Powens);
- `label` `original_wording` (else `wording`, `simplified_wording`);
- `counterpartyName` the `counterparty`'s `label`; `counterpartyIban` its `account_identification`
  when its `account_scheme_name` is `iban`; no `reference`;
- `raw` the line.

**Webhooks.** [notify()](https://github.com/glitchr-omnisense/omnibank/blob/1.x/docs/usage-webhooks.md):
the signature checked first (`BI-Signature`, `BI-Signature-Date`: base64 of the HMAC-SHA256 of
`POST.<webhook_url's path>.<date>.<body>`, compared exactly; `webhook_url` and the date required),
then its age (a `BI-Signature-Date` more than `webhook_tolerance` seconds from now is "too old": a
webhook captured and sent again is refused), then the body read: `type` from its `type` or `event`, else `CONNECTION_SYNCED` when it has a
`connection`, else `UNKNOWN`; `connectionId` the connection's `id`; the consent from the
connection's `state` (or `error`) - `SCARequired`, `webauthRequired`, `decoupled`,
`additionalInformationNeeded`, `actionNeeded`, `wrongpass`, `passwordExpired` are NEEDS_RENEWAL,
anything else ACTIVE, `expire` its expiry; no connection: NONE.

## Example

The connect flow on a mock client is in the family's manual
([connecting](https://github.com/glitchr-omnisense/omnibank/blob/1.x/docs/usage-connecting.md#an-aggregators-flow)).
Reading, on the answers the package's tests keep:

```php
<?php // powens.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use Omnibank\Powens\PowensGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The answers omnibank/powens's tests keep, written from Powens' documentation; each request printed
$fixtures = '/ws/powens/Tests/Fixtures';   // the package's Tests/Fixtures, here in the family's workspace
$http = new MockHttpClient(function (string $method, string $url) use ($fixtures) {
    echo '  ', $method, ' ', urldecode($url), "\n";
    $path = parse_url($url, PHP_URL_PATH);

    return new MockResponse(file_get_contents($fixtures.'/'.match (true) {
        str_ends_with($path, '/accounts') => 'accounts.json',
        str_ends_with($path, '/accounts/4211') => 'account-4211.json',
        str_ends_with($path, '/transactions') => str_contains($url, 'offset=') && !str_contains($url, 'offset=0') ? 'transactions-page-2.json' : 'transactions-page-1.json',
    }));
});
$powens = (new PowensGatewayFactory($http))->create(['domain' => 'example-sandbox.biapi.pro', 'client_id' => '12345678', 'client_secret' => 'secret']);

// The connection kept after connect() and the Webview's return
$connection = new Connection(['auth_token' => 'pwns_test_not_a_real_token', 'user_id' => 1987, 'connection_id' => '77'], Consent::active());

$accounts = $powens->accounts($connection);
foreach ($accounts as $account) {
    echo $account->id, ' ', $account->name, ' ', $account->iban ?? '-', ' ', $account->type, "\n";
}
$account = $accounts[0];
foreach ($powens->balances($connection, $account) as $balance) {
    echo '  ', $balance->type, ' ', $balance->amount, ' at ', $balance->at->format('Y-m-d H:i'), "\n";
}
foreach ($powens->transactions($connection, $account, new DateTimeImmutable('2026-09-01')) as $t) {
    printf("  %s %9s  %-34s %s\n", $t->bookedOn->format('Y-m-d'), $t->amount->decimal(), $t->label, $t->counterpartyName ?? '-');
}
```

```
$ php powens.php
  GET https://example-sandbox.biapi.pro/2.0/users/me/accounts
4211 Compte chèque FR6010278060410002051020134 checking
4212 Livret Bleu - savings
  GET https://example-sandbox.biapi.pro/2.0/users/me/accounts/4211
  booked 9178.44 EUR at 2026-09-30 22:15
  expected 9057.94 EUR at 2026-09-30 22:15
  GET https://example-sandbox.biapi.pro/2.0/users/me/accounts/4211/transactions?min_date=2026-09-01&limit=1000&offset=0
  GET https://example-sandbox.biapi.pro/2.0/users/me/accounts/4211/transactions?min_date=2026-09-01&max_date=2026-09-30&limit=2&offset=2
  2026-09-01  -1234.56  PRLV SEPA SCI DES TILLEULS LOYER SEPTEMBRE SCI DES TILLEULS
  2026-09-02   2500.00  VIR SEPA RECU /DE NAKAYA SAS /MOTIF SALAIRE NAKAYA SAS
  2026-09-03     -3.50  PAIEMENT CB 0209 PARIS CAFE DE LA GARE -
```

(run on 2026-10-26, PHP 8.4.27, no network; the user, the accounts and the amounts are made up)

In Symfony:

```yaml
omnibank:
    gateways:
        banks:
            factory: powens
            options:
                domain: '%env(POWENS_DOMAIN)%'
                client_id: '%env(POWENS_CLIENT_ID)%'
                client_secret: '%env(POWENS_CLIENT_SECRET)%'
                redirect_uri: 'https://app.example/bank/back'
                webhook_secret: '%env(POWENS_WEBHOOK_SECRET)%'
                webhook_url: 'https://app.example/bank/webhook'
                language: fr
```

## Errors

| Case | Exception |
|---|---|
| a transport failure, HTTP 5xx, 429, a body that is not JSON | `UnavailableException` (`[powens] Powens answered HTTP 503: try again later.`) |
| another 4xx | `ProviderException`, the message Powens' `description` (else `message`, `code`), `providerCode` its `code` or the status |
| no `auth_token` in the state for a user's call | `ProviderException` (`The connection has no Powens user yet: connect() first.`) |
| Powens gives no token or no code | `ProviderException` |
| a pagination link out of the client's domain | `ProviderException` |
| a webhook without a secret configured, unsigned or with a wrong signature | `InvalidNotificationException` |
| a signed webhook body that is not JSON | `ProviderException` |
| missing `domain`, `client_id` or `client_secret`; no return URL | `InvalidConfigException` |
| `transfer()` | `RequestNotSupportedException` |

## Verified, and not

- Verified (the package's tests, 10, green on 2026-10-26 in the family's workspace): the first
  connect creates the user and opens the Webview; a consent to renew opens the reconnect page;
  connect without a return URL needs the option; the user's accounts and balances; transactions
  every page, booked only; not connected says so; a CONNECTION_SYNCED webhook checked then read, one
  not signed with the secret refused; an outage never "no data"; no transfers.
- Not verified: against a real Powens sandbox; the webhook signature as Powens really sends it.

## Responsibility

This package is an independent, unofficial client, not affiliated with, endorsed by or sponsored by
Powens SAS. Whoever runs it is responsible for complying with Powens's terms
([powens.com/sas-conditions-use](https://www.powens.com/sas-conditions-use/)) and for what is done
with what it returns; see the README's Responsibility section.
