# Migration Quick Start Guide

## Option 1: Seed Admin Account First (Recommended)

### Step 1: Create Admin Account

**Via Command Line:**
```bash
cd C:\MAMP\htdocs\abegeppme-v4\server\database
php seed_admin.php
```

**Via Postman (API):**
```
POST http://localhost/abegeppme-v4/server/api/migration
Body: {
  "action": "seed-admin",
  "email": "admin@abegeppme.com",
  "password": "admin123",
  "name": "Admin User"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "message": "Admin account created successfully",
    "email": "admin@abegeppme.com",
    "password": "admin123",
    "note": "Please change the password after first login"
  }
}
```

### Step 2: Sign In as Admin

```
POST http://localhost/abegeppme-v4/server/api/auth
Body: {
  "action": "sign-in",
  "email": "admin@abegeppme.com",
  "password": "admin123"
}
```

### Step 3: Run Migration

Use the token from Step 2 in the Authorization header.

## Option 2: Run Migration Without Auth (Development Only)

**Note:** This only works when `APP_ENV=development` in your `.env` file.

```
POST http://localhost/abegeppme-v4/server/api/migration
Body: {
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

## Idempotency Features

The migration is **safe to rerun** multiple times:

✅ **Users:** 
- Checks by email - skips if exists
- Creates mapping if user exists but mapping missing
- Won't duplicate users

✅ **Subaccounts:**
- Checks by user_id OR subaccount_code
- Skips if either exists
- Won't duplicate subaccounts

✅ **Orders:**
- Checks by order_number pattern (ORD-{wp_id})
- Skips if order already migrated
- Won't duplicate orders

## Migration Response

```json
{
  "success": true,
  "message": "Migration completed successfully",
  "note": "Existing data was skipped. Safe to rerun.",
  "results": {
    "users": {
      "migrated": 150,
      "skipped": 5,
      "total": 155
    },
    "subaccounts": {
      "migrated": 20,
      "skipped": 5,
      "total": 25
    },
    "orders": {
      "migrated": 200,
      "skipped": 10,
      "total": 210
    }
  }
}
```

## What Gets Skipped

- **Users:** If email already exists in database
- **Subaccounts:** If user_id or subaccount_code already exists
- **Orders:** If order_number (ORD-{wp_id}) already exists

## Rerunning Migration

If you need to rerun migration (e.g., for missing data):

1. **Safe to rerun** - Existing data will be skipped
2. **Only new data** will be migrated
3. **Mappings preserved** - WordPress ID mappings maintained
4. **No duplicates** - All checks prevent duplicates

## Troubleshooting

### "Admin account already exists"
- The account was already created
- Use the existing credentials to sign in

### "Migration failed: Connection refused"
- Check WordPress database credentials
- Ensure MySQL is running
- Verify database name is correct

### "No data migrated"
- Check WordPress database has data
- Verify table prefix matches (default: `wp_`)
- Check PHP error logs for details
