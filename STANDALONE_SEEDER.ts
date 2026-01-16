/**
 * STANDALONE SEEDER FOR CRYPTO AND BILL PAYMENT SETUP
 * 
 * This file contains all seed data for:
 * 1. Crypto wallet currencies (blockchains and tokens)
 * 2. Bill payment categories, providers, and plans
 * 
 * USAGE:
 * 1. Copy this file to your backend project
 * 2. Update imports to match your project structure
 * 3. Run: npx prisma db seed
 * 
 * Make sure your Prisma schema matches the models referenced here.
 */

import { PrismaClient } from '@prisma/client';
import { Decimal } from 'decimal.js';

const prisma = new PrismaClient();

// ============================================
// CRYPTO WALLET CURRENCIES
// ============================================

const walletCurrencies = [
  // Ethereum
  { 
    blockchain: 'ethereum', 
    currency: 'ETH', 
    name: 'Ethereum', 
    symbol: 'ETH', 
    isToken: false, 
    decimals: 18 
  },
  { 
    blockchain: 'ethereum', 
    currency: 'USDT', 
    name: 'Tether USD', 
    symbol: 'USDT', 
    isToken: true, 
    contractAddress: '0xdac17f958d2ee523a2206206994597c13d831ec7', 
    decimals: 6 
  },
  { 
    blockchain: 'ethereum', 
    currency: 'USDC', 
    name: 'USD Coin', 
    symbol: 'USDC', 
    isToken: true, 
    contractAddress: '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 
    decimals: 6 
  },
  
  // TRON
  { 
    blockchain: 'tron', 
    currency: 'TRX', 
    name: 'Tron', 
    symbol: 'TRX', 
    isToken: false, 
    decimals: 18 
  },
  { 
    blockchain: 'tron', 
    currency: 'USDT_TRON', 
    name: 'Tether USD (TRON)', 
    symbol: 'USDT', 
    isToken: true, 
    contractAddress: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 
    decimals: 6 
  },
  
  // BSC (Binance Smart Chain)
  { 
    blockchain: 'bsc', 
    currency: 'BNB', 
    name: 'Binance Coin', 
    symbol: 'BNB', 
    isToken: false, 
    decimals: 18 
  },
  { 
    blockchain: 'bsc', 
    currency: 'USDT_BSC', 
    name: 'Tether USD (BSC)', 
    symbol: 'USDT', 
    isToken: true, 
    contractAddress: '0x55d398326f99059fF775485246999027B3197955', 
    decimals: 18 
  },
  
  // Bitcoin
  { 
    blockchain: 'bitcoin', 
    currency: 'BTC', 
    name: 'Bitcoin', 
    symbol: 'BTC', 
    isToken: false, 
    decimals: 8 
  },
  
  // Solana
  { 
    blockchain: 'solana', 
    currency: 'SOL', 
    name: 'Solana', 
    symbol: 'SOL', 
    isToken: false, 
    decimals: 9 
  },
  { 
    blockchain: 'solana', 
    currency: 'USDT_SOL', 
    name: 'Tether USD (Solana)', 
    symbol: 'USDT', 
    isToken: true, 
    contractAddress: 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB', 
    decimals: 6 
  },
  
  // Polygon
  { 
    blockchain: 'polygon', 
    currency: 'MATIC', 
    name: 'Polygon', 
    symbol: 'MATIC', 
    isToken: false, 
    decimals: 18 
  },
  { 
    blockchain: 'polygon', 
    currency: 'USDT_POLYGON', 
    name: 'Tether USD (Polygon)', 
    symbol: 'USDT', 
    isToken: true, 
    contractAddress: '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 
    decimals: 6 
  },
  
  // Dogecoin
  { 
    blockchain: 'dogecoin', 
    currency: 'DOGE', 
    name: 'Dogecoin', 
    symbol: 'DOGE', 
    isToken: false, 
    decimals: 8 
  },
  
  // XRP
  { 
    blockchain: 'xrp', 
    currency: 'XRP', 
    name: 'Ripple', 
    symbol: 'XRP', 
    isToken: false, 
    decimals: 6 
  },
  
  // Litecoin
  { 
    blockchain: 'litecoin', 
    currency: 'LTC', 
    name: 'Litecoin', 
    symbol: 'LTC', 
    isToken: false, 
    decimals: 8 
  },
];

