# Frontend API — complete integration reference

Base URL: `https://<your-api-host>/api`  
All JSON bodies: `Content-Type: application/json` unless noted (chat message may use `multipart/form-data`).

---

## 1. Standard response envelope

**Success**

```json
{
  "success": true,
  "message": "string",
  "data": {}
}
```

`data` may be `null`, an object, an array, or omitted.

**Error (business)**

```json
{
  "success": false,
  "message": "string"
}
```

**Validation (HTTP 422)**

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field_name": ["message1", "message2"]
  }
}
```

**Auth (HTTP 401)**

```json
{
  "success": false,
  "message": "Unauthenticated"
}
```

(or Laravel default JSON)

**Account suspended/banned (HTTP 403)** — most routes use middleware `account.active`:

```json
{
  "success": false,
  "message": "Your account is not active.",
  "code": "ACCOUNT_SUSPENDED",
  "account_status": "suspended"
}
```

`code` is `ACCOUNT_BANNED` when banned. **Still allowed when suspended:** `GET /api/user` and `GET /api/user/profile` only. All other routes under `auth:sanctum` + `account.active` return **403** until the account is active again.

---

## 2. Authentication header

Protected routes:

```http
Authorization: Bearer <sanctum_token>
Accept: application/json
```

Token is returned from `POST /api/auth/verify-email-otp` and `POST /api/auth/login`.

---

## 3. Exceptions — routes that do NOT use the standard envelope

| Method | Path | Response |
|--------|------|----------|
| GET | `/api/user` | **Raw** `User` model JSON (not wrapped in `success`/`data`). Hidden attributes applied. |

All other documented routes use `ResponseHelper` (`success` / `error`).

---

## 4. v1 supported crypto (wallet_currencies)

| `currency` (path/query) | `blockchain` |
|-------------------------|--------------|
| ETH | ethereum |
| USDT | ethereum |
| BTC | bitcoin |
| DOGE | dogecoin |
| USDT_BSC | bsc |
| USDT_TRON | tron |

`GET /api/crypto/usdt/blockchains` only returns rows with `currency === "USDT"` (typically **Ethereum** only). Use **`GET /api/crypto/accounts`** or **`GET /api/wallet/crypto`** for all networks.

---

## 5. Quick route index (method + path)

Paths are relative to `/api`. **Auth** = `Authorization: Bearer <token>` unless noted.

| Method | Path | Auth |
|--------|------|------|
| POST | `/auth/login` | — |
| POST | `/auth/register` | — |
| POST | `/auth/verify-email-otp` | — |
| POST | `/auth/resend-otp` | — |
| POST | `/auth/forgot-password` | — |
| POST | `/auth/verify-password-reset-otp` | — |
| POST | `/auth/reset-password` | — |
| POST | `/webhooks/palmpay` | server |
| POST | `/webhooks/palmpay/bill-payment` | server |
| POST | `/webhooks/tatum` | server |
| POST | `/auth/set-pin` | user |
| GET | `/auth/check-pin-status` | user |
| POST | `/auth/verify-pin` | user |
| GET | `/dashboard` | user |
| GET | `/wallet/balance` | user |
| GET | `/wallet/fiat` | user |
| GET | `/wallet/crypto` | user |
| GET | `/deposit/bank-account` | user |
| POST | `/deposit/initiate` | user |
| POST | `/deposit/confirm` | user |
| GET | `/deposit/history` | user |
| POST | `/deposit/palmpay/initiate` | user |
| GET | `/deposit/palmpay/status/{depositReference}` | user |
| GET | `/deposit/{reference}` | user |
| GET | `/bill-payment/palmpay/billers` | user |
| GET | `/bill-payment/palmpay/items` | user |
| POST | `/bill-payment/palmpay/verify-account` | user |
| POST | `/bill-payment/palmpay/create-order` | user |
| GET | `/withdrawal/bank-accounts` | user |
| POST | `/withdrawal/bank-accounts` | user |
| PUT | `/withdrawal/bank-accounts/{id}` | user |
| POST | `/withdrawal/bank-accounts/{id}/set-default` | user |
| DELETE | `/withdrawal/bank-accounts/{id}` | user |
| GET | `/withdrawal/fee` | user |
| POST | `/withdrawal` | user |
| GET | `/withdrawal/transactions` | user |
| GET | `/withdrawal/transactions/{transactionId}` | user |
| GET | `/transactions` | user |
| GET | `/transactions/all` | user |
| GET | `/transactions/bill-payments` | user |
| GET | `/transactions/withdrawals` | user |
| GET | `/transactions/deposits` | user |
| GET | `/transactions/fiat` | user |
| GET | `/transactions/stats` | user |
| GET | `/transactions/{transactionId}` | user |
| POST | `/kyc` | user |
| GET | `/kyc` | user |
| GET | `/bill-payment/categories` | user |
| GET | `/bill-payment/providers` | user |
| GET | `/bill-payment/plans` | user |
| POST | `/bill-payment/validate-meter` | user |
| POST | `/bill-payment/validate-account` | user |
| POST | `/bill-payment/preview` | user |
| POST | `/bill-payment/initiate` | user |
| POST | `/bill-payment/confirm` | user |
| GET | `/bill-payment/beneficiaries` | user |
| POST | `/bill-payment/beneficiaries` | user |
| PUT | `/bill-payment/beneficiaries/{id}` | user |
| DELETE | `/bill-payment/beneficiaries/{id}` | user |
| GET | `/crypto/usdt/blockchains` | user |
| GET | `/crypto/accounts` | user |
| GET | `/crypto/accounts/{currency}` | user |
| GET | `/crypto/deposit-address` | user |
| GET | `/crypto/exchange-rate` | user |
| POST | `/crypto/buy/preview` | user |
| POST | `/crypto/buy/confirm` | user |
| POST | `/crypto/sell/preview` | user |
| POST | `/crypto/sell/confirm` | user |
| POST | `/crypto/send` | user |
| GET | `/virtual-cards` | user |
| POST | `/virtual-cards` | user |
| GET | `/virtual-cards/{id}` | user |
| POST | `/virtual-cards/{id}/fund` | user |
| POST | `/virtual-cards/{id}/withdraw` | user |
| GET | `/virtual-cards/{id}/transactions` | user |
| POST | `/virtual-cards/{id}/terminate` | user |
| GET | `/virtual-cards/{id}/check-3ds` | user |
| GET | `/virtual-cards/{id}/check-wallet` | user |
| POST | `/virtual-cards/{id}/approve-3ds` | user |
| GET | `/virtual-cards/{id}/billing-address` | user |
| PUT | `/virtual-cards/{id}/billing-address` | user |
| GET | `/virtual-cards/{id}/limits` | user |
| PUT | `/virtual-cards/{id}/limits` | user |
| POST | `/virtual-cards/{id}/freeze` | user |
| POST | `/virtual-cards/{id}/unfreeze` | user |
| GET | `/support` | user |
| GET | `/support/tickets` | user |
| POST | `/support/tickets` | user |
| GET | `/support/tickets/{id}` | user |
| PUT | `/support/tickets/{id}` | user |
| POST | `/support/tickets/{id}/close` | user |
| GET | `/chat/session` | user |
| GET | `/chat/sessions` | user |
| POST | `/chat/start` | user |
| GET | `/chat/sessions/{id}` | user |
| POST | `/chat/sessions/{id}/messages` | user |
| GET | `/chat/sessions/{id}/messages` | user |
| POST | `/chat/sessions/{id}/read` | user |
| POST | `/chat/sessions/{id}/close` | user |
| GET | `/user` | user |
| GET | `/user/profile` | user |
| PUT | `/user/profile` | user |
| GET | `/user/notifications` | user |
| POST | `/user/notifications/{id}/read` | user |
| POST | `/user/notifications/read-all` | user |
| GET | `/admin/stats` | admin |
| GET | `/admin/users` | admin |
| GET | `/admin/users/{user}` | admin |
| PATCH | `/admin/users/{user}` | admin |
| POST | `/admin/users/{user}/suspend` | admin |
| POST | `/admin/users/{user}/unsuspend` | admin |
| POST | `/admin/users/{user}/ban` | admin |
| POST | `/admin/users/{user}/tokens/revoke` | admin |
| GET | `/admin/users/{user}/timeline` | admin |
| GET | `/admin/users/{user}/fiat-wallets` | admin |
| PATCH | `/admin/fiat-wallets/{fiatWallet}` | admin |
| GET | `/admin/users/{user}/virtual-accounts` | admin |
| PATCH | `/admin/virtual-accounts/{virtualAccount}` | admin |
| GET | `/admin/users/{user}/deposit-addresses` | admin |
| GET | `/admin/transactions` | admin |
| GET | `/admin/transactions/{transactionId}` | admin |
| GET | `/admin/deposits` | admin |
| GET | `/admin/deposits/{id}` | admin |
| GET | `/admin/withdrawals` | admin |
| GET | `/admin/withdrawals/{transactionId}` | admin |
| GET | `/admin/kyc` | admin |
| GET | `/admin/kyc/{user}` | admin |
| POST | `/admin/kyc/{user}/approve` | admin |
| POST | `/admin/kyc/{user}/reject` | admin |
| GET | `/admin/bill-payments` | admin |
| GET | `/admin/virtual-cards` | admin |
| GET | `/admin/virtual-cards/{id}` | admin |
| POST | `/admin/virtual-cards/{id}/freeze` | admin |
| GET | `/admin/support/tickets` | admin |
| PATCH | `/admin/support/tickets/{supportTicket}` | admin |
| GET | `/admin/webhooks/tatum/raw` | admin |
| GET | `/admin/webhooks/palmpay/raw` | admin |
| POST | `/admin/webhooks/tatum/raw/{id}/replay` | admin |
| POST | `/admin/webhooks/palmpay/raw/{id}/replay` | admin |
| POST | `/admin/adjustments/fiat` | admin |
| POST | `/admin/adjustments/crypto` | admin |
| GET | `/admin/crypto/summary` | admin |
| GET | `/admin/crypto/deposits` | admin |
| GET | `/admin/crypto/sweeps` | admin |
| POST | `/admin/crypto/sweeps` | admin |
| POST | `/admin/crypto/sweeps/{id}/execute` | admin |
| POST | `/admin/crypto/sweeps/{id}/tx` | admin |
| GET | `/admin/crypto/external-sends` | admin |
| GET | `/admin/crypto/vendors` | admin |
| POST | `/admin/crypto/vendors` | admin |
| PUT | `/admin/crypto/vendors/{id}` | admin |
| PUT | `/admin/crypto/wallet-currencies/{id}/rate` | admin |
| GET | `/admin/crypto/users/{user}/virtual-accounts` | admin |
| GET | `/admin/crypto/users/{user}/deposit-addresses` | admin |
| GET | `/admin/crypto/deposit-addresses` | admin |
| GET | `/admin/crypto/master-wallets` | admin |

---

# Part A — Endpoint catalog (payload + response)

Below, **200** means HTTP 200 with `{ success: true, message, data }` unless stated.

---

## A1. Public — Authentication

### POST `/auth/register`

| | |
|---|---|
| **Auth** | None |
| **Body** | See validation |

**Validation**

| Field | Rules |
|-------|--------|
| first_name | required, string, max 255 |
| last_name | required, string, max 255 |
| email | required, email, unique users |
| phone_number | nullable, string, unique users |
| password | required, string, min 8 |
| country_code | nullable, string, max 10 (default NG in app) |

**201**

```json
{
  "success": true,
  "message": "Registration successful. Please verify your email.",
  "data": {
    "success": true,
    "message": "...",
    "user": { "id": 1, "email": "...", "email_verified": false },
    "otp": { "success": true, "message": "...", "expires_at": "Y-m-d H:i:s" }
  }
}
```

**400** — user already exists (`success: false`).

---

### POST `/auth/verify-email-otp`

| | |
|---|---|
| **Auth** | None |

**Body**

```json
{
  "email": "user@example.com",
  "otp": "12345"
}
```

**Validation:** `otp` required, string, **size 5**.

**200**

```json
{
  "success": true,
  "message": "Email verified successfully. Wallets created.",
  "data": {
    "user": { "...": "user model refreshed" },
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
  }
}
```

**400** — invalid OTP / already verified.

---

### POST `/auth/resend-otp`

**Body**

```json
{
  "type": "email",
  "email": "user@example.com"
}
```

Or `"type": "phone"` with `"phone_number": "..."`.

**Validation:** `type` required, `in:email,phone`; `email` required_if type email; `phone_number` required_if type phone.

**200** — `data` includes OTP service result (`expires_at`, etc.).

---

### POST `/auth/login`

**Body**

```json
{
  "email": "user@example.com",
  "password": "secret12"
}
```

**Validation:** email required; password required, string, **min 6**.

**200**

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { "id": 1, "...": "..." },
    "token": "1|..."
  }
}
```

