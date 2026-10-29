# omnibank/powens

Powens (ex Budget Insight) for [omnisense/omnibank](https://github.com/glitchr-omnisense/omnibank):
the user connects their banks in Powens' Webview; their accounts, balances and transactions are
read with the user's permanent token; CONNECTION_SYNCED webhooks say when the data is fresh or the
consent must be renewed.

> **Unverified.** Written from Powens' published documentation and recorded answers
> (`Tests/Fixtures`); it has not yet been run against a real Powens sandbox. Try it with real keys
> (`docker compose run --rm omnibank connect powens` in `omnisense/omnibank`'s `docker/`) before
> relying on it, and drop this notice once it holds - the webhook signature above all.

```php
$gateway = (new PowensGatewayFactory($http))->create(['domain' => '...', 'client_id' => '...', 'client_secret' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `omnisense/omnibank` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibank:
    gateways:
        banks:
            factory: powens
            options:
                domain: 'nakaya-sandbox.biapi.pro'               # the client's domain in the Powens console
                client_id: '%env(POWENS_CLIENT_ID)%'
                client_secret: '%env(POWENS_CLIENT_SECRET)%'
                redirect_uri: 'https://app.example/bank/back'    # when connect() is given no return URL
                webhook_secret: '%env(POWENS_WEBHOOK_SECRET)%'   # for notify()
                webhook_url: 'https://app.example/bank/webhook'  # the URL registered: its path is signed
                webhook_tolerance: 300                           # seconds a webhook's date may be from now
                language: fr                                     # the Webview's
```

- `connect()` - the first time, `POST /2.0/auth/init` creates the Powens user; its permanent token
  goes in the connection's state (`auth_token`, `user_id`): **keep the connection**. Each time,
  `GET /2.0/auth/token/code` gives a temporary code and the result's URL is the Webview:
  `https://webview.powens.com/connect?domain=…&client_id=…&redirect_uri=…&code=…`. When the
  consent is NEEDS_RENEWAL and the state has `connection_id`, it is the `/reconnect` page of that
  connection. The Webview sends the user back with `connection_id` in the query: put it in the
  state and mark the consent active (`$connection->withState([...] + $connection->state)->withConsent(Consent::active())`).
- `accounts()` - `GET /2.0/users/me/accounts` (deleted ones left out); `balances()` -
  `GET /2.0/users/me/accounts/{id}`: `balance` (`booked`), and with `coming` (`expected`).
- `transactions()` - `GET /2.0/users/me/accounts/{id}/transactions?min_date=&max_date=&limit=`,
  every page (`_links.next.href`, else the next offset while pages come full); coming and deleted
  transactions left out; `original_wording` is the label, `counterparty` its name and IBAN.
- `notify()` - a body with a `connection` is CONNECTION_SYNCED: its `connectionId`, and its
  state as the consent - `SCARequired`, `webauthRequired`, `decoupled`,
  `additionalInformationNeeded`, `actionNeeded`, `wrongpass`, `passwordExpired` are NEEDS_RENEWAL;
  none (or a bank outage: `websiteUnavailable`, `rateLimiting`, `bug`) is ACTIVE; `expire` is its
  expiry. Another body comes back `UNKNOWN`, consent NONE (it says nothing of it).
- No `transfer()` in this version.

**Webhook signature - an assumption.** Powens signs webhooks with the console's webhook secret in
`BI-Signature`, with `BI-Signature-Date`. This package takes base64(HMAC-SHA256(secret,
`POST.<webhook path>.<BI-Signature-Date>.<body>`)), as Powens documents it - which needs
`webhook_url` - compared exactly, and refuses a webhook signed more than `webhook_tolerance`
seconds (300) from now. Check it against a real sandbox webhook.

A 5xx, a 429 or a network failure is an `UnavailableException`, never an empty list.

Credentials: in the Powens console (console.powens.com), a **domain** (`<x>-sandbox.biapi.pro`
for the sandbox), a client application's **client_id** and **client_secret** with the return URL
whitelisted, and a webhook on CONNECTION_SYNCED with its **secret**.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Powens SAS. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Powens's terms
([powens.com/sas-conditions-use](https://www.powens.com/sas-conditions-use/)), for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
