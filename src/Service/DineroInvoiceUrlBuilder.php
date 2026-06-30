<?php

namespace Drupal\commerce_dinero\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Url;

/**
 * Builds URLs for Dinero invoices.
 */
class DineroInvoiceUrlBuilder {

  /**
   * Checks whether the Dinero invoice is booked and has a PDF available.
   */
  public function isInvoiceBooked(OrderInterface $order): bool {
    return strcasecmp((string) $order->getData('commerce_dinero.invoice_status'), 'Booked') === 0;
  }

  /**
   * Builds the local route URL that streams the invoice PDF from Dinero.
   */
  public function buildPdfUrl(int $order_id): Url {
    return Url::fromRoute('commerce_dinero.invoice_pdf', [
      'commerce_order' => $order_id,
    ]);
  }

}