**400** — wrong password, user not found, or email not verified.

---

### POST `/auth/forgot-password`

**Body:** `{ "email": "..." }` — email required, exists in `users`.

**200** — `data` includes `{ success, message, expires_at? }` from service.

**400** — no user (message from service).

---

### POST `/auth/verify-password-reset-otp`

**Body:** `{ "email": "...", "otp": "12345" }` — email exists; OTP size 5.

**200** — `data` may be null; `message` success.

**400** — invalid OTP.

---

### POST `/auth/reset-password`

**Body**

```json
{
  "email": "user@example.com",
  "otp": "12345",
  "password": "newsecret12",
  "password_confirmation": "newsecret12"
}
```

**Validation:** password required, min 8, **confirmed**.

**200** — `data` null; password updated.

**400** — OTP invalid.

---

## A2. Protected — Auth (PIN)

| Method | Path | Body | Success `data` |
|--------|------|------|----------------|
| POST | `/auth/set-pin` | `{ "pin": "1234" }` — 4 digits | null |
| GET | `/auth/check-pin-status` | — | `{ "pin_set": true \| false }` |
| POST | `/auth/verify-pin` | `{ "pin": "1234" }` | null |

**400** — wrong PIN or PIN not set.

---

## A3. Protected — User & profile

### GET `/user`

