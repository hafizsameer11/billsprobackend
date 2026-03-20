# Crypto treasury and fees

## User withdrawals (`/api/crypto/send`)

- The user’s **virtual account** is debited for **send amount + platform fee** (`SEND_FEE_USD` converted to crypto using the pair’s `wallet_currencies.rate`).
- On-chain broadcast uses the **master wallet** (custodial). The **Tatum-reported network fee** is stored on the user `Transaction` metadata as `network_fee_actual` and on `master_wallet_transactions.network_fee`.
- **Policy:** The platform fee covers operational cost; **on-chain gas variance** vs the quoted platform fee is absorbed by **treasury** unless you add a separate reconciliation job. Metadata documents this for audit.

## CoinMarketCap sync

- `SyncWalletCurrencyRatesFromCoinMarketCapJob` (scheduled every 15 minutes) updates `wallet_currencies.rate` (USD per unit) and `naira_price` / `price` using `CRYPTO_NGN_PER_USD`.
- Admin override: `PUT /api/admin/crypto/wallet-currencies/{id}/rate` (admin middleware).

## Swagger docs OTP

- `SWAGGER_DOCS_ALLOWED_EMAILS` restricts who can receive an OTP. Session TTL: `SWAGGER_DOCS_SESSION_HOURS`.
