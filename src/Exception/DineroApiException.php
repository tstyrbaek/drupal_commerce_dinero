<?php

namespace Drupal\commerce_dinero\Exception;

/**
 * Exception thrown when the Dinero API returns an error.
 */
class DineroApiException extends \RuntimeException {

  public function __construct(
    string $message,
    protected int $statusCode = 0,
    protected array $responseBody = [],
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $statusCode, $previous);
  }

  /**
   * Gets the HTTP status code.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

  /**
   * Gets the decoded API response body.
   */
  public function getResponseBody(): array {
    return $this->responseBody;
  }

}