Returns **raw** User JSON (not wrapped). No `success` field.

---

### GET `/user/profile`

**200**

```json
{
  "success": true,
  "message": "Profile retrieved successfully",
  "data": {
    "user": { "...": "hidden password, pin" }
  }
}
```

---

### PUT `/user/profile` — `UpdateProfileRequest`

All fields optional:

| Field | Rules |
|-------|--------|
| first_name | nullable string, max 255 |
| last_name | nullable string, max 255 |
| phone_number | nullable string, **unique** on `users.phone_number` except current user |
| country_code | nullable string, max 10 |

**200** — `data.user` updated.

---

### GET `/user/notifications`

**Query:** `page`, `per_page` (default 20), `read` (true/false), `type` (string).

**200**

```json
{
  "success": true,
  "data": {
    "notifications": [],
    "unread_count": 0,
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 20,
      "total": 0
    }
  }
}
```

---

### POST `/user/notifications/{id}/read`

**200** — message only; `data` null.

**404** — notification not found.

---

### POST `/user/notifications/read-all`

**200**

```json
{
  "success": true,
  "data": { "marked_count": 5 },
  "message": "..."
}
```

---

## A4. Protected — Dashboard & wallet

### GET `/dashboard`

**200 `data`**

```json
{
  "user": {
    "name": "",
    "first_name": "",
    "last_name": "",
    "email": ""
  },
  "balances": {
    "fiat": { "currency": "NGN", "balance": "0.00" },
    "crypto": {
      "total_usd": 0,
      "breakdown": []
    }
  }
}
```

---

### GET `/wallet/balance`

Same balance shape as dashboard (fiat NGN + crypto USD + breakdown).

---

### GET `/wallet/fiat`

**200** — `data`: array of fiat wallet objects from service.

---

### GET `/wallet/crypto`

**200** — `data`: array of virtual account summaries from `CryptoWalletService::getUserVirtualAccounts`.