// ============================================
// BILL PAYMENT CATEGORIES
// ============================================

const billCategories = [
  { code: 'airtime', name: 'Airtime', description: 'Mobile airtime recharge' },
  { code: 'data', name: 'Data', description: 'Mobile data plans and bundles' },
  { code: 'electricity', name: 'Electricity', description: 'Electricity bill payments' },
  { code: 'cable_tv', name: 'Cable TV', description: 'Cable TV and streaming subscriptions' },
  { code: 'betting', name: 'Betting', description: 'Sports betting platform funding' },
  { code: 'internet', name: 'Internet Subscription', description: 'Internet router subscriptions' },
];

// ============================================
// BILL PAYMENT PROVIDERS
// ============================================

const billProviders = [
  // Airtime providers
  { category: 'airtime', code: 'MTN', name: 'MTN', logoUrl: '/uploads/billpayments/mtn.png' },
  { category: 'airtime', code: 'GLO', name: 'GLO', logoUrl: '/uploads/billpayments/glo.png' },
  { category: 'airtime', code: 'AIRTEL', name: 'Airtel', logoUrl: '/uploads/billpayments/airtel.png' },
  
  // Data providers (same as airtime)
  { category: 'data', code: 'MTN', name: 'MTN', logoUrl: '/uploads/billpayments/mtn.png' },
  { category: 'data', code: 'GLO', name: 'GLO', logoUrl: '/uploads/billpayments/glo.png' },
  { category: 'data', code: 'AIRTEL', name: 'Airtel', logoUrl: '/uploads/billpayments/airtel.png' },
  
  // Electricity providers
  { 
    category: 'electricity', 
    code: 'IKEJA', 
    name: 'Ikeja Electric', 
    logoUrl: '/uploads/billpayments/ikeja.png', 
    metadata: { meterTypes: ['prepaid', 'postpaid'] } 
  },
  { 
    category: 'electricity', 
    code: 'IBADAN', 
    name: 'Ibadan Electric', 
    logoUrl: '/uploads/billpayments/ibandan.png', 
    metadata: { meterTypes: ['prepaid', 'postpaid'] } 
  },
  { 
    category: 'electricity', 
    code: 'ABUJA', 
    name: 'Abuja Electric', 
    logoUrl: '/uploads/billpayments/abuja.png', 
    metadata: { meterTypes: ['prepaid', 'postpaid'] } 
  },
  
  // Cable TV providers
  { category: 'cable_tv', code: 'DSTV', name: 'DSTV', logoUrl: '/uploads/billpayments/dstv.png' },
  { category: 'cable_tv', code: 'SHOWMAX', name: 'Showmax', logoUrl: '/uploads/billpayments/showmax.png' },
  { category: 'cable_tv', code: 'GOTV', name: 'GOtv', logoUrl: '/uploads/billpayments/gotv.png' },
  
  // Betting providers
  { category: 'betting', code: '1XBET', name: '1xBet', logoUrl: '/uploads/billpayments/1xbet.png' },
  { category: 'betting', code: 'BET9JA', name: 'Bet9ja', logoUrl: '/uploads/billpayments/bet9ja.png' },
  { category: 'betting', code: 'SPORTBET', name: 'SportBet', logoUrl: '/uploads/billpayments/sportbet.png' },
];

// ============================================
// DATA PLANS
// ============================================

