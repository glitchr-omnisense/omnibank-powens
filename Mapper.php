<?php

namespace Omnibank\Powens;

use Omnibank\Model\Account;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Consent;
use Omnibank\Model\Money;

/** Powens' accounts, transactions and connection states, read as Omnibank's. */
final class Mapper
{
    /** Connection states the user must act on: through the Webview again (reconnect). */
    public const RENEWAL_STATES = ['SCARequired', 'webauthRequired', 'decoupled', 'additionalInformationNeeded', 'actionNeeded', 'wrongpass', 'passwordExpired'];

    public static function account(array $data): Account
    {
        $currency = \is_array($data['currency'] ?? null) ? ($data['currency']['id'] ?? 'EUR') : ($data['currency'] ?? 'EUR');

        return new Account(
            id: (string) $data['id'],
            iban: isset($data['iban']) && '' !== $data['iban'] ? (string) $data['iban'] : null,
            bic: isset($data['bic']) && '' !== $data['bic'] ? (string) $data['bic'] : null,
            name: (string) ($data['name'] ?? $data['original_name'] ?? $data['number'] ?? $data['id']),
            currency: strtoupper((string) $currency),
            type: isset($data['type']) ? (string) $data['type'] : null,
            raw: $data,
        );
    }

    /** @return list<Balance> booked (balance), and expected (balance with what is coming) when Powens knows what is coming */
    public static function balances(array $data, string $currency): array
    {
        if (!isset($data['balance'])) {
            return [];
        }
        $at = isset($data['last_update']) ? new \DateTimeImmutable((string) $data['last_update']) : new \DateTimeImmutable();
        $booked = Money::fromDecimal((float) $data['balance'], $currency);
        $balances = [new Balance($booked, $at, 'booked')];
        if (isset($data['coming']) && 0.0 !== (float) $data['coming']) {
            $balances[] = new Balance(Money::of($booked->amount + Money::fromDecimal((float) $data['coming'], $currency)->amount, $currency), $at, 'expected');
        }

        return $balances;
    }

    /** A booked transaction; null for one still coming or deleted. */
    public static function transaction(array $data, string $currency): ?BankTransaction
    {
        if (!empty($data['coming']) || !empty($data['deleted']) || !isset($data['date'], $data['value'])) {
            return null;
        }
        $counterparty = \is_array($data['counterparty'] ?? null) ? $data['counterparty'] : [];
        $iban = 'iban' === strtolower((string) ($counterparty['account_scheme_name'] ?? '')) ? ($counterparty['account_identification'] ?? null) : null;

        return new BankTransaction(
            id: (string) $data['id'],
            bookedOn: new \DateTimeImmutable((string) $data['date']),
            valueOn: !empty($data['vdate']) ? new \DateTimeImmutable((string) $data['vdate']) : null,
            amount: Money::fromDecimal((float) $data['value'], $currency),
            label: (string) ($data['original_wording'] ?? $data['wording'] ?? $data['simplified_wording'] ?? ''),
            counterpartyName: isset($counterparty['label']) && '' !== $counterparty['label'] ? (string) $counterparty['label'] : null,
            counterpartyIban: \is_string($iban) && '' !== $iban ? $iban : null,
            reference: null,
            raw: $data,
        );
    }

    /** What a connection's state means for the consent: null is fine, a state the user must act on needs renewal. */
    public static function consent(array $connection): Consent
    {
        $state = $connection['state'] ?? $connection['error'] ?? null;
        $expires = !empty($connection['expire']) ? new \DateTimeImmutable((string) $connection['expire']) : null;

        return \in_array($state, self::RENEWAL_STATES, true) ? Consent::needsRenewal($expires) : Consent::active($expires);
    }
}
