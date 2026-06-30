<?php

namespace Drupal\commerce_dinero\Plugin\views\field;

use Drupal\commerce_dinero\Service\DineroInvoiceManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows Dinero invoice status for a Commerce order.
 */
#[ViewsField('commerce_dinero_invoice_status')]
class DineroInvoiceStatus extends FieldPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DineroInvoiceManager $invoiceManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_dinero.invoice_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Status is read from order data, not SQL.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $order = $this->getEntity($values);
    if (!$order instanceof OrderInterface) {
      return '';
    }

    $label = $this->invoiceManager->getInvoiceStatusLabel($order);
    if ($label === NULL) {
      return '';
    }

    $status = (string) $order->getData('commerce_dinero.invoice_status');
    $class = match (strtolower($status)) {
      'booked' => 'commerce-dinero-invoice-status--booked',
      'draft' => 'commerce-dinero-invoice-status--draft',
      default => 'commerce-dinero-invoice-status--draft',
    };

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => [
        'class' => ['commerce-dinero-invoice-status', $class],
      ],
    ];
  }

}
