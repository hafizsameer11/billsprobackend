# Crypto and Bill Payment Setup Documentation

Complete guide for setting up crypto payment and bill payment systems in your backend.

## Table of Contents

1. [Overview](#overview)
2. [Database Schema](#database-schema)
3. [Crypto Payment Setup](#crypto-payment-setup)
4. [Bill Payment Setup](#bill-payment-setup)
5. [Environment Variables](#environment-variables)
6. [Database Seeding](#database-seeding)
7. [API Endpoints](#api-endpoints)
8. [Integration Guide](#integration-guide)

---

## Overview

This system provides:

### Crypto Payment System
- Multi-blockchain support (Bitcoin, Ethereum, TRON, BSC, Solana, Polygon, etc.)
- Virtual accounts for each user per currency/blockchain
- Deposit address generation
- Wallet balance management
- Transaction tracking

### Bill Payment System
- 6 bill payment categories: Airtime, Data, Electricity, Cable TV, Betting, Internet
- Multiple providers per category
- Plan/bundle management for Data and Cable TV
- Beneficiary management
- Transaction processing with PIN verification

---

## Database Schema

### Crypto Payment Models

#### WalletCurrency
Stores supported cryptocurrencies and tokens per blockchain.

```prisma
model WalletCurrency {
  id              Int              @id @default(autoincrement())
  blockchain      String           @db.VarChar(255)
  currency        String           @db.VarChar(50)
  symbol          String?          @db.VarChar(255)
  name            String           @db.VarChar(255)
  icon            String?
  price           Decimal?         @db.Decimal(20, 8)
  nairaPrice      Decimal?         @map("naira_price") @db.Decimal(20, 8)
  tokenType       String?          @map("token_type") @db.VarChar(50)
  contractAddress String?          @map("contract_address") @db.VarChar(255)
  decimals        Int              @default(18)
  isToken         Boolean          @default(false) @map("is_token")
  blockchainName  String?          @map("blockchain_name") @db.VarChar(255)
  createdAt       DateTime         @default(now()) @map("created_at")
  updatedAt       DateTime         @updatedAt @map("updated_at")
  virtualAccounts VirtualAccount[]

  @@unique([blockchain, currency])
  @@map("wallet_currencies")
}
```

#### VirtualAccount
User's crypto wallet account per currency/blockchain.

```prisma
model VirtualAccount {
  id                 Int              @id @default(autoincrement())
  userId             Int              @map("user_id")
  blockchain         String           @db.VarChar(255)
  currency           String           @db.VarChar(50)
  customerId         String?          @map("customer_id") @db.VarChar(255)
  accountId          String           @unique @map("account_id") @db.VarChar(255)
  accountCode        String?          @map("account_code") @db.VarChar(255)
  active             Boolean          @default(true)
  frozen             Boolean          @default(false)
  accountBalance     String           @default("0") @map("account_balance") @db.VarChar(255)
  availableBalance   String           @default("0") @map("available_balance") @db.VarChar(255)
  xpub               String?          @db.VarChar(500)
  accountingCurrency String?          @map("accounting_currency") @db.VarChar(50)
  currencyId         Int?             @map("currency_id")
  createdAt          DateTime         @default(now()) @map("created_at")
  updatedAt          DateTime         @updatedAt @map("updated_at")
  user               User             @relation(fields: [userId], references: [id], onDelete: Cascade)
  walletCurrency     WalletCurrency?  @relation(fields: [currencyId], references: [id])
  depositAddresses   DepositAddress[]

  @@unique([userId, blockchain, currency])
  @@map("virtual_accounts")
}
```

#### DepositAddress
Deposit addresses for receiving crypto.

```prisma
model DepositAddress {
  id               Int            @id @default(autoincrement())
  virtualAccountId Int            @map("virtual_account_id")
  userWalletId     Int?           @map("user_wallet_id")
  blockchain       String?        @db.VarChar(255)
  currency         String?        @db.VarChar(50)
  address          String         @db.VarChar(255)
  index            Int?           @db.Int
  privateKey       String?        @map("private_key") @db.Text // Encrypted
  createdAt        DateTime       @default(now()) @map("created_at")
  updatedAt        DateTime       @updatedAt @map("updated_at")
  virtualAccount   VirtualAccount @relation(fields: [virtualAccountId], references: [id], onDelete: Cascade)
  userWallet       UserWallet?    @relation(fields: [userWalletId], references: [id], onDelete: SetNull)

  @@index([address])
  @@map("deposit_addresses")
}
```

#### UserWallet
User's master wallet per blockchain.

```prisma
model UserWallet {
  id               Int              @id @default(autoincrement())
  userId           Int              @map("user_id")
  blockchain       String           @db.VarChar(255)
  mnemonic         String?          @db.Text // Encrypted
  xpub             String?          @db.VarChar(500)
  derivationPath   String?          @map("derivation_path") @db.VarChar(100)
  createdAt        DateTime         @default(now()) @map("created_at")
  updatedAt        DateTime         @updatedAt @map("updated_at")
  user             User             @relation(fields: [userId], references: [id], onDelete: Cascade)
  depositAddresses DepositAddress[]

  @@unique([userId, blockchain])
  @@map("user_wallets")
}
```

### Bill Payment Models

#### BillPaymentCategory
Bill payment categories.

```prisma
model BillPaymentCategory {
  id          Int      @id @default(autoincrement())
  code        String   @unique @db.VarChar(50) // airtime, data, electricity, cable_tv, betting, internet
  name        String   @db.VarChar(100)
  description String?  @db.Text
  isActive    Boolean  @default(true) @map("is_active")
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")
  providers     BillPaymentProvider[]
  beneficiaries Beneficiary[]

  @@map("bill_payment_categories")
}
```

#### BillPaymentProvider
Providers within each category.

```prisma
model BillPaymentProvider {
  id          Int      @id @default(autoincrement())
  categoryId  Int      @map("category_id")
  code        String   @db.VarChar(50) // MTN, GLO, AIRTEL, DSTV, etc.
  name        String   @db.VarChar(100)
  logoUrl     String?  @map("logo_url")
  countryCode String   @default("NG") @map("country_code") @db.VarChar(10)
  currency    String   @default("NGN") @db.VarChar(10)
  isActive    Boolean  @default(true) @map("is_active")
  metadata    Json? // Additional provider-specific data
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")
  category      BillPaymentCategory @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  plans         BillPaymentPlan[]
  beneficiaries Beneficiary[]

  @@unique([categoryId, code])
  @@map("bill_payment_providers")
}
```

#### BillPaymentPlan
Plans/bundles for Data and Cable TV providers.

```prisma
model BillPaymentPlan {
  id          Int      @id @default(autoincrement())
  providerId  Int      @map("provider_id")
  code        String   @db.VarChar(100) // Unique plan code
  name        String   @db.VarChar(255)
  amount      Decimal  @db.Decimal(20, 8)
  currency    String   @default("NGN") @db.VarChar(10)
  dataAmount  String?  @map("data_amount") @db.VarChar(50) // For data plans: "1GB", "2GB", etc.
  validity    String?  @db.VarChar(50) // Validity period
  description String?  @db.Text
  isActive    Boolean  @default(true) @map("is_active")
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")
  provider BillPaymentProvider @relation(fields: [providerId], references: [id], onDelete: Cascade)

  @@map("bill_payment_plans")
}
```

#### Beneficiary
Saved beneficiaries for quick payments.

```prisma
model Beneficiary {
  id            Int      @id @default(autoincrement())
  userId        Int      @map("user_id")
  categoryId    Int      @map("category_id")
  providerId    Int      @map("provider_id")
  name          String?  @db.VarChar(255)
  accountNumber String   @map("account_number") @db.VarChar(255)
  accountType   String?  @map("account_type") @db.VarChar(50) // prepaid, postpaid
  isActive      Boolean  @default(true) @map("is_active")
  createdAt     DateTime @default(now()) @map("created_at")
  updatedAt     DateTime @updatedAt @map("updated_at")
  user     User                @relation(fields: [userId], references: [id], onDelete: Cascade)
  category BillPaymentCategory @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  provider BillPaymentProvider @relation(fields: [providerId], references: [id], onDelete: Cascade)

  @@map("beneficiaries")
}
```

---

## Crypto Payment Setup

### Supported Blockchains and Currencies

The system supports the following:

- **Ethereum**: ETH, USDT, USDC
- **TRON**: TRX, USDT_TRON
- **BSC (Binance Smart Chain)**: BNB, USDT_BSC
- **Bitcoin**: BTC
- **Solana**: SOL, USDT_SOL
- **Polygon**: MATIC, USDT_POLYGON
- **Dogecoin**: DOGE
- **XRP**: XRP
- **Litecoin**: LTC

### Key Features

1. **Virtual Account Creation**: Automatically creates virtual accounts for all supported currencies when user verifies email
2. **Deposit Address Generation**: Generates unique deposit addresses per currency/blockchain
3. **Balance Management**: Tracks account balance and available balance (accountBalance - lockedAmount)
4. **Multi-Token Support**: Supports native coins and ERC-20/TRC-20 tokens

### Initialization Flow

```typescript
// When user verifies email, call:
await cryptoService.initializeUserCryptoWallets(userId);

// This creates:
// 1. UserWallet per blockchain (if not exists)
// 2. VirtualAccount per WalletCurrency
// 3. DepositAddress for each VirtualAccount
```

### API Endpoints

#### Get Virtual Accounts
```
GET /api/crypto/virtual-accounts
```
Returns all user's crypto virtual accounts with balances.

#### Get Deposit Address
```
GET /api/crypto/deposit-address/:currency/:blockchain
```
Returns deposit address for specific currency/blockchain.

#### Get USDT Tokens (Public)
```
GET /api/crypto/usdt-tokens
```
Returns all USDT variants across blockchains.

#### Get Tokens by Symbol (Public)
```
GET /api/crypto/tokens/:symbol
```
Returns all tokens matching symbol (e.g., USDT, USDC).

---

## Bill Payment Setup

### Supported Categories

1. **Airtime**: Mobile airtime recharge
2. **Data**: Mobile data bundles
3. **Electricity**: Electricity bill payments (prepaid/postpaid)
4. **Cable TV**: Cable TV subscriptions
5. **Betting**: Sports betting platform funding
6. **Internet**: Internet router subscriptions

### Supported Providers

#### Airtime & Data
- MTN
- GLO
- Airtel

#### Electricity
- Ikeja Electric
- Ibadan Electric
- Abuja Electric

#### Cable TV
- DSTV
- Showmax
- GOtv

#### Betting
- 1xBet
- Bet9ja
- SportBet

### Payment Flow

```
1. Get Categories
2. Get Providers (by category)
3. Get Plans (for Data/Cable TV only)
4. Validate Account/Meter (Electricity/Betting only)
5. Initiate Payment (preview)
6. Confirm Payment (with PIN)
```

### Fee Calculation

- Default: 1% of amount
- Minimum fees:
  - NGN: 20
  - USD: 0.1
  - KES: 2
  - GHS: 0.5

### API Endpoints

#### Get Categories
```
GET /api/bill-payment/categories
```

#### Get Providers
```
GET /api/bill-payment/providers?categoryCode=airtime&countryCode=NG
```

#### Get Plans
```
GET /api/bill-payment/plans?providerId=1
```

#### Validate Meter (Electricity)
```
POST /api/bill-payment/validate-meter
Body: {
  "providerId": 7,
  "meterNumber": "1234567890",
  "accountType": "prepaid"
}
```

#### Validate Account (Betting)
```
POST /api/bill-payment/validate-account
Body: {
  "providerId": 13,
  "accountNumber": "12345"
}
```

#### Initiate Payment
```
POST /api/bill-payment/initiate
Body: {
  "categoryCode": "airtime",
  "providerId": 1,
  "currency": "NGN",
  "amount": "1000",
  "accountNumber": "08012345678"
}
```

#### Confirm Payment
```
POST /api/bill-payment/confirm
Body: {
  "categoryCode": "airtime",
  "providerId": 1,
  "currency": "NGN",
  "amount": "1000",
  "accountNumber": "08012345678",
  "pin": "1234"
}
```

#### Beneficiaries
```
GET /api/bill-payment/beneficiaries
POST /api/bill-payment/beneficiaries
PUT /api/bill-payment/beneficiaries/:id
DELETE /api/bill-payment/beneficiaries/:id
```

---

## Environment Variables

### Required Variables

```env
# Database
DATABASE_URL=mysql://username:password@host:port/database_name

# Encryption (MUST be exactly 32 characters)
ENCRYPTION_KEY=your-32-character-encryption-key-here!!

# JWT
JWT_SECRET=your-256-bit-secret-key
JWT_EXPIRES_IN=3600
REFRESH_TOKEN_SECRET=your-refresh-token-secret
REFRESH_TOKEN_EXPIRES_IN=2592000

# Application
NODE_ENV=development
PORT=3000
BASE_URL=http://localhost:3000
```

### Optional Variables (for Tatum integration if needed)

```env
# Tatum API (if using external wallet service)
TATUM_API_KEY=your_tatum_api_key_here
TATUM_BASE_URL=https://api.tatum.io/v3
TATUM_WEBHOOK_URL=https://yourdomain.com/api/crypto/webhook/tatum
```

---

## Database Seeding

### Step 1: Run Migrations

```bash
cd backend
npx prisma migrate dev
# or
npm run prisma:migrate
```

### Step 2: Run Seeders

```bash
npx prisma db seed
# or
npm run seed
```

The seed file (`backend/prisma/seed.ts`) will automatically seed:

1. **Countries** (20+ countries)
2. **Currencies** (Fiat and Crypto)
3. **Wallet Currencies** (All supported crypto currencies)
4. **Bank Accounts** (Sample bank accounts for deposits)
5. **Mobile Money Providers** (Multiple countries)
6. **Exchange Rates** (Currency conversion rates)
7. **Bill Payment Categories** (6 categories)
8. **Bill Payment Providers** (All providers per category)
9. **Bill Payment Plans** (Data plans and Cable TV plans)

### Seed Data Summary

#### Wallet Currencies (Crypto)
- Ethereum: ETH, USDT, USDC
- TRON: TRX, USDT_TRON
- BSC: BNB, USDT_BSC
- Bitcoin: BTC
- Solana: SOL, USDT_SOL
- Polygon: MATIC, USDT_POLYGON
- Dogecoin: DOGE
- XRP: XRP
- Litecoin: LTC

#### Bill Payment Categories
- airtime
- data
- electricity
- cable_tv
- betting
- internet

#### Bill Payment Providers
- **Airtime/Data**: MTN, GLO, Airtel
- **Electricity**: Ikeja Electric, Ibadan Electric, Abuja Electric
- **Cable TV**: DSTV, Showmax, GOtv
- **Betting**: 1xBet, Bet9ja, SportBet

#### Data Plans
- 100MB, 200MB, 500MB, 1GB, 2GB, 5GB, 10GB (for each provider)

#### Cable TV Plans
- **DSTV**: Compact, Compact Plus, Premium, Asian, Pidgin
- **Showmax**: Mobile, Standard, Pro
- **GOtv**: Smallie, Jinja, Jinja Plus, Max

---

## Integration Guide

### 1. Install Dependencies

```bash
npm install @prisma/client prisma decimal.js bcryptjs
npm install -D @types/node @types/bcryptjs
```

### 2. Setup Database

```bash
# Create database
mysql -u root -p
CREATE DATABASE your_database_name;
EXIT;

# Update DATABASE_URL in .env
DATABASE_URL=mysql://user:password@localhost:3306/your_database_name
```

### 3. Run Migrations

```bash
npx prisma migrate dev --name init
```

### 4. Seed Database

```bash
npx prisma db seed
```

### 5. Initialize Crypto Wallets for Users

When a user verifies their email, call:

```typescript
import { CryptoService } from './modules/crypto/crypto.service';

const cryptoService = new CryptoService();
await cryptoService.initializeUserCryptoWallets(userId);
```

### 6. Use Bill Payment Service

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
const result = await billPaymentService.confirmBillPayment(userId, transactionId, pin);
```

---

## Important Notes

### Crypto Payment

1. **Virtual Accounts**: Created automatically when user verifies email
2. **Deposit Addresses**: Generated on-demand when user requests
3. **Balance Tracking**: Uses `accountBalance` (total) and `availableBalance` (unlocked)
4. **Frozen Balance**: Calculated as `accountBalance - availableBalance`
5. **Multi-Blockchain**: Each blockchain has separate UserWallet
6. **Token Support**: Supports both native coins and tokens (ERC-20, TRC-20, etc.)

### Bill Payment

1. **Initiate vs Confirm**: 
   - `initiate` = Preview only, creates pending transaction
   - `confirm` = Completes transaction, deducts balance, requires PIN

2. **Amount Handling**:
   - Data/Cable TV: Use `planId`, `amount` is ignored
   - Others: `amount` is required

3. **Validation**:
   - Electricity: Must validate meter before initiate
   - Betting: Must validate account before initiate
   - Others: No validation needed

4. **Plans**:
   - Only Data and Cable TV use plans
   - Airtime, Electricity, Betting: User enters custom amount

5. **Beneficiaries**:
   - Optional feature for saving frequently used accounts
   - Can be used instead of typing account number each time

---

## Troubleshooting

### Crypto Issues

**Problem**: Virtual accounts not created
- **Solution**: Ensure `initializeUserCryptoWallets()` is called after email verification

**Problem**: Deposit address not generated
- **Solution**: Check that WalletCurrency exists in database for that blockchain/currency

**Problem**: Balance not updating
- **Solution**: Check webhook processing (if using external service) or manual balance updates

### Bill Payment Issues

**Problem**: Provider not found
- **Solution**: Ensure seeders have run and provider exists in database

**Problem**: Plan not found
- **Solution**: Check that plan exists for the provider and is active

**Problem**: Insufficient balance
- **Solution**: Ensure user has enough balance (amount + fee)

**Problem**: Invalid PIN
- **Solution**: User must set PIN before making payments

---

## Support

For issues or questions:
1. Check the seed data in `backend/prisma/seed.ts`
2. Review API documentation in `backend/docs/BILL_PAYMENT_API.md`
3. Check database schema in `backend/prisma/schema.prisma`

---

## Next Steps

1. Customize providers and plans for your region
2. Integrate with actual bill payment APIs (currently uses mock validation)
3. Set up webhook handlers for crypto deposits (if using external service)
4. Add more currencies/blockchains as needed
5. Customize fee structure
6. Add payment provider logos to `/uploads/billpayments/`