---

## A5. Protected — KYC

### POST `/kyc`

**Body** (all optional)

```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "a@b.com",
  "date_of_birth": "1990-01-01",
  "bvn_number": "",
  "nin_number": ""
}
```

**200** — `data` contains service result (may include `kyc` model).

**400** — service failure.

**409** — duplicate KYC (duplicate DB).

---

### GET `/kyc`

**200**

```json
{
  "success": true,
  "data": { "kyc": { "...": "or null" } }
}
```

---

## A6. Protected — Deposits (fiat)

### GET `/deposit/bank-account`

**Query:** `currency` (default NGN), `country_code` (default NG).

**200** — `data`: bank account object.

**404** — no bank configured.

---

### POST `/deposit/initiate`

**Body**

```json
{
  "amount": 10000,
  "currency": "NGN",
  "country_code": "NG",
  "payment_method": "instant_transfer"
}
```

**Validation:** `amount` required numeric **min 100**; `payment_method` nullable `in:bank_transfer,instant_transfer`.

**200** — When PalmPay: `data` includes `deposit`, `bank_account`, `reference`, `palmpay` (checkoutUrl, etc.). Legacy: `deposit`, `bank_account`, `reference`, amounts.

---

### POST `/deposit/confirm`

**Body:** `{ "reference": "DEP..." }` — must exist on `deposits.deposit_reference`.

**200**

```json
{
  "data": {
    "deposit": {},
    "transaction": {}
  }
}
```

(Legacy manual flow; PalmPay credits via webhook.)

---

### GET `/deposit/history`

**Query:** `limit` (default 20).

**200** — `data`: array of deposits.

---

### GET `/deposit/{reference}`

**200** — `data`: single deposit model.

**404**

---

### POST `/deposit/palmpay/initiate`

**Body:** `amount` required numeric min 0.01; `currency` optional string max 10.

**200**

```json
{
  "data": {
    "depositReference": "",
    "amount": "",
    "currency": "NGN",
    "merchantOrderId": "",
    "orderNo": "",
    "virtualAccount": {},
    "checkoutUrl": ""
  }
}
```

**400** — exception message as `message`.

---

### GET `/deposit/palmpay/status/{depositReference}`

**200** — status payload; if completed includes `transactionId`. May return local-only status on remote failure.

**404** — deposit or order not found.

---

## A7. Protected — Withdrawals (fiat to bank)

### GET `/withdrawal/bank-accounts`

**200** — `data`: array of bank accounts.

---

### POST `/withdrawal/bank-accounts`

**Body**

```json
{
  "bank_name": "Access Bank",
  "bank_code": "044",
  "account_number": "0123456789",
  "account_name": "John Doe",
  "currency": "NGN",
  "country_code": "NG",
  "metadata": {}
}
```

**201** — `data`: created bank account.

**400** — duplicate.

---

### PUT `/withdrawal/bank-accounts/{id}`

**Body:** optional fields — `bank_name`, `bank_code`, `account_number`, `account_name`, `currency` (size 3), `country_code` (size 2), `is_active`, `metadata`.

---

### DELETE `/withdrawal/bank-accounts/{id}`

**200** — `data` null.

---

### POST `/withdrawal/bank-accounts/{id}/set-default`

**200** — `data`: bank account.

---

### GET `/withdrawal/fee`

**200**

```json
{
  "data": { "fee": "0.00" }
}
```

---

### POST `/withdrawal`

**Body**

```json
{
  "bank_account_id": 1,
  "amount": 5000,
  "pin": "1234"
}
```

**Validation:** bank_account_id exists; amount min 1; PIN 4 digits.

**200 `data`** — service result (amount, payout_status, etc.).

**400** — invalid PIN, insufficient balance, PalmPay errors.

---

### GET `/withdrawal/transactions`

**Query:** `type`, `limit` (default 50).

**200** — `data`: array.

---

### GET `/withdrawal/transactions/{transactionId}`

**200** — `data`: transaction object.

**404**

---

## A8. Protected — Transactions (unified)

### GET `/transactions`

**Query:** `type`, `status`, `category`, `currency`, `limit` (default 50).

**200** — `data`: array of formatted transactions (see **Transaction object** below).

---

### GET `/transactions/{transactionId}`

**200** — single formatted transaction.

**404**

---

### GET `/transactions/all`

**Query:** `wallet_type` (`naira`|`crypto`|`virtual_card`), `type`, `status`, `start_date`, `end_date`, `limit`.

**200** — aggregated list.

---

### GET `/transactions/bill-payments`

**Query:** `status`, `category`, `limit`.

---

### GET `/transactions/withdrawals`

**Query:** `status`, `limit`.

---

### GET `/transactions/deposits`

**Query:** `status`, `limit`.

---

### GET `/transactions/fiat`

**Query:** `type`, `status`, `category`, `currency`, `limit`.

---

### GET `/transactions/stats`

**Query:** `period` — `day` \| `week` \| `month` (default month).

**200** — `data`: stats object from service.

---

### Transaction object shape (formatted)

Regular ledger transaction:

```json
{
  "id": 1,
  "transaction_id": "hex_id",
  "type": "deposit|withdrawal|bill_payment|...",
  "category": "string",
  "status": "pending|completed|failed|cancelled",
  "currency": "NGN",
  "amount": 0,
  "fee": 0,
  "total_amount": 0,
  "reference": "",
  "description": "",
  "bank_name": "",
  "account_number": "",
  "account_name": "",
  "metadata": {},
  "created_at": "ISO8601",
  "updated_at": "ISO8601",
  "completed_at": "ISO8601|null"
}
```

