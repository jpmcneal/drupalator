# Drupal.org Updates API Documentation

## Overview

This document describes the Drupal.org Updates API dependencies, endpoints, response formats, and implementation details used by the Multi-Site Security Report Generator.

## API Base URL

```
https://updates.drupal.org/release-history/
```

## Authentication

No authentication required. The API is publicly accessible.

## Rate Limiting

- The API does not officially document rate limits
- Our implementation includes:
  - 500ms delay between module checks (`usleep(500000)`)
  - Retry logic with exponential backoff for 429 (Too Many Requests) responses
  - Maximum 3 retry attempts per request

## API Endpoints

### 1. Drupal Core Updates

**Endpoint:**
```
GET https://updates.drupal.org/release-history/drupal/current
```

**Purpose:** Retrieve available Drupal core versions and security updates

**Response Format:** XML

**Example Usage:**
```php
$url = "https://updates.drupal.org/release-history/drupal/current";
```

### 2. Module/Project Updates

**Endpoint:**
```
GET https://updates.drupal.org/release-history/{project_name}/current
```

**Parameters:**
- `{project_name}`: Machine name of the module/project (e.g., "token", "pathauto")

**Purpose:** Retrieve available versions and security updates for a specific module

**Response Format:** XML

**Example Usage:**
```php
$url = "https://updates.drupal.org/release-history/token/current";
```

## XML Response Structure

### Required Root Element
```xml
<project>
```

### Required Fields

All responses must contain:
- `<title>` - Human-readable project name
- `<short_name>` - Machine name of the project
- `<api_version>` - Drupal API version (e.g., "10.x", "11.x")
- `<releases>` - Container for release information

### Optional Fields

- `<supported_branches>` - Comma-separated list of supported version branches
  - Format: "10.5.,10.6.,11.2.,11.3."
  - Used to determine End-of-Life (EOL) versions
- `<project_status>` - Project status (e.g., "published")
- `<link>` - Project URL on Drupal.org

### Release Structure

Each release within `<releases>` contains:
```xml
<release>
    <version>10.3.5</version>
    <status>published</status>
    <date>1704067200</date>
    <core_compatibility>^10 || ^11</core_compatibility>
    <terms>
        <term>
            <name>Release type</name>
            <value>Security update</value>
        </term>
    </terms>
</release>
```

#### Release Fields:
- `<version>` - Version number (formats: "10.3.5", "8.x-1.2", "2.0.15")
- `<status>` - Release status:
  - `published` - Stable release
  - `unpublished` - Not available
- `<date>` - Unix timestamp of release
- `<core_compatibility>` - Drupal core compatibility constraint
  - Examples: "^10", "^9.3 | ^10", "^10.2 || ^11"
- `<terms>` - Metadata about the release
  - Security releases have: `<name>Release type</name><value>Security update</value>`

### Example Response

```xml
<?xml version="1.0" encoding="UTF-8"?>
<project>
    <title>Token</title>
    <short_name>token</short_name>
    <api_version>10.x</api_version>
    <supported_branches>1.0.</supported_branches>
    <releases>
        <release>
            <version>1.13.0</version>
            <status>published</status>
            <date>1704067200</date>
            <core_compatibility>^10 || ^11</core_compatibility>
            <terms>
                <term>
                    <name>Release type</name>
                    <value>Bug fixes</value>
                </term>
            </terms>
        </release>
    </releases>
</project>
```

## Response Validation

Our implementation validates:

1. **Non-empty response** - Response body is not null/empty
2. **Valid XML** - Response can be parsed as XML
3. **Root element** - Root element is `<project>`
4. **Required fields** - All required fields are present
5. **Releases section** - `<releases>` element exists
6. **supported_branches format** - If present, matches expected format

### Validation Method
```php
$validation = $this->validateDrupalOrgResponse($xmlString, $type);
// Returns: ['valid' => bool, 'xml' => SimpleXMLElement|null, 'error' => string|null]
```

## Error Handling

### HTTP Status Codes

