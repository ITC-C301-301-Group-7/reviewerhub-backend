<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * POST /api/translate  { text, source, target }
 *
 * The React app extracts text (PDF / Word / PowerPoint / OCR) in the browser and sends it
 * here in pieces of about 2,500 characters. This controller forwards it to the configured
 * translation provider (see config/services.php -> translation):
 *
 *   mymemory       free, no account, small daily quota (default)
 *   libretranslate self-hosted or hosted LibreTranslate, no practical quota
 */
class TranslationController extends Controller
{
    // MyMemory rejects queries over 500 bytes, so stay safely below that per request.
    private const CHUNK_BYTES = 450;

    public function translate(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:3000'],
            'source' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z]{2,4})?$/'],
            'target' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z]{2,4})?$/', 'different:source'],
        ]);

        @set_time_limit(90);

        try {
            $source = $validated['source'];
            $target = $validated['target'];
            $out = [];
            $batch = [];
            $size = 0;

            // Consecutive short lines are sent together (one provider call instead of one per line),
            // which keeps long PDFs fast and within the provider's free quota.
            $flush = function () use (&$batch, &$size, &$out, $source, $target) {
                if ($batch !== []) {
                    $out[] = $this->translateChunk(implode("\n", $batch), $source, $target);
                    $batch = [];
                    $size = 0;
                }
            };

            foreach (preg_split('/\R/', $validated['text']) as $line) {
                $line = trim($line);

                if ($line === '') {
                    $flush();
                    $out[] = '';
                    continue;
                }

                if (strlen($line) > self::CHUNK_BYTES) {
                    $flush();
                    $pieces = [];
                    foreach ($this->chunk($line) as $chunk) {
                        $pieces[] = $this->translateChunk($chunk, $source, $target);
                    }
                    $out[] = implode(' ', $pieces);
                    continue;
                }

                if ($size > 0 && $size + 1 + strlen($line) > self::CHUNK_BYTES) {
                    $flush();
                }

                $batch[] = $line;
                $size += strlen($line) + 1;
            }

            $flush();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Could not reach the translation service. Check the server\'s internet connection.',
            ], 503);
        }

        return response()->json([
            'translation' => implode("\n", $out),
            'provider' => config('services.translation.provider'),
        ]);
    }

    private function translateChunk(string $text, string $source, string $target): string
    {
        $provider = config('services.translation.provider', 'mymemory');
        $key = 'translate:' . sha1("$provider|$source|$target|$text");

        // Cached so re-translating the same document does not use up the provider quota.
        return Cache::remember($key, now()->addDays(7), function () use ($provider, $text, $source, $target) {
            return $provider === 'libretranslate'
                ? $this->libreTranslate($text, $source, $target)
                : $this->myMemory($text, $source, $target);
        });
    }

    private function myMemory(string $text, string $source, string $target): string
    {
        $params = ['q' => $text, 'langpair' => "$source|$target"];

        // Adding an email raises the free daily quota (see MYMEMORY_EMAIL in .env).
        if ($email = config('services.translation.mymemory_email')) {
            $params['de'] = $email;
        }

        $response = Http::timeout(20)->acceptJson()->get('https://api.mymemory.translated.net/get', $params);

        if (!$response->ok()) {
            throw new RuntimeException('The translation service is unavailable right now. Please try again.');
        }

        $data = $response->json();
        $translated = $data['responseData']['translatedText'] ?? null;
        $status = (int) ($data['responseStatus'] ?? 200);

        if (
            $status !== 200
            || !is_string($translated)
            || str_starts_with($translated, 'MYMEMORY WARNING')
            || str_starts_with($translated, 'QUERY LENGTH LIMIT')
        ) {
            if ($status === 429 || str_starts_with((string) $translated, 'MYMEMORY WARNING')) {
                throw new RuntimeException(
                    'The free translation quota for today is used up. Set MYMEMORY_EMAIL in .env for a higher limit, '
                        . 'or switch TRANSLATION_PROVIDER to libretranslate.'
                );
            }

            throw new RuntimeException($data['responseDetails'] ?? 'The translation service could not translate this text.');
        }

        return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function libreTranslate(string $text, string $source, string $target): string
    {
        $base = rtrim((string) config('services.translation.libretranslate_url'), '/');

        if ($base === '') {
            throw new RuntimeException('LIBRETRANSLATE_URL is not set in .env.');
        }

        $payload = ['q' => $text, 'source' => $source, 'target' => $target, 'format' => 'text'];

        if ($apiKey = config('services.translation.libretranslate_key')) {
            $payload['api_key'] = $apiKey;
        }

        $response = Http::timeout(30)->acceptJson()->post("$base/translate", $payload);

        if (!$response->ok() || !is_string($response->json('translatedText'))) {
            throw new RuntimeException($response->json('error') ?: 'LibreTranslate could not translate this text.');
        }

        return $response->json('translatedText');
    }

    /** Splits one line into pieces below CHUNK_BYTES, preferring sentence and word boundaries. */
    private function chunk(string $line): array
    {
        if (strlen($line) <= self::CHUNK_BYTES) {
            return [$line];
        }

        $chunks = [];
        $current = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $line) as $sentence) {
            foreach ($this->splitLongSentence($sentence) as $piece) {
                if ($current !== '' && strlen($current) + 1 + strlen($piece) > self::CHUNK_BYTES) {
                    $chunks[] = $current;
                    $current = $piece;
                } else {
                    $current = $current === '' ? $piece : "$current $piece";
                }
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function splitLongSentence(string $sentence): array
    {
        if (strlen($sentence) <= self::CHUNK_BYTES) {
            return [$sentence];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/\s+/u', $sentence) as $word) {
            // A single "word" longer than the limit (rare): cut it by characters.
            while (strlen($word) > self::CHUNK_BYTES) {
                $cut = mb_strcut($word, 0, self::CHUNK_BYTES, 'UTF-8');
                $pieces[] = $cut;
                $word = substr($word, strlen($cut));
            }

            if ($current !== '' && strlen($current) + 1 + strlen($word) > self::CHUNK_BYTES) {
                $pieces[] = $current;
                $current = $word;
            } else {
                $current = $current === '' ? $word : "$current $word";
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
