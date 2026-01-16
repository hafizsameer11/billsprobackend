<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(version: "1.0.0", title: "Bill's Pro API", description: "API documentation for Bill's Pro - Crypto and Bill Payment System", contact: new OA\Contact(name: "API Support", email: "support@billspro.com"))]
#[OA\Server(url: "http://localhost:8000", description: "Local development server")]
#[OA\SecurityScheme(securityScheme: "sanctum", type: "http", scheme: "bearer", bearerFormat: "JWT", description: "Enter your Sanctum token")]
class Controller
{
    //
}
