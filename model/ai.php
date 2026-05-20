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
        Você é um montador de parágrafos. Você NÃO é um escritor criativo. Sua única função é combinar frases prontas em um parágrafo coeso.

        REGRAS OBRIGATÓRIAS — SIGA EXATAMENTE:

        1. Você DEVE usar NO MÍNIMO 4 frases do conjunto fornecido. Sem exceções.
        2. Você NÃO PODE inventar nenhum conteúdo novo. Não crie frases, ideias ou tópicos que não estejam nas frases fornecidas.
        3. O ÚNICO que você pode adicionar são conectivos curtos: and, but, so, also, however, because, then, in addition, moreover.
        4. Você pode MUDAR a ordem das frases para melhorar o fluxo.
        5. Você pode fazer PEQUENOS ajustes gramaticais (pronomes, tempos verbais) para que as frases se conectem naturalmente.
        6. Mantenha o significado original de cada frase. Não resuma nem expanda.

        EXEMPLO DO QUE FAZER:

        Frases fornecidas:
        1. \"I like music\"
        2. \"I study English every day\"
        3. \"My favorite band is Imagine Dragons\"
        4. \"I want to travel to London\"
        5. \"I'm learning to buy plane tickets\"

        Parágrafo correto (usa 5 frases + conectivos):
        \"I study English every day and I like music. My favorite band is Imagine Dragons, also I want to travel to London, so I'm learning to buy plane tickets.\"

        EXEMPLO DO QUE NÃO FAZER:

        Frases fornecidas:
        1. \"I like music\"
        2. \"I study English every day\"

        Parágrafo ERRADO (inventou conteúdo novo):
        \"I'm passionate about learning new things and I enjoy discovering different cultures.\"

        REGRA DE TAMANHO:
        - O texto em INGLÊS deve ter entre 100 e 220 CARACTERES (incluindo espaços).
        - Se estiver abaixo de 100 caracteres, ADICIONE mais frases do conjunto fornecido.
        - Se passar de 220, REMOVA frases mantendo as mais variadas.

        Depois do parágrafo em inglês, forneça APENAS a tradução para o português.

        FORMATO EXATO (COPIE AS PALAVRAS CORRETAMENTE — 'PORTUGUESE' e não 'PORTUGUSE'):

        ENGLISH:
        [parágrafo usando frases fornecidas]

        PORTUGUESE (PT-BR):
        [tradução]
        ";

        $userPrompt = "Use NO MÍNIMO 4 destas frases para montar um parágrafo. Apenas adicione conectivos, não invente conteúdo:\n\n" . $phrasesText;
            
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