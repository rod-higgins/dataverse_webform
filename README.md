# Dataverse Webform Integration v1.0.0

A comprehensive Drupal module that integrates Webform submissions with Microsoft Dataverse via the OData API, featuring **multi-entity mapping support**, secure Azure AD authentication, and advanced data processing capabilities.

## Features

- ✅ **Multi-Entity Mapping**: Map webform fields to different Dataverse entities in a single submission
- ✅ **Secure OData Integration**: Uses parameterized OData query builder to prevent injection attacks
- ✅ **Azure AD Authentication**: Secure authentication via Azure Active Directory with token caching
- ✅ **Dynamic Entity Discovery**: Automatically discovers available Dataverse entities and fields
- ✅ **Advanced Field Mapping UI**: Visual interface with entity-specific field loading
- ✅ **Data Transformation**: Built-in transformations for different data types
- ✅ **Key Module Integration**: Secure credential storage using Drupal's Key module
- ✅ **Intelligent Caching**: Efficient caching of entity and field metadata
- ✅ **Comprehensive Logging**: Detailed logging and error reporting
- ✅ **Batch Processing**: Configurable batch sizes for optimal performance
- ✅ **Connection Testing**: Advanced tools to test and validate configurations

## Requirements

- **Drupal**: 10.4+ or Drupal 11
- **PHP**: 7.4+ (8.0+ recommended)
- **Required Modules**:
  - Webform module
  - Key module
- **PHP Extensions**:
  - cURL
  - JSON
  - OpenSSL
- **External Services**:
  - Microsoft Dataverse instance
  - Azure AD application registration

## Installation

### 1. Install Dependencies

```bash
composer require drupal/webform drupal/key
drush en webform key
```

### 2. Install Module

```bash
# Place module files in modules/custom/dataverse_webform/
drush en dataverse_webform
drush cr
```

### 3. Set Permissions

Navigate to `/admin/people/permissions` and grant "Administer Dataverse Webform Integration" to appropriate roles.

## Azure AD Setup

### 1. Create Azure AD App Registration

