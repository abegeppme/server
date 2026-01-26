# Quick Start Guide

## 1. Create .env File

**IMPORTANT:** You need to manually create the `.env` file in the `server/` directory.

1. Open your file explorer and navigate to:
   ```
   C:\MAMP\htdocs\abegeppme-v4\server\
   ```

2. Create a new text file named `.env` (make sure it has no extension)

3. Copy the content from `SETUP_ENV.md` into this file

4. Update these values for your setup:
   - `DB_NAME=abegeppme` (your database name)
   - `DB_USER=root` (your MySQL username)
   - `DB_PASS=root` (your MySQL password - usually `root` for MAMP)

## 2. Test the API

### Option A: Using Browser

Open your browser and visit:
```
http://localhost/abegeppme-v4/server/
```

You should see JSON response with API information.

### Option B: Using Postman

1. **Open Postman**
2. **Create new request**
3. **Set method to:** `GET`
4. **Enter URL:** `http://localhost/abegeppme-v4/server/`
5. **Click Send**

Expected response:
```json
{
  "success": true,
  "message": "AbegEppMe API v1.0.0",
  "endpoints": {
    "auth": "/api/auth",
    "users": "/api/users",
    "services": "/api/services",
    "orders": "/api/orders",
    "payments": "/api/payments",
    "countries": "/api/countries",
    "chat": "/api/chat"
  }
}
```

## 3. Test Authentication

### Sign Up (Create Account)

**POST** `http://localhost/abegeppme-v4/server/api/auth`

**Body (JSON):**
```json
{
  "action": "sign-up",
  "email": "test@example.com",
  "password": "password123",
  "name": "Test User",
  "country_id": "NG"
}
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": "...",
      "email": "test@example.com",
      "name": "Test User",
      "role": "CUSTOMER"
    }
  }
}
```

### Sign In

**POST** `http://localhost/abegeppme-v4/server/api/auth`

**Body (JSON):**
```json
{
  "action": "sign-in",
  "email": "test@example.com",
  "password": "password123"
}
```

## 4. Test with Authentication

After signing in, you'll get a `token`. Use it in the Authorization header:

**Headers:**
```
Authorization: Bearer {your-token-here}
```

**Example - Get Services:**
- **GET** `http://localhost/abegeppme-v4/server/api/services`
- Add header: `Authorization: Bearer {token}`

## Common Issues

### ❌ 404 Not Found

**Problem:** URL not found

**Solutions:**
1. Make sure you're using the full path: `http://localhost/abegeppme-v4/server/api/{endpoint}`
2. Check that `.htaccess` file exists in `server/` directory
3. Ensure Apache `mod_rewrite` is enabled in MAMP

### ❌ 500 Internal Server Error

**Problem:** Server error

**Solutions:**
1. Check `.env` file exists and has correct database credentials
2. Check database exists: `abegeppme`
3. Check MAMP error logs

### ❌ Database Connection Error

**Problem:** Can't connect to database

**Solutions:**
1. Verify database name in `.env` matches your MySQL database
2. Check MySQL is running in MAMP
3. Verify username/password in `.env`

## Next Steps

1. ✅ Create `.env` file
2. ✅ Test root endpoint
3. ✅ Test sign up
4. ✅ Test sign in
5. ✅ Test authenticated endpoints
6. ✅ Configure payment gateways in database
7. ✅ Test payment flow

For detailed testing instructions, see `TESTING_GUIDE.md`
