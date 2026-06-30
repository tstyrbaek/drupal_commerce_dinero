<?php

namespace Drupal\commerce_dinero\Service;

use Drupal\commerce_dinero\Event\InvoicePreCreateEvent;
use Drupal\commerce_dinero\Exception\DineroApiException;
use Drupal\commerce_dinero\Exception\DineroValidationException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrates creating draft invoices in Dinero.
 */
class DineroInvoiceManager {

  public function __construct(
    protected DineroApiClient $apiClient,
    protected DineroContactManager $contactManager,
    protected OrderInvoiceMapper $invoiceMapper,
    protected EventDispatcherInterface $eventDispatcher,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks whether a draft invoice can be created for the order.
   */
  public function canCreateInvoice(OrderInterface $order): bool {
    if ($this->hasInvoice($order)) {
      return FALSE;
    }

    $state = $order->getState()->getId();
    return !in_array($state, ['draft', 'canceled'], TRUE);
  }

  /**
   * Checks whether the order already has a Dinero invoice reference.
   */
  public function hasInvoice(OrderInterface $order): bool {
    return (string) $order->getData('commerce_dinero.invoice_guid') !== '';
  }

  /**
   * Gets the Dinero invoice GUID stored on the order.
   */
  public function getInvoiceGuid(OrderInterface $order): ?string {
    $guid = (string) $order->getData('commerce_dinero.invoice_guid');
    return $guid !== '' ? $guid : NULL;
  }

  /**
   * Creates a draft invoice in Dinero for the given order.
   *
   * @return string
   *   The created invoice GUID.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   * @throws \Drupal\commerce_dinero\Exception\DineroValidationException
   */
  public function createDraftInvoice(OrderInterface $order): string {
    if (!$this->canCreateInvoice($order)) {
      throw new DineroValidationException('This order cannot be invoiced in Dinero.');
    }

    $contact_guid = $this->contactManager->resolveContactGuid($order);
    $payload = $this->invoiceMapper->buildInvoicePayload($order, $contact_guid);

    $event = new InvoicePreCreateEvent($order, $payload);
    $this->eventDispatcher->dispatch($event, InvoicePreCreateEvent::EVENT_NAME);
    $payload = $event->getPayload();

    $response = $this->apiClient->post('invoices', $payload);
    if (empty($response['Guid'])) {
      throw new DineroApiException('Dinero did not return an invoice GUID after creation.');
    }

    $invoice_guid = (string) $response['Guid'];
    $order->setData('commerce_dinero.invoice_guid', $invoice_guid);
    $order->setData('commerce_dinero.contact_guid', $contact_guid);
    $order->setData('commerce_dinero.invoiced_at', $this->time->getRequestTime());
    $order->save();

    try {
      $this->syncInvoiceStatus($order, $invoice_guid);
    }
    catch (DineroApiException $exception) {
      $this->logger->warning('Created Dinero invoice @guid for order @order, but invoice status could not be fetched: @message', [
        '@guid' => $invoice_guid,
        '@order' => $order->id(),
        '@message' => $exception->getMessage(),
      ]);
    }

    $this->logger->info('Created Dinero draft invoice @guid for order @order.', [
      '@guid' => $invoice_guid,
      '@order' => $order->id(),
    ]);

    return $invoice_guid;
  }

  /**
   * Returns a Danish label for the Dinero invoice status, if invoiced.
   */
  public function getInvoiceStatusLabel(OrderInterface $order): ?string {
    if (!$this->hasInvoice($order)) {
      return NULL;
    }

    $status = strtolower((string) $order->getData('commerce_dinero.invoice_status'));
    if ($status === 'booked') {
      return (string) t('Faktureret');
    }

    return (string) t('Kladde');
  }

  /**
   * Syncs Dinero invoice status for recent uninvoiced-booked orders.
   *
   * @return int
   *   Number of orders synced.
   */
  public function syncPendingInvoiceStatuses(int $limit = 25): int {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('changed', 'DESC')
      ->range(0, 200)
      ->execute();

    if ($ids === []) {
      return 0;
    }

    $synced = 0;
    foreach ($storage->loadMultiple($ids) as $order) {
      if ($synced >= $limit) {
        break;
      }
      if (!$order instanceof OrderInterface || !$this->hasInvoice($order)) {
        continue;
      }
      if (strcasecmp((string) $order->getData('commerce_dinero.invoice_status'), 'Booked') === 0) {
        continue;
      }

      $this->ensureInvoiceMetadata($order);
      $synced++;
    }

    return $synced;
  }

  /**
   * Ensures invoice status is stored and up to date for PDF availability.
   */
  public function ensureInvoiceMetadata(OrderInterface $order): void {
    $invoice_guid = $this->getInvoiceGuid($order);
    if ($invoice_guid === NULL) {
      return;
    }

    if (strcasecmp((string) $order->getData('commerce_dinero.invoice_status'), 'Booked') === 0) {
      return;
    }

    try {
      $this->syncInvoiceStatus($order, $invoice_guid);
    }
    catch (DineroApiException $exception) {
      $this->logger->warning('Could not fetch Dinero invoice status for order @order: @message', [
        '@order' => $order->id(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Fetches invoice status from Dinero and stores it on the order.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  protected function syncInvoiceStatus(OrderInterface $order, string $invoice_guid): void {
    $invoice = $this->apiClient->get('invoices/' . rawurlencode($invoice_guid));
    if (empty($invoice['Status']) || !is_string($invoice['Status'])) {
      throw new DineroApiException('Dinero did not return an invoice status.');
    }

    $order->setData('commerce_dinero.invoice_status', $invoice['Status']);
    $order->save();
  }

}
