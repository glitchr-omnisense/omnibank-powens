<?php

namespace Omnibank\Powens;

use Omnibank\Model\Account;
use Omnibank\Model\AccountKind;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\Consent;
use Omnibank\Model\Money;
use Omnibank\Model\TransactionCategory;

/** Powens' accounts, transactions and connection states, read as Omnibank's. */
final class Mapper
{
    /** Connection states the user must act on: through the Webview again (reconnect). */
    public const RENEWAL_STATES = ['SCARequired', 'webauthRequired', 'decoupled', 'additionalInformationNeeded', 'actionNeeded', 'wrongpass', 'passwordExpired'];

    /**
     * Powens' account types (docs.powens.com, Bank account types), as
     * Omnibank's kinds. joint is deprecated by Powens and read as checking.
     */
    public const ACCOUNT_KINDS = [
        'checking' => AccountKind::CHECKING,
        'joint' => AccountKind::CHECKING,
        'card' => AccountKind::CARD,
        'savings' => AccountKind::SAVINGS,
        'deposit' => AccountKind::SAVINGS,
        'livret_a' => AccountKind::SAVINGS,
        'livret_b' => AccountKind::SAVINGS,
        'ldds' => AccountKind::SAVINGS,
        'csl' => AccountKind::SAVINGS,
        'cat' => AccountKind::TERM_DEPOSIT,
        'loan' => AccountKind::LOAN,
        'consumercredit' => AccountKind::CONSUMER_CREDIT,
        'revolvingcredit' => AccountKind::REVOLVING,
        'market' => AccountKind::MARKET,
        'lifeinsurance' => AccountKind::LIFE_INSURANCE,
        'pea' => AccountKind::SAVINGS_PLAN,
        'pee' => AccountKind::SAVINGS_PLAN,
        'pel' => AccountKind::SAVINGS_PLAN,
        'cel' => AccountKind::SAVINGS_PLAN,
        'capitalisation' => AccountKind::SAVINGS_PLAN,
        'per' => AccountKind::RETIREMENT,
        'perco' => AccountKind::RETIREMENT,
        'perp' => AccountKind::RETIREMENT,
        'madelin' => AccountKind::RETIREMENT,
        'article83' => AccountKind::RETIREMENT,
        'rsp' => AccountKind::RETIREMENT,
        'real_estate' => AccountKind::OTHER_INVESTMENT,
        'crowdlending' => AccountKind::OTHER_INVESTMENT,
        'unknown' => AccountKind::UNKNOWN,
    ];

    /**
     * Powens' connection states (docs.powens.com, SCA and connection states;
     * null is a synchronisation that went through), as Omnibank's.
     */
    public const CONNECTION_STATES = [
        'SCARequired' => ConnectionState::ACTION_NEEDED,
        'webauthRequired' => ConnectionState::ACTION_NEEDED,
        'additionalInformationNeeded' => ConnectionState::ACTION_NEEDED,
        'decoupled' => ConnectionState::ACTION_NEEDED,
        'actionNeeded' => ConnectionState::ACTION_NEEDED,
        'passwordExpired' => ConnectionState::ACTION_NEEDED,
        'validating' => ConnectionState::PENDING,
        'wrongpass' => ConnectionState::WRONG_CREDENTIALS,
        'rateLimiting' => ConnectionState::BANK_UNAVAILABLE,
        'websiteUnavailable' => ConnectionState::BANK_UNAVAILABLE,
        'bug' => ConnectionState::BUG,
    ];

