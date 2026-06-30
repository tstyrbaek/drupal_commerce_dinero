<?php

namespace Drupal\commerce_dinero\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched before a Dinero invoice is created.
 */
class InvoicePreCreateEvent extends Event {

  public const EVENT_NAME = 'commerce_dinero.invoice_pre_create';

  public function __construct(
    protected OrderInterface $order,
    protected array $payload,
  ) {}

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets the invoice payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

  /**
   * Sets the invoice payload.
   */
  public function setPayload(array $payload): void {
    $this->payload = $payload;
  }

}
