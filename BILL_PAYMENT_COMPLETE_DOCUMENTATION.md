# Bill Payment System - Complete Documentation

**Version:** 1.0  
**Last Updated:** 2024  
**Status:** Production Ready

---

## Table of Contents

1. [Overview](#overview)
2. [System Architecture](#system-architecture)
3. [Database Schema](#database-schema)
4. [API Reference](#api-reference)
5. [Payment Flows](#payment-flows)
6. [Category-Specific Guides](#category-specific-guides)
7. [Beneficiary Management](#beneficiary-management)
8. [Fee Calculation](#fee-calculation)
9. [Error Handling](#error-handling)
10. [Integration Guide](#integration-guide)
11. [Testing](#testing)
12. [Best Practices](#best-practices)
13. [Troubleshooting](#troubleshooting)
14. [Appendix](#appendix)

---

## Overview

### What is Bill Payment System?

The Bill Payment System is a comprehensive solution that allows users to pay for various utility bills and services directly from their wallet balance. It supports multiple payment categories, providers, and includes features like beneficiary management, plan-based payments, and account validation.

### Supported Categories

1. **Airtime** - Mobile airtime recharge
2. **Data** - Mobile data bundles and plans
3. **Electricity** - Electricity bill payments (prepaid/postpaid)
4. **Cable TV** - Cable TV and streaming subscriptions
5. **Betting** - Sports betting platform funding
6. **Internet** - Internet router subscriptions (reserved for future use)

### Key Features

- ✅ Multi-category support (6 categories)
- ✅ Multiple providers per category
- ✅ Plan-based payments (Data & Cable TV)
- ✅ Custom amount payments (Airtime, Electricity, Betting)
- ✅ Account/Meter validation (Electricity & Betting)
- ✅ Beneficiary management (save frequently used accounts)
- ✅ PIN-based authorization
- ✅ Transaction tracking and history
- ✅ Fee calculation and management
- ✅ Wallet balance management

### System Requirements

- Node.js 20+
- MySQL 8.0+
- Prisma ORM
- Express.js
- TypeScript

---

## System Architecture

### High-Level Architecture

```
┌─────────────────┐
│   Client App    │
└────────┬────────┘
         │
         │ HTTP/REST API
         │
┌────────▼─────────────────────────┐
│   Bill Payment Controller         │
│   (HTTP Request Handler)         │
└────────┬─────────────────────────┘
         │
         │ Service Layer
         │
┌────────▼─────────────────────────┐
│   Bill Payment Service            │
│   (Business Logic)                │
└────────┬─────────────────────────┘
         │
    ┌────┴────┬──────────┬──────────┐
    │         │          │          │
┌───▼───┐ ┌──▼───┐ ┌────▼────┐ ┌───▼────┐
│Wallet │ │Prisma│ │Provider │ │Transaction│
│Service│ │  ORM │ │   API   │ │  Service │
└───────┘ └──────┘ └─────────┘ └─────────┘
    │         │
    │         │
┌───▼─────────▼───┐
│   MySQL Database │
└──────────────────┘
```

### Component Overview

#### 1. Bill Payment Controller
- Handles HTTP requests
- Validates request parameters
- Returns JSON responses
- Error handling

#### 2. Bill Payment Service
- Business logic implementation
- Transaction management
- Fee calculation
- Account validation
- Beneficiary management

#### 3. Database Layer
- Prisma ORM for database access
- Models: Category, Provider, Plan, Beneficiary, Transaction

#### 4. Wallet Service
- Balance checking
- Balance deduction
- Wallet retrieval

---

## Database Schema

### Models Overview

```
BillPaymentCategory (1) ──< (N) BillPaymentProvider
                                    │
                                    │ (1)
                                    │
                                    │ (N)
                                    ▼
                            BillPaymentPlan
                                    │
                                    │
BillPaymentProvider (1) ──< (N) Beneficiary
                                    │
                                    │ (N)
                                    │
                                    ▼
                                User (N)
```

### Model Definitions

#### 1. BillPaymentCategory

Stores bill payment categories (Airtime, Data, Electricity, etc.)

```prisma
model BillPaymentCategory {
  id          Int      @id @default(autoincrement())
  code        String   @unique @db.VarChar(50)
  name        String   @db.VarChar(100)
  description String?  @db.Text
  isActive    Boolean  @default(true) @map("is_active")
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")

  providers     BillPaymentProvider[]
  beneficiaries Beneficiary[]

  @@index([code])
  @@index([isActive])
  @@map("bill_payment_categories")
}
```

**Fields:**
- `id`: Primary key
- `code`: Unique category code (e.g., "airtime", "data")
- `name`: Display name (e.g., "Airtime", "Data")
- `description`: Category description
- `isActive`: Whether category is active
- `createdAt`: Creation timestamp
- `updatedAt`: Last update timestamp

**Seeded Categories:**
- `airtime` - Mobile airtime recharge
- `data` - Mobile data plans
- `electricity` - Electricity bills
- `cable_tv` - Cable TV subscriptions
- `betting` - Sports betting
- `internet` - Internet subscriptions

#### 2. BillPaymentProvider

Stores providers within each category (MTN, GLO, DSTV, etc.)

```prisma
model BillPaymentProvider {
  id          Int      @id @default(autoincrement())
  categoryId  Int      @map("category_id")
  code        String   @db.VarChar(50)
  name        String   @db.VarChar(100)
  logoUrl     String?  @map("logo_url")
  countryCode String   @default("NG") @map("country_code") @db.VarChar(10)
  currency    String   @default("NGN") @db.VarChar(10)
  isActive    Boolean  @default(true) @map("is_active")
  metadata    Json?
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")

  category      BillPaymentCategory @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  plans         BillPaymentPlan[]
  beneficiaries Beneficiary[]

  @@unique([categoryId, code])
  @@index([categoryId])
  @@index([code])
  @@index([isActive])
  @@index([countryCode])
  @@map("bill_payment_providers")
}
```

**Fields:**
- `id`: Primary key
- `categoryId`: Foreign key to BillPaymentCategory
- `code`: Provider code (e.g., "MTN", "GLO", "DSTV")
- `name`: Provider display name
- `logoUrl`: Path to provider logo image
- `countryCode`: Country code (e.g., "NG", "KE")
- `currency`: Default currency (e.g., "NGN")
- `isActive`: Whether provider is active
- `metadata`: Additional provider-specific data (JSON)
- `createdAt`: Creation timestamp
- `updatedAt`: Last update timestamp

**Seeded Providers:**

**Airtime/Data:**
- MTN (code: MTN)
- GLO (code: GLO)
- Airtel (code: AIRTEL)

**Electricity:**
- Ikeja Electric (code: IKEJA)
- Ibadan Electric (code: IBADAN)
- Abuja Electric (code: ABUJA)

**Cable TV:**
- DSTV (code: DSTV)
- Showmax (code: SHOWMAX)
- GOtv (code: GOTV)

**Betting:**
- 1xBet (code: 1XBET)
- Bet9ja (code: BET9JA)
- SportBet (code: SPORTBET)

#### 3. BillPaymentPlan

Stores plans/bundles for Data and Cable TV providers

```prisma
model BillPaymentPlan {
  id          Int      @id @default(autoincrement())
  providerId  Int      @map("provider_id")
  code        String   @db.VarChar(100)
  name        String   @db.VarChar(255)
  amount      Decimal  @db.Decimal(20, 8)
  currency    String   @default("NGN") @db.VarChar(10)
  dataAmount  String?  @map("data_amount") @db.VarChar(50)
  validity    String?  @db.VarChar(50)
  description String?  @db.Text
  isActive    Boolean  @default(true) @map("is_active")
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")

  provider BillPaymentProvider @relation(fields: [providerId], references: [id], onDelete: Cascade)

  @@index([providerId])
  @@index([code])
  @@index([isActive])
  @@map("bill_payment_plans")
}
```

**Fields:**
- `id`: Primary key
- `providerId`: Foreign key to BillPaymentProvider
- `code`: Unique plan code (e.g., "MTN_1GB", "DSTV_COMPACT")
- `name`: Plan display name (e.g., "1GB", "DSTV Compact")
- `amount`: Plan price (Decimal)
- `currency`: Currency code (default: "NGN")
- `dataAmount`: Data amount for data plans (e.g., "1GB", "2GB")
- `validity`: Validity period (e.g., "30 days", "1 month")
- `description`: Plan description
- `isActive`: Whether plan is active
- `createdAt`: Creation timestamp
- `updatedAt`: Last update timestamp

**Seeded Plans:**

**Data Plans (21 total):**
- MTN: 100MB, 200MB, 500MB, 1GB, 2GB, 5GB, 10GB
- GLO: 100MB, 200MB, 500MB, 1GB, 2GB, 5GB, 10GB
- Airtel: 100MB, 200MB, 500MB, 1GB, 2GB, 5GB, 10GB

**Cable TV Plans (12 total):**
- DSTV: Compact, Compact Plus, Premium, Asian, Pidgin
- Showmax: Mobile, Standard, Pro
- GOtv: Smallie, Jinja, Jinja Plus, Max

#### 4. Beneficiary

Stores user's saved beneficiaries for quick payments

```prisma
model Beneficiary {
  id            Int      @id @default(autoincrement())
  userId        Int      @map("user_id")
  categoryId    Int      @map("category_id")
  providerId    Int      @map("provider_id")
  name          String?  @db.VarChar(255)
  accountNumber String   @map("account_number") @db.VarChar(255)
  accountType   String?  @map("account_type") @db.VarChar(50)
  isActive      Boolean  @default(true) @map("is_active")
  createdAt     DateTime @default(now()) @map("created_at")
  updatedAt     DateTime @updatedAt @map("updated_at")

  user     User                @relation(fields: [userId], references: [id], onDelete: Cascade)
  category BillPaymentCategory @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  provider BillPaymentProvider @relation(fields: [providerId], references: [id], onDelete: Cascade)

  @@index([userId])
  @@index([categoryId])
  @@index([providerId])
  @@index([isActive])
  @@map("beneficiaries")
}
```

**Fields:**
- `id`: Primary key
- `userId`: Foreign key to User
- `categoryId`: Foreign key to BillPaymentCategory
- `providerId`: Foreign key to BillPaymentProvider
- `name`: Friendly name for beneficiary (optional)
- `accountNumber`: Account number (phone, meter, etc.)
- `accountType`: Account type (for electricity: "prepaid" or "postpaid")
- `isActive`: Whether beneficiary is active (soft delete)
- `createdAt`: Creation timestamp
- `updatedAt`: Last update timestamp

#### 5. Transaction (Related Model)

Stores bill payment transactions

```prisma
model Transaction {
  id            Int       @id @default(autoincrement())
  walletId      Int
  type          String    // "bill_payment"
  status        String    @default("pending") // pending, processing, completed, failed, cancelled
  amount        Decimal   @db.Decimal(20, 8)
  currency      String
  fee           Decimal   @default(0) @db.Decimal(20, 8)
  reference     String    @unique
  description   String?
  channel       String?   // Category code (airtime, data, etc.)
  country       String?   // Country code
  metadata      Json?     // Bill payment details
  completedAt   DateTime? @map("completed_at")
  createdAt     DateTime  @default(now())
  updatedAt     DateTime  @updatedAt

  wallet Wallet @relation(fields: [walletId], references: [id], onDelete: Cascade)

  @@index([walletId])
  @@index([reference])
  @@index([status])
  @@index([type])
  @@index([channel])
  @@map("transactions")
}
```

**Transaction Metadata Structure:**

```json
{
  "categoryCode": "airtime",
  "categoryName": "Airtime",
  "providerId": 1,
  "providerCode": "MTN",
  "providerName": "MTN",
  "accountNumber": "08012345678",
  "accountName": null,
  "accountType": null,
  "planId": null,
  "planCode": null,
  "planName": null,
  "planDataAmount": null,
  "beneficiaryId": null,
  "rechargeToken": null
}
```

---

## API Reference

### Base URL

```
/api/bill-payment
```

### Authentication

All endpoints require authentication via:
- Bearer token in `Authorization` header, OR
- Session cookie

### Response Format

**Success Response:**
```json
{
  "success": true,
  "data": { ... }
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "Error message here"
}
```

### HTTP Status Codes

- `200` - Success
- `400` - Bad Request (validation error, invalid parameters)
- `401` - Unauthorized (authentication required)
- `404` - Not Found (resource doesn't exist)
- `500` - Internal Server Error

---

### Endpoint 1: Get Categories

**GET** `/api/bill-payment/categories`

Get all available bill payment categories.

**Authentication:** Required

**Query Parameters:** None

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "airtime",
      "name": "Airtime",
      "description": "Mobile airtime recharge"
    },
    {
      "id": 2,
      "code": "data",
      "name": "Data",
      "description": "Mobile data plans and bundles"
    },
    {
      "id": 3,
      "code": "electricity",
      "name": "Electricity",
      "description": "Electricity bill payments"
    },
    {
      "id": 4,
      "code": "cable_tv",
      "name": "Cable TV",
      "description": "Cable TV and streaming subscriptions"
    },
    {
      "id": 5,
      "code": "betting",
      "name": "Betting",
      "description": "Sports betting platform funding"
    },
    {
      "id": 6,
      "code": "internet",
      "name": "Internet Subscription",
      "description": "Internet router subscriptions"
    }
  ]
}
```

**Usage:**
- First step in payment flow
- Display categories to user
- Use `code` field for subsequent API calls

---

### Endpoint 2: Get Providers

**GET** `/api/bill-payment/providers`

Get providers for a specific category.

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `categoryCode` | string | Yes | Category code from Endpoint 1 |
| `countryCode` | string | No | Filter by country (e.g., "NG", "KE") |

**Example Request:**
```
GET /api/bill-payment/providers?categoryCode=airtime&countryCode=NG
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "MTN",
      "name": "MTN",
      "logoUrl": "/uploads/billpayments/mtn.png",
      "countryCode": "NG",
      "currency": "NGN",
      "category": {
        "id": 1,
        "code": "airtime",
        "name": "Airtime"
      },
      "metadata": null
    },
    {
      "id": 2,
      "code": "GLO",
      "name": "GLO",
      "logoUrl": "/uploads/billpayments/glo.png",
      "countryCode": "NG",
      "currency": "NGN",
      "category": {
        "id": 1,
        "code": "airtime",
        "name": "Airtime"
      },
      "metadata": null
    }
  ]
}
```

**Usage:**
- After user selects category
- Display providers to user
- Use `id` field for subsequent API calls

**Error Responses:**
- `400` - categoryCode is required
- `500` - Category not found

---

### Endpoint 3: Get Plans

**GET** `/api/bill-payment/plans`

Get available plans/bundles for a provider. **Only for Data and Cable TV categories.**

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `providerId` | integer | Yes | Provider ID from Endpoint 2 |

**Example Request:**
```
GET /api/bill-payment/plans?providerId=1
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 4,
      "code": "MTN_1GB",
      "name": "1GB",
      "amount": "1000",
      "currency": "NGN",
      "dataAmount": "1GB",
      "validity": "30 days",
      "description": null
    },
    {
      "id": 5,
      "code": "MTN_2GB",
      "name": "2GB",
      "amount": "2000",
      "currency": "NGN",
      "dataAmount": "2GB",
      "validity": "30 days",
      "description": null
    }
  ]
}
```

**Usage:**
- After user selects provider (for Data/Cable TV only)
- Display plans to user
- Use `id` field in initiate payment

**Error Responses:**
- `400` - providerId is required or invalid
- `404` - Provider not found
- `500` - Provider is not active

**Note:** Plans are sorted by amount (ascending)

---

### Endpoint 4: Validate Meter (Electricity Only)

**POST** `/api/bill-payment/validate-meter`

Validate electricity meter number before payment.

**Authentication:** Required

**Request Body:**
```json
{
  "providerId": 7,
  "meterNumber": "1234567890",
  "accountType": "prepaid"
}
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `providerId` | integer | Yes | Provider ID (Ikeja, Ibadan, Abuja) |
| `meterNumber` | string | Yes | Meter number to validate (min 8 characters) |
| `accountType` | string | Yes | "prepaid" or "postpaid" |

**Response:**
```json
{
  "success": true,
  "data": {
    "isValid": true,
    "accountName": "John Doe",
    "meterNumber": "1234567890",
    "accountType": "prepaid",
    "provider": {
      "id": 7,
      "name": "Ikeja Electric",
      "code": "IKEJA"
    }
  }
}
```

**Usage:**
- **Required** before initiating electricity payment
- Validates meter number format
- Returns account holder name
- Use validated meter number in initiate payment

**Error Responses:**
- `400` - Missing required parameters
- `400` - Invalid meter number format
- `400` - Provider not found

**Note:** In production, this should call the electricity provider's API for real validation.

---

### Endpoint 5: Validate Account (Betting Only)

**POST** `/api/bill-payment/validate-account`

Validate betting account number before payment.

**Authentication:** Required

**Request Body:**
```json
{
  "providerId": 13,
  "accountNumber": "12345"
}
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `providerId` | integer | Yes | Provider ID (1xBet, Bet9ja, SportBet) |
| `accountNumber` | string | Yes | Betting account number (min 5 characters) |

**Response:**
```json
{
  "success": true,
  "data": {
    "isValid": true,
    "accountNumber": "12345",
    "provider": {
      "id": 13,
      "name": "1xBet",
      "code": "1XBET"
    }
  }
}
```

**Usage:**
- **Required** before initiating betting payment
- Validates account number format
- Use validated account number in initiate payment

**Error Responses:**
- `400` - Missing required parameters
- `400` - Invalid account number format
- `400` - Provider not found

**Note:** In production, this should call the betting provider's API for real validation.

---

### Endpoint 6: Initiate Payment

**POST** `/api/bill-payment/initiate`

Create a pending transaction and return payment summary. **Does NOT deduct balance yet.**

**Authentication:** Required

**Request Body (Common Parameters):**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `categoryCode` | string | Yes | Category code |
| `providerId` | integer | Yes | Provider ID |
| `currency` | string | Yes | Wallet currency (e.g., "NGN") |
| `amount` | string | Conditional | Payment amount (required for Airtime, Electricity, Betting) |
| `accountNumber` | string | Conditional | Account number OR use beneficiaryId |
| `beneficiaryId` | integer | Optional | Saved beneficiary ID (replaces accountNumber) |
| `accountType` | string | Conditional | "prepaid" or "postpaid" (required for Electricity) |
| `planId` | integer | Conditional | Plan ID (required for Data and Cable TV) |

**Category-Specific Examples:**

#### Airtime
```json
{
  "categoryCode": "airtime",
  "providerId": 1,
  "currency": "NGN",
  "amount": "1000",
  "accountNumber": "08012345678"
}
```

#### Data
```json
{
  "categoryCode": "data",
  "providerId": 1,
  "currency": "NGN",
  "planId": 4,
  "accountNumber": "08012345678"
}
```

#### Electricity
```json
{
  "categoryCode": "electricity",
  "providerId": 7,
  "currency": "NGN",
  "amount": "5000",
  "accountNumber": "1234567890",
  "accountType": "prepaid"
}
```

#### Cable TV
```json
{
  "categoryCode": "cable_tv",
  "providerId": 10,
  "currency": "NGN",
  "planId": 25,
  "accountNumber": "1234567890"
}
```

#### Betting
```json
{
  "categoryCode": "betting",
  "providerId": 13,
  "currency": "NGN",
  "amount": "5000",
  "accountNumber": "12345"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "transactionId": 123,
    "reference": "BILLABC123XYZ",
    "category": {
      "id": 1,
      "code": "airtime",
      "name": "Airtime"
    },
    "provider": {
      "id": 1,
      "code": "MTN",
      "name": "MTN",
      "logoUrl": "/uploads/billpayments/mtn.png"
    },
    "plan": null,
    "accountNumber": "08012345678",
    "accountName": null,
    "accountType": null,
    "amount": "1000",
    "currency": "NGN",
    "fee": "10",
    "totalAmount": "1010",
    "wallet": {
      "id": 1,
      "currency": "NGN",
      "balance": "50000"
    }
  }
}
```

**Usage:**
- Show payment summary to user
- Display amount, fee, and total
- User reviews before confirming
- Save `transactionId` for confirm endpoint

**Error Responses:**
- `400` - Missing required parameters
- `400` - Category not found
- `400` - Provider not found
- `400` - Wallet not found
- `400` - Insufficient balance
- `400` - Invalid account number
- `400` - Plan not found
- `400` - Beneficiary not found
- `401` - Unauthorized

**Important Notes:**
1. Creates a **pending** transaction in database
2. Does **NOT** deduct balance
3. Validates account/meter if required
4. For Data/Cable TV, if `planId` provided, `amount` is ignored
5. If `beneficiaryId` provided, `accountNumber` is ignored

---

### Endpoint 7: Confirm Payment

**POST** `/api/bill-payment/confirm`

Complete the payment by confirming the pending transaction. **Deducts balance and requires PIN.**

**Authentication:** Required

**Request Body:**
```json
{
  "transactionId": 123,
  "pin": "1234"
}
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `transactionId` | integer | Yes | Transaction ID from initiate endpoint |
| `pin` | string | Yes | User's 4-6 digit PIN |

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "reference": "BILLABC123XYZ",
    "status": "completed",
    "amount": "1000",
    "currency": "NGN",
    "fee": "10",
    "totalAmount": "1010",
    "accountNumber": "08012345678",
    "accountName": null,
    "rechargeToken": null,
    "category": {
      "code": "airtime",
      "name": "Airtime"
    },
    "provider": {
      "id": 1,
      "code": "MTN",
      "name": "MTN"
    },
    "plan": null,
    "completedAt": "2024-01-15T10:30:00Z",
    "createdAt": "2024-01-15T10:30:00Z"
  }
}
```

**Special Response Fields:**
- `rechargeToken`: Only for prepaid electricity payments (token to recharge meter)

**Usage:**
- After user reviews payment summary
- User enters PIN to authorize
- Payment is completed and balance deducted
- Transaction status changes to "completed"

**Error Responses:**
- `400` - Missing transactionId or pin
- `400` - Transaction not found
- `400` - Unauthorized access to transaction
- `400` - Transaction is not a bill payment
- `400` - Transaction is already completed/failed
- `400` - PIN not set
- `400` - Invalid PIN
- `400` - Insufficient balance
- `401` - Unauthorized

**Important Notes:**
1. **Deducts balance** from user's wallet
2. **Requires PIN** verification
3. Updates transaction status to "completed"
4. Sets `completedAt` timestamp
5. For prepaid electricity, generates `rechargeToken`

---

### Endpoint 8: Get Beneficiaries

**GET** `/api/bill-payment/beneficiaries`

Get user's saved beneficiaries.

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `categoryCode` | string | No | Filter by category code |

**Example Request:**
```
GET /api/bill-payment/beneficiaries?categoryCode=airtime
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "My Phone",
      "accountNumber": "08012345678",
      "accountType": null,
      "category": {
        "id": 1,
        "code": "airtime",
        "name": "Airtime"
      },
      "provider": {
        "id": 1,
        "code": "MTN",
        "name": "MTN",
        "logoUrl": "/uploads/billpayments/mtn.png"
      },
      "createdAt": "2024-01-15T10:00:00Z"
    }
  ]
}
```

**Usage:**
- Display saved beneficiaries to user
- Allow user to select beneficiary instead of typing account number
- Filter by category if needed

**Error Responses:**
- `401` - Unauthorized
- `500` - Internal server error

---

### Endpoint 9: Create Beneficiary

**POST** `/api/bill-payment/beneficiaries`

Save a beneficiary for future use.

**Authentication:** Required

**Request Body:**
```json
{
  "categoryCode": "airtime",
  "providerId": 1,
  "name": "My Phone",
  "accountNumber": "08012345678",
  "accountType": null
}
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `categoryCode` | string | Yes | Category code |
| `providerId` | integer | Yes | Provider ID |
| `name` | string | No | Friendly name |
| `accountNumber` | string | Yes | Account number (phone, meter, etc.) |
| `accountType` | string | No | "prepaid" or "postpaid" (for electricity) |

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "My Phone",
    "accountNumber": "08012345678",
    "accountType": null,
    "category": {
      "id": 1,
      "code": "airtime",
      "name": "Airtime"
    },
    "provider": {
      "id": 1,
      "code": "MTN",
      "name": "MTN",
      "logoUrl": "/uploads/billpayments/mtn.png"
    },
    "createdAt": "2024-01-15T10:00:00Z"
  }
}
```

**Usage:**
- Save beneficiary after successful payment
- Save beneficiary manually
- Use beneficiary in future payments

**Error Responses:**
- `400` - Missing required parameters
- `400` - Category not found
- `400` - Provider not found
- `400` - Beneficiary already exists
- `401` - Unauthorized

**Note:** Duplicate beneficiaries (same category, provider, accountNumber) are not allowed.

---

### Endpoint 10: Update Beneficiary

**PUT** `/api/bill-payment/beneficiaries/:id`

Update a beneficiary.

**Authentication:** Required

**Path Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Beneficiary ID |

**Request Body:**
```json
{
  "name": "Updated Name",
  "accountNumber": "08087654321",
  "accountType": "prepaid"
}
```

**Parameters (all optional):**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | No | Friendly name |
| `accountNumber` | string | No | Account number |
| `accountType` | string | No | "prepaid" or "postpaid" |

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Updated Name",
    "accountNumber": "08087654321",
    "accountType": "prepaid",
    "category": {
      "id": 1,
      "code": "airtime",
      "name": "Airtime"
    },
    "provider": {
      "id": 1,
      "code": "MTN",
      "name": "MTN",
      "logoUrl": "/uploads/billpayments/mtn.png"
    },
    "updatedAt": "2024-01-15T11:00:00Z"
  }
}
```

