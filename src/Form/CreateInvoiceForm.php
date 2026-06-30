<?php

namespace Drupal\commerce_dinero\Form;

use Drupal\commerce_dinero\Exception\DineroApiException;
use Drupal\commerce_dinero\Exception\DineroValidationException;
use Drupal\commerce_dinero\Service\DineroInvoiceManager;
use Drupal\commerce_dinero\Service\OrderInvoiceMapper;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Confirmation form for creating a Dinero draft invoice.
 */
class CreateInvoiceForm extends ConfirmFormBase {

  protected ?OrderInterface $order = NULL;

  public function __construct(
    protected DineroInvoiceManager $invoiceManager,
    protected OrderInvoiceMapper $invoiceMapper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_dinero.invoice_manager'),
      $container->get('commerce_dinero.order_invoice_mapper'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_dinero_create_invoice_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->t('Create a draft invoice in Dinero for order @number?', [
      '@number' => $this->order->getOrderNumber() ?: $this->order->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->order->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->t('Create invoice');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $commerce_order = NULL): array {
    $this->order = $commerce_order;
    if (!$this->order) {
      throw new DineroValidationException('Order is missing.');
    }

    if (!$this->invoiceManager->canCreateInvoice($this->order)) {
      $this->messenger()->addError($this->t('This order cannot be invoiced in Dinero.'));
      $form_state->setResponse(new RedirectResponse($this->order->toUrl('canonical')->toString()));
      return [];
    }

    try {
      $lines = $this->invoiceMapper->buildProductLines($this->order);
      if ($lines === []) {
        throw new DineroValidationException('The order does not contain any invoice lines.');
      }
    }
    catch (DineroValidationException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setResponse(new RedirectResponse($this->order->toUrl('canonical')->toString()));
      return [];
    }

    $items = [];
    foreach ($lines as $line) {
      $items[] = $this->t('@description (@quantity x @amount)', [
        '@description' => $line['Description'],
        '@quantity' => $line['Quantity'],
        '@amount' => number_format((float) $line['BaseAmountValue'], 2, ',', '.'),
      ]);
    }

    $form['summary'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Invoice lines'),
      '#items' => $items,
    ];

    $form['note'] = [
      '#type' => 'item',
      '#markup' => $this->t('A draft invoice will be created in Dinero. It will not be booked automatically.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $invoice_guid = $this->invoiceManager->createDraftInvoice($this->order);
      $this->messenger()->addStatus($this->t('Draft invoice created in Dinero. Invoice GUID: @guid', [
        '@guid' => $invoice_guid,
      ]));
    }
    catch (DineroValidationException $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
    catch (DineroApiException $exception) {
      $this->messenger()->addError($this->t('Could not create Dinero invoice: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRedirectUrl($this->order->toUrl('canonical'));
  }

}
