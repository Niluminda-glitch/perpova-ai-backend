<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngestionController extends Controller
{
    public function ingest(Request $request)
    {
        set_time_limit(300); // Allow script to run for 5 minutes
        
        $request->validate(['url' => 'required|url']);
        $url = $request->input('url');
        $domain = parse_url($url, PHP_URL_HOST);

        try {
            // 1. Scrape the website HTML
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ])
                ->timeout(15)
                ->get($url);
            
            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to access the website.'], 400);
            }

            $html = $response->body();

            // 2. Clean encoding
            $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html, 'UTF-8, ISO-8859-1, WINDOWS-1252', true) ?: 'UTF-8');
            $html = mb_scrub($html, 'UTF-8'); 

            // 3. Clean up the HTML
            $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $html);
            $text = strip_tags($html);
            $text = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            if (empty($text)) {
                return response()->json(['error' => 'No readable text found on the page.'], 400);
            }

            // 4. Chunk SAFELY
            $chunks = mb_str_split($text, 1000, 'UTF-8');
            $geminiKey = env('GEMINI_API_KEY');
            $processedCount = 0;

            // 5. Convert to Vectors
            foreach ($chunks as $chunk) {
                if (mb_strlen($chunk) < 50) continue;

                try {
                    $hfToken = env('HF_TOKEN');
                    
                    // The correct Router Domain + A model strictly built for Feature Extraction!
                    $embedResponse = Http::withoutVerifying()
                        ->withHeaders([
                            'Authorization' => 'Bearer ' . $hfToken,
                            'Content-Type' => 'application/json'
                        ])
                        ->post("https://router.huggingface.co/hf-inference/models/BAAI/bge-base-en-v1.5", [
                            'inputs' => $chunk
                        ]);

                    // LOUD DEBUG
                    if (!$embedResponse->successful()) {
                        return response()->json([
                            'error' => 'Hugging Face API Failed!',
                            'details' => $embedResponse->json(),
                            'status' => $embedResponse->status()
                        ], 500);
                    }

                    $vectorData = $embedResponse->json();
                    
                    // HF returns either [0.1, 0.2...] or [[0.1, 0.2...]]. We handle both!
                    $vector = isset($vectorData[0]) && is_array($vectorData[0]) ? $vectorData[0] : $vectorData;
                    
                    if (is_array($vector) && count($vector) === 768) {
                        $vectorString = '[' . implode(',', $vector) . ']';
                        
                        DB::table('website_documents')->insert([
                            'domain_url' => $domain,
                            'content' => $chunk,
                            'embedding' => DB::raw("'$vectorString'::vector")
                        ]);
                        
                        $processedCount++;
                    } else {
                        Log::warning("Skipped chunk: Vector dimension mismatch.");
                    }
                } catch (\Exception $chunkException) {
                    return response()->json([
                        'error' => 'Crash during chunk processing!',
                        'message' => $chunkException->getMessage()
                    ], 500);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully ingested data for {$domain}",
                'chunks_processed' => $processedCount
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Main Crash', 'message' => $e->getMessage()], 500);
        }
    }
}