**Usage:**
- Update beneficiary details
- Change account number
- Update account type

**Error Responses:**
- `400` - Beneficiary ID is required
- `400` - Beneficiary not found
- `401` - Unauthorized

---

### Endpoint 11: Delete Beneficiary

**DELETE** `/api/bill-payment/beneficiaries/:id`

Delete (soft delete) a beneficiary.

**Authentication:** Required

**Path Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Beneficiary ID |

**Response:**
```json
{
  "success": true,
  "message": "Beneficiary deleted successfully"
}
```

**Usage:**
- Remove beneficiary from list
- Soft delete (sets `isActive` to false)

**Error Responses:**
- `400` - Beneficiary ID is required
- `400` - Beneficiary not found
- `401` - Unauthorized

---

## Payment Flows

### General Payment Flow

```
┌─────────────┐
│  1. Get     │
│  Categories │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│  2. Get     │
│  Providers  │
└──────┬──────┘
       │
       ▼
┌─────────────────┐
│  3. Get Plans    │ (Data/Cable TV only)
│  (Optional)      │
└──────┬──────────┘
       │
       ▼
┌─────────────────┐
│  4. Validate     │ (Electricity/Betting only)
│  Account/Meter   │
│  (Optional)      │
└──────┬──────────┘
       │
       ▼
┌─────────────────┐
│  5. Initiate     │
│  Payment        │
└──────┬──────────┘
       │
       ▼
┌─────────────────┐
│  6. Confirm      │
│  Payment        │
│  (with PIN)     │
└─────────────────┘
```

