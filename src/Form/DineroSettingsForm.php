<?php

namespace Drupal\commerce_dinero\Form;

use Drupal\commerce_dinero\Exception\DineroApiException;
use Drupal\commerce_dinero\Service\DineroApiClient;
use Drupal\commerce_dinero\Service\DineroTokenManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Commerce Dinero.
 */
class DineroSettingsForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    protected DineroTokenManager $tokenManager,
    protected DineroApiClient $apiClient,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('commerce_dinero.token_manager'),
      $container->get('commerce_dinero.api_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_dinero_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_dinero.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('commerce_dinero.settings');

    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API credentials'),
      '#open' => TRUE,
    ];
    $form['api']['organization_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID'),
      '#description' => $this->t('Your Dinero FirmaId. Found in the bottom left corner when logged into Dinero.'),
      '#default_value' => $config->get('organization_id'),
      '#required' => TRUE,
    ];
    $form['api']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('Personal integration client ID from Dinero.'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];
    $form['api']['client_secret_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Client secret'),
      '#default_value' => $config->get('client_secret_key'),
      '#required' => TRUE,
    ];
    $form['api']['api_key_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Organization API key generated in Dinero.'),
      '#default_value' => $config->get('api_key_key'),
      '#required' => TRUE,
    ];

    $form['invoice'] = [
      '#type' => 'details',
      '#title' => $this->t('Invoice settings'),
      '#open' => TRUE,
    ];
    $form['invoice']['account_number'] = [
      '#type' => 'number',
      '#title' => $this->t('Default account number'),
      '#description' => $this->t('Default Dinero account number used on invoice lines.'),
      '#default_value' => $config->get('account_number') ?: 1000,
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['invoice']['line_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Default line unit'),
      '#description' => $this->t('Unit type required by Dinero on product lines.'),
      '#default_value' => $config->get('line_unit') ?: 'parts',
      '#options' => [
        'parts' => $this->t('Parts'),
        'hours' => $this->t('Hours'),
        'km' => $this->t('Kilometers'),
        'day' => $this->t('Day'),
        'week' => $this->t('Week'),
        'month' => $this->t('Month'),
        'kilogram' => $this->t('Kilogram'),
        'set' => $this->t('Set'),
        'litre' => $this->t('Litre'),
        'box' => $this->t('Box'),
        'package' => $this->t('Package'),
        'shipment' => $this->t('Shipment'),
      ],
      '#required' => TRUE,
    ];

    $form['actions']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [
        ['organization_id'],
        ['client_id'],
        ['client_secret_key'],
        ['api_key_key'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('commerce_dinero.settings')
      ->set('organization_id', trim((string) $form_state->getValue('organization_id')))
      ->set('client_id', trim((string) $form_state->getValue('client_id')))
      ->set('client_secret_key', $form_state->getValue('client_secret_key'))
      ->set('api_key_key', $form_state->getValue('api_key_key'))
      ->set('account_number', (int) $form_state->getValue('account_number'))
      ->set('line_unit', (string) $form_state->getValue('line_unit'))
      ->save();

    $this->tokenManager->clearToken();
    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for the test connection button.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $this->config('commerce_dinero.settings')
      ->set('organization_id', trim((string) $form_state->getValue('organization_id')))
      ->set('client_id', trim((string) $form_state->getValue('client_id')))
      ->set('client_secret_key', $form_state->getValue('client_secret_key'))
      ->set('api_key_key', $form_state->getValue('api_key_key'))
      ->set('account_number', (int) $form_state->getValue('account_number'))
      ->set('line_unit', (string) $form_state->getValue('line_unit'))
      ->save();

    $this->tokenManager->clearToken();

    try {
      $this->tokenManager->requestAccessToken();
      $this->apiClient->testConnection();
      $this->messenger()->addStatus($this->t('Successfully connected to the Dinero API.'));
    }
    catch (DineroApiException $exception) {
      $this->messenger()->addError($this->t('Could not connect to Dinero: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }
  }

}
