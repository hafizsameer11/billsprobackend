# Crypto and Bill Payment Seeders - Quick Reference

This document provides a quick reference for all seed data used in the crypto and bill payment systems.

## Quick Setup

```bash
# 1. Run migrations
npx prisma migrate dev

# 2. Run seeders
npx prisma db seed
```

---

## Crypto Wallet Currencies

### Supported Blockchains and Currencies

| Blockchain | Currency | Symbol | Type | Contract Address | Decimals |
|------------|----------|--------|------|------------------|----------|
| ethereum | ETH | ETH | Native | - | 18 |
| ethereum | USDT | USDT | Token | 0xdac17f958d2ee523a2206206994597c13d831ec7 | 6 |
| ethereum | USDC | USDC | Token | 0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48 | 6 |
| tron | TRX | TRX | Native | - | 18 |
| tron | USDT_TRON | USDT | Token | TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t | 6 |
| bsc | BNB | BNB | Native | - | 18 |
| bsc | USDT_BSC | USDT | Token | 0x55d398326f99059fF775485246999027B3197955 | 18 |
| bitcoin | BTC | BTC | Native | - | 8 |
| solana | SOL | SOL | Native | - | 9 |
| solana | USDT_SOL | USDT | Token | Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB | 6 |
| polygon | MATIC | MATIC | Native | - | 18 |
| polygon | USDT_POLYGON | USDT | Token | 0xc2132D05D31c914a87C6611C10748AEb04B58e8F | 6 |
| dogecoin | DOGE | DOGE | Native | - | 8 |
| xrp | XRP | XRP | Native | - | 6 |
| litecoin | LTC | LTC | Native | - | 8 |

**Total: 16 wallet currencies across 9 blockchains**

---

## Bill Payment Categories

| Code | Name | Description |
|------|------|-------------|
| airtime | Airtime | Mobile airtime recharge |
| data | Data | Mobile data plans and bundles |
| electricity | Electricity | Electricity bill payments |
| cable_tv | Cable TV | Cable TV and streaming subscriptions |
| betting | Betting | Sports betting platform funding |
| internet | Internet Subscription | Internet router subscriptions |

---

## Bill Payment Providers

### Airtime Providers
- **MTN** (code: MTN)
- **GLO** (code: GLO)
- **Airtel** (code: AIRTEL)

### Data Providers
- **MTN** (code: MTN)
- **GLO** (code: GLO)
- **Airtel** (code: AIRTEL)

### Electricity Providers
- **Ikeja Electric** (code: IKEJA)
- **Ibadan Electric** (code: IBADAN)
- **Abuja Electric** (code: ABUJA)

### Cable TV Providers
- **DSTV** (code: DSTV)
- **Showmax** (code: SHOWMAX)
- **GOtv** (code: GOTV)

### Betting Providers
- **1xBet** (code: 1XBET)
- **Bet9ja** (code: BET9JA)
- **SportBet** (code: SPORTBET)

---

## Data Plans

### MTN Data Plans
| Code | Name | Amount (NGN) | Data | Validity |
|------|------|---------------|------|----------|
| MTN_100MB | 100MB | 100 | 100MB | 1 day |
| MTN_200MB | 200MB | 200 | 200MB | 3 days |
| MTN_500MB | 500MB | 500 | 500MB | 7 days |
| MTN_1GB | 1GB | 1,000 | 1GB | 30 days |
| MTN_2GB | 2GB | 2,000 | 2GB | 30 days |
| MTN_5GB | 5GB | 5,000 | 5GB | 30 days |
| MTN_10GB | 10GB | 10,000 | 10GB | 30 days |

### GLO Data Plans
| Code | Name | Amount (NGN) | Data | Validity |
|------|------|---------------|------|----------|
| GLO_100MB | 100MB | 100 | 100MB | 1 day |
| GLO_200MB | 200MB | 200 | 200MB | 3 days |
| GLO_500MB | 500MB | 500 | 500MB | 7 days |
| GLO_1GB | 1GB | 1,000 | 1GB | 30 days |
| GLO_2GB | 2GB | 2,000 | 2GB | 30 days |
| GLO_5GB | 5GB | 5,000 | 5GB | 30 days |
| GLO_10GB | 10GB | 10,000 | 10GB | 30 days |

