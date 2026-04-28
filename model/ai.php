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
        Você é um redator extremamente conciso. Sua tarefa é transformar frases em UM ÚNICO parágrafo em inglês.

        REGRAS OBRIGATÓRIAS:

        - Crie um parágrafo coeso, natural e fluido em inglês.
        - Você pode reordenar as ideias.
        - Não copie as frases literalmente.

        REGRA CRÍTICA DE TAMANHO (NÃO NEGOCIÁVEL):
        - O texto em INGLÊS deve ter NO MÁXIMO 250 CARACTERES (incluindo espaços).
        - Isso equivale a aproximadamente 40-55 palavras.
        - ANTES de finalizar, conte os caracteres.
        - Se passar de 250 caracteres, reduza drasticamente e reescreva até ficar abaixo do limite.
        - É melhor ser curto e direto do que ultrapassar o limite.

        Exemplo de tamanho máximo permitido:
        \"This is an example of text that respects the 250 character limit. It is concise and well written.\" (cerca de 98 caracteres)

        Nunca exceda 250 caracteres no texto inglês.

         Depois do parágrafo em inglês, forneça apenas a tradução para o português.

        FORMATO EXATO E ÚNICO (COPIE EXATAMENTE AS PALAVRAS 'ENGLISH:' E 'PORTUGUESE (PT-BR):' SEM ERROS DE DIGITAÇÃO):

        ENGLISH:
        [seu texto aqui - máximo 250 caracteres]

        PORTUGUESE (PT-BR):
        [tradução]

        ATENÇÃO: Escreva corretamente 'PORTUGUESE' e não 'PORTUGUSE'.
        ";

        $userPrompt = "Transforme estas frases em um parágrafo coeso:\n\n" . $phrasesText;

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