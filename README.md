# Dataverse Webform Integration v1.0.0

A comprehensive Drupal module that integrates Webform submissions with Microsoft Dataverse via the OData API, featuring **multi-entity mapping support**, secure Azure AD authentication, and advanced data processing capabilities.

## Key Features

- ✅ **Multi-Entity Mapping**: Map webform fields to different Dataverse entities in a single submission
- ✅ **Secure OData Integration**: Parameterized query builder prevents injection attacks
- ✅ **Azure AD Authentication**: Secure authentication with token caching
- ✅ **Dynamic Entity Discovery**: Automatically discovers available entities and fields
- ✅ **Advanced Field Mapping UI**: Visual interface with entity-specific field loading
- ✅ **Data Transformation**: Built-in transformations for different data types
- ✅ **Key Module Integration**: Secure credential storage using Drupal's Key module
- ✅ **Intelligent Caching**: Efficient caching of entity and field metadata
- ✅ **Comprehensive Logging**: Detailed logging and error reporting
- ✅ **Batch Processing**: Configurable batch sizes for optimal performance

## Requirements

- **Drupal**: 10.4+ or Drupal 11
- **PHP**: 7.4+ (8.0+ recommended)
- **Required Modules**: Webform, Key
- **PHP Extensions**: cURL, JSON, OpenSSL
- **External Services**: Microsoft Dataverse instance, Azure AD application

## Quick Start

### 1. Installation

```bash
composer require drupal/webform drupal/key
drush en webform key dataverse_webform
drush cr
```

### 2. Azure AD Setup

1. Create Azure AD app registration at [Azure Portal](https://portal.azure.com)
2. Note Application (client) ID and Directory (tenant) ID
3. Create client secret and copy the value immediately
4. Grant "Dynamics CRM" API permissions
5. Create application user in Dataverse with appropriate roles

### 3. Drupal Configuration

1. Create security keys at `/admin/config/system/keys`:
   - Azure Client ID key
   - Azure Client Secret key

2. Configure webform integration:
   - Edit any webform → Settings → Dataverse Integration
   - Enable integration and configure Azure/Dataverse settings
   - Map webform fields to Dataverse entities
   - Test connection and save

### 4. Test Configuration

Use the built-in test form at `/admin/config/services/dataverse/test` to verify:
- Azure AD authentication
- Entity and field retrieval
- Multi-entity operations
- Complete configuration validation

## Configuration Examples

### Basic Contact Creation
```yaml
Field Mappings:
- first_name → contacts.firstname (string)
- last_name → contacts.lastname (string)
- email → contacts.emailaddress1 (email)
- phone → contacts.telephone1 (phone)
```

### Multi-Entity: Contact + Account
```yaml
Field Mappings:
- contact_first_name → contacts.firstname (string)
- contact_last_name → contacts.lastname (string)
- contact_email → contacts.emailaddress1 (email)
- company_name → accounts.name (string)
- company_website → accounts.websiteurl (url)

Submission Order:
1. accounts
2. contacts
```

### Advanced with Transformations
```yaml
Field Mappings:
- interested_products → leads.description (json)
- budget_range → leads.budgetamount (number)
- contact_me → leads.donotbulkemail (boolean, inverted)
- signup_date → leads.createdon (date)
```

## Data Transformations

| Transform | Description | Example |
|-----------|-------------|---------|
| `string` | Convert to string | Arrays joined with "; " |
| `number` | Convert to number | "100.50" → 100.5 |
| `boolean` | Convert to boolean | "yes" → true |
| `date` | Convert to ISO date | "2024-01-15" → ISO format |
| `email` | Validate/format email | Lowercase validation |
| `phone` | Format phone number | Remove non-numeric chars |
| `url` | Validate/format URL | Add http:// if missing |
| `json` | Convert to JSON | Array/object → JSON string |

## Security Features

- **Input Validation**: GUID format validation, URL validation with HTTPS requirement
- **OData Query Protection**: Parameterized queries, identifier validation, value sanitization
- **Credential Security**: Azure credentials stored in Key module only, secure token caching
- **Access Control**: Permission-based access, entity-level security via Dataverse roles

## Performance Optimization

- **Caching Strategy**: Entity/field metadata cached for 1 hour, access tokens cached with safety buffer
- **Batch Processing**: Configurable batch sizes (1-100 entities), automatic rate limiting
- **API Rate Limiting**: Built-in delays, exponential backoff, configurable retry attempts

## Troubleshooting

### Common Issues

**Connection Failed**
- Verify Azure AD app permissions and grant admin consent
- Check Dataverse application user setup and security roles
- Validate tenant ID format (must be GUID)

**No Entities Loaded**
- Confirm application user has read permissions on metadata
- Check Dataverse security roles include entity access
- Verify environment URL format and accessibility

**Field Mapping Errors**
- Ensure target entity permissions allow record creation
- Verify field-level security settings in Dataverse
- Check field types compatibility

### Debug Tools

1. **Built-in Test Form**: `/admin/config/services/dataverse/test`
2. **Drupal Logs**: `/admin/reports/dblog` (filter by 'dataverse_webform')
3. **Configuration Validation**: Real-time validation in webform settings
4. **Connection Testing**: Test authentication, entities, fields, and multi-entity operations

## API Reference

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
```

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
    ],
  ],
  'submission_order' => ['contacts'],
  'batch_size' => 10,
  'retry_attempts' => 3,
  'timeout' => 30,
];
```

## Support

### Getting Help
1. **Documentation**: Review this README and inline help text
2. **Test Tools**: Use built-in testing and validation tools
3. **Logs**: Check Drupal logs for detailed error information

### Reporting Issues
Include when reporting issues:
- Drupal and module versions
- PHP version and extensions
- Azure AD configuration details (no secrets)
- Complete error messages from logs
- Steps to reproduce

## License

GPL-2.0-or-later

---

**Dataverse Webform Integration v1.0.0** - Comprehensive multi-entity integration between Drupal Webforms and Microsoft Dataverse with advanced security and performance optimization.