    /**
     * Powens' categorisation (docs.powens.com, Categorization: the French,
     * Spanish and European trees), as Omnibank's categories: a child code
     * first, else its parent's. A code in none of them is UNKNOWN.
     */
    public const CATEGORIES = [
        // French tree, parents
        'administration_and_taxes' => TransactionCategory::TAXES,
        'bank_insurances' => TransactionCategory::BANK_FEES,
        'food_and_restaurants' => TransactionCategory::FOOD,
        'household' => TransactionCategory::HOUSEHOLD,
        'healthcare' => TransactionCategory::HEALTH,
        'housing' => TransactionCategory::HOUSING,
        'leisure' => TransactionCategory::LEISURE,
        'loans' => TransactionCategory::LOAN,
        'media_and_telecommunications' => TransactionCategory::SUBSCRIPTIONS,
        'others' => TransactionCategory::OTHER,
        'revenue' => TransactionCategory::INCOME,
        'shopping' => TransactionCategory::SHOPPING,
        'travel_and_transport' => TransactionCategory::TRANSPORT,
        // French tree, children that leave their parent's family
        'legal_advice' => TransactionCategory::BUSINESS,
        'insurance_repayments' => TransactionCategory::INSURANCE,
        'insurances' => TransactionCategory::INSURANCE,
        'retirement_insurance' => TransactionCategory::INSURANCE,
        'savings' => TransactionCategory::SAVINGS,
        'savings_investments_and_crypto_assets' => TransactionCategory::SAVINGS,
        'withdrawal' => TransactionCategory::CASH,
        'deposit' => TransactionCategory::CASH,
        'consumer_loans_disposal' => TransactionCategory::INCOME,
        'mortgage_disposal' => TransactionCategory::INCOME,
        'health_insurance_reimbursement' => TransactionCategory::HEALTH,
        'charity_ngo_unions_associations' => TransactionCategory::LEISURE,
        'transfers' => TransactionCategory::TRANSFER,
        'online_transfers_and_payment_services' => TransactionCategory::TRANSFER,
        'return_debit' => TransactionCategory::OTHER,
        // Spanish tree, parents and children without one
        'social_security_benefits' => TransactionCategory::INCOME,
        'gambling' => TransactionCategory::LEISURE,
        'personal_transfers' => TransactionCategory::TRANSFER,
        'other_incoming_movements' => TransactionCategory::OTHER,
        'food_and_supplies' => TransactionCategory::FOOD,
        'utility_services' => TransactionCategory::HOUSING,
        'leisure_activities_and_hobbies' => TransactionCategory::LEISURE,
        'transportation' => TransactionCategory::TRANSPORT,
        'education_health_and_beauty' => TransactionCategory::HEALTH,
        'savings_and_investments' => TransactionCategory::SAVINGS,
        'insurance' => TransactionCategory::INSURANCE,
        'online_transactions' => TransactionCategory::SHOPPING,
        'other_outgoing_movements' => TransactionCategory::OTHER,
        'salary' => TransactionCategory::INCOME,
        'pension' => TransactionCategory::INCOME,
        'cash_deposit' => TransactionCategory::CASH,
        'cheque_deposit' => TransactionCategory::CASH,
        'income_from_rent' => TransactionCategory::INCOME,
        'alimony_and_child_support' => TransactionCategory::INCOME,
        'dividends_received' => TransactionCategory::INCOME,
        'interest_received' => TransactionCategory::INCOME,
        'invoices_and_business' => TransactionCategory::BUSINESS,
        'tax_return' => TransactionCategory::TAXES,
        'financial_services_and_commissions' => TransactionCategory::BANK_FEES,
        'returned_payments' => TransactionCategory::OTHER,
        'insurance_indemnity' => TransactionCategory::INSURANCE,
        'bailiffs_and_debt_collection' => TransactionCategory::TAXES,
        'unions_and_associations' => TransactionCategory::LEISURE,
        'postal_services' => TransactionCategory::BUSINESS,
        'cash_withdrawal' => TransactionCategory::CASH,
        'cheque_withdrawal' => TransactionCategory::CASH,
        'clothing_shoes_and_complements' => TransactionCategory::SHOPPING,
        'children' => TransactionCategory::HOUSEHOLD,
        'household_goods' => TransactionCategory::HOUSEHOLD,
        'taxes_and_government_fees' => TransactionCategory::TAXES,
        // European tree, parents
        'employment _wages' => TransactionCategory::INCOME,
        'employment_wages' => TransactionCategory::INCOME,
        'government_support' => TransactionCategory::INCOME,
        'housing_and_utilities' => TransactionCategory::HOUSING,
        'groceries_and_stores' => TransactionCategory::FOOD,
        'dining_out_and_food' => TransactionCategory::FOOD,
        'vehicle_expenses' => TransactionCategory::TRANSPORT,
        'transports' => TransactionCategory::TRANSPORT,
        'travel_and_accommodation_expenses' => TransactionCategory::LEISURE,
        'medical_expenses' => TransactionCategory::HEALTH,
        'health_and_wellness' => TransactionCategory::HEALTH,
        'clothing_and_accessories' => TransactionCategory::SHOPPING,
        'digital_entertainment_and_technology' => TransactionCategory::SHOPPING,
        'general_shopping' => TransactionCategory::SHOPPING,
        'activities_and_events' => TransactionCategory::LEISURE,
        'taxes' => TransactionCategory::TAXES,
        'loans_and_credit' => TransactionCategory::LOAN,
        'education' => TransactionCategory::HOUSEHOLD,
        'professional' => TransactionCategory::BUSINESS,
        'support' => TransactionCategory::LEISURE,
        'bank_and_fees' => TransactionCategory::BANK_FEES,
        'misc' => TransactionCategory::OTHER,
        // European tree, children that leave their parent's family
        'media_subscriptions' => TransactionCategory::SUBSCRIPTIONS,
        'internet_mobile_tv' => TransactionCategory::SUBSCRIPTIONS,
        'refund' => TransactionCategory::INCOME,
        'savings_deposits_and_investments' => TransactionCategory::SAVINGS,
    ];