### Flow Diagram by Category

#### Airtime Flow
```
1. GET /categories
2. GET /providers?categoryCode=airtime
3. POST /initiate { categoryCode, providerId, amount, accountNumber }
4. POST /confirm { transactionId, pin }
```

#### Data Flow
```
1. GET /categories
2. GET /providers?categoryCode=data
3. GET /plans?providerId=1
4. POST /initiate { categoryCode, providerId, planId, accountNumber }
5. POST /confirm { transactionId, pin }
```

#### Electricity Flow
```
1. GET /categories
2. GET /providers?categoryCode=electricity
3. POST /validate-meter { providerId, meterNumber, accountType }
4. POST /initiate { categoryCode, providerId, amount, accountNumber, accountType }
5. POST /confirm { transactionId, pin }
   → Returns rechargeToken (for prepaid)
```

#### Cable TV Flow
```
1. GET /categories
2. GET /providers?categoryCode=cable_tv
3. GET /plans?providerId=10
4. POST /initiate { categoryCode, providerId, planId, accountNumber }
5. POST /confirm { transactionId, pin }
```

#### Betting Flow
```
1. GET /categories
2. GET /providers?categoryCode=betting
3. POST /validate-account { providerId, accountNumber }
4. POST /initiate { categoryCode, providerId, amount, accountNumber }
5. POST /confirm { transactionId, pin }
```

