# Swagger API Documentation

## Overview
This Laravel application includes comprehensive Swagger/OpenAPI documentation for all API endpoints.

## Accessing the Documentation

### Swagger UI
Once the application is running, access the Swagger UI at:
```
http://localhost:8000/api/documentation
```

### JSON Documentation
The generated OpenAPI JSON specification is available at:
```
http://localhost:8000/api-docs.json
```

## Regenerating Documentation

After making changes to API endpoints or annotations, regenerate the documentation:

```bash
php artisan l5-swagger:generate
```

## Documented Endpoints

### Authentication Endpoints
- `POST /api/auth/register` - Register a new user
- `POST /api/auth/verify-email-otp` - Verify email OTP and create wallets
- `POST /api/auth/resend-otp` - Resend OTP to email or phone
- `POST /api/auth/set-pin` - Set 4-digit PIN for transactions (Protected)

### KYC Endpoints
- `POST /api/kyc` - Submit or update KYC information (Protected)
- `GET /api/kyc` - Get KYC information (Protected)

### Wallet Endpoints
- `GET /api/wallet/balance` - Get wallet balance (fiat + crypto in USD) (Protected)
- `GET /api/wallet/fiat` - Get fiat wallets (Protected)
- `GET /api/wallet/crypto` - Get crypto wallets/virtual accounts (Protected)

## Features

### Interactive API Testing
The Swagger UI provides:
- **Try it out** functionality to test endpoints directly
- Request/response examples
- Schema validation
- Authentication support (Sanctum Bearer tokens)

### Authentication in Swagger
1. Click the **Authorize** button at the top of the Swagger UI
2. Enter your Sanctum Bearer token
3. All protected endpoints will now use this token

### Request/Response Examples
All endpoints include:
- Request body schemas with examples
- Response schemas with examples
- Validation rules
- Error response formats

## Configuration

The Swagger configuration is located in:
```
config/l5-swagger.php
```

Key settings:
- **Title**: "Bill's Pro API Documentation"
- **API Base URL**: `http://localhost:8000`
- **Documentation Route**: `/api/documentation`
- **JSON File**: `storage/api-docs/api-docs.json`

## Annotations

All controllers use PHP 8 attributes for Swagger annotations:

```php
use OpenApi\Attributes as OA;

#[OA\Post(
    path: "/api/endpoint",
    summary: "Endpoint description",
    tags: ["Tag Name"],
    // ... more attributes
)]
```

## Main API Info

The main API information is defined in:
```
app/Http/Controllers/Controller.php
```

This includes:
- API Title: "Bill's Pro API"
- Version: "1.0.0"
- Description: "API documentation for Bill's Pro - Crypto and Bill Payment System"
- Security Scheme: Sanctum Bearer token authentication

## Notes

- Documentation is automatically generated from PHP annotations
- All endpoints are properly documented with request/response schemas
- Examples are provided for all request bodies
- Error responses are documented (400, 401, 422, etc.)
- Protected endpoints are marked with security requirements

## Updating Documentation

When adding new endpoints:
1. Add Swagger annotations to the controller method
2. Run `php artisan l5-swagger:generate`
3. Refresh the Swagger UI page

## Example Usage

### Testing an Endpoint in Swagger UI

1. Navigate to `http://localhost:8000/api/documentation`
2. Find the endpoint you want to test
3. Click **Try it out**
4. Fill in the request parameters
5. Click **Execute**
6. View the response

### Getting a Bearer Token

To test protected endpoints:
1. First, register a user via `/api/auth/register`
2. Verify email via `/api/auth/verify-email-otp`
3. Use Sanctum to create a token (add login endpoint if needed)
4. Use the token in the **Authorize** button in Swagger UI
