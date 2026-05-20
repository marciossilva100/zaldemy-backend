<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

class EnglishParagraphGenerator
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl = 'https://api.groq.com/openai/v1';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function generateCohesiveParagraph(array $phrases, $model = null): array
    {
        if (empty($phrases)) {
            throw new Exception("A lista de frases não pode estar vazia.");
        }

        $phrasesText = implode("\n", array_map(function ($i, $phrase) {
            return ($i + 1) . '. "' . trim($phrase) . '"';
        }, array_keys($phrases), $phrases));

        $originalWordCount = $this->countWordsInPhrases($phrases);

      $systemPrompt = "
        You are a paragraph assembler. You are NOT a creative writer. Your only job is to combine provided English phrases into one cohesive paragraph.

        CRITICAL RULES — FOLLOW EXACTLY:

        1. You MUST use AT LEAST 4 phrases from the provided list. No exceptions.
        2. You MUST NOT invent any new content, ideas, or topics.
        3. The ONLY thing you can add are short connectors: and, but, so, also, however, because, then.
        4. You CAN reorder phrases and make small grammatical adjustments so they flow naturally.
        5. The ENTIRE paragraph must be in ENGLISH. Not a single word in Portuguese or any other language. NEVER mix languages.
        6. Do NOT translate anything to Portuguese in the English paragraph. The Portuguese translation comes later, in its own section.
        7. Keep the original meaning of each phrase. Do not summarize or expand.

        SIZE RULE:
        - The English paragraph must be between 100 and 220 characters (including spaces).
        - If under 100 characters, ADD more phrases from the provided list.
        - If over 220 characters, REMOVE some phrases.

        After the English paragraph, provide ONLY the Portuguese translation.

        EXACT FORMAT (spell correctly — 'PORTUGUESE', NOT 'PORTUGUSE'):

        ENGLISH:
        [paragraph using provided phrases — ENGLISH ONLY]

        PORTUGUESE (PT-BR):
        [translation]

        EXAMPLE OF CORRECT OUTPUT:

        ENGLISH:
        I study English every day because I want to travel to London. My favorite band is Imagine Dragons and I also enjoy listening to music while I work. I'm learning to buy plane tickets so I can visit new places.

        PORTUGUESE (PT-BR):
        Eu estudo inglês todos os dias porque quero viajar para Londres. Minha banda favorita é Imagine Dragons e também gosto de ouvir música enquanto trabalho. Estou aprendendo a comprar passagens de avião para poder visitar novos lugares.
        ";

        $userPrompt = "Combine at least 4 of these phrases into one paragraph. Add ONLY connectors, do NOT invent content. The paragraph must be 100% in English — NO Portuguese words:\n\n" . $phrasesText; 
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        // Modelo padrão do Groq
        $model = $model ?? 'llama-3.1-8b-instant';

        $paragraph = $this->callGroqAPI($model, $messages);

        $wordStats = $this->analyzeWordUsage(
            $paragraph,
            $phrases,
            $originalWordCount
        );

        return [
            'paragraph'     => $paragraph,
            'word_stats'    => $wordStats,
            'phrases_used'  => count($phrases),
            'model_used'    => $model
        ];
    }

    private function callGroqAPI(string $model, array $messages): string
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 300,
            'top_p' => 0.95
        ];

        $ch = curl_init($this->baseUrl . '/chat/completions');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception('Erro cURL: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $result = json_decode($response, true);

        if (
            $httpCode !== 200 ||
            !isset($result['choices'][0]['message']['content'])
        ) {
            $error = $result['error']['message'] ?? 'Erro desconhecido';
            throw new Exception("API Error ({$httpCode}): {$error}");
        }

        return trim($result['choices'][0]['message']['content']);
    }

    private function countWordsInPhrases(array $phrases): int
    {
        return count(
            preg_split(
                '/\s+/',
                implode(' ', $phrases),
                -1,
                PREG_SPLIT_NO_EMPTY
            )
        );
    }

    private function analyzeWordUsage(
        string $paragraph,
        array $phrases,
        int $originalWordCount
    ): array {
        $paraWords = preg_split('/\s+/', strtolower($paragraph));
        $origWords = preg_split('/\s+/', strtolower(implode(' ', $phrases)));

        $common = array_intersect($paraWords, $origWords);

        $percentage = $originalWordCount > 0
            ? (count($common) / $originalWordCount) * 100
            : 0;

        return [
            'original_words'     => $originalWordCount,
            'overlap_percentage' => round($percentage, 1),
            'meets_90_percent'   => $percentage >= 90
        ];
    }
}