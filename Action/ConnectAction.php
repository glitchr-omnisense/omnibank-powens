<?php

namespace Omnibank\Powens\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Exception\InvalidConfigException;
use Omnibank\Model\ConnectResult;
use Omnibank\Model\ConsentStatus;
use Omnibank\Powens\Api;
use Omnibank\Request\Connect;
use Omnibank\Request\Request;

/**
 * The Webview page the user connects a bank on. The first time, a Powens
 * user is created (POST /auth/init) and its permanent token kept in the
 * connection's state ("auth_token", "user_id"); each time, a temporary code
 * (GET /auth/token/code) opens the Webview. When the consent needs renewal
 * and the state names the connection ("connection_id" - the Webview hands it
 * back on the return URL, the webhook gives it too), the page is the
 * reconnect one for that connection.
 */
final class ConnectAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct(private readonly ?string $redirectUri = null)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Connect;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Connect);
        $redirectUri = '' !== $request->returnUrl ? $request->returnUrl : ($this->redirectUri ?? throw new InvalidConfigException('The "powens" gateway needs a return URL: connect()\'s, or its "redirect_uri" option.'));

        $state = $request->connection->state;
        if (!\is_string($state['auth_token'] ?? null) || '' === $state['auth_token']) {
            $user = $this->api->createUser();
            $state['auth_token'] = $user['auth_token'];
            $state['user_id'] = $user['id_user'];
        }
        $code = $this->api->temporaryCode($state['auth_token']);
        $renew = ConsentStatus::NEEDS_RENEWAL === $request->connection->consent->status && isset($state['connection_id']) && '' !== (string) $state['connection_id'];

        $request->setResult(new ConnectResult(
            $this->api->webviewUrl($code, $redirectUri, $renew ? (string) $state['connection_id'] : null),
            $request->connection->withState($state),
        ));
    }
}
