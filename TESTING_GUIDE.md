# Backend API Testing Guide

## Base URL

Your API base URL is: `http://localhost/abegeppme-v4/server`

## Testing Endpoints

### 1. Test Root Endpoint (API Info)

**GET** `http://localhost/abegeppme-v4/server/`

This should return:
```json
{
  "success": true,
  "message": "AbegEppMe API v1.0.0",
  "endpoints": {
    "auth": "/api/auth",
    "users": "/api/users",
    "services": "/api/services",
    ...
  }
}
```

### 2. Test Authentication

#### Sign Up
**POST** `http://localhost/abegeppme-v4/server/api/auth`

Body:
```json
{
  "action": "sign-up",
  "email": "test@example.com",
  "password": "password123",
  "name": "Test User",
  "country_id": "NG"
}
```

#### Sign In
**POST** `http://localhost/abegeppme-v4/server/api/auth`

Body:
```json
{
  "action": "sign-in",
  "email": "test@example.com",
  "password": "password123"
}
```

Response:
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

### 3. Test Services

#### List Services
**GET** `http://localhost/abegeppme-v4/server/api/services`

#### Get Service by ID
**GET** `http://localhost/abegeppme-v4/server/api/services/{id}`

#### Create Service (Vendor only)
**POST** `http://localhost/abegeppme-v4/server/api/services`

Headers:
```
Authorization: Bearer {your-token}
```

Body:
```json
{
  "title": "Plumbing Service",
  "description": "Professional plumbing services",
  "price": 50000,
  "category": "Home Services",
  "country_id": "NG",
  "currency_code": "NGN"
}
```

### 4. Test Countries

#### List Countries
**GET** `http://localhost/abegeppme-v4/server/api/countries`

#### Get Country by ID
**GET** `http://localhost/abegeppme-v4/server/api/countries/NG`

## Using Postman

1. **Create a new request**
2. **Set method** (GET, POST, etc.)
3. **Enter URL**: `http://localhost/abegeppme-v4/server/api/{endpoint}`
4. **For POST requests:**
   - Go to "Body" tab
   - Select "raw"
   - Choose "JSON"
   - Enter your JSON payload
5. **For authenticated requests:**
   - Go to "Authorization" tab
   - Select "Bearer Token"
   - Enter your JWT token

## Common Issues

### 404 Not Found

If you get a 404 error:

1. **Check your URL**: Make sure you're using the correct base path
   - ✅ Correct: `http://localhost/abegeppme-v4/server/api/auth`
   - ❌ Wrong: `http://localhost/abegeppme-v4/server/auth`

2. **Check .htaccess**: Make sure mod_rewrite is enabled in Apache
   - In MAMP: Go to Apache → httpd.conf and ensure `mod_rewrite` is enabled

3. **Check file permissions**: Make sure Apache can read the files

### 500 Internal Server Error

1. **Check error logs**: Look in MAMP logs or PHP error log
2. **Check database connection**: Verify `.env` file has correct DB credentials
3. **Check PHP version**: Ensure PHP 7.4+ is installed

### CORS Errors

If you get CORS errors from frontend:

1. **Check CORS_ORIGIN** in `.env` file
2. **Update it** to match your frontend URL (e.g., `http://localhost:5173`)

## Quick Test Script

You can also test using curl:

```bash
# Test root endpoint
curl http://localhost/abegeppme-v4/server/

# Test sign up
curl -X POST http://localhost/abegeppme-v4/server/api/auth \
  -H "Content-Type: application/json" \
  -d '{"action":"sign-up","email":"test@example.com","password":"password123","name":"Test User","country_id":"NG"}'

# Test sign in
curl -X POST http://localhost/abegeppme-v4/server/api/auth \
  -H "Content-Type: application/json" \
  -d '{"action":"sign-in","email":"test@example.com","password":"password123"}'
```

## Next Steps

1. ✅ Test root endpoint - should return API info
2. ✅ Test sign up - create a test user
3. ✅ Test sign in - get JWT token
4. ✅ Test authenticated endpoints - use token in Authorization header
5. ✅ Test services - list, create, update
6. ✅ Test payments - initialize payment flow
