<?php

namespace Drupal\commerce_dinero\Controller;

use Drupal\commerce_dinero\Exception\DineroApiException;
use Drupal\commerce_dinero\Service\DineroApiClient;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Streams Dinero invoice PDFs for Commerce orders.
 */
class InvoicePdfController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected DineroApiClient $apiClient,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_dinero.api_client'),
      $container->get('messenger'),
    );
  }

  /**
   * Downloads or displays the Dinero invoice PDF for an order.
   */
  public function download(OrderInterface $commerce_order): Response {
    $invoice_guid = trim((string) $commerce_order->getData('commerce_dinero.invoice_guid'));
    if ($invoice_guid === '') {
      throw new NotFoundHttpException();
    }

    try {
      $pdf = $this->apiClient->getInvoicePdf($invoice_guid);
    }
    catch (DineroApiException $exception) {
      $this->messenger->addError($this->t('Kunne ikke hente faktura-PDF fra Dinero: @message', [
        '@message' => $exception->getMessage(),
      ]));

      throw new ServiceUnavailableHttpException(NULL, $exception->getMessage(), $exception);
    }

    if ($pdf === '') {
      throw new NotFoundHttpException();
    }

    $filename = sprintf(
      'dinero-faktura-%s.pdf',
      $commerce_order->getOrderNumber() ?: $commerce_order->id(),
    );

    return new Response($pdf, 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="' . $filename . '"',
      'Content-Length' => (string) strlen($pdf),
      'Cache-Control' => 'private, no-store',
    ]);
  }

}
