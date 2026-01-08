# Salesforce Object Export Scripts

A collection of scripts designed to export Salesforce object data with a focus on Account relationships. These scripts demonstrate how to programmatically interact with the Salesforce CLI to retrieve metadata and export data using the Bulk API.

## Purpose

These scripts were created for **training and educational purposes** to demonstrate:

- How to use the Salesforce CLI programmatically from PHP and Python
- Working with Salesforce object metadata (Schema Describe)
- Bulk data export patterns using the Bulk API 2.0
- Processing and filtering Salesforce field metadata
- Building dynamic SOQL queries based on discovered fields

## What These Scripts Do

The scripts export multiple Salesforce objects (Account, Contact, Case, Opportunity, Order, Asset) to CSV files, but **only include**:
- The `Id` field (primary key)
- Any lookup/reference fields that point to Account records

This focused approach is useful for:
- Analyzing Account relationships across different objects
- Data migration planning
- Understanding object interconnections
- Reference data analysis

## Files in This Repository

### `export.php`
The primary implementation written in PHP. Heavily commented with detailed explanations of:
- Salesforce CLI command structure and parameters
- How the Schema Describe API works
- Bulk API 2.0 export process
- Field filtering logic

### `export.py`
A Python implementation with identical functionality to the PHP version. Use this if you prefer Python or want to compare implementation approaches between languages.

**Key Difference**: Python uses `subprocess.run()` for command execution while PHP uses `shell_exec()` and `passthru()`. Both achieve the same result with slightly different syntax.

## Prerequisites

### Required
1. **Salesforce CLI (sf)** - Version 2.x or later
   ```bash
   # Install via npm
   npm install -g @salesforce/cli
   
   # Verify installation
   sf --version
   ```

2. **Authenticated Salesforce Org**
   - You must have an authenticated org with the alias `PROD`
   - To authenticate:
     ```bash
     sf org login web --alias PROD
     ```

### For PHP Version
- PHP 7.4 or later
- CLI access (php-cli)
- Extensions: `json` (usually included by default)

### For Python Version
- Python 3.7 or later
- No additional packages required (uses only standard library)

## How to Use

### 1. Configure the Scripts

Both scripts have configuration constants at the top:

```php
// PHP (export.php)
$SF_CLI_PATH = '/usr/local/bin/sf';  // Path to SF CLI
$EXPORT_DIRECTORY = './exports';      // Output directory
```

```python
# Python (export.py)
SF_CLI_PATH = '/usr/local/bin/sf'    # Path to SF CLI
EXPORT_DIRECTORY = './exports'        # Output directory
```

**Important**: Update `SF_CLI_PATH` if your Salesforce CLI is installed in a different location. Find it with:
```bash
which sf
```

### 2. Modify the Object List (Optional)

Both scripts process these objects by default:
- Account
- Contact
- Case
- Opportunity
- Order
- Asset

To export different objects, modify the array:

```php
// PHP
$objects = ['Account', 'Contact', 'CustomObject__c'];
```

```python
# Python
OBJECTS = ['Account', 'Contact', 'CustomObject__c']
```

### 3. Run the Script

**PHP:**
```bash
php export.php
```

**Python:**
```bash
python3 export.py
# Or make it executable first
chmod +x export.py
./export.py
```

### 4. Monitor Progress

The scripts provide real-time output showing:
- Which object is being processed
- Fields discovered that reference Account
- Bulk export job progress
- Success/failure status for each export

Example output:
```
=== Starting export for Contact ===
[DEBUG] Describe command: /usr/local/bin/sf force:schema:sobject:describe...
[DEBUG] Saved describe output to ./exports/Contact_describe.json
[DEBUG] Found fields in result.fields, count: 87
[DEBUG]   - Field: AccountId (type: reference, references: Account)
[DEBUG] Collected lookup fields referencing Account: 1 fields
[DEBUG] Bulk export command: /usr/local/bin/sf data export bulk...
✓ Successfully exported Contact to ./exports/Contact_export.csv
=== Finished export for Contact ===
```

## Output Files

After running, the `./exports/` directory will contain:

### CSV Export Files
- `Account_export.csv`
- `Contact_export.csv`
- `Case_export.csv`
- `Opportunity_export.csv`
- `Order_export.csv`
- `Asset_export.csv`

Each CSV contains:
- Column A: The object's `Id`
- Columns B+: Any fields that reference Account records

### Metadata JSON Files
- `Account_describe.json`
- `Contact_describe.json`
- etc.

These contain the complete metadata response from Salesforce, useful for:
- Debugging field discovery issues
- Understanding object structure
- Learning about Salesforce metadata API responses

## Understanding the Code

### Step 1: Schema Describe

```bash
sf force:schema:sobject:describe --target-org PROD --sobjecttype Contact --json
```

This command retrieves complete metadata about an object including:
- All field names and API names
- Field types (text, reference, picklist, etc.)
- Relationships (`referenceTo` property)
- Field properties (required, updateable, etc.)

**Key Learning**: The `referenceTo` array tells us which objects a lookup field points to. For example:
```json
{
  "name": "AccountId",
  "type": "reference",
  "referenceTo": ["Account"]
}
```

### Step 2: Field Filtering

The scripts filter fields to find only those that reference Account:

```php
// PHP
if (isset($field['referenceTo']) && in_array('Account', $field['referenceTo'])) {
    $fieldList[] = $field['name'];
}
```

