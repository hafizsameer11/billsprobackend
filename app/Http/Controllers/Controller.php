<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: "Bill's Pro API", description: "Bill's Pro API: fiat/crypto wallets, bill payments, KYC, and **virtual Mastercard** (reseller API). See tag **Virtual Cards** for creation fees (`VIRTUAL_CARD_*` env) and `MASTERCARD_API_*` provider settings.", contact: new OA\Contact(name: 'API Support', email: 'support@billspro.com'))]
#[OA\Server(url: 'https://billspro.hmstech.org', description: 'Production server')]
#[OA\SecurityScheme(securityScheme: 'sanctum', type: 'http', scheme: 'bearer', bearerFormat: 'JWT', description: 'Enter your Sanctum token')]
class Controller
{
    //
}
