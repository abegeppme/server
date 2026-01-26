# Service Provider Categories Setup

## Overview

Service providers can select **one or more categories** to showcase their services. Categories are organized hierarchically (parent categories with subcategories).

## Database Schema

### Tables Created

1. **`service_categories`** - Category definitions
   - Parent categories (e.g., "Plumbing", "Photography")
   - Subcategories (e.g., "Residential Plumbers", "Wedding Photographers")
   - Supports hierarchical structure

2. **`service_provider_categories`** - Many-to-many relationship
   - Links vendors to their categories
   - Supports multiple categories per provider
   - One category can be marked as "primary"

## Setup

### Step 1: Create Tables

```sql
SOURCE server/database/schema_categories.sql;
```

### Step 2: Seed Categories

```bash
cd C:\MAMP\htdocs\abegeppme-v4\server\database
php seed_categories.php
```

This will seed **all categories** from your client categories file, including:
- 23 main categories
- 200+ subcategories
- All organized hierarchically

## Categories Included

1. **Automotive Services** (12 subcategories)
2. **Building & Construction** (23 subcategories)
3. **Transport, Cargo & Logistics Services** (14 subcategories)
4. **Care Services** (9 subcategories)
5. **Education** (16 subcategories)
6. **Cleaning Services** (12 subcategories)
7. **Computer & IT Services** (12 subcategories)
8. **Entertainment Services** (11 subcategories)
9. **Fitness & Personal Training Services** (8 subcategories)
10. **Health & Beauty Services** (15 subcategories)
11. **Landscaping and Gardening Services** (9 subcategories)
12. **Legal Services** (13 subcategories)
13. **Manufacturing Services** (11 subcategories)
14. **Catering & Events Services** (8 subcategories)
15. **Pet Services** (7 subcategories)
16. **Photography & Video Services** (5 subcategories)
17. **Printing Services** (6 subcategories)
18. **Recruitment Services** (8 subcategories)
19. **Rental Services** (5 subcategories)
20. **Repair Services** (9 subcategories)
21. **Tax & Financial Services** (7 subcategories)
22. **Travel Agents & Tours** (8 subcategories)
23. **Media Services** (35 subcategories)

**Total: 200+ categories**

## API Usage

### Get All Categories

```
GET /api/categories
```

### Get Categories for Provider

```
GET /api/service-providers/{id}/categories
```

### Set Provider Categories

```
POST /api/service-providers/{id}/categories
Body: {
  "category_ids": ["category-uuid-1", "category-uuid-2"],
  "primary_category_id": "category-uuid-1"
}
```

## Features

✅ **Multiple Categories** - Providers can select multiple categories
✅ **Primary Category** - One category can be marked as primary
✅ **Hierarchical** - Parent/child category structure
✅ **Searchable** - Filter providers by category
✅ **Comprehensive** - 200+ categories covering all service types

## Notes

- Categories are seeded from `client/app/utils/categories.ts`
- All categories are active by default
- Categories can be filtered by `is_active` status
- Subcategories are linked to parent categories via `parent_id`