const dataPlans = [
  // MTN Data Plans
  { providerKey: 'data_MTN', code: 'MTN_100MB', name: '100MB', amount: 100, dataAmount: '100MB', validity: '1 day' },
  { providerKey: 'data_MTN', code: 'MTN_200MB', name: '200MB', amount: 200, dataAmount: '200MB', validity: '3 days' },
  { providerKey: 'data_MTN', code: 'MTN_500MB', name: '500MB', amount: 500, dataAmount: '500MB', validity: '7 days' },
  { providerKey: 'data_MTN', code: 'MTN_1GB', name: '1GB', amount: 1000, dataAmount: '1GB', validity: '30 days' },
  { providerKey: 'data_MTN', code: 'MTN_2GB', name: '2GB', amount: 2000, dataAmount: '2GB', validity: '30 days' },
  { providerKey: 'data_MTN', code: 'MTN_5GB', name: '5GB', amount: 5000, dataAmount: '5GB', validity: '30 days' },
  { providerKey: 'data_MTN', code: 'MTN_10GB', name: '10GB', amount: 10000, dataAmount: '10GB', validity: '30 days' },
  
  // GLO Data Plans
  { providerKey: 'data_GLO', code: 'GLO_100MB', name: '100MB', amount: 100, dataAmount: '100MB', validity: '1 day' },
  { providerKey: 'data_GLO', code: 'GLO_200MB', name: '200MB', amount: 200, dataAmount: '200MB', validity: '3 days' },
  { providerKey: 'data_GLO', code: 'GLO_500MB', name: '500MB', amount: 500, dataAmount: '500MB', validity: '7 days' },
  { providerKey: 'data_GLO', code: 'GLO_1GB', name: '1GB', amount: 1000, dataAmount: '1GB', validity: '30 days' },
  { providerKey: 'data_GLO', code: 'GLO_2GB', name: '2GB', amount: 2000, dataAmount: '2GB', validity: '30 days' },
  { providerKey: 'data_GLO', code: 'GLO_5GB', name: '5GB', amount: 5000, dataAmount: '5GB', validity: '30 days' },
  { providerKey: 'data_GLO', code: 'GLO_10GB', name: '10GB', amount: 10000, dataAmount: '10GB', validity: '30 days' },
  
  // Airtel Data Plans
  { providerKey: 'data_AIRTEL', code: 'AIRTEL_100MB', name: '100MB', amount: 100, dataAmount: '100MB', validity: '1 day' },
  { providerKey: 'data_AIRTEL', code: 'AIRTEL_200MB', name: '200MB', amount: 200, dataAmount: '200MB', validity: '3 days' },
  { providerKey: 'data_AIRTEL', code: 'AIRTEL_500MB', name: '500MB', amount: 500, dataAmount: '500MB', validity: '7 days' },
  { providerKey: 'data_AIRTEL', code: 'AIRTEL_1GB', name: '1GB', amount: 1000, dataAmount: '1GB', validity: '30 days' },
  { providerKey: 'data_AIRTEL', code: 'AIRTEL_2GB', name: '2GB', amount: 2000, dataAmount: '2GB', validity: '30 days' },
  { providerKey: 'data_AIRTEL', code: 'AIRTEL_5GB', name: '5GB', amount: 5000, dataAmount: '5GB', validity: '30 days' },
  { providerKey: 'data_AIRTEL', code: 'AIRTEL_10GB', name: '10GB', amount: 10000, dataAmount: '10GB', validity: '30 days' },
];

// ============================================
// CABLE TV PLANS
// ============================================