---

## Category-Specific Guides

### 1. Airtime Payments

**Overview:**
Mobile airtime recharge for phone numbers.

**Supported Providers:**
- MTN
- GLO
- Airtel

**Payment Flow:**
1. User selects Airtime category
2. User selects provider (MTN, GLO, Airtel)
3. User enters phone number
4. User enters amount (custom)
5. System shows summary (amount + fee)
6. User confirms with PIN
7. Payment completed

**Request Example:**
```json
{
  "categoryCode": "airtime",
  "providerId": 1,
  "currency": "NGN",
  "amount": "1000",
  "accountNumber": "08012345678"
}
```

**Minimum Amount:** Usually 50 NGN  
**Maximum Amount:** Usually 10,000 NGN (varies by provider)

**Account Number Format:**
- Phone number (10-11 digits)
- Must start with country code or 0
- Example: "08012345678" or "2348012345678"

---

### 2. Data Bundle Payments

**Overview:**
Mobile data bundle purchases.

**Supported Providers:**
- MTN
- GLO
- Airtel

**Available Plans:**

**MTN Data Plans:**
- 100MB - ₦100 (1 day)
- 200MB - ₦200 (3 days)
- 500MB - ₦500 (7 days)
- 1GB - ₦1,000 (30 days)
- 2GB - ₦2,000 (30 days)
- 5GB - ₦5,000 (30 days)
- 10GB - ₦10,000 (30 days)

