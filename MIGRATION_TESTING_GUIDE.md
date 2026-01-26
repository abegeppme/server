# WordPress Migration Testing Guide

## Overview

The WordPress migration can be tested via API endpoint or command line. This guide shows both methods.

## Method 1: Via Postman (API Endpoint)

### Step 1: Authenticate as Admin

First, you need to sign in as an admin user:

**POST** `http://localhost/abegeppme-v4/server/api/auth`

**Body:**
```json
{
  "action": "sign-in",
  "email": "admin@example.com",
  "password": "your-password"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {...}
  }
}
```

**Copy the token** - you'll need it for the migration request.

### Step 2: Run Migration

**POST** `http://localhost/abegeppme-v4/server/api/migration`

**Headers:**
```
Authorization: Bearer {your-admin-token}
Content-Type: application/json
```

**Body:**
```json
{
  "action": "run",
  "wp_host": "localhost",
  "wp_dbname": "u302767073_tLEcf",
  "wp_username": "root",
  "wp_password": "root",
  "wp_prefix": "wp_",
  "migrate_users": true,
  "migrate_subaccounts": true,
  "migrate_orders": true
}
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Migration completed successfully",
  "results": {
    "users": {
      "migrated": 150,
      "skipped": 0
    },
    "subaccounts": {
      "migrated": 25
    },
    "orders": {
      "migrated": 200,
      "skipped": 5
    }
  }
}
```

### Step 3: Check Migration Status

**GET** `http://localhost/abegeppme-v4/server/api/migration/status`

**Headers:**
```
Authorization: Bearer {your-admin-token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "migrated_users": 150,
    "migration_table_exists": true
  }
}
```

## Method 2: Via Command Line

### Step 1: Update Migration Script

Edit `server/database/migrate_wordpress_complete.php` and update the database credentials:

```php
$wp_db_config = [
    'host' => 'localhost',
    'dbname' => 'u302767073_tLEcf',
    'username' => 'root',
    'password' => 'root', // Your MySQL password
    'prefix' => 'wp_',
];
```

### Step 2: Uncomment Migration Functions

In the same file, uncomment these lines at the bottom:

```php
// Uncomment to run migration
$user_mapping = migrateUsers($wp_pdo, $abe_pdo, $wp_db_config['prefix']);
migrateSubaccounts($wp_pdo, $abe_pdo, $wp_db_config['prefix'], $user_mapping);
migrateOrders($wp_pdo, $abe_pdo, $wp_db_config['prefix'], $user_mapping);
```

### Step 3: Run Migration

Open terminal/command prompt and run:

```bash
cd C:\MAMP\htdocs\abegeppme-v4\server\database
php migrate_wordpress_complete.php
```

**Expected Output:**
```
========================================
WordPress to AbegEppMe Complete Migration
========================================

⚠️  WARNING: This will migrate all data from WordPress to AbegEppMe.
⚠️  Make sure you have backups of both databases!

✓ Connected to WordPress database
✓ Connected to AbegEppMe database

✓ Mapping table ready

=== Migrating Users ===
  ✓ Migrated: user1@example.com (CUSTOMER)
  ✓ Migrated: vendor1@example.com (VENDOR)
  ...

Users migrated: 150
Users skipped: 0

=== Migrating Vendor Subaccounts ===
  ✓ Migrated subaccount for user {uuid}
  ...

Subaccounts migrated: 25

=== Migrating Orders ===
  ✓ Migrated order ORD-7223
  ...

Orders migrated: 200
Orders skipped: 5
```

## What Gets Migrated

### 1. Users
- Email, name, password (WordPress hash)
- Role mapping:
  - `administrator` → `ADMIN`
  - `shop_vendor` / `dokan_vendor` → `VENDOR`
  - `customer` / `subscriber` → `CUSTOMER`
- Phone, avatar (if available)
- Default country: Nigeria (NG)

### 2. Vendor Subaccounts
- Paystack subaccount codes
- Bank account details
- Transfer recipient codes

### 3. Orders
- Order details (total, status)
- Customer and vendor mapping
- Order status mapping:
  - `wc-pending` → `PENDING`
  - `wc-processing` → `PROCESSING`
  - `wc-completed` → `COMPLETED`
  - etc.

## Verification

After migration, verify the data:

### Check Users
**GET** `http://localhost/abegeppme-v4/server/api/users`

### Check Orders
**GET** `http://localhost/abegeppme-v4/server/api/orders`

### Check Migration Mapping
You can query the `wordpress_user_mapping` table directly in phpMyAdmin to see the ID mappings.

## Troubleshooting

### Error: "WordPress database connection failed"
- Check WordPress database credentials
- Ensure MySQL is running
- Verify database name is correct

### Error: "AbegEppMe database connection failed"
- Check `.env` file has correct database credentials
- Ensure database exists
- Verify database user has permissions

### Error: "Table already exists"
- The migration script handles existing data
- Users with same email are skipped
- Safe to run multiple times

### No data migrated
- Check if WordPress database has data
- Verify table prefixes match
- Check for PHP errors in logs

## Safety

- ✅ Migration is **idempotent** - safe to run multiple times
- ✅ Existing users are **skipped** (not duplicated)
- ✅ WordPress passwords are **preserved** (compatible hash)
- ✅ Creates **mapping table** for reference

## Next Steps After Migration

1. **Test user login** - Try signing in with WordPress credentials
2. **Verify orders** - Check orders are properly linked
3. **Test payments** - Verify payment references
4. **Update passwords** - Users can update passwords on first login
