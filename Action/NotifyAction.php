<?php

namespace Omnibank\Powens\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Exception\ProviderException;
use Omnibank\Model\Consent;
use Omnibank\Model\Notification;
use Omnibank\Powens\Api;
use Omnibank\Powens\Mapper;
use Omnibank\Request\Notify;
use Omnibank\Request\Request;

/**
 * A webhook, its signature checked (BI-Signature, see Api). Powens sends one
 * kind of event per registered URL and does not name it in the body: a body
 * with a "connection" is read as CONNECTION_SYNCED, its connection's state
 * as the consent (SCARequired, webauthRequired, decoupled, wrongpass... need
 * renewal). Any other body is handed back as "UNKNOWN", consent NONE - it
 * says nothing of it - to read from $raw.
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $this->api->verify($request->body, $request->header('BI-Signature'), $request->header('BI-Signature-Date'));

        $event = json_decode($request->body, true);
        if (!\is_array($event)) {
            throw new ProviderException('powens', 'The notification is not JSON.');
        }
        $connection = \is_array($event['connection'] ?? null) ? $event['connection'] : null;

        $request->setResult(new Notification(
            type: (string) ($event['type'] ?? $event['event'] ?? (null !== $connection ? 'CONNECTION_SYNCED' : 'UNKNOWN')),
            connectionId: isset($connection['id']) ? (string) $connection['id'] : null,
            consent: null !== $connection ? Mapper::consent($connection) : Consent::none(),
            raw: $event,
        ));
    }
}