**GLO Data Plans:**
- Same structure as MTN

**Airtel Data Plans:**
- Same structure as MTN

**Payment Flow:**
1. User selects Data category
2. User selects provider
3. System shows available data plans
4. User selects plan (planId)
5. User enters phone number
6. System shows summary (plan amount + fee)
7. User confirms with PIN
8. Payment completed

**Request Example:**
```json
{
  "categoryCode": "data",
  "providerId": 1,
  "currency": "NGN",
  "planId": 4,
  "accountNumber": "08012345678"
}
```

**Important:**
- `planId` is **required**
- `amount` is **ignored** if `planId` provided
- Amount comes from selected plan

---

### 3. Electricity Payments

**Overview:**
Electricity bill payments (prepaid and postpaid meters).

**Supported Providers:**
- Ikeja Electric
- Ibadan Electric
- Abuja Electric

**Account Types:**
- **Prepaid:** Pay for units in advance, get recharge token
- **Postpaid:** Pay outstanding bill

**Payment Flow:**
1. User selects Electricity category
2. User selects provider (Ikeja, Ibadan, Abuja)
3. User selects account type (prepaid/postpaid)
4. User enters meter number
5. **System validates meter** (required)
6. System shows account name
7. User enters amount (custom)
8. System shows summary
9. User confirms with PIN
10. Payment completed
11. **For prepaid:** System returns recharge token

**Validation Request:**
```json
{
  "providerId": 7,
  "meterNumber": "1234567890",
  "accountType": "prepaid"
}
```

**Payment Request:**
```json
{
  "categoryCode": "electricity",
  "providerId": 7,
  "currency": "NGN",
  "amount": "5000",
  "accountNumber": "1234567890",
  "accountType": "prepaid"
}
```

