# Dataverse Webform Integration v1.0.0

A comprehensive Drupal module that seamlessly integrates Webform submissions with Microsoft Dataverse via the OData API, featuring **multi-entity mapping support**, secure Azure AD authentication, intelligent caching, and enterprise-grade data processing capabilities.

## 🚀 Key Features

- ✅ **Multi-Entity Mapping**: Map webform fields to different Dataverse entities in a single submission
- ✅ **Secure OData Integration**: Parameterized query builder prevents injection attacks
- ✅ **Azure AD Authentication**: Secure authentication with intelligent token caching and refresh
- ✅ **Dynamic Entity Discovery**: Automatically discovers available entities and fields from your Dataverse
- ✅ **Advanced Field Mapping UI**: Visual interface with entity-specific field loading and auto-mapping suggestions
- ✅ **Data Transformation Engine**: Built-in transformations for different data types (string, number, boolean, date, email, phone, URL, JSON)
- ✅ **Key Module Integration**: Secure credential storage using Drupal's Key module
- ✅ **Intelligent Caching**: Efficient caching of entity metadata, field definitions, and access tokens
- ✅ **Queue-Based Processing**: Background processing for complex submissions with retry mechanisms
- ✅ **Comprehensive Logging**: Detailed logging and error reporting with context
- ✅ **Batch Processing**: Configurable batch sizes for optimal performance
- ✅ **Rate Limiting**: Built-in API rate limiting and throttling protection
- ✅ **Configuration Validation**: Real-time validation of settings and connections
- ✅ **AJAX Interface**: Dynamic loading of entities and fields in admin interface

## 📋 Requirements

### System Requirements
- **Drupal**: 10.4+ or Drupal 11
- **PHP**: 7.4+ (8.0+ recommended for optimal performance)
- **Memory**: 128MB+ recommended for processing large submissions
- **PHP Extensions**: cURL, JSON, OpenSSL, Hash

### Required Drupal Modules
- **Webform**: For form creation and submission handling
- **Key**: For secure credential storage and management

### External Services
- **Microsoft Dataverse**: Active Dataverse environment
- **Azure AD Application**: Registered app with appropriate permissions

## 🔧 Installation

### 1. Module Installation

```bash
# Install dependencies
composer require drupal/webform drupal/key

# Enable modules
drush en webform key dataverse_webform

# Clear caches
drush cr
```

### 2. Azure AD Application Setup