```python
# Python
if 'referenceTo' in field and 'Account' in field['referenceTo']:
    field_list.append(field['name'])
```

### Step 3: SOQL Query Construction

A dynamic query is built with discovered fields:
```sql
SELECT Id, AccountId, ParentAccountId FROM Contact
```

### Step 4: Bulk Export

```bash
sf data export bulk --target-org PROD --query "SELECT..." --output-file output.csv --wait 30
```

**How Bulk API 2.0 Works**:
1. CLI creates a bulk query job in Salesforce
2. Salesforce processes the query asynchronously (in the background)
3. CLI polls job status every few seconds
4. When complete, CLI downloads results to CSV
5. Large datasets are handled efficiently without timeouts

**Key Learning**: Bulk API is designed for large data volumes. Unlike synchronous REST API queries (limited to 2000 records), Bulk API can handle millions of records.

### Step 5: Verification

Scripts check two conditions for success:
1. Command exit code = 0 (no errors)
2. Output CSV file exists on disk

## Differences Between PHP and Python Versions

| Aspect | PHP Version | Python Version |
|--------|-------------|----------------|
| **Command Execution** | `shell_exec()`, `passthru()` | `subprocess.run()` |
| **JSON Parsing** | `json_decode($str, true)` | `json.loads(str)` |
| **Array Deduplication** | `array_unique(array_merge(...))` | `dict.fromkeys(list)` |
| **Output Flushing** | `flush()` | `sys.stdout.flush()` |
| **File Operations** | `file_exists()`, `mkdir()` | `os.path.exists()`, `Path.mkdir()` |
| **Shell Escaping** | `escapeshellarg()` (required) | Not needed (list args) |

**Important Security Note**: The Python version is slightly more secure by default because `subprocess.run()` with a list argument doesn't involve shell interpretation, eliminating potential injection risks. The PHP version properly uses `escapeshellarg()` to mitigate this.

## Troubleshooting

### "Command not found" Error
**Problem**: SF CLI path is incorrect
**Solution**: Find the correct path with `which sf` and update `SF_CLI_PATH`

### "No fields found for object"
**Problem**: Object has no lookup fields pointing to Account
**Solution**: This is expected for some objects. The script skips them automatically.

### "Export failed (exit code 1)"
**Possible Causes**:
1. Not authenticated to the org
   - Run: `sf org login web --alias PROD`
2. Incorrect org alias
   - Change `PROD` in scripts to match your org alias
   - Check aliases: `sf org list`
3. Insufficient permissions
   - Ensure your user has read access to the objects

### Bulk Job Timeout
**Problem**: Export exceeds 30-minute wait time
**Solution**: Increase `--wait 30` to a higher value (e.g., `--wait 60`)

### Memory Issues (PHP Only)
**Problem**: PHP runs out of memory on large exports
**Solution**: The script already sets `memory_limit = -1`. If still failing, the issue is likely with SF CLI, not PHP.

## Educational Use Cases

These scripts are ideal for learning:

1. **CLI Automation**: How to wrap Salesforce CLI in scripts for batch operations
2. **Metadata API**: Understanding object schema and field relationships
3. **Bulk API**: Best practices for exporting large datasets
4. **Dynamic Queries**: Building SOQL based on discovered metadata
5. **Error Handling**: Checking exit codes and file existence
6. **Multi-Language Patterns**: Comparing PHP and Python approaches

## Extending the Scripts

### Add More Objects
Simply add to the objects array:
```php
$objects = ['Account', 'Lead', 'CustomObject__c'];
```

### Filter Different Relationships
Change the filtering logic to find fields referencing other objects:
```php
if (in_array('Contact', $field['referenceTo'])) {
    // Find Contact lookups instead
}
```

### Export All Fields
Remove the filtering and export all fields:
```php
$fieldList[] = $field['name'];  // No condition
```

### Add Custom Filtering
Export only fields matching certain criteria:
```php
if ($field['type'] === 'reference' && $field['updateable']) {
    $fieldList[] = $field['name'];
}
```

## Best Practices Demonstrated

1. **Progress Feedback**: Real-time output keeps users informed
2. **Debug Information**: Detailed logging helps troubleshoot issues
3. **Error Handling**: Graceful failures with informative messages
4. **Metadata Preservation**: Saving describe JSON for later review
5. **Configuration Separation**: Constants at top for easy modification
6. **Shell Safety**: Proper escaping of user inputs

## License

These scripts are provided for educational purposes. Feel free to modify and adapt them for your training needs.

## Contributing

This is a training repository. Contributions that improve educational value are welcome:
- Additional comments explaining complex concepts
- Examples of common use cases
- Troubleshooting tips based on real experiences
- Translations to other programming languages

## Resources

- [Salesforce CLI Documentation](https://developer.salesforce.com/docs/atlas.en-us.sfdx_cli_reference.meta/sfdx_cli_reference/)
- [Bulk API 2.0 Guide](https://developer.salesforce.com/docs/atlas.en-us.api_asynch.meta/api_asynch/bulk_api_2_0.htm)
- [SOQL Reference](https://developer.salesforce.com/docs/atlas.en-us.soql_sosl.meta/soql_sosl/)
- [Schema Describe API](https://developer.salesforce.com/docs/atlas.en-us.api.meta/api/sforce_api_calls_describesobjects.htm)