### Airtel Data Plans
| Code | Name | Amount (NGN) | Data | Validity |
|------|------|---------------|------|----------|
| AIRTEL_100MB | 100MB | 100 | 100MB | 1 day |
| AIRTEL_200MB | 200MB | 200 | 200MB | 3 days |
| AIRTEL_500MB | 500MB | 500 | 500MB | 7 days |
| AIRTEL_1GB | 1GB | 1,000 | 1GB | 30 days |
| AIRTEL_2GB | 2GB | 2,000 | 2GB | 30 days |
| AIRTEL_5GB | 5GB | 5,000 | 5GB | 30 days |
| AIRTEL_10GB | 10GB | 10,000 | 10GB | 30 days |

**Total: 21 data plans (7 per provider)**

---

## Cable TV Plans

### DSTV Plans
| Code | Name | Amount (NGN) | Validity |
|------|------|--------------|----------|
| DSTV_COMPACT | DSTV Compact | 7,900 | 1 month |
| DSTV_COMPACT_PLUS | DSTV Compact Plus | 12,900 | 1 month |
| DSTV_PREMIUM | DSTV Premium | 24,500 | 1 month |
| DSTV_ASIAN | DSTV Asian | 1,900 | 1 month |
| DSTV_PIDGIN | DSTV Pidgin | 2,650 | 1 month |

### Showmax Plans
| Code | Name | Amount (NGN) | Validity |
|------|------|--------------|----------|
| SHOWMAX_MOBILE | Showmax Mobile | 1,200 | 1 month |
| SHOWMAX_STANDARD | Showmax Standard | 2,900 | 1 month |
| SHOWMAX_PRO | Showmax Pro | 4,900 | 1 month |

### GOtv Plans
| Code | Name | Amount (NGN) | Validity |
|------|------|--------------|----------|
| GOTV_SMALLIE | GOtv Smallie | 1,650 | 1 month |
| GOTV_JINJA | GOtv Jinja | 2,650 | 1 month |
| GOTV_JINJA_PLUS | GOtv Jinja Plus | 3,250 | 1 month |
| GOTV_MAX | GOtv Max | 5,650 | 1 month |

**Total: 12 cable TV plans**

---

## Countries Seeded

| Code | Name | Flag |
|------|------|------|
| NG | Nigeria | ngn.png |
| KE | Kenya | kenya.png |
| GH | Ghana | ghana-c.png |
| ZA | South Africa | south-africa.png |
| TZ | Tanzania | tanzania.png |
| UG | Uganda | uganda.png |
| BW | Botswana | botswana.png |
| US | United States | - |
| GB | United Kingdom | - |
| CA | Canada | - |
| AU | Australia | - |
| DE | Germany | - |
| FR | France | - |
| IN | India | - |
| CN | China | - |
| JP | Japan | - |
| BR | Brazil | - |
| MX | Mexico | - |
| ES | Spain | - |
| IT | Italy | - |

**Total: 20 countries**

---

## Currencies Seeded

### Fiat Currencies
- NGN (Nigerian Naira) - Base currency
- KES (Kenyan Shilling)
- GHS (Ghanaian Cedi)
- ZAR (South African Rand)
- TZS (Tanzanian Shilling)
- UGX (Ugandan Shilling)
- USD (US Dollar)
- GBP (British Pound)
- EUR (Euro)

### Crypto Currencies
- BTC (Bitcoin)
- ETH (Ethereum)
- USDT (Tether)

**Total: 12 currencies (9 fiat + 3 crypto)**

---

## Exchange Rates

Key exchange rates seeded (NGN as base):

