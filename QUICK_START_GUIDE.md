# Quick Start Guide - Crypto & Bill Payment Setup

This guide will help you quickly set up the crypto and bill payment systems in your backend.

## 📋 Prerequisites

- Node.js 20+
- MySQL 8.0+
- Prisma installed
- TypeScript configured

## 🚀 Quick Setup (5 Steps)

### Step 1: Copy Files

Copy these files to your backend project:
- `STANDALONE_SEEDER.ts` → `prisma/seed.ts` (or merge with existing seed file)
- `CRYPTO_AND_BILL_PAYMENT_SETUP.md` → Keep for reference
- `SEEDERS_QUICK_REFERENCE.md` → Keep for reference

### Step 2: Install Dependencies

```bash
npm install @prisma/client prisma decimal.js
npm install -D @types/node
```

### Step 3: Ensure Database Schema

Make sure your Prisma schema includes these models:
- `WalletCurrency`
- `VirtualAccount`
- `DepositAddress`
- `UserWallet`
- `BillPaymentCategory`
- `BillPaymentProvider`
- `BillPaymentPlan`
- `Beneficiary`

See `backend/prisma/schema.prisma` for complete schema.

### Step 4: Run Migrations

```bash
npx prisma migrate dev
# or
npm run prisma:migrate
```

### Step 5: Seed Database

```bash
npx prisma db seed
# or
npm run seed
```

**Done!** Your database is now seeded with:
- ✅ 16 crypto wallet currencies
- ✅ 6 bill payment categories
- ✅ 15 bill payment providers
- ✅ 21 data plans
- ✅ 12 cable TV plans

---

## 📝 Integration Steps

### 1. Initialize Crypto Wallets for Users

When a user verifies their email, call:

```typescript
import { CryptoService } from './modules/crypto/crypto.service';

const cryptoService = new CryptoService();
await cryptoService.initializeUserCryptoWallets(userId);
```

This creates:
- UserWallet per blockchain
- VirtualAccount per currency
- DepositAddress for each account

### 2. Use Bill Payment Service

```typescript
import { BillPaymentService } from './modules/bill-payment/bill-payment.service';

const billPaymentService = new BillPaymentService();

// Get categories
const categories = await billPaymentService.getCategories();

// Get providers
const providers = await billPaymentService.getProvidersByCategory('airtime');

// Initiate payment (preview)
const preview = await billPaymentService.initiateBillPayment(userId, {
  categoryCode: 'airtime',
  providerId: 1,
  currency: 'NGN',
  amount: '1000',
  accountNumber: '08012345678'
});

// Confirm payment (with PIN)
const result = await billPaymentService.confirmBillPayment(
  userId, 
  transactionId, 
  pin
);
```

---

## 🔧 Environment Variables

Add to your `.env` file:

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

---

## 📚 API Endpoints

### Crypto Endpoints

```
GET  /api/crypto/virtual-accounts          # Get user's crypto wallets
GET  /api/crypto/deposit-address/:currency/:blockchain  # Get deposit address
GET  /api/crypto/usdt-tokens               # Get all USDT variants (public)
GET  /api/crypto/tokens/:symbol            # Get tokens by symbol (public)
```

### Bill Payment Endpoints

```
GET  /api/bill-payment/categories          # Get all categories
GET  /api/bill-payment/providers            # Get providers by category
GET  /api/bill-payment/plans               # Get plans by provider
POST /api/bill-payment/validate-meter      # Validate electricity meter
POST /api/bill-payment/validate-account    # Validate betting account
POST /api/bill-payment/initiate            # Initiate payment (preview)
POST /api/bill-payment/confirm             # Confirm payment (with PIN)
GET  /api/bill-payment/beneficiaries        # Get saved beneficiaries
POST /api/bill-payment/beneficiaries        # Create beneficiary
PUT  /api/bill-payment/beneficiaries/:id    # Update beneficiary
DELETE /api/bill-payment/beneficiaries/:id  # Delete beneficiary
```

---

## 📊 What Gets Seeded

### Crypto (16 currencies)
- Ethereum: ETH, USDT, USDC
- TRON: TRX, USDT_TRON
- BSC: BNB, USDT_BSC
- Bitcoin: BTC
- Solana: SOL, USDT_SOL
- Polygon: MATIC, USDT_POLYGON
- Dogecoin: DOGE
- XRP: XRP
- Litecoin: LTC

### Bill Payment
- **6 Categories**: Airtime, Data, Electricity, Cable TV, Betting, Internet
- **15 Providers**: MTN, GLO, Airtel, Ikeja Electric, DSTV, etc.
- **21 Data Plans**: 100MB to 10GB for each provider
- **12 Cable TV Plans**: DSTV, Showmax, GOtv plans

---

## 🎯 Common Use Cases

