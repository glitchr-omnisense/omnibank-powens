<?php

namespace Omnibank\Powens;

use Omnibank\Config;
use Omnibank\GatewayFactory;
use Omnibank\Powens\Action\AccountsAction;
use Omnibank\Powens\Action\BalancesAction;
use Omnibank\Powens\Action\ConnectAction;
use Omnibank\Powens\Action\NotifyAction;
use Omnibank\Powens\Action\TransactionsAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Powens (ex Budget Insight): the user connects their banks in Powens'
 * Webview; accounts, balances and transactions are read with the user's
 * permanent token, kept in the connection's state; CONNECTION_SYNCED
 * webhooks say when the data is fresh or the consent must be renewed. No
 * transfers in this version.
 *
 *   options:
 *     domain: 'nakaya-sandbox.biapi.pro'                # the client's domain in the Powens console
 *     client_id: '%env(POWENS_CLIENT_ID)%'
 *     client_secret: '%env(POWENS_CLIENT_SECRET)%'
 *     redirect_uri: 'https://app.example/bank/back'     # when connect() is given no return URL; whitelisted in the console
 *     webhook_secret: '%env(POWENS_WEBHOOK_SECRET)%'    # for notify()
 *     webhook_url: 'https://app.example/bank/webhook'   # the URL registered, its path signed
 *     webview: 'https://webview.powens.com'
 *     language: null                                    # "fr", "en"...: the Webview's
 */
final class PowensGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'powens',
            'omnibank.factory_title' => 'Powens',
            'omnibank.required_options' => ['domain', 'client_id', 'client_secret'],
            'redirect_uri' => null,
            'webhook_secret' => null,
            'webhook_url' => null,
            'webview' => Api::WEBVIEW,
            'language' => null,
            'omnibank.api' => fn (Config $c) => new Api(
                $this->http ?? HttpClient::create(),
                preg_replace('#^https?://|/.*$#', '', (string) $c['domain']),
                (string) $c['client_id'],
                (string) $c['client_secret'],
                $c['webhook_secret'] ?: null,
                $c['webhook_url'] ?: null,
                (string) $c['webview'],
                $c['language'] ?: null,
            ),
            'omnibank.action.connect' => static fn (Config $c) => new ConnectAction($c['redirect_uri'] ?: null),
            'omnibank.action.accounts' => new AccountsAction(),
            'omnibank.action.balances' => new BalancesAction(),
            'omnibank.action.transactions' => new TransactionsAction(),
            'omnibank.action.notify' => new NotifyAction(),
        ]);
    }
}
