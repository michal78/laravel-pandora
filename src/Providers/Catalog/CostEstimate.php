<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Catalog;

/**
 * What a model call cost, and how much that figure is worth believing.
 *
 * Money is carried in MICRO units -- millionths of the currency -- because
 * token prices are quoted per million tokens and a single call routinely costs
 * a fraction of a cent. Rounding to minor units at the point of measurement
 * would turn a thousand small calls into zero.
 *
 * `source` and `date` travel with the amount rather than being looked up
 * later, so a cost recorded today still says what it was based on after
 * somebody edits the catalog tomorrow.
 */
final readonly class CostEstimate
{
    public function __construct(
        public int $amountMicro,
        public string $currency,
        public ?string $source = null,
        public ?\DateTimeImmutable $pricedAt = null,
        public bool $stale = false,
    ) {}

    public function amount(): float
    {
        return $this->amountMicro / 1_000_000;
    }

    /**
     * Minor units, rounded. For budgets, which are expressed in whole cents.
     */
    public function minorUnits(): int
    {
        return (int) round($this->amountMicro / 10_000);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount_micro' => $this->amountMicro,
            'currency' => $this->currency,
            'pricing_source' => $this->source,
            'pricing_date' => $this->pricedAt?->format('Y-m-d'),
            'pricing_stale' => $this->stale,
        ];
    }
}
