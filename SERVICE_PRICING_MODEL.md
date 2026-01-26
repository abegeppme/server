# Service Pricing Model - Negotiated Pricing

## Business Model

Services are **showcases**, not fixed-price products. Pricing is **negotiated per order** via chat.

## Flow

1. **Service Provider** creates service listing:
   - Title, description
   - Images/gallery
   - Category
   - **Optional:** Starting price or price range (for reference)
   - **Status:** ACTIVE (showcased)

2. **Customer** browses services:
   - Views service details
   - Sees gallery, description
   - May see "Starting from ₦X" or "Price on Request"

3. **Customer initiates chat** with vendor:
   - Discusses requirements
   - Negotiates price
   - Agrees on final amount

4. **Vendor creates invoice** (from chat/negotiation):
   - Uses negotiated price
   - Creates order with agreed amount
   - Customer pays invoice

## Database Changes

### Services Table
- `price` → **NULLABLE** (optional starting price)
- `currency_code` → **NULLABLE** (only if price is set)
- **New:** `price_type` ENUM('FIXED', 'RANGE', 'NEGOTIABLE', 'ON_REQUEST')
- **New:** `price_range_min` (optional)
- **New:** `price_range_max` (optional)

### Orders Table
- Price comes from **negotiated amount** in order creation
- Not from service.price
- Order subtotal = negotiated price

## API Changes

### Create Service
**Before:**
```json
{
  "title": "Plumbing Service",
  "price": 5000,  // Required
  "description": "..."
}
```

**After:**
```json
{
  "title": "Plumbing Service",
  "description": "...",
  "price": null,  // Optional - can be negotiated
  "price_type": "NEGOTIABLE",  // or "ON_REQUEST", "RANGE", "FIXED"
  "price_range_min": 3000,  // If price_type is "RANGE"
  "price_range_max": 10000
}
```

### Create Order (from Invoice)
**Now requires negotiated price:**
```json
{
  "vendor_id": "...",
  "service_id": "...",
  "subtotal": 7500,  // Negotiated price (REQUIRED)
  "service_charge": 250,
  "description": "Agreed in chat: Full bathroom plumbing repair"
}
```

## Benefits

✅ **Flexible Pricing** - Vendors can negotiate based on job complexity
✅ **Better UX** - Customers see services, then discuss pricing
✅ **Real-world Model** - Matches how service marketplaces work
✅ **No Price Conflicts** - Price is set at order creation, not service listing

## Migration

Run:
```sql
SOURCE server/database/schema_services_update.sql;
```

Or manually:
```sql
ALTER TABLE services 
MODIFY COLUMN price DECIMAL(10,2) NULL,
MODIFY COLUMN currency_code CHAR(3) NULL,
ADD COLUMN price_type ENUM('FIXED', 'RANGE', 'NEGOTIABLE', 'ON_REQUEST') DEFAULT 'NEGOTIABLE',
ADD COLUMN price_range_min DECIMAL(10,2) NULL,
ADD COLUMN price_range_max DECIMAL(10,2) NULL;
```
