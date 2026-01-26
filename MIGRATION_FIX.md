# Migration Fix - country_id Column Issue

## Problem
The migration was failing with:
```
Column not found: 1054 Unknown column 'country_id' in 'field list'
```

## Root Cause
The migration service was trying to insert into `country_id` and `preferred_currency` columns that don't exist in the base schema (`schema.sql`). These columns only exist in the multi-country schema (`schema_multicountry.sql`).

## Solution
Updated `MigrationService.php` to:
1. **Check if columns exist** before using them
2. **Use conditional SQL** based on available columns
3. **Optimize** by checking once per method, not per row

## Fixed Locations

### 1. User Migration (`migrateUsers`)
- Checks for `country_id` and `preferred_currency` columns
- Uses appropriate INSERT statement based on schema

### 2. Subaccount Migration (`migrateSubaccounts`)
- Checks for `country_id` column
- Uses appropriate INSERT statement

### 3. Order Migration (`migrateOrders`)
- Checks for `country_id` and `currency_code` columns
- Uses appropriate INSERT statement

## Testing

The migration should now work with:
- ✅ Base schema (`schema.sql`) - without country_id
- ✅ Multi-country schema (`schema_multicountry.sql`) - with country_id

## Usage

```bash
POST http://localhost/abegeppme-v4/server/api/migration
Body: {
  "action": "run",
  "wp_host": "localhost",
  "wp_dbname": "u302767073_tLEcf",
  "wp_username": "root",
  "wp_password": "root",
  "wp_prefix": "wp_"
}
```

The migration will automatically detect your schema and use the appropriate columns.
