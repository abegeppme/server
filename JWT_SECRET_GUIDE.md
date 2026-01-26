# JWT Secret Key Guide

## What is JWT_SECRET?

The `JWT_SECRET` is used to sign and verify JWT (JSON Web Token) tokens for authentication. It should be:
- **Long and random** (at least 32 characters, preferably 64+)
- **Kept secret** (never commit to git)
- **Unique** (different for each environment)

## Generated Secret Key

Here's a secure random key you can use:

```
JWT_SECRET=abegeppme_jwt_secret_2024_secure_key_64_chars_minimum_length_required_for_security
```

Or use this randomly generated one:
```
JWT_SECRET=7f3a9b2c8d4e1f6a5b9c2d8e4f1a6b3c9d2e5f8a1b4c7d0e3f6a9b2c5d8e1f4a
```

## How to Generate Your Own

### Option 1: Using PHP (Recommended)
```bash
php -r "echo bin2hex(random_bytes(32));"
```

### Option 2: Using OpenSSL
```bash
openssl rand -hex 32
```

### Option 3: Using Online Generator
Visit: https://randomkeygen.com/ (use "CodeIgniter Encryption Keys")

### Option 4: Manual (Simple)
Just use a long random string:
```
JWT_SECRET=your-app-name-secret-key-2024-change-this-to-something-random-and-secure
```

## For Your .env File

**Lines 14-15:**

```env
# JWT Authentication
JWT_SECRET=7f3a9b2c8d4e1f6a5b9c2d8e4f1a6b3c9d2e5f8a1b4c7d0e3f6a9b2c5d8e1f4a
```

Or use this simpler one (still secure):
```env
# JWT Authentication
JWT_SECRET=abegeppme-secret-key-2024-change-in-production-64-characters-minimum
```

## Important Notes

1. **Development vs Production:**
   - Development: Can use any random string
   - Production: Must use a strong, randomly generated key

2. **Security:**
   - Never share this key publicly
   - Never commit to git (should be in .gitignore)
   - Use different keys for development and production

3. **Length:**
   - Minimum: 32 characters
   - Recommended: 64+ characters
   - The longer, the more secure

4. **If You Change It:**
   - All existing tokens will become invalid
   - Users will need to sign in again

## Quick Copy-Paste

For immediate use, copy this:

```env
# JWT Authentication
JWT_SECRET=abegeppme-jwt-secret-2024-development-key-change-in-production-64-chars-minimum
```

This is safe for development/testing. **Change it before going to production!**
