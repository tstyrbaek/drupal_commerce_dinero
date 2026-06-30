<?php

namespace Drupal\commerce_dinero\Service;

use Drupal\commerce_dinero\Exception\DineroValidationException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
/**
 * Maps Commerce orders to Dinero invoice payloads.
 */
class OrderInvoiceMapper {

  /**
   * Dinero PaymentConditionType for "Fakturaen er betalt".
   */
  private const PAYMENT_CONDITION_PAID = 'Paid';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds an InvoiceCreateModel payload.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroValidationException
   */
  public function buildInvoicePayload(OrderInterface $order, string $contact_guid): array {
    $lines = $this->buildProductLines($order);
    if ($lines === []) {
      throw new DineroValidationException('The order does not contain any invoice lines.');
    }

    $currency = $order->getTotalPrice()?->getCurrencyCode() ?: 'DKK';

    $payload = [
      'ContactGuid' => $contact_guid,
      'Date' => date('Y-m-d'),
      'Currency' => $currency,
      'ExternalReference' => $this->buildExternalReference($order),
      'Comment' => $this->buildInvoiceComment($order),
      'ProductLines' => $lines,
    ];

    return $payload + $this->buildPaymentConditions($order);
  }

  /**
   * Builds payment condition fields for the invoice payload.
   *
   * Unpaid orders inherit payment terms from the Dinero contact or defaults.
   * Paid orders are marked as already paid on the invoice.
   *
   * @return array<string, string>
   */
  protected function buildPaymentConditions(OrderInterface $order): array {
    if (!$order->isPaid()) {
      return [];
    }

    return [
      'PaymentConditionType' => self::PAYMENT_CONDITION_PAID,
    ];
  }

  /**
   * Builds invoice product lines from the order.
   *
   * @return array<int, array<string, mixed>>
   */
  public function buildProductLines(OrderInterface $order): array {
    $lines = [];
    $account_number = (int) $this->configFactory->get('commerce_dinero.settings')->get('account_number') ?: 1000;

    foreach ($order->getItems() as $order_item) {
      if (!$order_item instanceof OrderItemInterface) {
        continue;
      }

      $quantity = (float) $order_item->getQuantity();
      if ($quantity <= 0) {
        continue;
      }

      $unit_amount = $this->getUnitAmountExcludingTax($order_item);
      if ($unit_amount <= 0) {
        continue;
      }

      $lines[] = $this->buildProductLine(
        (string) $order_item->getTitle(),
        $quantity,
        round($unit_amount, 2),
        $account_number,
      );
    }

    foreach ($order->getAdjustments(['shipping']) as $adjustment) {
      $amount = $this->priceToFloat($this->getAdjustmentAmountExcludingTax($adjustment, $order));
      if ($amount <= 0) {
        continue;
      }

      $lines[] = $this->buildProductLine(
        (string) ($adjustment->getLabel() ?: 'Fragt'),
        1,
        round($amount, 2),
        $account_number,
      );
    }

    return $lines;
  }

  /**
   * Gets the unit amount excluding tax for an order item.
   */
  protected function getUnitAmountExcludingTax(OrderItemInterface $order_item): float {
    $total = $order_item->getAdjustedTotalPrice();
    foreach ($order_item->getAdjustments(['tax']) as $adjustment) {
      $total = $total->subtract($adjustment->getAmount());
    }

    $quantity = (float) $order_item->getQuantity();
    if ($quantity <= 0) {
      return 0;
    }

    return $this->priceToFloat($total->divide((string) $quantity));
  }

  /**
   * Gets an adjustment amount excluding linked tax adjustments.
   */
  protected function getAdjustmentAmountExcludingTax($adjustment, OrderInterface $order): Price {
    $amount = $adjustment->getAmount();
    foreach ($order->getAdjustments(['tax']) as $tax_adjustment) {
      if ($tax_adjustment->getSourceId() === $adjustment->getId()) {
        $amount = $amount->subtract($tax_adjustment->getAmount());
      }
    }
    return $amount;
  }

  /**
   * Converts a price to a float.
   */
  protected function priceToFloat(Price $price): float {
    return (float) $price->getNumber();
  }

  /**
   * Builds a single Dinero invoice line.
   */
  protected function buildProductLine(string $description, float $quantity, float $unit_amount, int $account_number): array {
    $unit = (string) ($this->configFactory->get('commerce_dinero.settings')->get('line_unit') ?: 'parts');

    return [
      'Description' => $description,
      'Quantity' => $quantity,
      'BaseAmountValue' => $unit_amount,
      'AccountNumber' => $account_number,
      'Discount' => 0,
      'LineType' => 'Product',
      'Unit' => $unit,
    ];
  }

  /**
   * Builds an external reference for the invoice.
   */
  protected function buildExternalReference(OrderInterface $order): string {
    $reference = sprintf('Drupal order %s', $order->getOrderNumber() ?: $order->id());
    return substr($reference, 0, 128);
  }

  /**
   * Builds the invoice comment shown in Dinero.
   */
  protected function buildInvoiceComment(OrderInterface $order): string {
    $order_number = $order->getOrderNumber() ?: $order->id();

    return sprintf('Købt på %s. Ordrenummer: %s.', $this->buildStoreUrl($order), $order_number);
  }

  /**
   * Builds the storefront URL for the order's store.
   */
  protected function buildStoreUrl(OrderInterface $order): string {
    $store = $order->getStore();
    if ($store instanceof StoreInterface && $store->hasField('path') && !$store->get('path')->isEmpty()) {
      $alias = trim((string) $store->get('path')->alias);
      if ($alias !== '') {
        return Url::fromUserInput('/' . ltrim($alias, '/'), ['absolute' => TRUE])->toString();
      }
    }

    return Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
  }

}