Prepaid electricity may include top-level `"token"` from metadata. Virtual card txs use `wallet_type: virtual_card` and card metadata.

---

## A9. Protected — Crypto

### GET `/crypto/usdt/blockchains`

**200** — `data`: array of `{ id, blockchain, blockchain_name, network, currency, symbol, contract_address, decimals, is_token, crediting_time }` for **`currency === USDT`** rows only.

---

### GET `/crypto/accounts`

**200** — `data`: array; USDT may be **grouped** with `is_grouped: true`, `blockchains: [{ blockchain, balance, account_id }]`.

---

### GET `/crypto/accounts/{currency}`

**Query:** `blockchain` — **required** when `currency` is `USDT` (ethereum, tron, bsc, etc.).

For `USDT_BSC` / `USDT_TRON`, use path `{currency}` = `USDT_BSC` or `USDT_TRON`.

**200** — account object + `transactions` array (last 20).

**404**

---

### GET `/crypto/deposit-address`

**Query:** `currency`, `blockchain` (both required).

**200**

```json
{
  "data": {
    "currency": "ETH",
    "blockchain": "ethereum",
    "network": "ethereum",
    "deposit_address": "0x...",
    "qr_code": "0x...",
    "account_id": 1
  }
}
```

**400** — missing params / Tatum error.

---

### GET `/crypto/exchange-rate`

**Query:** `from_currency`, `to_currency`, `amount`, optional `blockchain` (required for ambiguous symbols like USDT).

**200** — `data` from `CryptoService::getExchangeRate` (rate, blockchain, etc.).

**400**

---

### POST `/crypto/buy/preview` & `/crypto/buy/confirm`

**Body**

```json
{
  "currency": "BTC",
  "blockchain": "bitcoin",
  "amount": 10000,
  "payment_method": "naira"
}
```

`amount` = **NGN** to spend. `payment_method` optional, `in:naira`.

**200** — `data`: preview or completed transaction payload.

**400** — insufficient balance, frozen wallet, etc.

---

### POST `/crypto/sell/preview` & `/crypto/sell/confirm`

**Body**

```json
{
  "currency": "BTC",
  "blockchain": "bitcoin",
  "amount": 0.001
}
```

`amount` = **crypto** units to sell.

---

### POST `/crypto/send`

**Body**

```json
{
  "currency": "BTC",
  "blockchain": "bitcoin",
  "amount": 0.001,
  "address": "bc1...",
  "network": "bitcoin"
}
```

`network` optional.

**200** — `data`: send result (transaction metadata).

**400**

---

## A10. Protected — Bill payment (internal catalogue)

When PalmPay bills are enabled, legacy **preview/initiate/confirm** may return **400** with hints to PalmPay routes (see error `errors.palmpay`).

### GET `/bill-payment/categories`

**200** — `data`: categories array.

---

### GET `/bill-payment/providers`

**Query:** `categoryCode` **required**, optional `countryCode`.

---

### GET `/bill-payment/plans`

**Query:** `providerId` **required**.

---

### POST `/bill-payment/validate-meter` — `ValidateMeterRequest`

| Field | Rules |
|-------|--------|
| providerId | required, exists `bill_payment_providers,id` |
| meterNumber | required string, **min 8** |
| accountType | required, `prepaid`\|`postpaid` |

---

### POST `/bill-payment/validate-account` — `ValidateAccountRequest`

| Field | Rules |
|-------|--------|
| providerId | required, exists `bill_payment_providers,id` |
| accountNumber | required string, **min 5** |

---

### POST `/bill-payment/preview`

**Body** (inline validation in controller)

| Field | Rules |
|-------|--------|
| categoryCode | required string |
| providerId | required integer |
| amount | nullable numeric, min 0.01 |
| planId | nullable integer |
| currency | nullable string, max 10 |

**400** if legacy bill payment is disabled (PalmPay-only mode) or preview fails.

---

### POST `/bill-payment/initiate`

**Body**

```json
{
  "categoryCode": "airtime",
  "providerId": 1,
  "currency": "NGN",
  "amount": 1000,
  "planId": null,
  "accountNumber": "080...",
  "beneficiaryId": null,
  "accountType": "prepaid"
}
```

**200** — pending transaction summary in `data`.

---

### POST `/bill-payment/confirm` — `ConfirmBillPaymentRequest`

```json
{
  "transactionId": 123,
  "pin": "1234"
}
```

| Field | Rules |
|-------|--------|
| transactionId | required integer, exists `transactions,id` |
| pin | required string, **size 4**, regex `^[0-9]{4}$` |

---

### GET `/bill-payment/beneficiaries`

**Query:** optional `categoryCode`.

---

### POST `/bill-payment/beneficiaries` — `CreateBeneficiaryRequest`

| Field | Rules |
|-------|--------|
| categoryCode | required, exists `bill_payment_categories,code` |
| providerId | required, exists `bill_payment_providers,id` |
| accountNumber | required string, max 255 |
| name | optional, max 255 |
| accountType | optional `prepaid`\|`postpaid` |

---

### PUT `/bill-payment/beneficiaries/{id}`

**Body** (any subset; not all FormRequest — controller uses `only`)

```json
{
  "name": "Label",
  "accountNumber": "080...",
  "accountType": "prepaid"
}
```