    /**
     * When a transaction has no category, what its type says on its own
     * (docs.powens.com, Bank transactions); the other types say nothing of
     * what was paid (a card, an order) and leave it uncategorised.
     */
    public const TYPE_CATEGORIES = [
        'transfer' => TransactionCategory::TRANSFER,
        'withdrawal' => TransactionCategory::CASH,
        'check' => TransactionCategory::CASH,
        'bank' => TransactionCategory::BANK_FEES,
        'fee' => TransactionCategory::BANK_FEES,
        'market_fee' => TransactionCategory::BANK_FEES,
        'loan_repayment' => TransactionCategory::LOAN,
        'profit' => TransactionCategory::INCOME,
        'payout' => TransactionCategory::INCOME,
    ];

    /** Omnibank's kind for a Powens account type; UNKNOWN for a type this list does not know. */
    public static function kind(?string $type): AccountKind
    {
        return self::ACCOUNT_KINDS[strtolower((string) $type)] ?? AccountKind::UNKNOWN;
    }

    /** Omnibank's state for a Powens connection state (or an account's error): null is OK, a word this list does not know UNKNOWN. */
    public static function connectionState(?string $state): ConnectionState
    {
        if (null === $state || '' === $state) {
            return ConnectionState::OK;
        }

        return self::CONNECTION_STATES[$state] ?? ConnectionState::UNKNOWN;
    }

    /** A transaction's category: its categories (expand=categories), else its type; null when neither says. */
    public static function category(array $data): ?TransactionCategory
    {
        foreach ((array) ($data['categories'] ?? []) as $category) {
            if (!\is_array($category)) {
                continue;
            }
            foreach ([$category['code'] ?? null, $category['parent_code'] ?? null] as $code) {
                if (\is_string($code) && isset(self::CATEGORIES[$code])) {
                    return self::CATEGORIES[$code];
                }
            }
            if (isset($category['code']) || isset($category['parent_code'])) {
                return TransactionCategory::UNKNOWN;
            }
        }

        return self::TYPE_CATEGORIES[strtolower((string) ($data['type'] ?? ''))] ?? null;
    }

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
            kind: self::kind(isset($data['type']) ? (string) $data['type'] : null),
            state: \array_key_exists('error', $data) ? self::connectionState(\is_string($data['error']) ? $data['error'] : null) : null,
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
            category: self::category($data),
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
