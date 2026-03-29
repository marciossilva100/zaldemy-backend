<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

class EnglishParagraphGenerator
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl = 'https://openrouter.ai/api/v1';

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
        Você é um redator profissional. Sua tarefa é transformar uma lista de frases soltas em UM parágrafo coeso e natural em INGLÊS.

        REGRAS:
        - As frases fornecidas são fragmentos que devem ser INTEGRADOS em um texto fluido
        - Crie conexões lógicas entre as ideias
        - Use palavras de transição (however, then, because, etc.)
        - Mantenha o significado original mas REORDENE as ideias se necessário
        - NÃO simplesmente cole as frases com conectivos - funda-as em um texto natural
        - Máximo 4 linhas

        Em seguida, forneça a tradução em PT-BR desse parágrafo.

        EXEMPLO:
        Frases: ['I am happy', 'Today is raining', 'I will stay home']
        MAL: I am happy. Today is raining. I will stay home.
        BEM: Although today is rainy, I'm happy because it gives me a perfect excuse to stay home.

        FORMATO:
        ENGLISH:
        <texto coeso>

        PORTUGUESE (PT-BR):
        <tradução>
        ";

        $userPrompt = "Transforme estas frases em um parágrafo coeso:\n\n" . $phrasesText;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $modelsToTry = $model
            ? [$model]
            : [
                'deepseek/deepseek-chat',
                'deepseek/deepseek-chat-v3-0324:free',
                'deepseek/deepseek-r1-distill-qwen-32b:free'
            ];

        foreach ($modelsToTry as $tryModel) {
            try {
                $paragraph = $this->callOpenRouterAPI($tryModel, $messages);
                $wordStats = $this->analyzeWordUsage(
                    $paragraph,
                    $phrases,
                    $originalWordCount
                );

                return [
                    'paragraph'     => $paragraph,
                    'word_stats'    => $wordStats,
                    'phrases_used'  => count($phrases),
                    'model_used'    => $tryModel
                ];
            } catch (Exception $e) {
                error_log("Modelo {$tryModel} falhou: " . $e->getMessage());
            }
        }

        throw new Exception("Todos os modelos falharam.");
    }

    private function callOpenRouterAPI(string $model, array $messages): string
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 300,
            'top_p' => 0.9
        ];

        $ch = curl_init($this->baseUrl . '/chat/completions');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: http://localhost',
                'X-Title: Sistema Traducao'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
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
            $error = isset($result['error']['message'])
                ? $result['error']['message']
                : 'Erro desconhecido';

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