- **200** - Success
- **404** - Project not found (don't retry)
- **429** - Rate limited (retry with backoff)
- **5xx** - Server error (retry with backoff)
- **0** - Network/connection error (retry with backoff)

### Retry Logic

**Implementation:** `fetchWithRetry()` method

**Configuration:**
- Maximum retries: 3 attempts
- Initial delay: 500ms (0.5 seconds)
- Backoff strategy: Exponential (500ms → 1s → 2s)
- Connection timeout: 5 seconds
- Request timeout: 10 seconds

**Retry Conditions:**
- HTTP 0 (network errors)
- HTTP 429 (rate limiting)
- HTTP 5xx (server errors)

**No Retry Conditions:**
- HTTP 404 (not found)
- HTTP 4xx (other client errors)

### Error Response Handling

When API calls fail, methods return safe defaults:

**For Core Updates:**
```php
return [
    'error' => 'Error message',
    'has_update' => false,
    'has_security_update' => false
];
```

**For Module Updates:**
```php
return null; // Treated as "no update info available"
```

## Version Comparison Logic

### Module Versions

**Format Examples:**
- Semantic: `2.0.15`, `3.1.0`
- Drupal 8.x style: `8.x-1.2`, `8.x-3.5`

**Major Version Extraction:**
- `2.0.15` → Major: `2`
- `8.x-1.2` → Major: `1` (extracted from position after `8.x-`)

**Comparison Strategy:**
- Security updates: Only within same major version
- Regular updates: Prefer same major version, allow cross-major if no same-major available
- Rationale: Cross-major versions represent different software, not patches

### Core Versions

**Format:** Semantic versioning (e.g., `10.3.5`, `11.0.0`)

**Major.Minor Extraction:**
- `10.3.5` → `10.3`
- Used for comparing within supported branches

**End-of-Life Detection:**
- Uses `<supported_branches>` from API response
- Compares current major.minor against list
- Recommends upgrade path to nearest supported branch

## Core Compatibility Parsing

**Constraint Formats:**
- Caret: `^10`, `^10.2`
- OR conditions: `^9.3 | ^10`, `^10 || ^11`
- Legacy: `8.x`, `9.x`
- Greater than or equal: `>=10.2`

**Parsing Logic:**
```php
private function isCompatibleWithCore($constraint, $coreVersion)
```

**Examples:**
- `^10.2` matches: 10.2.x, 10.3.x (not 11.x)
- `^10 || ^11` matches: 10.x.x, 11.x.x
- `8.x` matches: 8.x.x only

## Dependencies

### PHP Requirements
- PHP 7.4+ (PHP 8.x recommended)
- Extensions:
  - `curl` - HTTP requests
  - `simplexml` - XML parsing
  - `libxml` - XML error handling

### System Requirements
- Network access to `updates.drupal.org`
- No firewall blocking HTTPS on port 443

### Drush Requirements
- Functioning Drush via CLI
- Drush aliases or equivalent
- Commands used:
  - `drush pm:list` - List installed modules
  - `drush status` - Get Drupal version and PHP version

## Performance Considerations

### Caching
- Module update responses cached in memory (`$this->drupalOrgCache`)
- Cache key format: `{project_name}_current`
- Cache lifetime: Duration of script execution only
- No persistent cache between runs

### Request Timing
- Core update check: ~1-2 seconds
- Per-module check: ~1-2 seconds
- Delay between modules: 500ms
- Total time for 50 modules: ~60-120 seconds

### Optimization Opportunities
1. Implement persistent file-based cache
2. Parallelize module checks
3. Use Drupal.org batch API (if available)
4. Reduce delay between requests if not rate limited

## Security Considerations

### Data Privacy
- No sensitive data transmitted
- All data from public Drupal.org API
- Site URLs and module lists exposed in reports

### SSL/TLS
- All requests use HTTPS
- `CURLOPT_FOLLOWLOCATION` enabled for redirects
- Certificate validation enabled by default

### User Agent
- Custom user agent: "Drupal Update Checker 1.0"
- Helps Drupal.org identify traffic source
- Should be updated if behavior changes

## Troubleshooting

### Common Issues

**1. Empty Response**
- **Cause:** Network connectivity, DNS issues
- **Solution:** Check internet connection, verify `updates.drupal.org` resolves

**2. XML Parse Errors**
- **Cause:** Malformed API response, HTML error page instead of XML
- **Solution:** Check HTTP status code, examine raw response

**3. Module Not Found (HTTP 404)**
- **Cause:** Module doesn't exist on Drupal.org (custom/private module)
- **Solution:** Expected behavior, reported as "no update info"

**4. Rate Limiting (HTTP 429)**
- **Cause:** Too many requests in short time
- **Solution:** Automatic retry with backoff, increase delay between modules

**5. Timeout Errors**
- **Cause:** Slow network or overloaded API server
- **Solution:** Automatic retry, increase timeout values if persistent

### Debug Mode

To enable verbose output, modify the code:
```php
// In fetchWithRetry():
echo "Fetching: $url (attempt $attempt)\n";
echo "Response code: $httpCode\n";
```

## Version History

- **v1.0** (2026-01-06) - Initial implementation
- **v1.1** (2026-01-13) - Added retry logic with exponential backoff
- **v1.2** (2026-01-13) - Added response validation
- **v1.3** (2026-01-13) - Added comprehensive API documentation

## References

- [Drupal.org Update Status XML Documentation](https://www.drupal.org/docs/drupal-apis/update-status-xml)
- [Drupal.org API](https://www.drupal.org/drupalorg/docs/apis)
- [Release XML Examples](https://updates.drupal.org/release-history/)

## Support

For issues or questions:
1. Check this documentation
2. Review error messages from validation
3. Examine raw XML responses for debugging
4. Consult Drupal.org API documentation
