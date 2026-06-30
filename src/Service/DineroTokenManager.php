<?php

namespace Drupal\commerce_dinero\Service;

use Drupal\commerce_dinero\Exception\DineroApiException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Manages OAuth access tokens for Dinero personal integration.
 */
class DineroTokenManager {

  private const AUTH_URI = 'https://authz.dinero.dk/dineroapi/oauth/token';

  private const STATE_KEY = 'commerce_dinero.access_token';

  private const STATE_EXPIRES_KEY = 'commerce_dinero.access_token_expires';

  private const TOKEN_BUFFER_SECONDS = 300;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected StateInterface $state,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns a valid access token, fetching a new one if needed.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  public function getAccessToken(bool $force_refresh = FALSE): string {
    $expires = (int) $this->state->get(self::STATE_EXPIRES_KEY, 0);
    $token = (string) $this->state->get(self::STATE_KEY, '');

    if (!$force_refresh && $token !== '' && $expires > time() + self::TOKEN_BUFFER_SECONDS) {
      return $token;
    }

    return $this->requestAccessToken();
  }

  /**
   * Clears the cached access token.
   */
  public function clearToken(): void {
    $this->state->delete(self::STATE_KEY);
    $this->state->delete(self::STATE_EXPIRES_KEY);
  }

  /**
   * Requests a new access token from Dinero.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  public function requestAccessToken(): string {
    $config = $this->configFactory->get('commerce_dinero.settings');
    $client_id = trim((string) $config->get('client_id'));
    $client_secret = $this->resolveKeyValue((string) $config->get('client_secret_key'), 'client secret');
    $api_key = $this->resolveKeyValue((string) $config->get('api_key_key'), 'API key');

    if ($client_id === '') {
      throw new DineroApiException('Dinero client ID is not configured.');
    }

    try {
      $response = $this->httpClient->request('POST', self::AUTH_URI, [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'password',
          'scope' => 'read write',
          'username' => $api_key,
          'password' => $api_key,
        ],
      ]);
    }
    catch (RequestException $exception) {
      $this->logger->error('Dinero token request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new DineroApiException('Could not authenticate with Dinero.', $exception->getCode(), [], $exception);
    }

    $body = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($body) || empty($body['access_token'])) {
      throw new DineroApiException('Dinero token response did not contain an access token.');
    }

    $token = (string) $body['access_token'];
    $expires_in = (int) ($body['expires_in'] ?? 3600);
    $this->state->set(self::STATE_KEY, $token);
    $this->state->set(self::STATE_EXPIRES_KEY, time() + $expires_in);

    return $token;
  }

  /**
   * Resolves a key entity value.
   *
   * @throws \Drupal\commerce_dinero\Exception\DineroApiException
   */
  protected function resolveKeyValue(string $key_id, string $label): string {
    if ($key_id === '') {
      throw new DineroApiException(sprintf('Dinero %s is not configured.', $label));
    }

    $key = $this->keyRepository->getKey($key_id);
    if ($key === NULL) {
      throw new DineroApiException(sprintf('Configured Dinero %s key "%s" was not found.', $label, $key_id));
    }

    $value = $key->getKeyValue();
    if ($value === NULL || $value === '') {
      throw new DineroApiException(sprintf('Configured Dinero %s key "%s" has no value.', $label, $key_id));
    }

    return $value;
  }

}
