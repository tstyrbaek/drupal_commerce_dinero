<?php

namespace Drupal\commerce_dinero\Service;

use Drupal\commerce_dinero\Exception\DineroApiException;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the Dinero REST API.
 */
class DineroApiClient {

  private const API_BASE_URI = 'https://api.dinero.dk';

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected DineroTokenManager $tokenManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Performs a GET request against the Dinero API.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  public function get(string $path, array $query = []): array {
    return $this->request('GET', $path, ['query' => $query]);
  }

  /**
   * Fetches an invoice PDF from Dinero.
   *
   * PDF is only available for booked invoices.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  public function getInvoicePdf(string $invoiceGuid): string {
    return $this->requestRaw('GET', 'invoices/' . rawurlencode($invoiceGuid), [
      'headers' => ['Accept' => 'application/octet-stream'],
    ]);
  }

  /**
   * Performs a POST request against the Dinero API.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  public function post(string $path, array $payload = []): array {
    return $this->request('POST', $path, ['json' => $payload]);
  }

  /**
   * Tests API connectivity by fetching a single contact.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  public function testConnection(): void {
    $this->get('contacts', ['pageSize' => 1]);
  }

  /**
   * Performs an HTTP request against the Dinero API.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  protected function request(string $method, string $path, array $options = [], bool $retry = TRUE): array {
    $organization_id = trim((string) $this->configFactory->get('commerce_dinero.settings')->get('organization_id'));
    if ($organization_id === '') {
      throw new DineroApiException('Dinero organization ID is not configured.');
    }

    $path = ltrim($path, '/');
    $uri = self::API_BASE_URI . '/v1/' . rawurlencode($organization_id) . '/' . $path;

    $options['headers']['Authorization'] = 'Bearer ' . $this->tokenManager->getAccessToken();
    $options['headers']['Accept'] = 'application/json';

    try {
      $response = $this->httpClient->request($method, $uri, $options);
    }
    catch (RequestException $exception) {
      $status = $exception->getResponse()?->getStatusCode() ?? 0;
      $body = $this->decodeBody($exception->getResponse()?->getBody()?->getContents() ?? '');

      if ($retry && $status === 401) {
        $this->tokenManager->clearToken();
        return $this->request($method, $path, $options, FALSE);
      }

      $message = $this->formatErrorMessage($status, $body, $exception->getMessage());
      $this->logger->error('Dinero API @method @path failed (@status): @message. Response: @body', [
        '@method' => $method,
        '@path' => $path,
        '@status' => $status,
        '@message' => $message,
        '@body' => json_encode($body, JSON_UNESCAPED_UNICODE),
      ]);
      throw new DineroApiException($message, $status, $body, $exception);
    }

    $decoded = $this->decodeBody((string) $response->getBody());
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Performs a raw HTTP request and returns the response body.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  protected function requestRaw(string $method, string $path, array $options = [], bool $retry = TRUE): string {
    $organization_id = trim((string) $this->configFactory->get('commerce_dinero.settings')->get('organization_id'));
    if ($organization_id === '') {
      throw new DineroApiException('Dinero organization ID is not configured.');
    }

    $path = ltrim($path, '/');
    $uri = self::API_BASE_URI . '/v1/' . rawurlencode($organization_id) . '/' . $path;

    $options['headers']['Authorization'] = 'Bearer ' . $this->tokenManager->getAccessToken();
    $options['headers']['Accept'] ??= 'application/octet-stream';

    try {
      $response = $this->httpClient->request($method, $uri, $options);
    }
    catch (RequestException $exception) {
      $status = $exception->getResponse()?->getStatusCode() ?? 0;
      $body = $this->decodeBody($exception->getResponse()?->getBody()?->getContents() ?? '');

      if ($retry && $status === 401) {
        $this->tokenManager->clearToken();
        return $this->requestRaw($method, $path, $options, FALSE);
      }

      $message = $this->formatErrorMessage($status, $body, $exception->getMessage());
      $this->logger->error('Dinero API @method @path failed (@status): @message. Response: @body', [
        '@method' => $method,
        '@path' => $path,
        '@status' => $status,
        '@message' => $message,
        '@body' => is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (string) $body,
      ]);
      throw new DineroApiException($message, $status, is_array($body) ? $body : [], $exception);
    }

    return (string) $response->getBody();
  }

  /**
   * Decodes a JSON response body.
   */
  protected function decodeBody(string $body): mixed {
    if ($body === '') {
      return [];
    }

    $decoded = json_decode($body, TRUE);
    return $decoded ?? [];
  }

  /**
   * Formats an API error message.
   */
  protected function formatErrorMessage(int $status, array $body, string $fallback): string {
    if ($status === 429) {
      return 'Dinero API rate limit exceeded. Please try again in a moment.';
    }

    foreach (['message', 'Message', 'error_description', 'error'] as $key) {
      if (!empty($body[$key]) && is_string($body[$key])) {
        $message = $body[$key];
        break;
      }
    }

    if (!isset($message)) {
      $message = $fallback !== '' ? $fallback : 'Dinero API request failed.';
    }

    $details = $this->formatValidationErrors($body);
    if ($details !== '') {
      $message .= ' (' . $details . ')';
    }

    return $message;
  }

  /**
   * Formats Dinero validation errors into a readable string.
   */
  protected function formatValidationErrors(array $body): string {
    $parts = [];

    if (!empty($body['validationErrors']) && is_array($body['validationErrors'])) {
      foreach ($body['validationErrors'] as $field => $error) {
        $parts[] = is_string($error) ? $field . ': ' . $error : (string) $field;
      }
    }

    if ($parts === [] && !empty($body['errorMessageList']) && is_array($body['errorMessageList'])) {
      foreach ($body['errorMessageList'] as $item) {
        if (is_array($item) && !empty($item['Code']) && !empty($item['Message'])) {
          $parts[] = $item['Code'] . ': ' . $item['Message'];
        }
      }
    }

    return implode('; ', $parts);
  }

}