`accountType`: `prepaid` \| `postpaid`.

---

### DELETE `/bill-payment/beneficiaries/{id}`

---

## A11. Protected — PalmPay bill APIs

Use the **`sceneCode`** string in query params and JSON bodies for `GET /bill-payment/palmpay/billers`, `GET /bill-payment/palmpay/items`, `POST /bill-payment/palmpay/verify-account`, and `POST /bill-payment/palmpay/create-order`.

### PalmPay `sceneCode` values (for UI labels)

| `sceneCode` | Description |
|-------------|-------------|
| `airtime` | Airtime top-up |
| `data` | Data bundle top-up |
| `betting` | Betting top-up |

These are the only values accepted by the API (`in:airtime,data,betting`).

---

### GET `/bill-payment/palmpay/billers`

**Query:** `sceneCode` **required**, `in:airtime,data,betting` (see table above).

**200**

```json
{
  "data": {
    "sceneCode": "airtime",
    "billers": []
  }
}
```

**400** — PalmPay / upstream error in `message`.

---

### GET `/bill-payment/palmpay/items`

**Query:** `sceneCode` **required** (`airtime`|`data`|`betting`); `billerId` **required** (string).

**200**

```json
{
  "data": {
    "sceneCode": "airtime",
    "billerId": "...",
    "items": []
  }
}
```

---

### POST `/bill-payment/palmpay/verify-account`

**Body**

| Field | Rules |
|-------|--------|
| sceneCode | required, `in:airtime,data,betting` |
| rechargeAccount | required string, max 50 |
| billerId | optional string |
| itemId | optional string |

Actually validation: `'billerId' => ['sometimes', 'string'], 'itemId' => ['sometimes', 'string']`

**200**

```json
{
  "data": {
    "sceneCode": "airtime",
    "rechargeAccount": "080...",
    "result": {}
  }
}
```

---

### POST `/bill-payment/palmpay/create-order`

**Body**

```json
{
  "sceneCode": "airtime",
  "billerId": "provider-biller-id",
  "itemId": "product-item-id",
  "rechargeAccount": "08012345678",
  "amount": 1000,
  "currency": "NGN",
  "pin": "1234"
}
```

| Field | Rules |
|-------|--------|
| sceneCode | required, `in:airtime,data,betting` |
| billerId | required string |
| itemId | required string |
| rechargeAccount | required, max 50 |
| amount | required numeric, min 0.01 |
| currency | optional, max 10 |
| pin | required, **digits:4** |

**200** — orchestrator result in `data`.

**400** — error message from exception.

---

## A12. Protected — Virtual cards

### POST `/virtual-cards` — create

**Body (`CreateCardRequest`)**

| Field | Rules |
|-------|--------|
| firstname, lastname | required, string, max 100 |
| dob | required, `date_format:Y-m-d` |
| address1, city, state | required, string |
| country | required, **size 2** (ISO alpha-2) |
| phone | required, max 20 |
| countrycode | required, max 5 |
| postalcode | required, max 20 |
| payment_wallet_type | required, `in:naira_wallet,crypto_wallet` |
| card_name | optional, max 255 |
| useremail | optional email |
| card_color | optional `green,brown,purple` |
| card_type | optional `mastercard,visa` |
| payment_wallet_currency | optional, max 10 |
| billing_address_* | optional street/city/state/country/postal_code |
| daily_spending_limit, monthly_spending_limit | optional numeric ≥ 0 |
| daily_transaction_limit, monthly_transaction_limit | optional integer ≥ 0 |

### POST `/virtual-cards/{id}/fund` — `FundCardRequest`

```json
{
  "amount": 10.5,
  "useremail": "optional@example.com",
  "payment_wallet_type": "naira_wallet",
  "payment_wallet_currency": "NGN"
}
```

`amount` required, numeric, **min 0.01**. `payment_wallet_type` optional: `naira_wallet` \| `crypto_wallet` \| `provider_balance`.

### POST `/virtual-cards/{id}/withdraw` — `WithdrawCardRequest`

`amount` required, numeric, **min 1**.

### POST `/virtual-cards/{id}/approve-3ds`

```json
{
  "event_id": "3ds-uuid-from-provider"
}
```

`event_id` required, string, max 255. **422** if missing.

### PUT `/virtual-cards/{id}/limits` — `UpdateCardLimitsRequest`

All optional: `daily_spending_limit`, `monthly_spending_limit` (numeric, min 0); `daily_transaction_limit`, `monthly_transaction_limit` (integer, min 0).

### Other virtual card routes

| Method | Path | Body |
|--------|------|------|
| GET | `/virtual-cards` | — |
| GET | `/virtual-cards/{id}` | — |
| GET | `/virtual-cards/{id}/transactions` | — |
| POST | `/virtual-cards/{id}/terminate` | — |
| GET | `/virtual-cards/{id}/check-3ds` | — |
| GET | `/virtual-cards/{id}/check-wallet` | — |
| GET/PUT | `/virtual-cards/{id}/billing-address` | PUT: billing address fields (per service) |
| GET | `/virtual-cards/{id}/limits` | — |
| POST | `/virtual-cards/{id}/freeze` | — |
| POST | `/virtual-cards/{id}/unfreeze` | — |

**200** — `data` from `VirtualCardService` (often includes provider payloads); **400** if provider rejects.

---

## A13. Protected — Support

### GET `/support`

