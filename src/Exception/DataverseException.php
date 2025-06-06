<?php

namespace Drupal\dataverse_webform\Exception;

/**
 * Exception thrown for Dataverse-related errors.
 */
class DataverseException extends \Exception {

  /**
   * The error code from Dataverse API, if available.
   */
  protected ?string $dataverseErrorCode;

  /**
   * Additional context information about the error.
   */
  protected array $context;

  /**
   * Constructs a DataverseException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous throwable.
   * @param string|null $dataverse_error_code
   *   The Dataverse-specific error code.
   * @param array $context
   *   Additional context information.
   */
  public function __construct(
    string $message = '',
    int $code = 0,
    ?\Throwable $previous = null,
    ?string $dataverse_error_code = null,
    array $context = []
  ) {
    parent::__construct($message, $code, $previous);
    $this->dataverseErrorCode = $dataverse_error_code;
    $this->context = $context;
  }

  /**
   * Get the Dataverse-specific error code.
   *
   * @return string|null
   *   The Dataverse error code or null if not available.
   */
  public function getDataverseErrorCode(): ?string {
    return $this->dataverseErrorCode;
  }

  /**
   * Get additional context information.
   *
   * @return array
   *   The context array.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Check if this is an authentication-related error.
   *
   * @return bool
   *   TRUE if this is an authentication error.
   */
  public function isAuthenticationError(): bool {
    return in_array($this->dataverseErrorCode, [
      'unauthorized',
      'invalid_token',
      'token_expired',
      'invalid_client',
      'invalid_grant',
    ]) || strpos($this->getMessage(), 'authentication') !== false;
  }

  /**
   * Check if this is a rate limiting error.
   *
   * @return bool
   *   TRUE if this is a rate limiting error.
   */
  public function isRateLimitError(): bool {
    return $this->getCode() === 429 || 
           $this->dataverseErrorCode === 'rate_limit_exceeded' ||
           strpos($this->getMessage(), 'rate limit') !== false;
  }

  /**
   * Check if this is a validation error.
   *
   * @return bool
   *   TRUE if this is a validation error.
   */
  public function isValidationError(): bool {
    return $this->getCode() === 400 ||
           in_array($this->dataverseErrorCode, [
             'invalid_request',
             'validation_error',
             'bad_request',
           ]) || strpos($this->getMessage(), 'validation') !== false;
  }

  /**
   * Check if this error might be temporary and worth retrying.
   *
   * @return bool
   *   TRUE if the error might be temporary.
   */
  public function isRetryable(): bool {
    // Rate limits and server errors are typically retryable
    return $this->isRateLimitError() || 
           $this->getCode() >= 500 ||
           in_array($this->dataverseErrorCode, [
             'service_unavailable',
             'timeout',
             'internal_error',
           ]);
  }

  /**
   * Create exception from HTTP response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The HTTP response.
   * @param string $operation
   *   The operation that failed.
   *
   * @return static
   *   The exception instance.
   */
  public static function fromHttpResponse($response, string $operation = 'request'): self {
    $status_code = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    $error_data = json_decode($body, true);
    $dataverse_error_code = null;
    $context = ['status_code' => $status_code];
    
    if (is_array($error_data)) {
      $dataverse_error_code = $error_data['error']['code'] ?? $error_data['error'] ?? null;
      $context['response_data'] = $error_data;
    }
    
    $message = "Dataverse {$operation} failed (HTTP {$status_code})";
    if ($dataverse_error_code) {
      $message .= ": {$dataverse_error_code}";
    }
    
    return new self($message, $status_code, null, $dataverse_error_code, $context);
  }

  /**
   * Create exception for configuration errors.
   *
   * @param string $message
   *   The error message.
   * @param array $context
   *   Additional context.
   *
   * @return static
   *   The exception instance.
   */
  public static function configurationError(string $message, array $context = []): self {
    return new self(
      "Configuration error: {$message}",
      0,
      null,
      'configuration_error',
      $context
    );
  }

  /**
   * Create exception for authentication errors.
   *
   * @param string $message
   *   The error message.
   * @param array $context
   *   Additional context.
   *
   * @return static
   *   The exception instance.
   */
  public static function authenticationError(string $message, array $context = []): self {
    return new self(
      "Authentication error: {$message}",
      401,
      null,
      'authentication_error',
      $context
    );
  }

  /**
   * Create exception for validation errors.
   *
   * @param string $message
   *   The error message.
   * @param array $context
   *   Additional context.
   *
   * @return static
   *   The exception instance.
   */
  public static function validationError(string $message, array $context = []): self {
    return new self(
      "Validation error: {$message}",
      400,
      null,
      'validation_error',
      $context
    );
  }

}