const cablePlans = [
  // DSTV Plans
  { providerKey: 'cable_tv_DSTV', code: 'DSTV_COMPACT', name: 'DSTV Compact', amount: 7900, validity: '1 month' },
  { providerKey: 'cable_tv_DSTV', code: 'DSTV_COMPACT_PLUS', name: 'DSTV Compact Plus', amount: 12900, validity: '1 month' },
  { providerKey: 'cable_tv_DSTV', code: 'DSTV_PREMIUM', name: 'DSTV Premium', amount: 24500, validity: '1 month' },
  { providerKey: 'cable_tv_DSTV', code: 'DSTV_ASIAN', name: 'DSTV Asian', amount: 1900, validity: '1 month' },
  { providerKey: 'cable_tv_DSTV', code: 'DSTV_PIDGIN', name: 'DSTV Pidgin', amount: 2650, validity: '1 month' },
  
  // Showmax Plans
  { providerKey: 'cable_tv_SHOWMAX', code: 'SHOWMAX_MOBILE', name: 'Showmax Mobile', amount: 1200, validity: '1 month' },
  { providerKey: 'cable_tv_SHOWMAX', code: 'SHOWMAX_STANDARD', name: 'Showmax Standard', amount: 2900, validity: '1 month' },
  { providerKey: 'cable_tv_SHOWMAX', code: 'SHOWMAX_PRO', name: 'Showmax Pro', amount: 4900, validity: '1 month' },
  
  // GOtv Plans
  { providerKey: 'cable_tv_GOTV', code: 'GOTV_SMALLIE', name: 'GOtv Smallie', amount: 1650, validity: '1 month' },
  { providerKey: 'cable_tv_GOTV', code: 'GOTV_JINJA', name: 'GOtv Jinja', amount: 2650, validity: '1 month' },
  { providerKey: 'cable_tv_GOTV', code: 'GOTV_JINJA_PLUS', name: 'GOtv Jinja Plus', amount: 3250, validity: '1 month' },
  { providerKey: 'cable_tv_GOTV', code: 'GOTV_MAX', name: 'GOtv Max', amount: 5650, validity: '1 month' },
];

// ============================================
// SEEDING FUNCTIONS
// ============================================

async function seedWalletCurrencies() {
  console.log('💰 Seeding wallet currencies...');
  
  for (const wc of walletCurrencies) {
    const existing = await prisma.walletCurrency.findUnique({
      where: {
        blockchain_currency: {
          blockchain: wc.blockchain,
          currency: wc.currency,
        },
      },
    });

    if (existing) {
      await prisma.walletCurrency.update({
        where: { id: existing.id },
        data: {
          name: wc.name,
          symbol: wc.symbol,
          isToken: wc.isToken,
          ...(wc.contractAddress !== undefined && { contractAddress: wc.contractAddress }),
          decimals: wc.decimals,
        },
      });
    } else {
      await prisma.walletCurrency.create({
        data: {
          blockchain: wc.blockchain,
          currency: wc.currency,
          name: wc.name,
          symbol: wc.symbol,
          isToken: wc.isToken,
          ...(wc.contractAddress !== undefined && { contractAddress: wc.contractAddress }),
          decimals: wc.decimals,
        },
      });
    }
  }
  
  console.log(`✅ Seeded ${walletCurrencies.length} wallet currencies`);
}

async function seedBillPaymentCategories() {
  console.log('💳 Seeding bill payment categories...');
  
  const categoryMap: { [key: string]: number } = {};
  
  for (const cat of billCategories) {
    let category = await prisma.billPaymentCategory.findUnique({
      where: { code: cat.code },
    });
    
    if (category) {
      category = await prisma.billPaymentCategory.update({
        where: { id: category.id },
        data: {
          name: cat.name,
          description: cat.description,
          isActive: true,
        },
      });
    } else {
      category = await prisma.billPaymentCategory.create({
        data: {
          code: cat.code,
          name: cat.name,
          description: cat.description,
          isActive: true,
        },
      });
    }
    categoryMap[cat.code] = category.id;
  }
  
  console.log(`✅ Seeded ${billCategories.length} bill payment categories`);
  return categoryMap;
}

