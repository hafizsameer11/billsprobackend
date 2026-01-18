<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CreateTicketRequest;
use App\Http\Requests\Support\UpdateTicketRequest;
use App\Services\Support\SupportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class SupportController extends Controller
{
    protected SupportService $supportService;

    public function __construct(SupportService $supportService)
    {
        $this->supportService = $supportService;
    }

    /**
     * Get support information (contact options, socials)
     */
    #[OA\Get(path: "/api/support", summary: "Get support information", description: "Get support contact options and social media links.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Response(response: 200, description: "Support information retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function index(): JsonResponse
    {
        try {
            $supportInfo = $this->supportService->getSupportInfo();
            return ResponseHelper::success($supportInfo, 'Support information retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get support info error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ResponseHelper::serverError('An error occurred while retrieving support information. Please try again.');
        }
    }

    /**
     * Create a new support ticket
     */
    #[OA\Post(path: "/api/support/tickets", summary: "Create support ticket", description: "Create a new support ticket.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["subject", "description"], properties: [new OA\Property(property: "subject", type: "string", example: "Issue with crypto deposit"), new OA\Property(property: "description", type: "string", example: "I am unable to complete my crypto deposit"), new OA\Property(property: "issue_type", type: "string", nullable: true, enum: ["fiat_issue", "virtual_card_issue", "crypto_issue", "general"], example: "crypto_issue"), new OA\Property(property: "priority", type: "string", nullable: true, enum: ["low", "medium", "high", "urgent"], example: "medium")]))]
    #[OA\Response(response: 201, description: "Ticket created successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function createTicket(CreateTicketRequest $request): JsonResponse
    {
        try {
            $ticket = $this->supportService->createTicket($request->user()->id, $request->validated());
            return ResponseHelper::success($ticket, 'Ticket created successfully.', 201);
        } catch (\Exception $e) {
            Log::error('Create ticket error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while creating ticket. Please try again.');
        }
    }

    /**
     * Get user's tickets
     */
    #[OA\Get(path: "/api/support/tickets", summary: "Get user tickets", description: "Get all support tickets for the authenticated user.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "status", in: "query", required: false, description: "Filter by status", schema: new OA\Schema(type: "string", enum: ["open", "in_progress", "resolved", "closed"]))]
    #[OA\Parameter(name: "issue_type", in: "query", required: false, description: "Filter by issue type", schema: new OA\Schema(type: "string", enum: ["fiat_issue", "virtual_card_issue", "crypto_issue", "general"]))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records per page", schema: new OA\Schema(type: "integer", example: 20))]
    #[OA\Response(response: 200, description: "Tickets retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getTickets(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['status', 'issue_type', 'limit']);
            $tickets = $this->supportService->getUserTickets($request->user()->id, $filters);
            return ResponseHelper::success($tickets, 'Tickets retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get tickets error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving tickets. Please try again.');
        }
    }

    /**
     * Get a single ticket
     */
    #[OA\Get(path: "/api/support/tickets/{id}", summary: "Get ticket", description: "Get a specific support ticket by ID.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Ticket ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Ticket retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Ticket not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getTicket(Request $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->supportService->getTicket($request->user()->id, $id);
            return ResponseHelper::success($ticket, 'Ticket retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get ticket error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'ticket_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::notFound('Ticket not found.');
        }
    }

    /**
     * Update a ticket
     */
    #[OA\Put(path: "/api/support/tickets/{id}", summary: "Update ticket", description: "Update a support ticket.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Ticket ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: "subject", type: "string", nullable: true), new OA\Property(property: "description", type: "string", nullable: true), new OA\Property(property: "status", type: "string", nullable: true, enum: ["open", "in_progress", "resolved", "closed"])]))]
    #[OA\Response(response: 200, description: "Ticket updated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Ticket not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function updateTicket(UpdateTicketRequest $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->supportService->updateTicket($request->user()->id, $id, $request->validated());
            return ResponseHelper::success($ticket, 'Ticket updated successfully.');
        } catch (\Exception $e) {
            Log::error('Update ticket error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'ticket_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::notFound('Ticket not found.');
        }
    }

    /**
     * Close a ticket
     */
    #[OA\Post(path: "/api/support/tickets/{id}/close", summary: "Close ticket", description: "Close a support ticket.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Ticket ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Ticket closed successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Ticket not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function closeTicket(Request $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->supportService->closeTicket($request->user()->id, $id);
            return ResponseHelper::success($ticket, 'Ticket closed successfully.');
        } catch (\Exception $e) {
            Log::error('Close ticket error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'ticket_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::notFound('Ticket not found.');
        }
    }
}
