<?php

namespace Drupal\commerce_dinero\Service;

use Drupal\commerce_dinero\Exception\DineroApiException;
use Drupal\commerce_dinero\Exception\DineroValidationException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Finds or creates Dinero contacts from Commerce orders.
 */
class DineroContactManager {

  public function __construct(
    protected DineroApiClient $apiClient,
  ) {}

  /**
   * Resolves a Dinero contact GUID for the order.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   * @throws \Drupal\commerce_dinero\Exception\DineroValidationException
   */
  public function resolveContactGuid(OrderInterface $order): string {
    $existing = (string) $order->getData('commerce_dinero.contact_guid');
    if ($existing !== '') {
      return $existing;
    }

    $profile = $order->getBillingProfile();
    if (!$profile instanceof ProfileInterface) {
      throw new DineroValidationException('The order does not have a billing profile.');
    }

    $payload = $this->buildContactPayload($order, $profile);
    $email = trim((string) ($payload['Email'] ?? ''));
    if ($email !== '') {
      $contact = $this->findContactByEmail($email);
      if ($contact !== NULL) {
        return (string) $contact['ContactGuid'];
      }
    }

    $response = $this->apiClient->post('contacts', $payload);
    if (empty($response['ContactGuid'])) {
      throw new DineroApiException('Dinero did not return a contact GUID after creation.');
    }

    return (string) $response['ContactGuid'];
  }

  /**
   * Finds a contact by email address.
   */
  protected function findContactByEmail(string $email): ?array {
    $filter = sprintf("Email eq '%s'", str_replace("'", "''", $email));
    $response = $this->apiClient->get('contacts', [
      'queryFilter' => $filter,
      'fields' => 'ContactGuid,Name,Email',
      'pageSize' => 1,
    ]);

    $collection = $response['Collection'] ?? [];
    if (!is_array($collection) || $collection === []) {
      return NULL;
    }

    $contact = $collection[0];
    return is_array($contact) ? $contact : NULL;
  }

  /**
   * Builds a ContactCreateModel payload.
   */
  protected function buildContactPayload(OrderInterface $order, ProfileInterface $profile): array {
    $address = $profile->get('address')->first();
    if ($address === NULL) {
      throw new DineroValidationException('The billing profile does not contain an address.');
    }

    $name = trim((string) $address->getGivenName() . ' ' . (string) $address->getFamilyName());
    if ($name === '' && $address->getOrganization() !== NULL) {
      $name = trim((string) $address->getOrganization());
    }
    if ($name === '') {
      throw new DineroValidationException('The billing profile does not contain a customer name.');
    }

    $country_code = strtoupper((string) $address->getCountryCode() ?: 'DK');
    $email = trim((string) $order->getEmail());

    $payload = [
      'Name' => $name,
      'CountryKey' => $country_code,
      'IsPerson' => $address->getOrganization() ? FALSE : TRUE,
      'IsMember' => FALSE,
      'UseCvr' => FALSE,
      'Street' => $this->formatStreet($address),
      'ZipCode' => (string) $address->getPostalCode(),
      'City' => (string) $address->getLocality(),
      'ExternalReference' => $this->buildExternalReference($order),
    ];

    if ($email !== '') {
      $payload['Email'] = $email;
    }

    if (!$payload['IsPerson'] && $address->getOrganization()) {
      $payload['Name'] = trim((string) $address->getOrganization());
    }

    return $payload;
  }

  /**
   * Formats a street address line.
   */
  protected function formatStreet($address): string {
    $lines = array_filter([
      trim((string) $address->getAddressLine1()),
      trim((string) $address->getAddressLine2()),
    ]);
    return implode(', ', $lines);
  }

  /**
   * Builds an external reference for the contact.
   */
  protected function buildExternalReference(OrderInterface $order): string {
    $reference = sprintf('Drupal order %s', $order->getOrderNumber() ?: $order->id());
    return substr($reference, 0, 128);
  }

}