**200** — `data`: contact / social info.

---

### POST `/support/tickets` — `CreateTicketRequest`

```json
{
  "subject": "Issue",
  "description": "Details",
  "issue_type": "crypto_issue",
  "priority": "medium"
}
```

| Field | Rules |
|-------|--------|
| subject | **required**, string, max 255 |
| description | **required**, string |
| issue_type | optional, `in:fiat_issue,virtual_card_issue,crypto_issue,general` |
| priority | optional, `in:low,medium,high,urgent` |

**201** — `data`: ticket model.

---

### GET `/support/tickets`

**Query:** `status`, `issue_type`, `limit`.

---

### GET `/support/tickets/{id}`

**404** on not found.

---

### PUT `/support/tickets/{id}` — `UpdateTicketRequest`

| Field | Rules |
|-------|--------|
| subject | optional string, max 255 |
| description | optional string |
| status | optional `open`\|`in_progress`\|`resolved`\|`closed` |

**404** — ticket not found for user.

---

### POST `/support/tickets/{id}/close`

---

## A14. Protected — Chat

### POST `/chat/start` — `StartChatRequest`

```json
{
  "issue_type": "crypto_issue",
  "message": "Hello"
}
```

**Validation:** `issue_type` **required**, `in:fiat_issue,virtual_card_issue,crypto_issue,general`. `message` **required**, string, min 1.

### POST `/chat/sessions/{id}/messages` — `SendMessageRequest`

Use **`multipart/form-data`** if uploading a file, else JSON.

| Field | Rules |
|-------|--------|
| message | required, string, min 1 |
| attachment | optional file, max **10240** KB (10MB) |

| Method | Path | Notes |
|--------|------|------|
| GET | `/chat/session` | `data` may be **null** if no active session |
| GET | `/chat/sessions` | Query `limit` |
| GET | `/chat/sessions/{id}` | — |
| GET | `/chat/sessions/{id}/messages` | — |
| POST | `/chat/sessions/{id}/read` | — |
| POST | `/chat/sessions/{id}/close` | — |

**201** on start/send when applicable. **400** if user already has an active chat session (shape from controller).

---

## A15. Webhooks (server-to-server — not for mobile UI)

| Method | Path |
|--------|------|
| POST | `/webhooks/palmpay` |
| POST | `/webhooks/palmpay/bill-payment` |
| POST | `/webhooks/tatum` |

Payloads defined by PalmPay/Tatum; secured by signatures.

---

## A16. Admin API (`Authorization: Bearer <admin token>`, `users.is_admin`)

All below return **403** `{ success: false, message: "Forbidden" }` if not admin.

### Dashboard & users

**GET `/admin/stats`** — `data`:

```json
{
  "users_total": 0,
  "pending_kyc": 0,
  "open_support_tickets": 0,
  "failed_jobs": 0,
  "recent_transaction_volume_7d": 0,
  "deposits_pending": 0
}
```

| Method | Path | Body / query |
|--------|------|----------------|
| GET | `/admin/stats` | — |
| GET | `/admin/users` | `per_page` (default 25, max 100), `search`, `account_status`, `is_admin` |
| GET | `/admin/users/{user}` | — |
| PATCH | `/admin/users/{user}` | See below |
| POST | `/admin/users/{user}/suspend` | `{ "reason": "optional" }` — `reason` nullable string, max 2000 |
| POST | `/admin/users/{user}/unsuspend` | — |
| POST | `/admin/users/{user}/ban` | same as suspend |
| POST | `/admin/users/{user}/tokens/revoke` | — |
| GET | `/admin/users/{user}/timeline` | `limit` (default 50, max 100) |

**PATCH `/admin/users/{user}`**

| Field | Rules |
|-------|--------|
| internal_notes | nullable string, max 65000 |
| suspension_reason | nullable string, max 2000 |

---

### Wallets & addresses

| Method | Path | Body |
|--------|------|------|
| GET | `/admin/users/{user}/fiat-wallets` | — |
| PATCH | `/admin/fiat-wallets/{fiatWallet}` | `is_active` sometimes boolean; `locked_balance` sometimes numeric **min 0** |
| GET | `/admin/users/{user}/virtual-accounts` | — |
| PATCH | `/admin/virtual-accounts/{virtualAccount}` | `frozen` sometimes boolean; `active` sometimes boolean |
| GET | `/admin/users/{user}/deposit-addresses` | — |

---

### Ledger (read-only lists)

**GET `/admin/transactions`** — `per_page` (default 25, max 100); optional `user_id`, `type`, `status`, `currency`, `from`, `to` (date filters on `created_at`).

**GET `/admin/transactions/{transactionId}`** — resolves by `transaction_id` **or** numeric `id`.

**GET `/admin/deposits`** — `per_page`; optional `user_id`, `status`, `from`, `to`.

**GET `/admin/withdrawals`** — `per_page`; optional `user_id`, `status`, `currency`, `from`, `to`.

**GET `/admin/deposits/{id}`** — numeric deposit id.

**GET `/admin/withdrawals/{transactionId}`** — same resolution as transaction show.

---

### KYC (admin)

**GET `/admin/kyc`** — `per_page`; optional `status`.

**POST `/admin/kyc/{user}/reject`**

```json
{ "reason": "Does not match ID" }
```

`reason` **required**, string, max 2000.

---

### Bill payments & virtual cards (admin)

**GET `/admin/bill-payments`** — `per_page`; optional `user_id`, `status`.