**Response (Prepaid):**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "reference": "BILLABC123XYZ",
    "status": "completed",
    "amount": "5000",
    "rechargeToken": "1234-5678-9012-3456",
    ...
  }
}
```

**Meter Number Format:**
- Usually 10-11 digits
- Format varies by provider
- Must be validated before payment

**Important:**
- **Meter validation is required** before initiate
- `accountType` is **required** ("prepaid" or "postpaid")
- For prepaid, `rechargeToken` is returned after payment
- User must enter recharge token on meter

---

### 4. Cable TV Payments

**Overview:**
Cable TV and streaming subscription payments.

**Supported Providers:**
- DSTV
- Showmax
- GOtv

**Available Plans:**

**DSTV Plans:**
- Compact - ₦7,900/month
- Compact Plus - ₦12,900/month
- Premium - ₦24,500/month
- Asian - ₦1,900/month
- Pidgin - ₦2,650/month

**Showmax Plans:**
- Mobile - ₦1,200/month
- Standard - ₦2,900/month
- Pro - ₦4,900/month

**GOtv Plans:**
- Smallie - ₦1,650/month
- Jinja - ₦2,650/month
- Jinja Plus - ₦3,250/month
- Max - ₦5,650/month

**Payment Flow:**
1. User selects Cable TV category
2. User selects provider (DSTV, Showmax, GOtv)
3. System shows available plans
4. User selects plan (planId)
5. User enters smart card/account number
6. System shows summary (plan amount + fee)
7. User confirms with PIN
8. Payment completed

**Request Example:**
```json
{
  "categoryCode": "cable_tv",
  "providerId": 10,
  "currency": "NGN",
  "planId": 25,
  "accountNumber": "1234567890"
}
```

**Account Number Format:**
- Smart card number (10-11 digits)
- Account number format varies by provider
- Example: "1234567890"

**Important:**
- `planId` is **required**
- `amount` is **ignored** if `planId` provided
- Amount comes from selected plan

---

### 5. Betting Payments

**Overview:**
Sports betting platform account funding.

**Supported Providers:**
- 1xBet
- Bet9ja
- SportBet

**Payment Flow:**
1. User selects Betting category
2. User selects provider (1xBet, Bet9ja, SportBet)
3. User enters betting account number
4. **System validates account** (required)
5. User enters amount (custom)
6. System shows summary
7. User confirms with PIN
8. Payment completed

**Validation Request:**
```json
{
  "providerId": 13,
  "accountNumber": "12345"
}
```

**Payment Request:**
```json
{
  "categoryCode": "betting",
  "providerId": 13,
  "currency": "NGN",
  "amount": "5000",
  "accountNumber": "12345"
}
```

**Account Number Format:**
- Usually 5-10 characters
- Format varies by provider
- Must be validated before payment

**Important:**
- **Account validation is required** before initiate
- Minimum amount usually 100 NGN
- Maximum amount varies by provider

---

### 6. Internet Subscriptions

**Overview:**
Internet router subscriptions (reserved for future use).

**Status:** Currently not active, reserved for future implementation.

---

## Beneficiary Management

### What are Beneficiaries?

Beneficiaries are saved account numbers (phone numbers, meter numbers, etc.) that users can reuse for quick payments without typing them each time.

### Benefits

- ✅ Faster payments
- ✅ Reduced errors
- ✅ Better user experience
- ✅ Support for multiple accounts

### Creating Beneficiaries

**After Payment:**
- Optionally save beneficiary after successful payment
- Use same details from payment

**Manually:**
- User can create beneficiary anytime
- Enter category, provider, account number

**Example:**
```json
POST /api/bill-payment/beneficiaries
{
  "categoryCode": "airtime",
  "providerId": 1,
  "name": "My Phone",
  "accountNumber": "08012345678"
}
```

### Using Beneficiaries

**In Initiate Payment:**
```json
{
  "categoryCode": "airtime",
  "providerId": 1,
  "currency": "NGN",
  "amount": "1000",
  "beneficiaryId": 1  // Use beneficiary instead of accountNumber
}
```

**Note:** If both `beneficiaryId` and `accountNumber` provided, `beneficiaryId` takes precedence.

### Managing Beneficiaries

- **Get all:** `GET /api/bill-payment/beneficiaries`
- **Get by category:** `GET /api/bill-payment/beneficiaries?categoryCode=airtime`
- **Update:** `PUT /api/bill-payment/beneficiaries/:id`
- **Delete:** `DELETE /api/bill-payment/beneficiaries/:id`

### Best Practices

1. **Auto-save:** Offer to save beneficiary after successful payment
2. **Friendly names:** Encourage users to add names (e.g., "My Phone", "Home Meter")
3. **Validation:** Validate account number before saving
4. **Limit:** Consider limiting number of beneficiaries per user
5. **Privacy:** Don't display full account numbers in UI (mask them)

---

## Fee Calculation

### Fee Structure

**Default Fee:**
- 1% of payment amount

**Minimum Fees by Currency:**
- NGN: 20
- USD: 0.1
- KES: 2
- GHS: 0.5
- Others: 0.1

### Calculation Formula

```typescript
fee = max(amount * 0.01, minFee[currency])
totalAmount = amount + fee
```

### Examples

**Example 1: NGN Payment (₦1,000)**
```
Amount: ₦1,000
Fee (1%): ₦10
Minimum fee: ₦20
Applied fee: ₦20 (minimum)
Total: ₦1,020
```

**Example 2: NGN Payment (₦5,000)**
```
Amount: ₦5,000
Fee (1%): ₦50
Minimum fee: ₦20
Applied fee: ₦50 (1%)
Total: ₦5,050
```

**Example 3: USD Payment ($10)**
```
Amount: $10
Fee (1%): $0.10
Minimum fee: $0.10
Applied fee: $0.10
Total: $10.10
```

### Customization

To customize fees, modify the `calculateFee` method in `BillPaymentService`:

```typescript
private calculateFee(amount: number, currency: string): number {
  // Custom fee calculation logic
  const feePercent = 0.015; // 1.5%
  const calculatedFee = amount * feePercent;
  
  const minFees: { [key: string]: number } = {
    NGN: 25,  // Updated minimum
    USD: 0.15,
    // ...
  };
  
  const minFee = minFees[currency] || 0.1;
  return Math.max(calculatedFee, minFee);
}
```

---

## Error Handling

### Error Response Format

```json
{
  "success": false,
  "message": "Error message here"
}
```

### Common Errors

#### 1. Validation Errors (400)

**Category not found:**
```json
{
  "success": false,
  "message": "Category not found"
}
```

**Provider not found:**
```json
{
  "success": false,
  "message": "Provider not found"
}
```

**Plan not found:**
```json
{
  "success": false,
  "message": "Plan not found"
}
```

**Invalid account number:**
```json
{
  "success": false,
  "message": "Invalid meter number format"
}
```

#### 2. Business Logic Errors (400)

**Insufficient balance:**
```json
{
  "success": false,
  "message": "Insufficient balance"
}
```

**Wallet not found:**
```json
{
  "success": false,
  "message": "Wallet for NGN not found"
}
```

**Beneficiary not found:**
```json
{
  "success": false,
  "message": "Beneficiary not found"
}
```

#### 3. Authentication Errors (401)

**Unauthorized:**
```json
{
  "success": false,
  "message": "Unauthorized"
}
```

#### 4. Transaction Errors (400)

**Transaction not found:**
```json
{
  "success": false,
  "message": "Transaction not found"
}
```

**Transaction already completed:**
```json
{
  "success": false,
  "message": "Transaction is already completed"
}
```

**Invalid PIN:**
```json
{
  "success": false,
  "message": "Invalid PIN"
}
```

**PIN not set:**
```json
{
  "success": false,
  "message": "PIN not set. Please setup your PIN first."
}
```

### Error Handling Best Practices

1. **User-friendly messages:** Provide clear, actionable error messages
2. **Log errors:** Log all errors server-side for debugging
3. **Handle edge cases:** Check for null/undefined values
4. **Validate early:** Validate inputs before processing
5. **Return appropriate status codes:** Use correct HTTP status codes

---

## Integration Guide

### Step 1: Install Dependencies

```bash
npm install @prisma/client prisma decimal.js bcryptjs
npm install -D @types/node @types/bcryptjs
```

### Step 2: Database Setup

```bash
# Run migrations
npx prisma migrate dev

