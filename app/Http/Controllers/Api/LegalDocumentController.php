<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalDocumentController extends Controller
{
    /**
     * Public: legal copy for mobile (no auth).
     *
     * @queryParam keys optional comma-separated list of document keys (e.g. signup_terms,signup_privacy)
     */
    public function index(Request $request): JsonResponse
    {
        $keysParam = $request->query('keys');
        $query = LegalDocument::query()->orderBy('key');

        if (is_string($keysParam) && $keysParam !== '') {
            $keys = array_values(array_filter(array_map('trim', explode(',', $keysParam))));
            $allowed = LegalDocument::allowedKeys();
            $keys = array_values(array_intersect($keys, $allowed));
            if ($keys !== []) {
                $query->whereIn('key', $keys);
            }
        } else {
            $query->whereIn('key', LegalDocument::allowedKeys());
        }

        $documents = $query->get(['key', 'title', 'body', 'updated_at']);

        $map = [];
        foreach ($documents as $doc) {
            $map[$doc->key] = [
                'title' => $doc->title,
                'body' => $doc->body,
                'updated_at' => $doc->updated_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'documents' => $map,
            ],
        ]);
    }
}
