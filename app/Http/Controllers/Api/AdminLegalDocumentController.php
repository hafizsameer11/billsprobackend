<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLegalDocumentController extends Controller
{
    public function index(): JsonResponse
    {
        $documents = LegalDocument::query()
            ->whereIn('key', LegalDocument::allowedKeys())
            ->orderBy('key')
            ->get(['id', 'key', 'title', 'body', 'updated_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'documents' => $documents,
            ],
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (! in_array($key, LegalDocument::allowedKeys(), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown document key.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:500000'],
        ]);

        $doc = LegalDocument::query()->where('key', $key)->firstOrFail();
        $doc->update([
            'title' => $validated['title'],
            'body' => $validated['body'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Legal document updated.',
            'data' => [
                'document' => $doc->fresh(['key', 'title', 'body', 'updated_at']),
            ],
        ]);
    }
}
