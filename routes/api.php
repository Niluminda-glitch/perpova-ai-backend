<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\IngestionController;

// Keep the Ingestion Controller since it works
Route::post('/ingest', [IngestionController::class, 'ingest']);

// Put the Chat Logic DIRECTLY here to bypass the class error!
Route::post('/chat', function (Request $request) {
    $request->validate([
        'question' => 'required|string',
        'url' => 'required|url'
    ]);

    $question = $request->input('question');
    $url = $request->input('url');
    $domain = parse_url($url, PHP_URL_HOST);

    try {
        // 1. Get Embedding from Hugging Face
        $hfToken = env('HF_TOKEN');
        
        $embedResponse = Http::withoutVerifying()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $hfToken,
                'Content-Type' => 'application/json'
            ])
            ->post("https://router.huggingface.co/hf-inference/models/BAAI/bge-base-en-v1.5", [
                'inputs' => $question
            ]);

        if (!$embedResponse->successful()) {
            return response()->json(['error' => 'Embedding Failed', 'details' => $embedResponse->json()], 500);
        }

        $vectorData = $embedResponse->json();
        // Handle array format [[0.1, ...]] or [0.1, ...]
        $vector = isset($vectorData[0]) && is_array($vectorData[0]) ? $vectorData[0] : $vectorData;
        $vectorString = '[' . implode(',', $vector) . ']';

        // 2. Search Database
        $results = DB::select("
            SELECT content, 1 - (embedding <=> '$vectorString'::vector) as similarity
            FROM website_documents 
            WHERE domain_url = ? 
            ORDER BY embedding <=> '$vectorString'::vector 
            LIMIT 4
        ", [$domain]);

        $context = count($results) > 0 
            ? collect($results)->pluck('content')->implode("\n\n")
            : "No specific context found.";

        // 3. Ask Groq (Llama 3)
        $groqKey = env('GROQ_API_KEY');
        
        $systemPrompt = "You are a helpful and polite AI assistant for the website $domain. 
        Answer the user's question based ONLY on the context below. 
        Use formatting like bullet points or numbered lists when listing multiple items. Keep it easy to read.
        If the answer is not in the context, say 'I could not find that information on this website.'
        
        Context:
        $context";

        $chatResponse = Http::withoutVerifying()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $groqKey,
                'Content-Type' => 'application/json'
            ])
            ->post("https://api.groq.com/openai/v1/chat/completions", [
                'model' => 'llama-3.3-70b-versatile',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $question]
                ],
                'temperature' => 0.5
            ]);

        if (!$chatResponse->successful()) {
            return response()->json(['error' => 'Groq API Failed', 'details' => $chatResponse->json()], 500);
        }

        return response()->json([
            'answer' => $chatResponse->json('choices.0.message.content'),
            'context_used' => $results // Optional debug info
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});