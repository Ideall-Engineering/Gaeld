<?php

namespace App\Domains\Accounting\Support;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Exceptions\UnknownVatCodeException;
use App\Domains\Accounting\Models\VatRate;
use App\Support\Exceptions\DomainException;
use App\Support\Money;

/**
 * Expands a shorthand journal line into explicit debit/credit lines.
 *
 * The shorthand mirrors how a booking is written in Banana: one row carries the
 * account, the contra account, the gross amount and a VAT code such as `M81`.
 * The codes are a client-side convenience only — the ledger stores rate,
 * direction and figure, never the code itself.
 *
 * Lives in the transport layer on purpose. LedgerService keeps its rule that
 * the caller supplies balanced lines; this class is what makes the caller able
 * to.
 */
final class VatCodeResolver
{
    /**
     * code => [VAT rate code, VAT entry type, default VAT figure].
     * A null rate and type mean "no VAT".
     *
     * @var array<string, array{0: string|null, 1: VatEntryType|null, 2?: string}>
     */
    private const CODES = [
        'V81' => ['NORMAL', VatEntryType::Output],
        'V26' => ['REDUCED', VatEntryType::Output],
        'V38' => ['ACCOMMODATION', VatEntryType::Output],
        'M81' => ['NORMAL', VatEntryType::Input],
        'M26' => ['REDUCED', VatEntryType::Input],
        'M38' => ['ACCOMMODATION', VatEntryType::Input],
        'I81' => ['NORMAL', VatEntryType::InputInvestment],
        'I26' => ['REDUCED', VatEntryType::InputInvestment],
        'I38' => ['ACCOMMODATION', VatEntryType::InputInvestment],
        'B81' => ['NORMAL', VatEntryType::Acquisition],
        'B26' => ['REDUCED', VatEntryType::Acquisition],
        'B38' => ['ACCOMMODATION', VatEntryType::Acquisition],
        'V0' => ['EXEMPT', VatEntryType::Output, '220'],
        'V0-N' => ['EXEMPT', VatEntryType::Output, '230'],
        'M0' => [null, null],
        'I0' => [null, null],
        'Z0' => [null, null],
        'Z0-A' => [null, null],
    ];

