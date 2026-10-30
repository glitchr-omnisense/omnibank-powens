<?php

namespace Omnibank\Powens\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Model\BankTransaction;
use Omnibank\Powens\Api;
use Omnibank\Powens\Mapper;
use Omnibank\Request\FetchTransactions;
use Omnibank\Request\Request;

/**
 * The account's booked transactions (GET /users/me/accounts/{id}/transactions,
 * min_date and max_date the dates asked), every page: the next link Powens
 * gives (_links.next.href), else the next offset while pages come full.
 * Transactions still coming, and deleted ones, are left out. With
 * $categories (the "categories" option, for a domain where Powens'
 * categorisation is enabled) each page is asked with expand=categories.
 */
final class TransactionsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const LIMIT = 1000;
    private const MAX_PAGES = 1000;

    public function __construct(private readonly bool $categories = false)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransactions;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransactions);
        $token = Api::token($request->connection);
        $path = '/users/me/accounts/'.rawurlencode($request->account->id).'/transactions';
        $query = array_filter(['min_date' => $request->since?->format('Y-m-d'), 'max_date' => $request->until?->format('Y-m-d'), 'limit' => self::LIMIT, 'offset' => 0, 'expand' => $this->categories ? 'categories' : null], static fn ($v) => null !== $v);

        $transactions = [];
        for ($page = 0; null !== $path && $page < self::MAX_PAGES; ++$page) {
            $data = $this->api->call('GET', $path, $token, null, $query);
            $lines = array_filter((array) ($data['transactions'] ?? []), 'is_array');
            foreach ($lines as $line) {
                $transaction = Mapper::transaction($line, $request->account->currency);
                if (null !== $transaction && $request->covers($transaction->bookedOn)) {
                    $transactions[$transaction->id] = $transaction;
                }
            }
            $next = $data['_links']['next']['href'] ?? null;
            if (\is_string($next) && '' !== $next) {
                [$path, $query] = [$next, []];
            } elseif (\count($lines) >= self::LIMIT) {
                $query['offset'] += self::LIMIT;
            } else {
                $path = null;
            }
        }
        $transactions = array_values($transactions);
        usort($transactions, static fn (BankTransaction $a, BankTransaction $b) => $a->bookedOn <=> $b->bookedOn);

        $request->setResult($transactions);
    }
}
