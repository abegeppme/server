# AbegEppMe Process Summary

Based on the **AbegEppMe Process Documentation**, here's a complete breakdown of the invoice and service process:

---

## 📋 Complete Process Flow

### **Phase 1: Invoice Creation** 
*(Vendor creates invoice for customer)*

1. **Vendor accesses invoice creation page**
   - Location: Vendor Dashboard → Create Invoice
   - Requires: Vendor account

2. **Customer search with auto-complete**
   - Vendor types customer name/email (min 2 chars)
   - System searches and shows: "Customer Name (email@example.com)"
   - Results include:
     - Previous customers of this vendor (prioritized)
     - All registered customers
     - Matched by name, email, or username
   - Vendor selects customer → auto-fills customer details

3. **Service details entry**
   - **Required:** Service title, Service amount
   - **Optional:** Service description, Additional notes
   - **Auto-calculation:**
     - Service Amount (vendor input)
     - Service Charge: ₦250 (flat)
     - VAT: 7.5% of (Service Amount + Service Charge)
     - **Total = Service + Charge + VAT**

4. **Invoice creation & notification**
   - Order created with status: "Pending Payment"
   - Customer receives email with:
     - Order number
     - Service details
     - Total amount
     - **Direct payment link** (big "Pay Now" button)

---

### **Phase 2: Payment Processing**
*(Customer pays invoice)*

1. **Customer clicks payment link** → Paystack payment page
2. **Payment successful** → Automatic split payment:
   - **40%** → Vendor (immediate payout)
   - **54%** → Escrow (held until completion)
   - **5%** → Platform commission
   - **1%** → Insurance fee
   - **₦250** → Service charge
3. **Order status:** "Pending Payment" → "Processing" → **"In Service"**

---

### **Phase 3: Service Completion**
*(Vendor performs and marks service complete)*

1. **Vendor performs service**
2. **Vendor marks service complete:**
   - Clicks "✅ Mark Service as Complete"
   - Can upload completion documents (optional)
   - Order status: **"Awaiting Confirmation"**
   - Customer receives email notification
   - Email includes:
     - Service completion notification
     - Link to view order
     - Instructions to confirm or dispute
     - **7-day auto-release deadline** mentioned

---

### **Phase 4: Double Confirmation System**
*(Customer confirms or disputes)*

#### **Option A: Customer Confirms** ✅

1. **Customer clicks "✅ Confirm Completion"**
2. **48-Hour Hold Period Begins:**
   - Status: **"In 48-Hour Hold"** (not "Completed")
   - `customer_confirmed` = `'pending'` (not `'yes'`)
   - `payout_release_date` = Current time + 48 hours
   - Payment is **NOT** processed immediately
   - Customer sees countdown timer
   - Customer can still raise dispute during 48h

3. **After 48 Hours (if no dispute):**
   - System automatically checks if 48 hours elapsed
   - If no dispute raised:
     - `customer_confirmed` = `'yes'`
     - Vendor balance payment **automatically triggered**
     - Paystack transfer initiated
     - Order status → **"Completed"**
     - Both parties notified

#### **Option B: Customer Raises Dispute** ⚠️

1. **Customer clicks "⚠️ Raise Dispute"**
2. **Dispute form:**
   - Reason (required)
   - Description (required)
   - Images (optional, up to 5)
3. **What happens:**
   - Order status → **"On Hold"**
   - Vendor balance payment **BLOCKED**
   - Admin notified via email
   - Both parties see "Order in Dispute"

#### **Option C: No Response (7-Day Auto-Release)** ⏰

- If customer doesn't confirm OR dispute within **7 days** of vendor marking complete:
  - System automatically marks customer as "confirmed" (auto)
  - Triggers vendor balance payment
  - Sends notifications
  - Order completed
  - **Purpose:** Protects vendors from indefinite holds

---

### **Phase 5: Dispute Resolution** *(If applicable)*

**Admin has 3 resolution options:**

1. **Resolve Dispute** → Return to normal flow
   - Clears dispute status
   - Order returns to previous state
   - Parties can proceed normally

2. **Force Complete & Pay Vendor** (Admin Override)
   - Bypasses customer confirmation
   - Immediately pays vendor balance
   - Marks order as completed
   - Use when: Customer unresponsive, dispute resolved in vendor's favor

3. **Process Refund**
   - Full or partial refund to customer
   - Processed through Paystack
   - Use when: Service unsatisfactory, dispute resolved in customer's favor

---

## 🔑 Key Features

### **48-Hour Hold Period**
- **Purpose:** Customer protection (time to test service, find hidden issues)
- **When:** After customer confirms completion
- **Duration:** 48 hours from confirmation
- **Status:** `IN_48H_HOLD` (not `COMPLETED`)
- **Customer can:** Still raise dispute during hold
- **After 48h:** Automatic payment if no dispute

### **7-Day Auto-Release**
- **Purpose:** Vendor protection (prevents indefinite holds)
- **When:** If customer doesn't respond within 7 days of vendor marking complete
- **Action:** Auto-confirms and pays vendor
- **Status:** `auto_released = true`

### **Double Confirmation**
- **Vendor confirms:** Service is done
- **Customer confirms:** Service is satisfactory
- **Both must agree** (or timers expire) before final payment

### **Payment Split**
- **40%** → Vendor upfront (immediate)
- **54%** → Escrow (held until completion)
- **5%** → Platform commission
- **1%** → Insurance
- **₦250** → Service charge

---

## ⚠️ Current Implementation Status

### ✅ **Implemented:**
- Invoice generation
- Order creation
- Payment processing
- Vendor marks complete
- Customer confirmation (but **immediately pays** - WRONG!)
- Dispute raising
- Admin force complete

### ❌ **Missing (Critical):**
1. **48-Hour Hold Period** - Currently pays immediately ❌
2. **7-Day Auto-Release** - Not implemented ❌
3. **Customer Confirmation Status** - Needs 'pending' state ❌
4. **Customer Search Endpoint** - For auto-complete (frontend) ⚠️

---

## 📊 Timeline Example

```
Day 0: Invoice created, customer pays
Day 1-5: Service being performed
Day 5: Vendor marks complete
Day 6: Customer confirms → 48-hour hold begins
Day 8: 48 hours elapsed → Vendor balance paid automatically
```

**Fastest:** Customer confirms → 48h → Payment (2 days)
**Longest:** 7-day auto-release if customer doesn't respond

---

## 🎯 Next Steps

1. **Update database schema** - Add 48h hold fields
2. **Update OrderController** - Implement 48h hold logic
3. **Create hold processor** - Cron job for auto-payment
4. **Add customer search** - Endpoint for auto-complete
5. **Update statuses** - Add `IN_48H_HOLD` status

See `PROCESS_ANALYSIS.md` for detailed implementation plan.