async function seedBillPaymentProviders(categoryMap: { [key: string]: number }) {
  console.log('🏢 Seeding bill payment providers...');
  
  const providerMap: { [key: string]: number } = {};
  
  for (const prov of billProviders) {
    const categoryId = categoryMap[prov.category];
    let provider = await prisma.billPaymentProvider.findFirst({
      where: {
        ...(categoryId && { categoryId }),
        code: prov.code,
      },
    });
    
    if (provider) {
      provider = await prisma.billPaymentProvider.update({
        where: { id: provider.id },
        data: {
          name: prov.name,
          logoUrl: prov.logoUrl,
          metadata: prov.metadata ? (prov.metadata as any) : null,
          isActive: true,
        },
      });
    } else {
      provider = await prisma.billPaymentProvider.create({
        data: {
          ...(categoryId && { categoryId }),
          code: prov.code,
          name: prov.name,
          logoUrl: prov.logoUrl,
          countryCode: 'NG',
          currency: 'NGN',
          metadata: prov.metadata ? (prov.metadata as any) : null,
          isActive: true,
        },
      });
    }
    providerMap[`${prov.category}_${prov.code}`] = provider.id;
  }
  
  console.log(`✅ Seeded ${billProviders.length} bill payment providers`);
  return providerMap;
}

async function seedDataPlans(providerMap: { [key: string]: number }) {
  console.log('📦 Seeding data plans...');
  
  for (const plan of dataPlans) {
    const providerId = providerMap[plan.providerKey];
    if (providerId) {
      let existingPlan = await prisma.billPaymentPlan.findFirst({
        where: {
          providerId,
          code: plan.code,
        },
      });
      
      if (existingPlan) {
        await prisma.billPaymentPlan.update({
          where: { id: existingPlan.id },
          data: {
            name: plan.name,
            amount: plan.amount,
            dataAmount: plan.dataAmount,
            validity: plan.validity,
            isActive: true,
          },
        });
      } else {
        await prisma.billPaymentPlan.create({
          data: {
            providerId,
            code: plan.code,
            name: plan.name,
            amount: plan.amount,
            currency: 'NGN',
            dataAmount: plan.dataAmount,
            validity: plan.validity,
            isActive: true,
          },
        });
      }
    }
  }
  
  console.log(`✅ Seeded ${dataPlans.length} data plans`);
}

async function seedCablePlans(providerMap: { [key: string]: number }) {
  console.log('📺 Seeding cable TV plans...');
  
  for (const plan of cablePlans) {
    const providerId = providerMap[plan.providerKey];
    if (providerId) {
      let existingPlan = await prisma.billPaymentPlan.findFirst({
        where: {
          providerId,
          code: plan.code,
        },
      });
      
      if (existingPlan) {
        await prisma.billPaymentPlan.update({
          where: { id: existingPlan.id },
          data: {
            name: plan.name,
            amount: plan.amount,
            validity: plan.validity,
            isActive: true,
          },
        });
      } else {
        await prisma.billPaymentPlan.create({
          data: {
            providerId,
            code: plan.code,
            name: plan.name,
            amount: plan.amount,
            currency: 'NGN',
            validity: plan.validity,
            isActive: true,
          },
        });
      }
    }
  }
  
  console.log(`✅ Seeded ${cablePlans.length} cable TV plans`);
}

// ============================================
// MAIN SEED FUNCTION
// ============================================

async function main() {
  console.log('🌱 Starting crypto and bill payment seed...');
  
  try {
    // Seed wallet currencies
    await seedWalletCurrencies();
    
    // Seed bill payment categories
    const categoryMap = await seedBillPaymentCategories();
    
    // Seed bill payment providers
    const providerMap = await seedBillPaymentProviders(categoryMap);
    
    // Seed data plans
    await seedDataPlans(providerMap);
    
    // Seed cable TV plans
    await seedCablePlans(providerMap);
    
    console.log('🎉 Crypto and bill payment seed completed!');
  } catch (error) {
    console.error('❌ Error seeding:', error);
    throw error;
  }
}

// ============================================
// EXECUTE
// ============================================

main()
  .catch((e) => {
    console.error('❌ Error seeding database:', e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
