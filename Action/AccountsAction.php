<?php

namespace Omnibank\Powens\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Powens\Api;
use Omnibank\Powens\Mapper;
use Omnibank\Request\FetchAccounts;
use Omnibank\Request\Request;

/** The user's accounts, every bank connected (GET /users/me/accounts). */
final class AccountsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchAccounts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchAccounts);
        $data = $this->api->call('GET', '/users/me/accounts', Api::token($request->connection));
        $accounts = array_filter((array) ($data['accounts'] ?? []), static fn ($a) => \is_array($a) && isset($a['id']) && empty($a['deleted']));
        $request->setResult(array_values(array_map(Mapper::account(...), $accounts)));
    }
}