# Seed database
npx prisma db seed
```

### Step 3: Service Integration

```typescript
import { BillPaymentService } from './modules/bill-payment/bill-payment.service';

const billPaymentService = new BillPaymentService();

// Get categories
const categories = await billPaymentService.getCategories();

// Get providers
const providers = await billPaymentService.getProvidersByCategory('airtime');

// Initiate payment
const preview = await billPaymentService.initiateBillPayment(userId, {
  categoryCode: 'airtime',
  providerId: 1,
  currency: 'NGN',
  amount: '1000',
  accountNumber: '08012345678'
});

// Confirm payment
const result = await billPaymentService.confirmBillPayment(
  userId,
  preview.transactionId,
  pin
);
```

### Step 4: Controller Setup

```typescript
import { BillPaymentController } from './modules/bill-payment/bill-payment.controller';
import { BillPaymentService } from './modules/bill-payment/bill-payment.service';

const service = new BillPaymentService();
const controller = new BillPaymentController(service);

// Setup routes
router.get('/categories', controller.getCategories.bind(controller));
router.get('/providers', controller.getProviders.bind(controller));
router.get('/plans', controller.getPlans.bind(controller));
router.post('/initiate', controller.initiatePayment.bind(controller));
router.post('/confirm', controller.confirmPayment.bind(controller));
// ... more routes
```

### Step 5: Authentication Middleware

```typescript
// Ensure user is authenticated
router.use('/bill-payment', authenticateMiddleware);