| From | To | Rate | Notes |
|------|----|----|-------|
| NGN | USD | 0.0012 | ~833 NGN = 1 USD |
| NGN | EUR | 0.0011 | ~909 NGN = 1 EUR |
| NGN | GBP | 0.00095 | ~1053 NGN = 1 GBP |
| NGN | KES | 0.15 | ~6.67 KES = 1 NGN |
| NGN | GHS | 0.012 | ~83.33 GHS = 1 NGN |
| USD | NGN | 833.33 | Reverse rate |
| USDT | NGN | 833.33 | Pegged to USD |
| USDT | USD | 1.0 | Pegged |

**Total: 50+ exchange rate pairs**

---

## Bank Accounts Seeded

### Nigeria (NGN) - 10 Banks
1. Access Bank
2. GTBank
3. First Bank of Nigeria
4. Zenith Bank
5. UBA
6. Fidelity Bank
7. Stanbic IBTC
8. Ecobank
9. Union Bank
10. Sterling Bank

### Other Countries
- Kenya (KES): Sample Bank Kenya
- Ghana (GHS): Sample Bank Ghana
- South Africa (ZAR): Sample Bank South Africa

**Total: 13 bank accounts**

---

## Mobile Money Providers

### Kenya (KES)
- MTN
- Vodafone
- M-Pesa
- Airtel Money

### Ghana (GHS)
- MTN Mobile Money
- Vodafone Cash
- AirtelTigo Money

### Nigeria (NGN)
- MTN MoMo
- Airtel Money

### Tanzania (TZS)
- M-Pesa
- Tigo Pesa
- Airtel Money

### Uganda (UGX)
- MTN Mobile Money
- Airtel Money

### South Africa (ZAR)
- MTN Mobile Money
- Vodacom M-Pesa

**Total: 16 mobile money providers across 6 countries**

---

## Summary Statistics

| Category | Count |
|----------|-------|
| Wallet Currencies (Crypto) | 16 |
| Bill Payment Categories | 6 |
| Bill Payment Providers | 15 |
| Data Plans | 21 |
| Cable TV Plans | 12 |
| Countries | 20 |
| Currencies | 12 |
| Exchange Rate Pairs | 50+ |
| Bank Accounts | 13 |
| Mobile Money Providers | 16 |

---

## Usage in Code

### Get All Wallet Currencies
```typescript
const currencies = await prisma.walletCurrency.findMany();
```

### Get Bill Payment Categories
```typescript
const categories = await prisma.billPaymentCategory.findMany({
  where: { isActive: true }
});
```

### Get Providers by Category
```typescript
const providers = await prisma.billPaymentProvider.findMany({
  where: {
    categoryId: categoryId,
    isActive: true
  }
});
```

### Get Plans by Provider
```typescript
const plans = await prisma.billPaymentPlan.findMany({
  where: {
    providerId: providerId,
    isActive: true
  }
});
```

---

## Notes

1. **Logo URLs**: Provider logos should be placed in `/uploads/billpayments/` directory
2. **Currency Icons**: Crypto currency icons should be placed in `/uploads/wallet_symbols/` directory
3. **Country Flags**: Country flags should be placed in `/uploads/flags/` directory
4. **Active Status**: All seeded data is set to `isActive: true` by default
5. **Amounts**: All bill payment amounts are in NGN (Nigerian Naira) by default
6. **Metadata**: Electricity providers have metadata with `meterTypes: ['prepaid', 'postpaid']`

---

## Customization

To customize for your region:

1. **Update Providers**: Modify provider list in seed file
2. **Update Plans**: Adjust plan amounts and validity periods
3. **Add Countries**: Add more countries as needed
4. **Add Currencies**: Add more fiat/crypto currencies
5. **Update Exchange Rates**: Set current exchange rates
6. **Add Bank Accounts**: Add your actual bank account details
7. **Update Logo URLs**: Point to your actual logo file paths

---

## File Locations

- **Main Seed File**: `backend/prisma/seed.ts`
- **Schema File**: `backend/prisma/schema.prisma`
- **Documentation**: `CRYPTO_AND_BILL_PAYMENT_SETUP.md`

---

## Quick Commands

```bash
# Reset and reseed database
npx prisma migrate reset

# Seed only (without reset)
npx prisma db seed

# Generate Prisma Client
npx prisma generate

# View database in Prisma Studio
npx prisma studio
```
