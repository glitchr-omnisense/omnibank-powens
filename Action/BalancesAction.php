<?php

namespace Omnibank\Powens\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Powens\Api;
use Omnibank\Powens\Mapper;
use Omnibank\Request\FetchBalances;
use Omnibank\Request\Request;

/** The account's balance as of its last sync (GET /users/me/accounts/{id}), and with what is coming. */
final class BalancesAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchBalances;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchBalances);
        $data = $this->api->call('GET', '/users/me/accounts/'.rawurlencode($request->account->id), Api::token($request->connection));
        $request->setResult(Mapper::balances($data, $request->account->currency));
    }
}
