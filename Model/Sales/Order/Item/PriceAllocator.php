<?php

namespace ShoppingFeed\Manager\Model\Sales\Order\Item;

use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\ItemInterface as MarketplaceItemInterface;
use ShoppingFeed\Manager\Model\Sales\Order\ConfigInterface as OrderConfigInterface;
use ShoppingFeed\Manager\Model\StringHelper;

class PriceAllocator
{
    const ROUNDING_MODE_UNIT_PRICE = 'unit_price';
    const ROUNDING_MODE_ROW_TOTAL = 'row_total';
    const ROUNDING_MODE_ORDER_TOTAL = 'order_total';

    /**
     * Prices and quantities are stored with 4 decimals:
     * calculations are made on integer amounts of ten-thousandths, to prevent any floating-point error.
     */
    const UNIT_FACTOR = 10000;
    const UNITS_PER_CENT = 100;

    /**
     * @var OrderConfigInterface
     */
    private $orderGeneralConfig;

    /**
     * @var StringHelper
     */
    private $stringHelper;

    /**
     * @var string[]
     */
    private $marketplaceRoundingModes = [];

    /**
     * @param OrderConfigInterface $orderGeneralConfig
     * @param StringHelper $stringHelper
     * @param string[] $marketplaceRoundingModes
     * @throws \InvalidArgumentException
     */
    public function __construct(
        OrderConfigInterface $orderGeneralConfig,
        StringHelper $stringHelper,
        array $marketplaceRoundingModes = []
    ) {
        $this->orderGeneralConfig = $orderGeneralConfig;
        $this->stringHelper = $stringHelper;

        foreach ($marketplaceRoundingModes as $marketplace => $mode) {
            if (!$this->isValidRoundingMode($mode)) {
                throw new \InvalidArgumentException(
                    sprintf('Unknown rounding mode "%s" for the marketplace "%s".', $mode, $marketplace)
                );
            }

            $this->marketplaceRoundingModes[$this->stringHelper->getNormalizedCode($marketplace)] = $mode;
        }
    }

    /**
     * @param mixed $mode
     * @return bool
     */
    private function isValidRoundingMode($mode)
    {
        return in_array(
            $mode,
            [
                self::ROUNDING_MODE_UNIT_PRICE,
                self::ROUNDING_MODE_ROW_TOTAL,
                self::ROUNDING_MODE_ORDER_TOTAL,
            ],
            true
        );
    }

    /**
     * @param StoreInterface $store
     * @param string $marketplace
     * @return string
     */
    public function getMarketplaceRoundingMode(StoreInterface $store, $marketplace)
    {
        $mode = $this->orderGeneralConfig->getMarketplaceRoundingMode($store, $marketplace);

        if ($this->isValidRoundingMode($mode)) {
            return $mode;
        }

        return $this->marketplaceRoundingModes[$this->stringHelper->getNormalizedCode($marketplace)]
            ?? self::ROUNDING_MODE_UNIT_PRICE;
    }

    /**
     * Divides two integers, rounding the result half away from zero (like round()).
     *
     * @param int $dividend
     * @param int $divisor
     * @return int
     */
    private function divideAndRound($dividend, $divisor)
    {
        $halfDivisor = intdiv($divisor, 2);

        return ($dividend >= 0)
            ? intdiv($dividend + $halfDivisor, $divisor)
            : -intdiv(-$dividend + $halfDivisor, $divisor);
    }