### Use Case 1: User Wants to Buy Airtime

```typescript
// 1. Get categories
const categories = await billPaymentService.getCategories();
// Returns: [{ code: 'airtime', name: 'Airtime', ... }]

// 2. Get providers
const providers = await billPaymentService.getProvidersByCategory('airtime');
// Returns: [{ id: 1, code: 'MTN', name: 'MTN', ... }, ...]

// 3. Initiate payment
const preview = await billPaymentService.initiateBillPayment(userId, {
  categoryCode: 'airtime',
  providerId: 1, // MTN
  currency: 'NGN',
  amount: '1000',
  accountNumber: '08012345678'
});

// 4. Show preview to user, then confirm
const result = await billPaymentService.confirmBillPayment(
  userId,
  preview.transactionId,
  userPin
);
```

### Use Case 2: User Wants to Buy Data Bundle

```typescript
// 1. Get providers
const providers = await billPaymentService.getProvidersByCategory('data');

// 2. Get plans
const plans = await billPaymentService.getPlansByProvider(1); // MTN
// Returns: [{ id: 4, code: 'MTN_1GB', name: '1GB', amount: '1000', ... }, ...]

// 3. Initiate with planId
const preview = await billPaymentService.initiateBillPayment(userId, {
  categoryCode: 'data',
  providerId: 1,
  currency: 'NGN',
  planId: 4, // 1GB plan
  accountNumber: '08012345678'
});

// 4. Confirm
const result = await billPaymentService.confirmBillPayment(
  userId,
  preview.transactionId,
  userPin
);
```

### Use Case 3: User Wants Crypto Deposit Address

```typescript
// Get deposit address for USDT on Ethereum
const address = await cryptoService.getDepositAddress(
  userId,
  'USDT',
  'ethereum'
);
// Returns: { address: '0x...', currency: 'USDT', blockchain: 'ethereum' }
```

---

## ⚠️ Important Notes

1. **Crypto Wallets**: Must be initialized when user verifies email
2. **Bill Payment PIN**: User must set PIN before making payments
3. **Initiate vs Confirm**: 
   - `initiate` = Preview only (creates pending transaction)
   - `confirm` = Completes payment (requires PIN)
4. **Plans**: Only Data and Cable TV use plans; others use custom amounts
5. **Validation**: Electricity and Betting require validation before payment

---

## 🔍 Verification

After seeding, verify data:

```typescript
// Check wallet currencies
const currencies = await prisma.walletCurrency.findMany();
console.log(`Wallet currencies: ${currencies.length}`); // Should be 16

// Check bill categories
const categories = await prisma.billPaymentCategory.findMany();
console.log(`Categories: ${categories.length}`); // Should be 6

// Check providers
const providers = await prisma.billPaymentProvider.findMany();
console.log(`Providers: ${providers.length}`); // Should be 15

// Check data plans
const dataPlans = await prisma.billPaymentPlan.findMany({
  where: {
    provider: {
      category: { code: 'data' }
    }
  }
});
console.log(`Data plans: ${dataPlans.length}`); // Should be 21

// Check cable plans
const cablePlans = await prisma.billPaymentPlan.findMany({
  where: {
    provider: {
      category: { code: 'cable_tv' }
    }
  }
});
console.log(`Cable plans: ${cablePlans.length}`); // Should be 12
```

---

## 📖 Documentation Files

- **CRYPTO_AND_BILL_PAYMENT_SETUP.md** - Complete setup documentation
- **SEEDERS_QUICK_REFERENCE.md** - Quick reference for all seed data
- **STANDALONE_SEEDER.ts** - Standalone seeder file
- **backend/docs/BILL_PAYMENT_API.md** - Detailed API documentation

---

## 🆘 Troubleshooting

### Issue: Seed fails with "model not found"
**Solution**: Ensure Prisma schema includes all required models

### Issue: Virtual accounts not created
**Solution**: Call `initializeUserCryptoWallets()` after email verification

### Issue: Provider not found
**Solution**: Run seeders: `npx prisma db seed`

### Issue: Plan not found
**Solution**: Check that plan exists and is active in database

---

## ✅ Checklist

- [ ] Database schema includes all models
- [ ] Dependencies installed
- [ ] Migrations run
- [ ] Seeders run successfully
- [ ] Environment variables set
- [ ] Crypto service initialized
- [ ] Bill payment service working
- [ ] API endpoints tested

---

## 🎉 You're Ready!

Your crypto and bill payment systems are now set up and ready to use!

For detailed information, see:
- `CRYPTO_AND_BILL_PAYMENT_SETUP.md` - Complete documentation
- `SEEDERS_QUICK_REFERENCE.md` - Seed data reference
- `backend/docs/BILL_PAYMENT_API.md` - API documentation
