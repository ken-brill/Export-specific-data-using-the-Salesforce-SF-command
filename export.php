<?php
/**
 * Salesforce Object Export Script
 * 
 * This script exports multiple Salesforce objects to CSV files, including only
 * the ID field and any lookup fields that reference the Account object.
 * 
 * Purpose: Extract Account relationship data from various Salesforce objects
 * for analysis and processing.
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

// Set the path to your Salesforce CLI executable
// The SF CLI (sf) is the next-generation Salesforce CLI tool
$SF_CLI_PATH = '/usr/local/bin/sf';

// Allow long-running and large exports
// These settings prevent PHP from timing out or running out of memory during large data exports
@set_time_limit(0);           // Remove execution time limit
@ini_set('memory_limit', -1); // Remove memory limit
ob_implicit_flush(true);       // Automatically flush output buffer (show progress in real-time)

// Array of Salesforce objects to process
// Each object will be queried and exported to a separate CSV file
$objects = ['Account', 'Contact', 'Case', 'Opportunity', 'Order', 'Asset'];

// Directory where the exported files will be saved
$EXPORT_DIRECTORY = './exports';

// Create the export directory if it doesn't exist
if (!file_exists($EXPORT_DIRECTORY)) {
    mkdir($EXPORT_DIRECTORY, 0755, true);
}

// ============================================================================
// MAIN EXPORT LOOP
// ============================================================================

// Process each Salesforce object one at a time
foreach ($objects as $object) {
    echo "\n=== Starting export for $object ===" . PHP_EOL;
    flush();
    
    // ------------------------------------------------------------------------
    // STEP 1: Get field metadata using Salesforce CLI describe command
    // ------------------------------------------------------------------------
    
    /**
     * SF CLI Command: force:schema:sobject:describe
     * 
     * Purpose: Retrieves metadata about a Salesforce object including all fields,
     *          their types, and relationships.
     * 
     * Parameters:
     *   --target-org PROD         : Specifies which Salesforce org to query
     *   --sobjecttype [object]    : The API name of the object to describe
     *   --json                    : Returns output in JSON format for parsing
     *   2>&1                      : Redirects stderr to stdout to capture all output
     * 
     * The describe command returns comprehensive metadata including:
     *   - All field names and types
     *   - Lookup/reference relationships (referenceTo)
     *   - Field properties (required, updateable, etc.)
     */
    $describeCommand = "$SF_CLI_PATH force:schema:sobject:describe --target-org PROD --sobjecttype " . escapeshellarg($object) . " --json 2>&1";
    echo "[DEBUG] Describe command: $describeCommand" . PHP_EOL;
    
    // Execute the describe command and capture output
    // shell_exec() runs the command and returns the complete output as a string
    $describeOutput = shell_exec($describeCommand);
    
    // Save JSON to file for inspection and debugging
    // This allows manual review of the metadata structure if needed
    file_put_contents("{$EXPORT_DIRECTORY}/{$object}_describe.json", $describeOutput);
    echo "[DEBUG] Saved describe output to {$EXPORT_DIRECTORY}/{$object}_describe.json" . PHP_EOL;
    
    // Parse the JSON response into a PHP associative array
    $describeData = json_decode($describeOutput, true);
    
    // Debug: Show the top-level structure of the response
    echo "[DEBUG] Top-level keys: " . implode(', ', array_keys($describeData ?? [])) . PHP_EOL;
    
    // ------------------------------------------------------------------------
    // STEP 2: Extract fields that reference Account objects
    // ------------------------------------------------------------------------
    
    /**
     * Field Filtering Logic:
     * 
     * We only want to export lookup/reference fields that point to Account records.
     * These are identified by checking the 'referenceTo' property of each field.
     * 
     * Example field structure:
     * {
     *   "name": "AccountId",
     *   "type": "reference",
     *   "referenceTo": ["Account"],
     *   ...
     * }
     */
    $fieldList = [];
    
    // Check for fields in the 'result.fields' path (standard SF CLI v2 response structure)
    if (isset($describeData['result']['fields']) && is_array($describeData['result']['fields'])) {
        echo "[DEBUG] Found fields in result.fields, count: " . count($describeData['result']['fields']) . PHP_EOL;
        foreach ($describeData['result']['fields'] as $field) {
            // Only include lookup fields that reference Account
            if (isset($field['referenceTo']) && in_array('Account', $field['referenceTo'])) {
                $fieldList[] = $field['name'];
                echo "[DEBUG]   - Field: " . $field['name'] . " (type: " . ($field['type'] ?? 'unknown') . ", references: " . implode(', ', $field['referenceTo']) . ")" . PHP_EOL;
            }
        }
    } 
    // Fallback: Check for fields in direct 'fields' path (alternate response structure)
    elseif (isset($describeData['fields']) && is_array($describeData['fields'])) {
        echo "[DEBUG] Found fields in direct fields, count: " . count($describeData['fields']) . PHP_EOL;
        foreach ($describeData['fields'] as $field) {
            if (isset($field['referenceTo']) && in_array('Account', $field['referenceTo'])) {
                $fieldList[] = $field['name'];
                echo "[DEBUG]   - Field: " . $field['name'] . " (type: " . ($field['type'] ?? 'unknown') . ", references: " . implode(', ', $field['referenceTo']) . ")" . PHP_EOL;
            }
        }
    } else {
        // No fields found - likely an error or unexpected response structure
        echo "[DEBUG] No fields found. Result keys: " . implode(', ', array_keys($describeData['result'] ?? [])) . PHP_EOL;
    }
    
    echo "[DEBUG] Collected lookup fields referencing Account: " . count($fieldList) . " fields" . PHP_EOL;
    
    // Skip this object if no Account lookup fields were found
    if (empty($fieldList)) {
        echo "No queryable fields found for object: $object" . PHP_EOL;
        continue;
    }
    
    // ------------------------------------------------------------------------
    // STEP 3: Build the SOQL query
    // ------------------------------------------------------------------------
    
    /**
     * SOQL Query Construction:
     * 
     * Always include 'Id' field to uniquely identify each record
     * Add all discovered Account lookup fields
     * Remove duplicates and build a comma-separated SELECT clause
     * 
     * Example query result: "SELECT Id, AccountId, ParentAccountId FROM Contact"
     */
    $fieldList = array_values(array_unique(array_merge(['Id'], $fieldList)));
    $selectClause = implode(', ', $fieldList);
    $query = "SELECT $selectClause FROM $object";

    // ------------------------------------------------------------------------
    // STEP 4: Execute bulk data export using Salesforce CLI
    // ------------------------------------------------------------------------
    
    /**
     * SF CLI Command: data export bulk
     * 
     * Purpose: Exports large amounts of Salesforce data using the Bulk API 2.0.
     *          This is optimized for large datasets and runs asynchronously.
     * 
     * Parameters:
     *   --target-org PROD         : Specifies which Salesforce org to query
     *   --query [SOQL]           : The SOQL query to execute (properly escaped)
     *   --output-file [path]     : Where to save the resulting CSV file
     *   --wait 30                : Maximum time (minutes) to wait for job completion
     * 
     * How Bulk API works:
     * 1. CLI submits the query as a bulk job to Salesforce
     * 2. Salesforce processes the query asynchronously in the background
     * 3. CLI polls the job status at regular intervals
     * 4. Once complete, CLI downloads the results and saves to CSV
     * 5. Returns exit code 0 on success, non-zero on failure
     * 
     * Note: Uses passthru() instead of shell_exec() to stream output in real-time,
     *       showing progress as the export runs rather than waiting until completion.
     */
    $queryArg = escapeshellarg($query);  // Properly escape query for shell execution
    $outputPath = "{$EXPORT_DIRECTORY}/{$object}_export.csv";
    $outputFileArg = escapeshellarg($outputPath);  // Properly escape file path for shell
    
    $command = "$SF_CLI_PATH data export bulk --target-org PROD --query $queryArg --output-file $outputFileArg --wait 30";
    echo "[DEBUG] Bulk export command: $command" . PHP_EOL;
    
    // Run command and stream output live
    // passthru() executes the command and displays output in real-time
    // $exitCode captures the command's return code (0 = success)
    passthru($command, $exitCode);

    echo "[DEBUG] Exit code: $exitCode" . PHP_EOL;
    
    // ------------------------------------------------------------------------
    // STEP 5: Verify export success
    // ------------------------------------------------------------------------
    
    /**
     * Success Criteria:
     * 1. Command exit code must be 0 (no errors)
     * 2. Output CSV file must exist on disk
     * 
     * If either check fails, the export is considered unsuccessful
     */
    if ($exitCode === 0 && file_exists($outputPath)) {
        echo "✓ Successfully exported $object to $outputPath" . PHP_EOL;
    } else {
        echo "✗ Export failed for $object (exit code $exitCode)." . PHP_EOL;
    }

    echo "=== Finished export for $object ===\n" . PHP_EOL;
    flush();  // Force output to display immediately
}
?>