1. Go to [Azure Portal](https://portal.azure.com) → Azure Active Directory → App registrations
2. Click "New registration"
3. Configure:
   - **Name**: "Drupal Dataverse Integration"
   - **Supported account types**: "Accounts in this organizational directory only"
   - **Redirect URI**: Leave blank
4. Note the **Application (client) ID** and **Directory (tenant) ID**

### 2. Create Client Secret

1. In your app registration, go to "Certificates & secrets"
2. Click "New client secret"
3. Set description and expiration
4. **Copy the secret value immediately** (you won't see it again)

### 3. Configure API Permissions

1. Go to "API permissions" in your app registration
2. Click "Add a permission" → APIs my organization uses
3. Search for and select "Dynamics CRM"
4. Select "Application permissions" → "user_impersonation"
5. Click "Grant admin consent"

### 4. Configure Dataverse Application User

1. Go to your Dataverse environment admin center
2. Navigate to "Users" → "Application Users"
3. Create new application user using your Azure AD app registration
4. Assign appropriate security roles (e.g., "System Administrator" for testing)

## Drupal Configuration

### 1. Create Security Keys

1. Go to `/admin/config/system/keys`
2. Create two keys:
   - **Azure Client ID**: Store your Application (client) ID
   - **Azure Client Secret**: Store your client secret value

### 2. Configure Multi-Entity Webform Integration

1. Edit any webform (`/admin/structure/webform`)
2. Go to "Settings" tab
3. Find "Dataverse Integration" section
4. Configure:

#### Basic Settings
- ✅ **Enable Dataverse integration**
- **Azure Tenant ID**: Your Directory (tenant) ID (GUID format)
- **Azure Client ID Key**: Select the key containing client ID
- **Azure Client Secret Key**: Select the key containing client secret
- **Dataverse URL**: Your Dataverse environment URL

#### Multi-Entity Field Mapping
- **Test Connection**: Click to load available entities
- **Field Mappings**: For each webform field:
  - Select target **Entity** (e.g., contacts, accounts, leads)
  - Select target **Field** (dynamically loaded based on entity)
  - Choose **Transform** type (string, number, boolean, date, etc.)
  - Mark as **Required** if needed

#### Advanced Configuration
- **Batch Size**: Number of entities to process per batch (1-100)
- **Retry Attempts**: Number of retry attempts for failed submissions (0-5)
- **Request Timeout**: Maximum time to wait for API responses (5-300 seconds)
- **Stop on Error**: Whether to stop processing if one entity creation fails

### 3. Test Configuration

1. Go to `/admin/config/services/dataverse/test`
2. Enter your configuration details
3. Run comprehensive tests:
   - **Test Connection**: Verify Azure AD authentication
   - **Test Entity Retrieval**: Verify entity access
   - **Test Field Retrieval**: Verify field metadata access
   - **Test Multi-Entity Operations**: Validate multi-entity field mappings
   - **Validate Configuration**: Complete configuration validation

## Usage Examples

### Example 1: Contact and Account Creation

A webform that creates both a contact and related account:

```yaml
Field Mappings:
- first_name → contacts.firstname (string)
- last_name → contacts.lastname (string)
- email → contacts.emailaddress1 (email)
- company_name → accounts.name (string)
- company_website → accounts.websiteurl (url)

Submission Order:
1. accounts (create company first)
2. contacts (create contact, can reference account)
```

### Example 2: Lead with Custom Transformations

A lead generation form with data transformations:

```yaml
Field Mappings:
- full_name → leads.fullname (string)
- contact_email → leads.emailaddress1 (email)
- phone_number → leads.telephone1 (phone)
- interested_in → leads.description (json)
- budget_range → leads.budgetamount (number)
- contact_me → leads.donotbulkemail (boolean, inverted)
```

### Example 3: Multi-Entity Customer Onboarding

Complex form creating account, contact, and opportunity:

```yaml
Field Mappings:
- company_name → accounts.name (string)
- company_industry → accounts.industrycode (string)
- contact_first_name → contacts.firstname (string)
- contact_last_name → contacts.lastname (string)
- contact_email → contacts.emailaddress1 (email)
- opportunity_title → opportunities.name (string)
- estimated_value → opportunities.estimatedvalue (number)
- close_date → opportunities.estimatedclosedate (date)

Submission Order:
1. accounts
2. contacts
3. opportunities
```

## Multi-Entity Field Mapping

### Supported Data Transformations

| Transform Type | Description | Example |
|---------------|-------------|---------|
| `none` | No transformation | Direct value mapping |
| `string` | Convert to string | Arrays joined with "; " |
| `number` | Convert to number | "100.50" → 100.5 |
| `boolean` | Convert to boolean | "yes" → true, "no" → false |
| `date` | Convert to ISO date | "2024-01-15" → "2024-01-15T00:00:00Z" |
| `email` | Validate and format email | Convert to lowercase |
| `phone` | Format phone number | Remove non-numeric chars |
| `url` | Validate and format URL | Add http:// if missing |
| `json` | Convert to JSON string | Array/object → JSON |

### Entity Relationship Handling

The module supports creating related entities in a specific order:

1. **Parent entities** created first (e.g., accounts)
2. **Child entities** created with references (e.g., contacts)
3. **Dependent entities** created last (e.g., opportunities)

### Field Mapping Best Practices

1. **Map Required Fields First**: Ensure all required Dataverse fields are mapped
2. **Use Appropriate Transformations**: Match data types between webform and Dataverse
3. **Test Field Mappings**: Use the validation tools to verify mappings
4. **Consider Field Length Limits**: Dataverse fields have maximum length constraints
5. **Handle Multi-Value Fields**: Arrays are automatically joined with semicolons

## API Reference

### Configuration Structure

```php
$config = [
  'enabled' => TRUE,
  'azure_tenant_id' => 'your-tenant-id',
  'azure_client_id_key' => 'client_id_key',
  'azure_client_secret_key' => 'client_secret_key',
  'dataverse_url' => 'https://yourorg.crm.dynamics.com',
  'field_mappings' => [
    [
      'webform_field' => 'first_name',
      'entity' => 'contacts',
      'field' => 'firstname',
      'transform' => 'string',
      'required' => false,
      'relationship_to' => null,
    ],
    [
      'webform_field' => 'company_name',
      'entity' => 'accounts',
      'field' => 'name',
      'transform' => 'string',
      'required' => true,
      'relationship_to' => null,
    ],
  ],
  'submission_order' => ['accounts', 'contacts'],
  'batch_size' => 10,
  'retry_attempts' => 3,
  'timeout' => 30,
  'stop_on_error' => true,
];
```

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

// Submit to multiple entities (automatic via hook)
$results = $client->submitToDataverse($submission, $config);

// Validate field mappings
$validation = $client->validateFieldMappings($config, $field_mappings);

// Create single entity
$result = $client->createEntity($config, 'contacts', $data);

// Batch create multiple entities
$results = $client->batchCreateEntities($config, $entities_data);
```

### Configuration Manager

```php
// Get configuration manager
$config_manager = \Drupal::service('dataverse_webform.config_manager');

// Get Azure credential keys
$keys = $config_manager->getAzureCredentialKeys();

// Validate configuration
$validation = $config_manager->validateConfiguration($config);

// Get entities from mappings
$entities = $config_manager->getEntitiesFromMappings($field_mappings);
```

## Security Features

### Enhanced Input Validation
- GUID format validation for Azure Tenant ID
- URL validation with HTTPS requirement
- Entity and field name sanitization
- Data type validation and transformation

### OData Query Protection
- Parameterized queries prevent injection attacks
- Identifier validation with whitelist approach
- Value sanitization and length limits
- Operator validation against allowed list

### Credential Security
- Azure credentials stored in Key module only
- No plaintext secrets in configuration
- Secure token caching with automatic expiration
- Token invalidation on configuration changes

### Access Control
- Permission-based access to configuration
- Entity-level security via Dataverse roles
- Field-level security enforcement
- Comprehensive audit logging

## Performance Optimization

### Caching Strategy
- **Entity Metadata**: Cached for 1 hour
- **Field Metadata**: Cached per entity for 1 hour
- **Access Tokens**: Cached with 60-second safety buffer
- **Configuration Validation**: Cached during form building

### Batch Processing
- Configurable batch sizes (1-100 entities)
- Automatic rate limiting between batches
- Progress tracking for large submissions
- Error handling per entity in batch

### API Rate Limiting
- Built-in delays between batch requests
- Exponential backoff for failed requests
- Configurable retry attempts
- Rate limit detection and handling

## Troubleshooting

### Common Issues

#### Connection Failed
- Verify Azure AD app permissions are granted
- Check Dataverse application user setup and security roles
- Validate tenant ID format (must be GUID)
- Ensure client credentials are correctly stored in keys

#### No Entities Loaded
- Confirm application user has read permissions on metadata
- Check Dataverse security roles include entity access
- Verify environment URL format and accessibility
- Test connection using the built-in test tools

#### Field Mapping Errors
- Ensure target entity permissions allow record creation
- Verify field-level security settings in Dataverse
- Check field types compatibility between webform and Dataverse
- Validate required field mappings are complete

#### Multi-Entity Submission Failures
- Check entity submission order for dependencies
- Verify all mapped entities have create permissions
- Review batch size settings for large forms
- Check timeout settings for complex operations

### Debug Information

#### Log Locations
- **Drupal Logs**: `/admin/reports/dblog` (filter by 'dataverse_webform')
- **Authentication Logs**: Check for Azure AD token acquisition issues
- **API Logs**: Review HTTP request/response errors
- **Validation Logs**: Field mapping and configuration validation

#### Test Tools Usage
1. **Connection Test**: Validates Azure AD authentication and API access
2. **Entity Test**: Verifies entity metadata retrieval
3. **Field Test**: Checks field metadata for multiple entities
4. **Multi-Entity Test**: Validates complete field mapping configuration
5. **Configuration Validation**: Comprehensive configuration check

#### Debugging Steps
1. Use built-in test form at `/admin/config/services/dataverse/test`
2. Check Recent Log Messages for specific error details
3. Verify Azure AD app registration and permissions
4. Test API connectivity using external tools
5. Validate Dataverse user permissions and security roles

## Support and Contributing

### Getting Help

1. **Documentation**: Review this README and inline help text
2. **Test Tools**: Use built-in testing and validation tools
3. **Logs**: Check Drupal logs for detailed error information
4. **Community**: Post issues with detailed error messages and configuration

### Reporting Issues

When reporting issues, please include:
- Drupal and module versions
- PHP version and extensions
- Azure AD configuration details (no secrets)
- Dataverse environment information
- Complete error messages from logs
- Steps to reproduce the issue

### Development

#### Code Standards
- Follow Drupal coding standards
- Use PHP type hints throughout
- Implement comprehensive error handling
- Include unit tests for new features
- Document all public methods

#### Testing
- Use the built-in test form for integration testing
- Test with various field types and transformations
- Verify multi-entity scenarios
- Test error conditions and edge cases

## License

GPL-2.0-or-later

---

**Dataverse Webform Integration v1.0.0** - Comprehensive multi-entity integration between Drupal Webforms and Microsoft Dataverse with advanced security, performance optimization, and user-friendly configuration tools.