**GET `/admin/virtual-cards`** — `per_page`; optional `user_id`.

**POST `/admin/virtual-cards/{id}/freeze`** — no body; **400** if provider/service rejects.

**GET `/admin/kyc/{user}`** — returns `{ user, kyc }`.

**POST `/admin/kyc/{user}/approve`** — no body.

---

### Support tickets (admin)

**GET `/admin/support/tickets`** — `per_page`; optional `status`, `user_id`.

**PATCH `/admin/support/tickets/{supportTicket}`**

| Field | Rules |
|-------|--------|
| status | optional string, max 50 |
| priority | optional string, max 50 |
| assigned_to | nullable integer, exists `users,id` |

---

### Webhooks (admin)

| Method | Path | Query |
|--------|------|--------|
| GET | `/admin/webhooks/tatum/raw` | `per_page` (default 25, max 100), `processed` (boolean filter) |
| GET | `/admin/webhooks/palmpay/raw` | same |

| Method | Path | Notes |
|--------|------|--------|
| POST | `/admin/webhooks/tatum/raw/{id}/replay` | Dispatches `ProcessTatumWebhookJob`. **403** if `config('admin.webhook_replay_enabled')` is false. |
| POST | `/admin/webhooks/palmpay/raw/{id}/replay` | Reprocesses stored payload. **403** if replay disabled; **422** if stored JSON invalid; **500** if processor throws. |

---

### Adjustments (throttle 30/min)

**POST `/admin/adjustments/fiat`**

```json
{
  "fiat_wallet_id": 1,
  "direction": "credit",
  "amount": "100.50",
  "reason": "Required explanation",
  "reference": "optional"
}
```

| Field | Rules |
|-------|--------|
| fiat_wallet_id | required, exists `fiat_wallets,id` |
| direction | required, `credit`\|`debit` |
| amount | required numeric, min `0.00000001` |
| reason | required string, max 2000 |
| reference | nullable string, max 255 |

**POST `/admin/adjustments/crypto`**

| Field | Rules |
|-------|--------|
| virtual_account_id | required, exists `virtual_accounts,id` |
| direction | required, `credit`\|`debit` |
| amount | required numeric, min `0.00000001` |
| reason | required string, max 2000 |
| reference | nullable string, max 255 |

**200** — `data` from service on success. **400** if adjustment fails (business message).

---

### Admin crypto treasury

List endpoints support `per_page` (default 25, max 100) where applicable.

**POST `/admin/crypto/sweeps`**

```json
{
  "vendor_id": 1,
  "virtual_account_id": 42,
  "amount": "0.5"
}
```

| Field | Rules |
|-------|--------|
| vendor_id | required, exists `crypto_vendors,id` |
| virtual_account_id | required, exists `virtual_accounts,id` |
| amount | required numeric, min `0.00000001` |

**201** on success. **400** on business rule failure (`RuntimeException` message).

**POST `/admin/crypto/sweeps/{id}/execute`**

No body. **200** — sweep broadcast. **400**/ **500** on failure.

**POST `/admin/crypto/sweeps/{id}/tx`**

```json
{
  "tx_hash": "0x..."
}
```

`tx_hash` required, string, max 255 — marks sweep completed with on-chain hash.

---

**POST `/admin/crypto/vendors`**

```json
{
  "name": "Vendor A",
  "code": "VENDOR_A",
  "blockchain": "ethereum",
  "currency": "ETH",
  "payout_address": "0x...",
  "contract_address": null,
  "is_active": true,
  "metadata": {}
}
```

| Field | Rules |
|-------|--------|
| name | required, max 255 |
| code | required, max 64, **unique** `crypto_vendors` |
| blockchain | required, max 64 |
| currency | required, max 32 |
| payout_address | required, max 255 |
| contract_address | nullable, max 255 |
| is_active | optional boolean |
| metadata | optional array |

**PUT `/admin/crypto/vendors/{id}`** — same fields but all **sometimes** (partial update); `code` unique except current row.

---

**PUT `/admin/crypto/wallet-currencies/{id}/rate`**

```json
{
  "rate": 2450.5
}
```

`rate` required, numeric, **min 0**. Updates `rate`, `price`, and `naira_price` (using `config('crypto.ngn_per_usd')`).

---

| Method | Path | Query / notes |
|--------|------|----------------|
| GET | `/admin/crypto/summary` | — |
| GET | `/admin/crypto/deposits` | `per_page` |
| GET | `/admin/crypto/sweeps` | `per_page` |
| GET | `/admin/crypto/external-sends` | `per_page` |
| GET | `/admin/crypto/vendors` | `active_only` (default true) |
| GET | `/admin/crypto/users/{user}/virtual-accounts` | — |
| GET | `/admin/crypto/users/{user}/deposit-addresses` | — |
| GET | `/admin/crypto/deposit-addresses` | — |
| GET | `/admin/crypto/master-wallets` | — |

---

## B. Operational notes

1. **Queue worker:** After email verification, crypto deposit provisioning runs in a **queue job**. Run `php artisan queue:work` in production.
2. **OpenAPI:** Regenerate with `php artisan l5-swagger:generate`; JSON at `storage/api-docs/api-docs.json`.
3. **Swagger UI:** May require OTP session — see `config/swagger_docs.php`.

---

*This document is generated from Laravel routes, FormRequests, and controllers in this repository. For a field not listed, inspect `app/Http/Requests/` or the controller method.*