    /**
     * @param MarketplaceItemInterface[] $items
     * @param string $mode The rounding mode of the marketplace.
     * @return MarketplaceItemInterface[][] The parts to import for each item, indexed like the items.
     */
    public function getItemsQuoteParts(array $items, $mode)
    {
        $quoteParts = [];

        foreach ($items as $key => $item) {
            $quoteParts[$key] = [ $item ];
        }

        if (
            (self::ROUNDING_MODE_ROW_TOTAL !== $mode)
            && (self::ROUNDING_MODE_ORDER_TOTAL !== $mode)
        ) {
            return $quoteParts;
        }

        // The items whose total can be adjusted.
        $lines = [];
        $orderTotal = 0;
        // The total in cents that Magento will compute for the other items.
        $fixedTotalInCents = 0;

        foreach ($items as $key => $item) {
            $unitPrice = (int) round($item->getPrice() * self::UNIT_FACTOR);
            $quantity = (int) round($item->getQuantity() * self::UNIT_FACTOR);
            $total = $this->divideAndRound($unitPrice * $quantity, self::UNIT_FACTOR);
            $orderTotal += $total;

            if (
                ($quantity <= 0)
                || ($quantity % self::UNIT_FACTOR !== 0)
                // Empty custom prices are ignored by Magento: each part needs a unit price of at least one cent.
                || ($unitPrice < self::UNITS_PER_CENT)
                // Without an ID, the parts would be merged in the quote.
                || (($quantity > self::UNIT_FACTOR) && !$item->getShoppingFeedItemId())
            ) {
                /** @see \Magento\Quote\Model\Quote\Item\AbstractItem::calcRowTotal() */
                $fixedTotalInCents += $this->divideAndRound(
                    $this->divideAndRound($unitPrice, self::UNITS_PER_CENT) * $quantity,
                    self::UNIT_FACTOR
                );

                continue;
            }

            $lines[$key] = [
                'unit_price' => $unitPrice,
                'quantity' => intdiv($quantity, self::UNIT_FACTOR),
                'total' => $total,
            ];
        }

        $lineTotalsInCents = [];

        if (self::ROUNDING_MODE_ROW_TOTAL === $mode) {
            foreach ($lines as $key => $line) {
                $lineTotalsInCents[$key] = $this->divideAndRound($line['total'], self::UNITS_PER_CENT);
            }
        } else {
            $remainingCents = $this->divideAndRound($orderTotal, self::UNITS_PER_CENT) - $fixedTotalInCents;

            foreach ($lines as $key => $line) {
                $lineTotalsInCents[$key] = intdiv($line['total'], self::UNITS_PER_CENT);
                $remainingCents -= $lineTotalsInCents[$key];
            }

            // Allocate the remaining cents to the lines with the largest remainders (in their original order on ties).
            $positions = array_flip(array_keys($lines));
            $sortedKeys = array_keys($lines);

            usort(
                $sortedKeys,
                function ($keyA, $keyB) use ($lines, $positions) {
                    $remainderA = $lines[$keyA]['total'] % self::UNITS_PER_CENT;
                    $remainderB = $lines[$keyB]['total'] % self::UNITS_PER_CENT;

                    return ($remainderB <=> $remainderA) ?: ($positions[$keyA] <=> $positions[$keyB]);
                }
            );

            // The remaining cents can only be out of bounds because of the items whose total can not be adjusted.
            $remainingCents = max(0, min($remainingCents, count($lines)));

            foreach (array_slice($sortedKeys, 0, $remainingCents) as $key) {
                $lineTotalsInCents[$key]++;
            }
        }

        foreach ($lineTotalsInCents as $key => $totalInCents) {
            $quantity = $lines[$key]['quantity'];
            $magentoUnitPriceInCents = $this->divideAndRound($lines[$key]['unit_price'], self::UNITS_PER_CENT);

            if ($magentoUnitPriceInCents * $quantity === $totalInCents) {
                // Magento will compute the expected total by itself.
                continue;
            }

            $lowerUnitPriceInCents = intdiv($totalInCents, $quantity);
            $upperQuantity = $totalInCents % $quantity;
            $quoteParts[$key] = [];

            $partQuantities = [
                $lowerUnitPriceInCents => $quantity - $upperQuantity,
                $lowerUnitPriceInCents + 1 => $upperQuantity,
            ];

            foreach ($partQuantities as $unitPriceInCents => $partQuantity) {
                if ($partQuantity > 0) {
                    $part = clone $items[$key];
                    $part->setQuantity($partQuantity);
                    $part->setPrice($unitPriceInCents / self::UNITS_PER_CENT);
                    $quoteParts[$key][] = $part;
                }
            }
        }

        return $quoteParts;
    }
}
