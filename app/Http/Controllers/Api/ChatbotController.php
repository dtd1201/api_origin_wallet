<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chatbot\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function message(Request $request, ChatbotService $chatbotService): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(
            $chatbotService->reply(
                user: $request->user(),
                message: $validated['message'],
                conversationId: $validated['conversation_id'] ?? null,
            )
        );
    }
}
