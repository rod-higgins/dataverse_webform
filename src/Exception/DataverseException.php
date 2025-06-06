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

  public function getDataverseErrorCode(): ?string {
    return $this->dataverseErrorCode;
  }

  public function getContext(): array {
    return $this->context;
  }

  public function isAuthenticationError(): bool {
    $auth_codes = [
      'unauthorized', 'invalid_token', 'token_expired', 
      'invalid_client', 'invalid_grant', self::ERROR_TYPE_AUTH
    ];
    
    return in_array($this->dataverseErrorCode, $auth_codes) || 
           $this->containsAuthenticationKeywords();
  }

  public function isRateLimitError(): bool {
    return $this->getCode() === 429 || 
           $this->dataverseErrorCode === self::ERROR_TYPE_RATE_LIMIT ||
           $this->containsRateLimitKeywords();
  }

  public function isValidationError(): bool {
    $validation_codes = [
      'invalid_request', self::ERROR_TYPE_VALIDATION, 'bad_request', self::ERROR_TYPE_CONFIG
    ];
    
    return $this->getCode() === 400 ||
           in_array($this->dataverseErrorCode, $validation_codes) ||
           $this->containsValidationKeywords();
  }

  public function isRetryable(): bool {
    // Rate limits and server errors are typically retryable
    if ($this->isRateLimitError() || $this->getCode() >= 500) {
      return true;
    }
    
    $retryable_codes = [
      'service_unavailable', 'timeout', 'internal_error', 'temporary_failure'
    ];
    
    return in_array($this->dataverseErrorCode, $retryable_codes);
  }

  public function isNetworkError(): bool {
    $network_codes = [
      self::ERROR_TYPE_NETWORK, 'connection_timeout', 'dns_error', 'ssl_error'
    ];
    
    return in_array($this->dataverseErrorCode, $network_codes) ||
           $this->containsNetworkKeywords();
  }

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

  public function getLogContext(): array {
    return array_merge($this->context, [
      'exception_type' => get_class($this),
      'error_code' => $this->dataverseErrorCode,
      'severity' => $this->getSeverity(),
      'is_retryable' => $this->isRetryable(),
    ]);
  }

  public static function fromHttpResponse(ResponseInterface $response, string $operation = 'request'): self {
    $status_code = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    $error_data = json_decode($body, true);
    $dataverse_error_code = null;
    $context = ['status_code' => $status_code, 'operation' => $operation];
    
    if (is_array($error_data)) {
      $dataverse_error_code = $error_data['error']['code'] ?? $error_data['error'] ?? null;
      $context['response_data'] = $error_data;
      
      // Extract additional error details
      if (isset($error_data['error']['message'])) {
        $context['error_details'] = $error_data['error']['message'];
      }
    }
    
    $message = self::buildErrorMessage($operation, $status_code, $dataverse_error_code, $error_data);
    
    return new self($message, $status_code, null, $dataverse_error_code, $context);
  }

  public static function configurationError(string $message, array $context = []): self {
    return new self(
      "Configuration error: {$message}",
      0,
      null,
      self::ERROR_TYPE_CONFIG,
      $context
    );
  }

  public static function authenticationError(string $message, array $context = []): self {
    return new self(
      "Authentication error: {$message}",
      401,
      null,
      self::ERROR_TYPE_AUTH,
      $context
    );
  }

  public static function validationError(string $message, array $context = []): self {
    return new self(
      "Validation error: {$message}",
      400,
      null,
      self::ERROR_TYPE_VALIDATION,
      $context
    );
  }

  public static function networkError(string $message, array $context = []): self {
    return new self(
      "Network error: {$message}",
      0,
      null,
      self::ERROR_TYPE_NETWORK,
      $context
    );
  }

  public static function rateLimitError(string $message, array $context = []): self {
    return new self(
      "Rate limit error: {$message}",
      429,
      null,
      self::ERROR_TYPE_RATE_LIMIT,
      $context
    );
  }

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

  protected function containsAuthenticationKeywords(): bool {
    $keywords = ['authentication', 'unauthorized', 'access denied', 'invalid credentials', 'forbidden'];
    return $this->containsKeywords($keywords);
  }

  protected function containsRateLimitKeywords(): bool {
    $keywords = ['rate limit', 'throttled', 'quota exceeded', 'too many requests'];
    return $this->containsKeywords($keywords);
  }

  protected function containsValidationKeywords(): bool {
    $keywords = ['validation', 'invalid', 'bad request', 'malformed', 'required field'];
    return $this->containsKeywords($keywords);
  }

  protected function containsNetworkKeywords(): bool {
    $keywords = ['network', 'connection', 'timeout', 'dns', 'ssl', 'certificate', 'host'];
    return $this->containsKeywords($keywords);
  }

  protected function containsKeywords(array $keywords): bool {
    $message_lower = strtolower($this->getMessage());
    
    foreach ($keywords as $keyword) {
      if (strpos($message_lower, $keyword) !== false) {
        return true;
      }
    }
    
    return false;
  }

}