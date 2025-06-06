<?php

namespace Drupal\dataverse_webform\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * Exception thrown for Dataverse-related errors.
 */
class DataverseException extends \Exception {

  public const SEVERITY_CRITICAL = 'critical';
  public const SEVERITY_ERROR = 'error';
  public const SEVERITY_WARNING = 'warning';
  public const SEVERITY_INFO = 'info';

  public const ERROR_TYPE_AUTH = 'authentication_error';
  public const ERROR_TYPE_CONFIG = 'configuration_error';
  public const ERROR_TYPE_VALIDATION = 'validation_error';
  public const ERROR_TYPE_NETWORK = 'network_error';
  public const ERROR_TYPE_RATE_LIMIT = 'rate_limit_exceeded';

  protected ?string $dataverseErrorCode;
  protected array $context;

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
   * Get Dataverse-specific error code.
   */
  public function getDataverseErrorCode(): ?string {
    return $this->dataverseErrorCode;
  }

  /**
   * Get error context.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Check if this is an authentication error.
   */
  public function isAuthenticationError(): bool {
    $auth_indicators = [
      'unauthorized', 'invalid_token', 'token_expired', 
      'invalid_client', 'invalid_grant', self::ERROR_TYPE_AUTH,
      'authentication', 'access denied', 'forbidden'
    ];
    
    return $this->hasErrorIndicator($auth_indicators);
  }

  /**
   * Check if this is a rate limit error.
   */
  public function isRateLimitError(): bool {
    return $this->getCode() === 429 || 
           $this->dataverseErrorCode === self::ERROR_TYPE_RATE_LIMIT ||
           $this->hasErrorIndicator(['rate limit', 'throttled', 'quota exceeded', 'too many requests']);
  }

  /**
   * Check if this is a validation error.
   */
  public function isValidationError(): bool {
    $validation_indicators = [
      'invalid_request', self::ERROR_TYPE_VALIDATION, 'bad_request', 
      self::ERROR_TYPE_CONFIG, 'validation', 'invalid', 'malformed', 'required field'
    ];
    
    return $this->getCode() === 400 || $this->hasErrorIndicator($validation_indicators);
  }

  /**
   * Check if error is retryable.
   */
  public function isRetryable(): bool {
    // Rate limits and server errors are typically retryable.
    if ($this->isRateLimitError() || $this->getCode() >= 500) {
      return true;
    }
    
    $retryable_indicators = [
      'service_unavailable', 'timeout', 'internal_error', 'temporary_failure'
    ];
    
    return $this->hasErrorIndicator($retryable_indicators);
  }

  /**
   * Check if this is a network error.
   */
  public function isNetworkError(): bool {
    $network_indicators = [
      self::ERROR_TYPE_NETWORK, 'connection_timeout', 'dns_error', 'ssl_error',
      'network', 'connection', 'timeout', 'dns', 'ssl', 'certificate', 'host'
    ];
    
    return $this->hasErrorIndicator($network_indicators);
  }

  /**
   * Get error severity level.
   */
  public function getSeverity(): string {
    if ($this->isAuthenticationError()) {
      return self::SEVERITY_CRITICAL;
    }
    
    if ($this->isValidationError()) {
      return self::SEVERITY_ERROR;
    }
    
    if ($this->isRateLimitError() || $this->isNetworkError()) {
      return self::SEVERITY_WARNING;
    }
    
    if ($this->getCode() >= 500) {
      return self::SEVERITY_ERROR;
    }
    
    return self::SEVERITY_INFO;
  }

  /**
   * Get formatted error message.
   */
  public function getFormattedMessage(): string {
    $message = $this->getMessage();
    
    if ($this->dataverseErrorCode) {
      $message = "[{$this->dataverseErrorCode}] {$message}";
    }
    
    if (!empty($this->context['status_code'])) {
      $message = "HTTP {$this->context['status_code']}: {$message}";
    }
    
    return $message;
  }

  /**
   * Get context for logging.
   */
  public function getLogContext(): array {
    return array_merge($this->context, [
      'exception_type' => get_class($this),
      'error_code' => $this->dataverseErrorCode,
      'severity' => $this->getSeverity(),
      'is_retryable' => $this->isRetryable(),
    ]);
  }

  /**
   * Create exception from HTTP response.
   */
  public static function fromHttpResponse(ResponseInterface $response, string $operation = 'request'): self {
    $status_code = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    $error_data = json_decode($body, true);
    $dataverse_error_code = null;
    $context = ['status_code' => $status_code, 'operation' => $operation];
    
    if (is_array($error_data)) {
      $dataverse_error_code = $error_data['error']['code'] ?? $error_data['error'] ?? null;
      $context['response_data'] = $error_data;
      
      // Extract additional error details.
      if (isset($error_data['error']['message'])) {
        $context['error_details'] = $error_data['error']['message'];
      }
    }
    
    $message = self::buildErrorMessage($operation, $status_code, $dataverse_error_code, $error_data);
    
    return new self($message, $status_code, null, $dataverse_error_code, $context);
  }

  /**
   * Create configuration error.
   */
  public static function configurationError(string $message, array $context = []): self {
    return new self(
      "Configuration error: {$message}",
      0,
      null,
      self::ERROR_TYPE_CONFIG,
      $context
    );
  }

  /**
   * Create authentication error.
   */
  public static function authenticationError(string $message, array $context = []): self {
    return new self(
      "Authentication error: {$message}",
      401,
      null,
      self::ERROR_TYPE_AUTH,
      $context
    );
  }

  /**
   * Create validation error.
   */
  public static function validationError(string $message, array $context = []): self {
    return new self(
      "Validation error: {$message}",
      400,
      null,
      self::ERROR_TYPE_VALIDATION,
      $context
    );
  }

  /**
   * Create network error.
   */
  public static function networkError(string $message, array $context = []): self {
    return new self(
      "Network error: {$message}",
      0,
      null,
      self::ERROR_TYPE_NETWORK,
      $context
    );
  }

  /**
   * Create rate limit error.
   */
  public static function rateLimitError(string $message, array $context = []): self {
    return new self(
      "Rate limit error: {$message}",
      429,
      null,
      self::ERROR_TYPE_RATE_LIMIT,
      $context
    );
  }

  /**
   * Build error message from response data.
   */
  protected static function buildErrorMessage(string $operation, int $status_code, ?string $error_code, ?array $error_data): string {
    $message = "Dataverse {$operation} failed (HTTP {$status_code})";
    
    if ($error_code) {
      $message .= ": {$error_code}";
    }
    
    if (is_array($error_data) && isset($error_data['error']['message'])) {
      $message .= " - " . $error_data['error']['message'];
    }
    
    return $message;
  }

  /**
   * Check if error message contains indicators.
   */
  protected function hasErrorIndicator(array $indicators): bool {
    $message_lower = strtolower($this->getMessage());
    $error_code_lower = strtolower($this->dataverseErrorCode ?? '');
    
    foreach ($indicators as $indicator) {
      if (strpos($message_lower, $indicator) !== false || 
          strpos($error_code_lower, $indicator) !== false) {
        return true;
      }
    }
    
    return false;
  }

}