// Extract userId from request
const userId = req.user?.id || req.userId;
```

### Step 6: Error Handling

```typescript
try {
  const result = await billPaymentService.initiateBillPayment(userId, data);
  return res.json({ success: true, data: result });
} catch (error: any) {
  return res.status(400).json({
    success: false,
    message: error.message || 'Failed to initiate payment'
  });
}
```

---

## Testing

### Unit Tests

```typescript
describe('BillPaymentService', () => {
  let service: BillPaymentService;

  beforeEach(() => {
    service = new BillPaymentService();
  });

  describe('getCategories', () => {
    it('should return all active categories', async () => {
      const categories = await service.getCategories();
      expect(categories.length).toBeGreaterThan(0);
      expect(categories[0]).toHaveProperty('code');
      expect(categories[0]).toHaveProperty('name');
    });
  });

  describe('initiateBillPayment', () => {
    it('should create pending transaction', async () => {
      const result = await service.initiateBillPayment(userId, {
        categoryCode: 'airtime',
        providerId: 1,
        currency: 'NGN',
        amount: '1000',
        accountNumber: '08012345678'
      });
      
      expect(result).toHaveProperty('transactionId');
      expect(result).toHaveProperty('reference');
      expect(result.status).toBe('pending');
    });
  });
});
```

### Integration Tests

```typescript
describe('Bill Payment API', () => {
  it('should complete airtime payment flow', async () => {
    // 1. Get categories
    const categoriesRes = await request(app)
      .get('/api/bill-payment/categories')
      .set('Authorization', `Bearer ${token}`);
    expect(categoriesRes.status).toBe(200);

    // 2. Get providers
    const providersRes = await request(app)
      .get('/api/bill-payment/providers?categoryCode=airtime')
      .set('Authorization', `Bearer ${token}`);
    expect(providersRes.status).toBe(200);

    // 3. Initiate payment
    const initiateRes = await request(app)
      .post('/api/bill-payment/initiate')
      .set('Authorization', `Bearer ${token}`)
      .send({
        categoryCode: 'airtime',
        providerId: 1,
        currency: 'NGN',
        amount: '1000',
        accountNumber: '08012345678'
      });
    expect(initiateRes.status).toBe(200);

    // 4. Confirm payment
    const confirmRes = await request(app)
      .post('/api/bill-payment/confirm')
      .set('Authorization', `Bearer ${token}`)
      .send({
        transactionId: initiateRes.body.data.transactionId,
        pin: '1234'
      });
    expect(confirmRes.status).toBe(200);
    expect(confirmRes.body.data.status).toBe('completed');
  });
});
```

### Test Cases

**Category Tests:**
- ✅ Get all categories
- ✅ Categories are active
- ✅ Categories have required fields

**Provider Tests:**
- ✅ Get providers by category
- ✅ Filter by country code
- ✅ Providers belong to category

**Plan Tests:**
- ✅ Get plans by provider
- ✅ Plans are active
- ✅ Plans have required fields

**Payment Tests:**
- ✅ Initiate payment creates pending transaction
- ✅ Confirm payment deducts balance
- ✅ Confirm payment requires PIN
- ✅ Invalid PIN is rejected
- ✅ Insufficient balance is rejected

**Beneficiary Tests:**
- ✅ Create beneficiary
- ✅ Get beneficiaries
- ✅ Update beneficiary
- ✅ Delete beneficiary
- ✅ Duplicate beneficiary is rejected

---

## Best Practices

### 1. Security

- ✅ **Always validate PIN:** Never skip PIN verification
- ✅ **Check balance:** Always verify sufficient balance
- ✅ **Validate inputs:** Validate all user inputs
- ✅ **Use HTTPS:** Always use HTTPS in production
- ✅ **Rate limiting:** Implement rate limiting
- ✅ **Log transactions:** Log all transactions for audit

### 2. User Experience

- ✅ **Clear error messages:** Provide user-friendly error messages
- ✅ **Loading states:** Show loading states during API calls
- ✅ **Confirmation dialogs:** Show confirmation before payment
- ✅ **Transaction history:** Display transaction history
- ✅ **Beneficiary suggestions:** Suggest saving beneficiaries

### 3. Performance

- ✅ **Cache categories:** Cache categories (rarely change)
- ✅ **Cache providers:** Cache providers (rarely change)
- ✅ **Optimize queries:** Use database indexes
- ✅ **Pagination:** Implement pagination for large lists

### 4. Code Quality

- ✅ **Type safety:** Use TypeScript
- ✅ **Error handling:** Handle all errors
- ✅ **Code comments:** Add comments for complex logic
- ✅ **Code organization:** Organize code by feature

### 5. Monitoring

- ✅ **Log errors:** Log all errors
- ✅ **Track metrics:** Track payment success/failure rates
- ✅ **Monitor balance:** Monitor wallet balances
- ✅ **Alert on failures:** Set up alerts for payment failures

---

## Troubleshooting

### Common Issues

#### Issue 1: Provider Not Found

**Symptoms:**
- Error: "Provider not found"
- 404 status code

**Solutions:**
1. Check if provider exists in database
2. Verify provider is active (`isActive: true`)
3. Check category-provider relationship
4. Run seeders: `npx prisma db seed`

#### Issue 2: Plan Not Found

**Symptoms:**
- Error: "Plan not found"
- Plans not showing for provider

**Solutions:**
1. Check if plans exist for provider
2. Verify plans are active
3. Check provider ID is correct
4. Run seeders: `npx prisma db seed`

#### Issue 3: Insufficient Balance

**Symptoms:**
- Error: "Insufficient balance"
- Payment fails

**Solutions:**
1. Check user's wallet balance
2. Verify fee calculation
3. Check if balance includes fee
4. Ensure wallet exists for currency

#### Issue 4: Invalid PIN

**Symptoms:**
- Error: "Invalid PIN"
- PIN verification fails

**Solutions:**
1. Verify PIN is correct
2. Check if PIN is set
3. Verify PIN hash in database
4. Check bcrypt comparison

#### Issue 5: Transaction Not Found

**Symptoms:**
- Error: "Transaction not found"
- Cannot confirm payment

**Solutions:**
1. Verify transaction ID
2. Check transaction exists
3. Verify transaction belongs to user
4. Check transaction status

#### Issue 6: Meter Validation Fails

**Symptoms:**
- Error: "Invalid meter number format"
- Cannot validate meter

**Solutions:**
1. Check meter number format
2. Verify meter number length (min 8 characters)
3. Check provider supports meter type
4. Verify provider API (if integrated)

### Debugging Tips

1. **Check logs:** Review server logs for errors
2. **Database queries:** Check database directly
3. **API testing:** Use Postman/Insomnia for testing
4. **Prisma Studio:** Use `npx prisma studio` to view data
5. **Console logs:** Add console.log for debugging

---

## Appendix

### A. Reference Tables

#### Category Codes
| Code | Name |
|------|------|
| `airtime` | Airtime |
| `data` | Data |
| `electricity` | Electricity |
| `cable_tv` | Cable TV |
| `betting` | Betting |
| `internet` | Internet |

#### Provider Codes
| Category | Code | Name |
|----------|------|------|
| Airtime/Data | `MTN` | MTN |
| Airtime/Data | `GLO` | GLO |
| Airtime/Data | `AIRTEL` | Airtel |
| Electricity | `IKEJA` | Ikeja Electric |
| Electricity | `IBADAN` | Ibadan Electric |
| Electricity | `ABUJA` | Abuja Electric |
| Cable TV | `DSTV` | DSTV |
| Cable TV | `SHOWMAX` | Showmax |
| Cable TV | `GOTV` | GOtv |
| Betting | `1XBET` | 1xBet |
| Betting | `BET9JA` | Bet9ja |
| Betting | `SPORTBET` | SportBet |

### B. Code Examples

#### Complete Payment Flow (TypeScript)

```typescript
async function payAirtime(
  userId: number,
  providerId: number,
  amount: string,
  phoneNumber: string,
  pin: string
) {
  const service = new BillPaymentService();

  // 1. Initiate payment
  const preview = await service.initiateBillPayment(userId, {
    categoryCode: 'airtime',
    providerId,
    currency: 'NGN',
    amount,
    accountNumber: phoneNumber
  });

  // 2. Confirm payment
  const result = await service.confirmBillPayment(
    userId,
    preview.transactionId,
    pin
  );

  return result;
}
```

#### Using Beneficiaries

```typescript
// Create beneficiary
const beneficiary = await service.createBeneficiary(userId, {
  categoryCode: 'airtime',
  providerId: 1,
  name: 'My Phone',
  accountNumber: '08012345678'
});

// Use beneficiary in payment
const preview = await service.initiateBillPayment(userId, {
  categoryCode: 'airtime',
  providerId: 1,
  currency: 'NGN',
  amount: '1000',
  beneficiaryId: beneficiary.id  // Use beneficiary
});
```

### C. Database Queries

#### Get All Categories
```sql
SELECT * FROM bill_payment_categories WHERE is_active = 1;
```

#### Get Providers by Category
```sql
SELECT * FROM bill_payment_providers 
WHERE category_id = 1 AND is_active = 1;
```

#### Get Plans by Provider
```sql
SELECT * FROM bill_payment_plans 
WHERE provider_id = 1 AND is_active = 1 
ORDER BY amount ASC;
```

#### Get User's Beneficiaries
```sql
SELECT * FROM beneficiaries 
WHERE user_id = 1 AND is_active = 1;
```

### D. Environment Variables

```env
# Database
DATABASE_URL=mysql://user:password@host:port/database

# Encryption
ENCRYPTION_KEY=your-32-character-encryption-key

# JWT
JWT_SECRET=your-secret-key
JWT_EXPIRES_IN=3600
```

### E. File Structure

```
backend/
├── src/
│   └── modules/
│       └── bill-payment/
│           ├── bill-payment.controller.ts
│           ├── bill-payment.service.ts
│           └── bill-payment.module.ts
├── prisma/
│   ├── schema.prisma
│   └── seed.ts
└── docs/
    └── BILL_PAYMENT_API.md
```

---

## Support

For issues or questions:
1. Check this documentation
2. Review error messages
3. Check server logs
4. Contact development team

---

**Document Version:** 1.0  
**Last Updated:** 2024  
**Maintained By:** Development Team
