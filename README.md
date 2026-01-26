# AbegEppMe Backend API (PHP/MySQL)

Lightweight PHP backend API for the AbegEppMe service marketplace platform.

## Tech Stack

- **Language:** PHP 7.4+
- **Database:** MySQL/MariaDB
- **Architecture:** RESTful API with MVC pattern
- **Authentication:** JWT (to be implemented)

## Getting Started

### Prerequisites

- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx web server
- phpMyAdmin (optional, for database management)

### Installation

1. **Set up the database:**
   - Import `database/schema.sql` into your MySQL database using phpMyAdmin or command line:
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   Or use phpMyAdmin:
   - Open phpMyAdmin
   - Create a new database named `abegeppme`
   - Import the `schema.sql` file

2. **Configure environment:**
   ```bash
   cp .env.example .env
   # Edit .env with your database credentials
   ```

3. **Set up web server:**
   - Point your web server document root to the `server` directory
   - Ensure mod_rewrite is enabled (for Apache)
   - Configure virtual host if needed

4. **Set permissions:**
   ```bash
   chmod -R 755 server/
   chmod -R 777 server/uploads/  # If uploads directory exists
   ```

### Database Setup

The `database/schema.sql` file contains all the necessary tables:

- `users` - User accounts
- `services` - Service listings
- `orders` - Orders/bookings
- `payments` - Payment records
- `payment_breakdowns` - Payment breakdown snapshots
- `transfers` - Transfer records
- `conversations` - Chat conversations
- `messages` - Chat messages
- `disputes` - Dispute records
- `notifications` - Push notifications
- `transaction_logs` - Audit logs
- `subaccounts` - Paystack subaccounts
- `suspicious_activity` - Security monitoring

### WordPress Migration

To migrate existing WordPress users and data:

1. **Update migration script:**
   - Edit `database/migrate_wordpress.php`
   - Update WordPress database connection settings
   - Update AbegEppMe database connection (uses .env)

2. **Run migration:**
   ```bash
   php database/migrate_wordpress.php
   ```

   The script will:
   - Migrate all WordPress users
   - Map user roles (administrator → ADMIN, shop_vendor → VENDOR, etc.)
   - Migrate vendor subaccounts (Dokan)
   - Create a mapping table for reference

3. **Verify migration:**
   - Check `users` table for migrated users
   - Check `wordpress_user_mapping` table for ID mappings
   - Check `subaccounts` table for vendor accounts

## Project Structure

```
server/
├── index.php                 # Main entry point
├── config/
│   ├── config.php           # Application configuration
│   └── database.php         # Database connection class
├── src/
│   └── controllers/         # API controllers
│       ├── BaseController.php
│       ├── AuthController.php
│       ├── UserController.php
│       ├── ServiceController.php
│       ├── OrderController.php
│       ├── ServiceProviderController.php
│       ├── ChatController.php
│       ├── AdminController.php
│       └── PaymentController.php
├── database/
│   ├── schema.sql           # Database schema
│   └── migrate_wordpress.php # WordPress migration script
├── .env.example             # Environment variables template
└── README.md
```

## API Endpoints

### Base URL
```
http://your-domain/api
```

### Authentication
- `POST /api/auth` (action: sign-up) - Register new user
- `POST /api/auth` (action: sign-in) - Login user
- `POST /api/auth` (action: sign-out) - Logout user
- `GET /api/auth/session` - Get current session

### Users
- `GET /api/users/profile` - Get user profile
- `PATCH /api/users/profile` - Update user profile

### Services
- `GET /api/services` - List services (with pagination/filters)
- `GET /api/services/:id` - Get service details
- `POST /api/services` - Create service (vendor)
- `PATCH /api/services/:id` - Update service (vendor)
- `DELETE /api/services/:id` - Delete service (vendor)

### Orders
- `GET /api/orders` - List orders (with filters)
- `GET /api/orders/:id` - Get order details
- `POST /api/orders` - Create order
- `POST /api/orders/:id/complete` - Mark service complete (vendor)
- `POST /api/orders/:id/confirm` - Confirm service complete (customer)
- `POST /api/orders/:id/dispute` - Raise dispute
- `POST /api/orders/:id/payout` - Vendor balance payout

### Service Providers
- `GET /api/service-providers` - List service providers
- `GET /api/service-providers/:id` - Get service provider profile
- `POST /api/service-providers` (action: register) - Register as service provider
- `GET /api/service-providers/:id/services` - Get vendor services

### Chat
- `GET /api/chat/conversations` - List conversations
- `GET /api/chat/conversations/:id/messages` - Get messages
- `POST /api/chat/conversations/:id/messages` - Send message (fallback)

### Admin
- `GET /api/admin/orders` - All orders (admin)
- `GET /api/admin/stats` - Platform statistics
- `GET /api/admin/logs` - Transaction logs
- `GET /api/admin/payment-settings` - Get payment settings
- `POST /api/admin/payment-settings` - Update payment settings
- `POST /api/admin/orders/:id/force-complete` - Force complete order
- `POST /api/admin/disputes/:id/resolve` - Resolve dispute

### Payments
- `POST /api/payments` (action: initialize) - Initialize Paystack payment
- `POST /api/payments` (action: verify) - Verify payment
- `POST /api/payments/webhooks/paystack` - Paystack webhook handler

## Environment Variables

See `.env.example` for all available environment variables.

Key variables:
- `DB_HOST` - Database host
- `DB_NAME` - Database name
- `DB_USER` - Database username
- `DB_PASS` - Database password
- `CORS_ORIGIN` - Allowed CORS origin
- `JWT_SECRET` - JWT secret key
- `PAYSTACK_SECRET_KEY` - Paystack secret key (to be configured)

## Development

### Current Status

All controllers are set up with placeholder methods. You need to implement:

1. **Authentication** - JWT token generation and validation
2. **Business Logic** - Service, order, payment processing
3. **Payment Integration** - Paystack API integration
4. **Middleware** - Authentication, authorization, validation
5. **Models** - Data models for database operations

### Next Steps

1. Implement authentication system
2. Implement service CRUD operations
3. Implement order management
4. Add payment system integration (when ready)
5. Add input validation and sanitization
6. Add error handling and logging

## WordPress Migration Notes

The migration script (`migrate_wordpress.php`) handles:

- User migration with role mapping
- Password preservation (WordPress hashed passwords)
- Vendor subaccount migration (Dokan)
- User ID mapping for reference

**Important:**
- Backup both databases before running migration
- Test migration on a copy first
- Review and adjust role mapping as needed
- The script creates a `wordpress_user_mapping` table for reference

## Security Considerations

- Use prepared statements (PDO) - ✅ Already implemented
- Validate and sanitize all inputs - ⚠️ To be implemented
- Implement authentication middleware - ⚠️ To be implemented
- Use HTTPS in production - ⚠️ Configure on server
- Rate limiting - ⚠️ To be implemented
- Input validation - ⚠️ To be implemented

## License

ISC
