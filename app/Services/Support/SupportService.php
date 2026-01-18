<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class SupportService
{
    /**
     * Get support information (contact options, socials)
     */
    public function getSupportInfo(): array
    {
        return [
            'contact_options' => [
                [
                    'type' => 'email',
                    'title' => 'Email Us',
                    'subtitle' => 'Contact us via Email',
                    'value' => 'support@billspro.com',
                ],
                [
                    'type' => 'live_chat',
                    'title' => 'Live Chat',
                    'subtitle' => 'Contact us via live chat',
                    'available' => true,
                ],
            ],
            'socials' => [
                [
                    'platform' => 'instagram',
                    'name' => 'Instagram',
                    'url' => 'https://instagram.com/billspro',
                ],
                [
                    'platform' => 'facebook',
                    'name' => 'Facebook',
                    'url' => 'https://facebook.com/billspro',
                ],
                [
                    'platform' => 'tiktok',
                    'name' => 'TikTok',
                    'url' => 'https://tiktok.com/@billspro',
                ],
            ],
        ];
    }

    /**
     * Create a new support ticket
     */
    public function createTicket(int $userId, array $data): SupportTicket
    {
        $ticket = SupportTicket::create([
            'user_id' => $userId,
            'ticket_number' => SupportTicket::generateTicketNumber(),
            'subject' => $data['subject'],
            'description' => $data['description'],
            'issue_type' => $data['issue_type'] ?? 'general',
            'status' => 'open',
            'priority' => $data['priority'] ?? 'medium',
        ]);

        return $ticket->load(['user', 'assignedAdmin']);
    }

    /**
     * Get user's tickets
     */
    public function getUserTickets(int $userId, array $filters = []): LengthAwarePaginator
    {
        $query = SupportTicket::where('user_id', $userId)
            ->with(['user', 'assignedAdmin'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['issue_type'])) {
            $query->where('issue_type', $filters['issue_type']);
        }

        $limit = $filters['limit'] ?? 20;
        return $query->paginate($limit);
    }

    /**
     * Get a single ticket
     */
    public function getTicket(int $userId, int $ticketId): SupportTicket
    {
        return SupportTicket::where('user_id', $userId)
            ->where('id', $ticketId)
            ->with(['user', 'assignedAdmin'])
            ->firstOrFail();
    }

    /**
     * Update a ticket
     */
    public function updateTicket(int $userId, int $ticketId, array $data): SupportTicket
    {
        $ticket = $this->getTicket($userId, $ticketId);

        $ticket->update($data);

        return $ticket->fresh(['user', 'assignedAdmin']);
    }

    /**
     * Close a ticket
     */
    public function closeTicket(int $userId, int $ticketId): SupportTicket
    {
        $ticket = $this->getTicket($userId, $ticketId);

        $ticket->update([
            'status' => 'closed',
            'resolved_at' => now(),
        ]);

        return $ticket->fresh(['user', 'assignedAdmin']);
    }
}
