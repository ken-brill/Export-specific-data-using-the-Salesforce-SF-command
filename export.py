#!/usr/bin/env python3
"""
Salesforce Object Export Script

This script exports multiple Salesforce objects to CSV files, including only
the ID field and any lookup fields that reference the Account object.

Purpose: Extract Account relationship data from various Salesforce objects
for analysis and processing.
"""

import subprocess
import json
import os
import sys
from pathlib import Path

# ============================================================================
# CONFIGURATION
# ============================================================================

# Set the path to your Salesforce CLI executable
# The SF CLI (sf) is the next-generation Salesforce CLI tool
SF_CLI_PATH = '/usr/local/bin/sf'

# Array of Salesforce objects to process
# Each object will be queried and exported to a separate CSV file
OBJECTS = ['Account', 'Contact', 'Case', 'Opportunity', 'Order', 'Asset']

# Directory where the exported files will be saved
EXPORT_DIRECTORY = './exports'

# Create the export directory if it doesn't exist
Path(EXPORT_DIRECTORY).mkdir(parents=True, exist_ok=True)

# ============================================================================
# MAIN EXPORT LOOP
# ============================================================================

# Process each Salesforce object one at a time
for obj in OBJECTS:
    print(f"\n=== Starting export for {obj} ===")
    sys.stdout.flush()
    
    # ------------------------------------------------------------------------
    # STEP 1: Get field metadata using Salesforce CLI describe command
    # ------------------------------------------------------------------------
    
    """
    SF CLI Command: force:schema:sobject:describe
    
    Purpose: Retrieves metadata about a Salesforce object including all fields,
             their types, and relationships.
    
    Parameters:
      --target-org PROD         : Specifies which Salesforce org to query
      --sobjecttype [object]    : The API name of the object to describe
      --json                    : Returns output in JSON format for parsing
    
    The describe command returns comprehensive metadata including:
      - All field names and types
      - Lookup/reference relationships (referenceTo)
      - Field properties (required, updateable, etc.)
    """
    describe_command = [
        SF_CLI_PATH,
        'force:schema:sobject:describe',
        '--target-org', 'PROD',
        '--sobjecttype', obj,
        '--json'
    ]
    
    print(f"[DEBUG] Describe command: {' '.join(describe_command)}")
    
    # Execute the describe command and capture output
    # subprocess.run() executes the command and captures stdout/stderr
    # capture_output=True captures both stdout and stderr
    # text=True returns output as strings instead of bytes
    try:
        result = subprocess.run(
            describe_command,
            capture_output=True,
            text=True,
            check=False  # Don't raise exception on non-zero exit
        )
        describe_output = result.stdout + result.stderr
    except Exception as e:
        print(f"[ERROR] Failed to run describe command: {e}")
        continue
    
    # Save JSON to file for inspection and debugging
    # This allows manual review of the metadata structure if needed
    describe_file = f"{EXPORT_DIRECTORY}/{obj}_describe.json"
    with open(describe_file, 'w') as f:
        f.write(describe_output)
    print(f"[DEBUG] Saved describe output to {describe_file}")
    
    # Parse the JSON response into a Python dictionary
    try:
        describe_data = json.loads(describe_output)
    except json.JSONDecodeError as e:
        print(f"[ERROR] Failed to parse JSON for {obj}: {e}")
        continue
    
    # Debug: Show the top-level structure of the response
    if describe_data:
        print(f"[DEBUG] Top-level keys: {', '.join(describe_data.keys())}")
    
    # ------------------------------------------------------------------------
    # STEP 2: Extract fields that reference Account objects
    # ------------------------------------------------------------------------
    
    """
    Field Filtering Logic:
    
    We only want to export lookup/reference fields that point to Account records.
    These are identified by checking the 'referenceTo' property of each field.
    
    Example field structure:
    {
      "name": "AccountId",
      "type": "reference",
      "referenceTo": ["Account"],
      ...
    }
    """
    field_list = []
    
    # Check for fields in the 'result.fields' path (standard SF CLI v2 response structure)
    if 'result' in describe_data and 'fields' in describe_data['result'] and \
       isinstance(describe_data['result']['fields'], list):
        fields = describe_data['result']['fields']
        print(f"[DEBUG] Found fields in result.fields, count: {len(fields)}")
        
        for field in fields:
            # Only include lookup fields that reference Account
            if 'referenceTo' in field and isinstance(field['referenceTo'], list) and \
               'Account' in field['referenceTo']:
                field_name = field.get('name', '')
                field_list.append(field_name)
                field_type = field.get('type', 'unknown')
                references = ', '.join(field['referenceTo'])
                print(f"[DEBUG]   - Field: {field_name} (type: {field_type}, references: {references})")
    
    # Fallback: Check for fields in direct 'fields' path (alternate response structure)
    elif 'fields' in describe_data and isinstance(describe_data['fields'], list):
        fields = describe_data['fields']
        print(f"[DEBUG] Found fields in direct fields, count: {len(fields)}")
        
        for field in fields:
            if 'referenceTo' in field and isinstance(field['referenceTo'], list) and \
               'Account' in field['referenceTo']:
                field_name = field.get('name', '')
                field_list.append(field_name)
                field_type = field.get('type', 'unknown')
                references = ', '.join(field['referenceTo'])
                print(f"[DEBUG]   - Field: {field_name} (type: {field_type}, references: {references})")
    else:
        # No fields found - likely an error or unexpected response structure
        result_keys = ', '.join(describe_data.get('result', {}).keys()) if 'result' in describe_data else 'N/A'
        print(f"[DEBUG] No fields found. Result keys: {result_keys}")
    
    print(f"[DEBUG] Collected lookup fields referencing Account: {len(field_list)} fields")
    
    # Skip this object if no Account lookup fields were found
    if not field_list:
        print(f"No queryable fields found for object: {obj}")
        continue
    
    # ------------------------------------------------------------------------
    # STEP 3: Build the SOQL query
    # ------------------------------------------------------------------------
    
    """
    SOQL Query Construction:
    
    Always include 'Id' field to uniquely identify each record
    Add all discovered Account lookup fields
    Remove duplicates and build a comma-separated SELECT clause
    
    Example query result: "SELECT Id, AccountId, ParentAccountId FROM Contact"
    """
    # Ensure Id is first, then add all lookup fields (removing duplicates)
    field_list = list(dict.fromkeys(['Id'] + field_list))  # dict.fromkeys preserves order while removing dupes
    select_clause = ', '.join(field_list)
    query = f"SELECT {select_clause} FROM {obj}"
    
    # ------------------------------------------------------------------------
    # STEP 4: Execute bulk data export using Salesforce CLI
    # ------------------------------------------------------------------------
    
    """
    SF CLI Command: data export bulk
    
    Purpose: Exports large amounts of Salesforce data using the Bulk API 2.0.
             This is optimized for large datasets and runs asynchronously.
    
    Parameters:
      --target-org PROD         : Specifies which Salesforce org to query
      --query [SOQL]           : The SOQL query to execute
      --output-file [path]     : Where to save the resulting CSV file
      --wait 30                : Maximum time (minutes) to wait for job completion
    
    How Bulk API works:
    1. CLI submits the query as a bulk job to Salesforce
    2. Salesforce processes the query asynchronously in the background
    3. CLI polls the job status at regular intervals
    4. Once complete, CLI downloads the results and saves to CSV
    5. Returns exit code 0 on success, non-zero on failure
    
    Note: We don't capture output here - we let it stream directly to the terminal
          so users can see real-time progress updates from the CLI.
    """
    output_path = f"{EXPORT_DIRECTORY}/{obj}_export.csv"
    
    export_command = [
        SF_CLI_PATH,
        'data', 'export', 'bulk',
        '--target-org', 'PROD',
        '--query', query,
        '--output-file', output_path,
        '--wait', '30'
    ]
    
    print(f"[DEBUG] Bulk export command: {' '.join(export_command)}")
    
    # Run command and stream output live to terminal
    # By not capturing output, subprocess will automatically display it in real-time
    # check=False means we handle the exit code manually rather than raising an exception
    try:
        result = subprocess.run(export_command, check=False)
        exit_code = result.returncode
    except Exception as e:
        print(f"[ERROR] Failed to run export command: {e}")
        exit_code = -1
    
    print(f"[DEBUG] Exit code: {exit_code}")
    
    # ------------------------------------------------------------------------
    # STEP 5: Verify export success
    # ------------------------------------------------------------------------
    
    """
    Success Criteria:
    1. Command exit code must be 0 (no errors)
    2. Output CSV file must exist on disk
    
    If either check fails, the export is considered unsuccessful
    """
    if exit_code == 0 and os.path.exists(output_path):
        print(f"✓ Successfully exported {obj} to {output_path}")
    else:
        print(f"✗ Export failed for {obj} (exit code {exit_code}).")
    
    print(f"=== Finished export for {obj} ===\n")
    sys.stdout.flush()  # Force output to display immediately

print("\n✓ All exports completed!")