1. **Create Azure AD App Registration**:
   - Go to [Azure Portal](https://portal.azure.com) → Azure Active Directory → App registrations
   - Click "New registration"
   - Name your application (e.g., "Drupal Dataverse Integration")
   - Select "Accounts in this organizational directory only"
   - No redirect URI needed for service-to-service auth

2. **Configure Application**:
   - Note the **Application (client) ID** and **Directory (tenant) ID**
   - Go to "Certificates & secrets" → "New client secret"
   - **Important**: Copy the secret value immediately (it's only shown once)
   - Set appropriate expiration (24 months recommended)

3. **Grant API Permissions**:
   - Go to "API permissions" → "Add a permission"
   - Select "Dynamics CRM" → "Application permissions"
   - Add "user_impersonation" permission
   - Click "Grant admin consent" for your organization

### 3. Dataverse Application User Setup

1. **Create Application User**:
   - In your Dataverse environment, go to Settings → Users + permissions → Application users
   - Click "New app user"
   - Select your Azure AD app registration
   - Choose appropriate security roles (typically "System Administrator" for full access)

2. **Verify Permissions**:
   - Ensure the application user has read/write permissions on target entities
   - Verify access to entity metadata and field definitions

### 4. Drupal Key Configuration

1. **Navigate to Key Management**:
   ```
   /admin/config/system/keys
   ```

2. **Create Azure Client ID Key**:
   - Click "Add key"
   - Label: "Azure Client ID"
   - Key type: "Configuration"
   - Key provider: "Configuration" or "Environment variable" (recommended)
   - Enter your Azure Application (client) ID

3. **Create Azure Client Secret Key**:
   - Click "Add key"
   - Label: "Azure Client Secret"
   - Key type: "Configuration"
   - Key provider: "Configuration" or "Environment variable" (recommended)
   - Enter your Azure client secret value

## ⚙️ Configuration

### 1. Basic Webform Integration

1. **Navigate to Webform Settings**:
   ```
   /admin/structure/webform/manage/[WEBFORM_ID]/settings
   ```

2. **Enable Dataverse Integration**:
   - Scroll to "Dataverse Integration" section
   - Check "Enable Dataverse integration"

3. **Azure AD Configuration**:
   - **Azure Tenant ID**: Your Azure AD tenant GUID
   - **Azure Client ID Key**: Select the key created earlier
   - **Azure Client Secret Key**: Select the secret key created earlier

4. **Dataverse Configuration**:
   - **Dataverse URL**: Your environment URL (e.g., `https://yourorg.crm.dynamics.com`)

### 2. Field Mapping Configuration

#### Basic Contact Creation Example
```yaml
Field Mappings:
- first_name → contacts.firstname (string transformation)
- last_name → contacts.lastname (string transformation)
- email → contacts.emailaddress1 (email transformation)
- phone → contacts.telephone1 (phone transformation)
- company → contacts.company (string transformation)
```

#### Multi-Entity Example: Contact + Account
```yaml
Field Mappings:
- contact_first_name → contacts.firstname (string)
- contact_last_name → contacts.lastname (string)
- contact_email → contacts.emailaddress1 (email)
- company_name → accounts.name (string)
- company_website → accounts.websiteurl (url)
- company_phone → accounts.telephone1 (phone)

Submission Order:
1. accounts (create account first)
2. contacts (create contact with reference to account)
```

#### Advanced Multi-Entity with Relationships
```yaml
Field Mappings:
- lead_first_name → leads.firstname (string)
- lead_last_name → leads.lastname (string)
- lead_email → leads.emailaddress1 (email)
- opportunity_name → opportunities.name (string)
- opportunity_value → opportunities.estimatedvalue (number)
- product_interests → leads.description (json transformation)

Submission Order:
1. leads
2. opportunities
```

### 3. Data Transformations

| Transform | Description | Input Example | Output Example |
|-----------|-------------|---------------|----------------|
| `string` | Convert to string, arrays joined with "; " | `["A", "B", "C"]` | `"A; B; C"` |
| `number` | Convert to integer or float | `"100.50"` | `100.5` |
| `boolean` | Convert to boolean (supports yes/no, true/false, etc.) | `"yes"` | `true` |
| `date` | Convert to ISO 8601 format | `"2024-01-15"` | `"2024-01-15T00:00:00Z"` |
| `email` | Validate and format email | `"USER@EXAMPLE.COM"` | `"user@example.com"` |
| `phone` | Format phone number | `"(555) 123-4567"` | `"555 123-4567"` |
| `url` | Validate and format URL | `"example.com"` | `"https://example.com"` |
| `json` | Convert to JSON string | `{"key": "value"}` | `'{"key":"value"}'` |

### 4. Advanced Configuration

#### Performance Settings
- **Request Timeout**: 5-300 seconds (default: 30)
- **Batch Size**: 1-100 entities per batch (default: 10)
- **Retry Attempts**: 0-5 retries for failed submissions (default: 3)

#### Error Handling
- **Stop on Error**: Halt processing when an entity creation fails
- **Queue Complex Submissions**: Automatically queue submissions with multiple entities

#### Submission Order
For multi-entity submissions, specify the order in which entities should be created:
1. Parent entities first (e.g., accounts)
2. Child entities second (e.g., contacts)
3. Related entities last (e.g., opportunities)

## 🧪 Testing and Validation

### 1. Built-in Test Interface

Navigate to the test interface:
```
/admin/config/services/dataverse/test
```

**Available Tests**:
- **Connection Test**: Verify Azure AD authentication and Dataverse access
- **Entity Retrieval**: Test loading available entities from your environment
- **Field Retrieval**: Test loading fields for specific entities
- **Multi-Entity Operations**: Validate field mappings across multiple entities
- **Configuration Validation**: Comprehensive validation of all settings

### 2. Configuration Validation

The module provides real-time validation:
- ✅ **Azure Configuration**: Validates tenant ID format and key availability
- ✅ **Dataverse URL**: Ensures HTTPS and proper format
- ✅ **Field Mappings**: Validates entity and field existence
- ✅ **Key Validation**: Confirms keys exist and have values
- ✅ **Permission Checks**: Verifies application user has required access

### 3. Troubleshooting Common Issues

#### Connection Failed
**Symptoms**: "Connection test failed" or authentication errors

**Solutions**:
1. Verify Azure AD app permissions and grant admin consent
2. Check Dataverse application user setup and security roles
3. Validate tenant ID format (must be GUID: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`)
4. Ensure client secret hasn't expired
5. Verify Dataverse URL format and accessibility

#### No Entities Loaded
**Symptoms**: Empty entity list or "No entities found"

**Solutions**:
1. Confirm application user has read permissions on metadata
2. Check Dataverse security roles include entity access
3. Verify environment URL format and accessibility
4. Test with different security role assignments

#### Field Mapping Errors
**Symptoms**: "Field not found" or creation failures

**Solutions**:
1. Ensure target entity permissions allow record creation
2. Verify field-level security settings in Dataverse
3. Check field types compatibility between webform and Dataverse
4. Validate required field mappings are complete

## 🔐 Security Features

### Input Validation & Sanitization
- **GUID Validation**: Azure tenant IDs must match GUID pattern
- **URL Validation**: Dataverse URLs must use HTTPS
- **Field Name Validation**: Entity and field names validated against patterns
- **Data Sanitization**: All user input sanitized before API submission

### OData Query Protection
- **Parameterized Queries**: All OData queries use parameterized values
- **Identifier Validation**: Entity and field names validated against allowed patterns
- **Value Sanitization**: Query values properly escaped and validated
- **Dangerous Character Filtering**: Prevents injection attacks

### Credential Security
- **Key Module Storage**: Azure credentials stored securely using Drupal's Key module
- **Token Caching**: Access tokens cached with expiration safety buffer
- **No Credential Logging**: Sensitive data excluded from log entries
- **Permission-based Access**: Configuration requires "administer dataverse webform" permission

### API Security
- **Rate Limiting**: Configurable API rate limiting (default: 100 requests/hour)
- **Request Timeout**: Prevents hanging requests
- **SSL/TLS Required**: All communications use HTTPS
- **Token Refresh**: Automatic token refresh with proper error handling

## 🚀 Performance Optimization

### Caching Strategy
- **Entity Metadata**: Cached for 4 hours (configurable)
- **Field Definitions**: Cached for 1 hour (configurable)
- **Access Tokens**: Cached with 60-second safety buffer
- **Configuration Validation**: Cached for 5 minutes
- **Cache Invalidation**: Automatic invalidation on configuration changes

### Batch Processing
- **Configurable Batch Sizes**: 1-100 entities per batch (default: 10)
- **Automatic Rate Limiting**: Built-in delays between batch operations
- **Memory Management**: Automatic detection of memory constraints
- **Time-based Processing**: Execution time monitoring for large submissions

### Queue-Based Processing
- **Automatic Queueing**: Complex submissions automatically queued for background processing
- **Retry Logic**: Exponential backoff for failed submissions (max 5 retries)
- **Memory Threshold**: Submissions queued when memory usage exceeds 128MB
- **Entity Count Threshold**: Submissions with >3 entities automatically queued

### Resource Management
- **Memory Monitoring**: Automatic memory usage detection
- **Execution Time Limits**: Respects PHP execution time limits
- **Connection Pooling**: Efficient HTTP client management
- **Database Optimization**: Minimal database queries with proper indexing

## 📊 Monitoring and Logging

### Comprehensive Logging
All logs available at: `/admin/reports/dblog` (filter by 'dataverse_webform')

**Log Categories**:
- **Authentication**: Azure AD token acquisition and refresh
- **API Operations**: Entity creation, field retrieval, batch operations
- **Configuration**: Settings changes and validation results
- **Queue Processing**: Background job status and retry attempts
- **Error Handling**: Detailed error context and stack traces
- **Performance**: Processing times and resource usage

### Statistics and Reporting
```php
// Get submission statistics
$handler = \Drupal::service('dataverse_webform.submission_handler');
$stats = $handler->getSubmissionStatistics();

// Statistics include:
// - Total submissions
// - Successful submissions  
// - Failed submissions
// - Queued submissions
// - Retry attempts
// - Average processing time
```

### Health Monitoring
- **Connection Health**: Regular validation of Dataverse connectivity
- **Token Status**: Monitoring of Azure AD token expiration
- **Queue Status**: Tracking of background job processing
- **Error Rates**: Monitoring submission success/failure rates

## 🔧 API Reference

### Service Usage

```php
// Get Dataverse client
$client = \Drupal::service('dataverse_webform.dataverse_client');

// Test connection
$connected = $client->testConnection($config);

// Get available entities
$entities = $client->getEntities($config);

// Get fields for specific entity
$fields = $client->getEntityFields($config, 'contacts');

// Create single entity
$result = $client->createEntity($config, 'contacts', $contact_data);

// Batch create multiple entities
$results = $client->batchCreateEntities($config, $entities_data);

// Validate field mappings
$validation = $client->validateFieldMappings($config, $field_mappings);
```

### Configuration Structure

```php
$config = [
  'enabled' => TRUE,
  'azure_tenant_id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
  'azure_client_id_key' => 'azure_client_id_key_name',
  'azure_client_secret_key' => 'azure_client_secret_key_name',
  'dataverse_url' => 'https://yourorg.crm.dynamics.com',
  'field_mappings' => [
    [
      'webform_field' => 'first_name',
      'entity' => 'contacts',
      'field' => 'firstname',
      'transform' => 'string',
      'required' => FALSE,
      'relationship_to' => NULL,
    ],
    // Additional mappings...
  ],
  'submission_order' => ['accounts', 'contacts', 'opportunities'],
  'batch_size' => 10,
  'retry_attempts' => 3,
  'timeout' => 30,
  'stop_on_error' => TRUE,
];
```

### Event Hooks

```php
/**
 * Implements hook_webform_submission_insert().
 */
function mymodule_webform_submission_insert(WebformSubmissionInterface $webform_submission) {
  // Custom processing after Dataverse submission
}

/**
 * Alter Dataverse configuration before processing.
 */
function mymodule_dataverse_webform_config_alter(array &$config, WebformSubmissionInterface $submission) {
  // Modify configuration based on submission data
}
```

## 🔄 Queue Management

### Queue Worker Configuration

The module uses Drupal's queue system for background processing:

```yaml
# Queue configuration
dataverse_webform_submissions:
  id: dataverse_webform_submissions
  title: "Dataverse Webform Submissions"
  cron:
    time: 60  # Process for 60 seconds during cron
```

### Manual Queue Processing

```bash
# Process queue items manually
drush queue:run dataverse_webform_submissions

# Check queue status
drush queue:list

# Clear failed queue items
drush queue:delete dataverse_webform_submissions
```

### Queue Item Structure

```php
$queue_item = [
  'submission_id' => '123',
  'webform_id' => 'contact_form',
  'config' => $dataverse_config,
  'created' => time(),
  'user_id' => 1,
  'retry_count' => 0,
  'last_error' => NULL,
];
```

## 🛠️ Development and Extending

### Custom Data Transformations

```php
/**
 * Implements hook_dataverse_webform_transform_alter().
 */
function mymodule_dataverse_webform_transform_alter(&$value, $transform_type, $context) {
  switch ($transform_type) {
    case 'custom_format':
      $value = mymodule_custom_transformation($value);
      break;
  }
}
```

### Custom Validation

```php
/**
 * Implements hook_dataverse_webform_validate_alter().
 */
function mymodule_dataverse_webform_validate_alter(&$errors, $config, $context) {
  // Add custom validation logic
  if (empty($config['custom_field'])) {
    $errors[] = 'Custom field is required';
  }
}
```

### Cache Management

```php
// Get cache manager
$cache_manager = \Drupal::service('dataverse_webform.cache_manager');

// Invalidate specific cache
$cache_manager->invalidateConfigCache($config);
$cache_manager->invalidateEntityCache($config, 'contacts');

// Clear all caches
$cache_manager->invalidateAllCaches();

// Get cache statistics
$stats = $cache_manager->getCacheStatistics();
```

## 📚 Best Practices

### Configuration Management
1. **Use Environment Variables**: Store Azure credentials in environment variables for security
2. **Version Control**: Exclude sensitive keys from version control
3. **Testing Environments**: Use separate Azure apps for development/staging
4. **Documentation**: Document field mappings and business logic

### Performance Optimization
1. **Batch Size Tuning**: Start with smaller batches (5-10) and increase based on performance
2. **Caching Strategy**: Leverage built-in caching for repeated operations
3. **Queue Processing**: Use queues for submissions with >3 entities
4. **Monitoring**: Regular monitoring of submission success rates and processing times

### Security Considerations
1. **Principle of Least Privilege**: Grant minimal required permissions to application user
2. **Regular Key Rotation**: Rotate Azure client secrets regularly
3. **Access Logging**: Monitor access patterns and unusual activity
4. **Error Handling**: Avoid exposing sensitive information in error messages

### Error Handling
1. **Graceful Degradation**: Handle API failures without breaking form submission
2. **User Feedback**: Provide clear, actionable error messages
3. **Retry Logic**: Implement appropriate retry strategies for transient failures
4. **Fallback Options**: Consider fallback data storage for critical submissions

## 🔄 Updates and Maintenance

### Module Updates

```bash
# Update the module
composer update drupal/dataverse_webform

# Run database updates
drush updb

# Clear caches
drush cr
```

### Configuration Backups

```bash
# Export configuration
drush config:export

# Backup webform settings
drush sql:dump --structure-tables-key=common
```

### Monitoring Tasks
- **Weekly**: Review submission statistics and error rates
- **Monthly**: Check Azure token expiration and rotate if needed
- **Quarterly**: Review and update security roles and permissions
- **Annually**: Conduct security audit and update documentation

## 📄 License

GPL-2.0-or-later

## 🆘 Support and Contributing

### Getting Help
1. **Documentation**: Review this README and inline help text
2. **Test Tools**: Use built-in testing and validation tools (`/admin/config/services/dataverse/test`)
3. **Logs**: Check Drupal logs for detailed error information (`/admin/reports/dblog`)
4. **Community**: Drupal community forums and issue queues

### Reporting Issues
When reporting issues, please include:
- **Drupal and Module Versions**: Exact version numbers
- **PHP Version**: PHP version and enabled extensions
- **Azure Configuration**: App registration details (no secrets)
- **Error Messages**: Complete error messages from logs
- **Reproduction Steps**: Detailed steps to reproduce the issue
- **Environment**: Development, staging, or production environment details

### Contributing
1. **Code Standards**: Follow Drupal coding standards
2. **Testing**: Include tests for new functionality
3. **Documentation**: Update documentation for changes
4. **Security**: Follow security best practices

---

**Dataverse Webform Integration v1.0.0** - Enterprise-grade integration between Drupal Webforms and Microsoft Dataverse with comprehensive multi-entity support, advanced security, and performance optimization.