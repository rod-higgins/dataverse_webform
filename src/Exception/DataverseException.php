<?php

namespace Drupal\dataverse_webform\Exception;

/**
 * Exception thrown for Dataverse-related errors.
 */
class DataverseException extends \Exception {

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
      'invalid_client', 'invalid_grant', 'authentication_error'
    ];
    
    return in_array($this->dataverseErrorCode, $auth_codes) || 
           $this->containsAuthenticationKeywords();
  }

  public function isRateLimitError(): bool {
    return $this->getCode() === 429 || 
           $this->dataverseErrorCode === 'rate_limit_exceeded' ||
           $this->containsRateLimitKeywords();
  }

  public function isValidationError(): bool {
    $validation_codes = [
      'invalid_request', 'validation_error', 'bad_request', 'configuration_error'
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
      'network_error', 'connection_timeout', 'dns_error', 'ssl_error'
    ];
    
    return in_array($this->dataverseErrorCode, $network_codes) ||
           $this->containsNetworkKeywords();
  }

  public function getSeverity(): string {
    if ($this->isAuthenticationError()) {
      return 'critical';
    }
    
    if ($this->isValidationError()) {
      return 'error';
    }
    
    if ($this->isRateLimitError() || $this->isNetworkError()) {
      return 'warning';
    }
    
    if ($this->getCode() >= 500) {
      return 'error';
    }
    
    return 'info';
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

  public static function fromHttpResponse($response, string $operation = 'request'): self {
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
      'configuration_error',
      $context
    );
  }

  public static function authenticationError(string $message, array $context = []): self {
    return new self(
      "Authentication error: {$message}",
      401,
      null,
      'authentication_error',
      $context
    );
  }

  public static function validationError(string $message, array $context = []): self {
    return new self(
      "Validation error: {$message}",
      400,
      null,
      'validation_error',
      $context
    );
  }

  public static function networkError(string $message, array $context = []): self {
    return new self(
      "Network error: {$message}",
      0,
      null,
      'network_error',
      $context
    );
  }

  public static function rateLimitError(string $message, array $context = []): self {
    return new self(
      "Rate limit error: {$message}",
      429,
      null,
      'rate_limit_exceeded',
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
    $keywords = ['authentication', 'unauthorized', 'access denied', 'invalid credentials'];
    return $this->containsKeywords($keywords);
  }

  protected function containsRateLimitKeywords(): bool {
    $keywords = ['rate limit', 'throttled', 'quota exceeded', 'too many requests'];
    return $this->containsKeywords($keywords);
  }

  protected function containsValidationKeywords(): bool {
    $keywords = ['validation', 'invalid', 'bad request', 'malformed'];
    return $this->containsKeywords($keywords);
  }

  protected function containsNetworkKeywords(): bool {
    $keywords = ['network', 'connection', 'timeout', 'dns', 'ssl', 'certificate'];
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