    public static function knows(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::CODES);
    }

    /**
     * @return string[]
     */
    public static function codes(): array
    {
        return array_keys(self::CODES);
    }

    /**
     * Expand one shorthand line into the explicit lines of a balanced entry.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, array<string, mixed>>
     *
     * @throws UnknownVatCodeException
     * @throws DomainException
     */
    public function expand(string $organizationId, array $line): array
    {
        $code = strtoupper((string) $line['vat_code']);

        if (! array_key_exists($code, self::CODES)) {
            throw UnknownVatCodeException::forCode($code);
        }

        [$rateCode, $vatType] = self::CODES[$code];
        $defaultFigure = self::CODES[$code][2] ?? null;

        $account = (string) $line['account_code'];
        $contra = (string) $line['contra_account_code'];
        $amount = Money::of($line['gross']);
        $description = $line['description'] ?? null;
        $figure = $line['vat_figure'] ?? $defaultFigure;

        if ($vatType === null) {
            return $this->plainLines($account, $contra, $amount, $description);
        }

        $rate = $this->rate($organizationId, (string) $rateCode);

        return match ($vatType) {
            VatEntryType::Output => Money::isZero((string) $rate->rate)
                ? $this->exemptOutputLines($account, $contra, $amount, $description, $rate, $figure)
                : $this->outputLines($account, $contra, $amount, $description, $rate),
            VatEntryType::Input, VatEntryType::InputInvestment => $this->inputLines(
                $account, $contra, $amount, $description, $rate, $vatType,
            ),
            VatEntryType::Acquisition => $this->acquisitionLines(
                $account, $contra, $amount, $description, $rate,
            ),
        };
    }

    private function rate(string $organizationId, string $rateCode): VatRate
    {
        $rate = VatRate::query()
            ->where('organization_id', $organizationId)
            ->where('code', $rateCode)
            ->where('is_active', true)
            ->first();

        if ($rate === null) {
            throw new DomainException("No active VAT rate '{$rateCode}' is configured for this organization.");
        }

        return $rate;
    }

    /**
     * Split a gross amount into net and VAT. The net is rounded first and the
     * VAT taken as the remainder, so the two always add back up to the gross
     * and the entry balances to the centime.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $gross, VatRate $rate): array
    {
        // Deliberately not gross / (1 + rate/100): Money::add works at two
        // decimals, which would turn a divisor of 1.081 into 1.08. Scaling by
        // 100 keeps the rate intact, and divideRounded rounds where the plain
        // bcdiv of Money::divide would truncate.
        $net = Money::divideRounded(
            Money::multiply($gross, '100'),
            Money::add('100', (string) $rate->rate),
        );

        return [$net, Money::subtract($gross, $net)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plainLines(string $account, string $contra, string $amount, ?string $description): array
    {
        // No-VAT expense codes debit the named account. Z0 reaches this path
        // with the source debit account as the named account as well.
        return [
            $this->line($account, $amount, '0.00', $description),
            $this->line($contra, '0.00', $amount, $description),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function outputLines(string $account, string $contra, string $gross, ?string $description, VatRate $rate): array
    {
        [$net, $vat] = $this->split($gross, $rate);

        return [
            $this->line($contra, $gross, '0.00', $description),
            $this->line($account, '0.00', $net, $description, $rate, $vat, VatEntryType::Output),
            $this->line(AccountCode::VAT_OUTPUT, '0.00', $vat, $description),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exemptOutputLines(
        string $account,
        string $contra,
        string $amount,
        ?string $description,
        VatRate $rate,
        ?string $figure,
    ): array {
        // Zero-rated turnover still belongs in chiffre 200 and is deducted
        // again under the figure the caller names, so it needs a VAT entry
        // even though no tax is owed.
        return [
            $this->line($contra, $amount, '0.00', $description),
            $this->line($account, '0.00', $amount, $description, $rate, '0.00', VatEntryType::Output, $figure),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inputLines(
        string $account,
        string $contra,
        string $gross,
        ?string $description,
        VatRate $rate,
        VatEntryType $vatType,
    ): array {
        [$net, $vat] = $this->split($gross, $rate);

        return [
            $this->line($account, $net, '0.00', $description, $rate, $vat, $vatType),
            $this->line(AccountCode::VAT_INPUT, $vat, '0.00', $description),
            $this->line($contra, '0.00', $gross, $description),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function acquisitionLines(
        string $account,
        string $contra,
        string $net,
        ?string $description,
        VatRate $rate,
    ): array {
        // A foreign supplier charges no VAT, so the amount given is already
        // net and the bank pays exactly that. The tax is owed to and reclaimed
        // from the FTA in the same breath.
        $vat = Money::percentage($net, (string) $rate->rate);

        return [
            $this->line($account, $net, '0.00', $description, $rate, $vat, VatEntryType::Acquisition),
            $this->line(AccountCode::VAT_INPUT, $vat, '0.00', $description),
            $this->line(AccountCode::VAT_ACQUISITION_TAX_PAYABLE, '0.00', $vat, $description),
            $this->line($contra, '0.00', $net, $description),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(
        string $accountCode,
        string $debit,
        string $credit,
        ?string $description,
        ?VatRate $rate = null,
        ?string $vatAmount = null,
        ?VatEntryType $vatType = null,
        ?string $figure = null,
    ): array {
        return [
            'account_code' => $accountCode,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
            'vat_rate_id' => $rate?->uuid,
            'vat_amount' => $vatAmount,
            'vat_type' => $vatType?->value,
            'vat_figure' => $figure,
        ];
    }
}
