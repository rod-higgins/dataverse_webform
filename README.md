# Dataverse Webform Integration

A Drupal module that integrates Webform submissions with Microsoft Dataverse via the OData API, featuring secure Azure AD authentication and dynamic field mapping.

## Features

- ✅ **Secure OData Integration**: Uses OData query builder to prevent SQL injection attacks
- ✅ **Azure AD Authentication**: Secure authentication via Azure Active Directory
- ✅ **Dynamic Entity Discovery**: Automatically discovers available Dataverse entities
- ✅ **Field Mapping UI**: Visual interface for mapping webform fields to Dataverse fields
- ✅ **Key Module Integration**: Secure credential storage using Drupal's Key module
- ✅ **Caching**: Intelligent caching of entity and field metadata
- ✅ **Comprehensive Logging**: Detailed logging for troubleshooting
- ✅ **Connection Testing**: Built-in tools to test Dataverse connectivity

## Requirements

- Drupal 10.4+ or Drupal 11
- Webform module
- Key module
- Microsoft Dataverse instance
- Azure AD application registration

## Installation

1. **Install Dependencies**:
   ```bash
   composer require drupal/webform drupal/key
   drush en webform key
   ```

2. **Install Module**:
   ```bash
   # Place module files in modules/custom/dataverse_webform/
   drush en dataverse_webform
   drush cr
   ```

3. **Set Permissions**:
   - Navigate to `/admin/people/permissions`
   - Grant "Administer Dataverse Webform Integration" to appropriate roles

## Azure AD Setup

### 1. Create Azure AD App Registration

1. Go to [Azure Portal](https://portal.azure.com) → Azure Active Directory → App registrations
2. Click "New registration"
3. Configure:
   - **Name**: "Drupal Dataverse Integration"
   - **Supported account types**: "Accounts in this organizational directory only"
   - **Redirect URI**: Leave blank for now
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

### 2. Configure Webform Integration

1. Edit any webform (`/admin/structure/webform`)
2. Go to "Settings" tab
3. Find "Dataverse Integration" section
4. Configure:
   - ✅ Enable Dataverse integration
   - **Azure Tenant ID**: Your Directory (tenant) ID
   - **Azure Client ID Key**: Select the key containing client ID
   - **Azure Client Secret Key**: Select the key containing client secret
   - **Dataverse URL**: Your Dataverse environment URL (e.g., `https://yourorg.crm.dynamics.com`)
   - **Target Entity**: Select from dynamically loaded entities
   - **Field Mapping**: Map webform fields to Dataverse fields

### 3. Test Configuration

1. Go to `/admin/config/services/dataverse/test`
2. Enter your configuration details
3. Click "Test Connection" to verify Azure AD authentication
4. Click "Test Entity Retrieval" to verify entity access

## Usage

Once configured, webform submissions will automatically:

1. **Authenticate** with Azure AD using stored credentials
2. **Map** webform data to Dataverse fields per your configuration
3. **Create** new records in the specified Dataverse entity
4. **Log** success/failure for monitoring

## Field Mapping

The module supports mapping between:

| Webform Field Type | Dataverse Field Type | Notes |
|-------------------|---------------------|-------|
| Text | Single Line of Text | Direct mapping |
| Textarea | Multiple Lines of Text | Direct mapping |
| Number | Whole Number, Decimal | Numeric validation |
| Email | Email | Email format validation |
| Select | Choice, Yes/No | Option mapping |
| Checkboxes | Multi-Select Choice | Semicolon-separated values |
| Date | Date Only, Date and Time | ISO format conversion |

## Security Features

### OData Query Protection
- All queries use parameterized OData query builder
- Input sanitization prevents injection attacks
- Identifier validation with whitelist approach

### Credential Security
- Azure credentials stored in Key module
- No plaintext secrets in configuration
- Token caching with secure expiration

### Access Control
- Permission-based access to configuration
- Entity-level security via Dataverse roles
- Audit logging for all operations

## Troubleshooting

### Common Issues

**Connection Failed**
- Verify Azure AD app permissions
- Check Dataverse application user setup
- Validate tenant ID and client credentials

**No Entities Loaded**
- Ensure application user has read permissions
- Check Dataverse security roles
- Verify environment URL format

**Field Mapping Not Working**
- Confirm target entity permissions
- Check field-level security
- Validate field types compatibility

### Debug Logging

Check logs at `/admin/reports/dblog` for:
- `dataverse_webform` entries
- Authentication failures
- Entity/field discovery issues
- Submission errors

### Test Tools

Use built-in test form at `/admin/config/services/dataverse/test`:
- Test Azure AD authentication
- Verify entity discovery
- Validate field retrieval
- Check API connectivity

## API Reference

### Configuration Structure

```php
$config = [
  'enabled' => TRUE,
  'azure_tenant_id' => 'your-tenant-id',
  'azure_client_id_key' => 'client_id_key',
  'azure_client_secret_key' => 'client_secret_key',
  'dataverse_url' => 'https://yourorg.crm.dynamics.com',
  'target_entity' => 'contacts',
  'field_mapping' => [
    'webform_field' => 'dataverse_field',
    'first_name' => 'firstname',
    'last_name' => 'lastname',
    'email' => 'emailaddress1',
  ],
];
```

### Service Usage

```php
// Get Dataverse client
$client = \Drupal::service('dataverse_webform.dataverse_client');

// Test connection
$connected = $client->testConnection($config);

// Get entities
$entities = $client->getEntities($config);

// Get entity fields
$fields = $client->getEntityFields($config, 'contacts');

// Submit to Dataverse (automatic via hook)
$client->submitToDataverse($submission, $config);
```

## Support

For issues and feature requests:
1. Check troubleshooting section above
2. Review logs for specific error messages
3. Verify Azure AD and Dataverse configuration
4. Test connection using built-in test tools

## License

GPL-2.0-or-later