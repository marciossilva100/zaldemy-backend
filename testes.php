<?php

class EnglishParagraphGenerator {
    private $apiKey;
    private $baseUrl = 'https://openrouter.ai/api/v1';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function generateCohesiveParagraph($phrases, $model = null) {
        if (empty($phrases)) {
            throw new Exception("A lista de frases não pode estar vazia.");
        }

        $phrasesText = implode("\n", array_map(function($i, $phrase) {
            return ($i + 1) . '. "' . trim($phrase) . '"';
        }, array_keys($phrases), $phrases));

        $originalWordCount = $this->countWordsInPhrases($phrases);

      $systemPrompt = "Você é um especialista em redação e tradução em inglês.

Sua tarefa é:
1. Criar UM parágrafo coeso e fluido em INGLÊS (máximo 4 linhas) usando as frases fornecidas
2. Em seguida, fornecer a TRADUÇÃO EM PORTUGUÊS DO BRASIL (PT-BR) desse parágrafo

REGRAS ESTRITAS:
- Use PELO MENOS 90% das palavras e estruturas das frases originais
- Faça apenas ajustes gramaticais necessários
- Não adicione novas ideias
- Preserve o significado original

IMPORTANTE:
- A tradução deve ser NATURAL para falantes do Brasil
- Evite português europeu
- Use construções comuns no Brasil

FORMATO DE RESPOSTA (OBRIGATÓRIO):
ENGLISH:
<parágrafo em inglês>

PORTUGUESE (PT-BR):
<tradução em português do Brasil>

Não escreva mais nada além disso.";


        $userPrompt = "Transforme estas frases em um parágrafo coeso de no máximo 4 linhas:\n\n" .


                      $phrasesText . "\n\nParágrafo:";

        $messages = array(
            array('role' => 'system', 'content' => $systemPrompt),
            array('role' => 'user', 'content' => $userPrompt)
        );

        if ($model === null) {
            $modelsToTry = array(
                'deepseek/deepseek-chat',
                'deepseek/deepseek-chat-v3-0324:free',
                'deepseek/deepseek-r1-distill-qwen-32b:free'
            );

            foreach ($modelsToTry as $tryModel) {
                try {
                    $paragraph = $this->callOpenRouterAPI($tryModel, $messages);
                    $wordStats = $this->analyzeWordUsage($paragraph, $phrases, $originalWordCount);

                    return array(
                        'paragraph' => $paragraph,
                        'word_stats' => $wordStats,
                        'phrases_used' => count($phrases),
                        'model_used' => $tryModel
                    );
                } catch (Exception $e) {
                    continue;
                }
            }

            throw new Exception("Todos os modelos testados falharam.");
        }

        $paragraph = $this->callOpenRouterAPI($model, $messages);
        $wordStats = $this->analyzeWordUsage($paragraph, $phrases, $originalWordCount);

        return array(
            'paragraph' => $paragraph,
            'word_stats' => $wordStats,
            'phrases_used' => count($phrases),
            'model_used' => $model
        );
    }

    private function callOpenRouterAPI($model, $messages) {
        $url = $this->baseUrl . '/chat/completions';

        $data = array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 300,
            'top_p' => 0.9
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: http://localhost',
                'X-Title: Sistema Traducao'
            ),
            CURLOPT_TIMEOUT => 30
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro de conexão: " . $error);
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
            $errorMsg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
            throw new Exception("API Error ($httpCode) - Modelo '$model': " . $errorMsg);
        }

        return trim($result['choices'][0]['message']['content']);
    }

    private function countWordsInPhrases($phrases) {
        $allText = implode(' ', $phrases);
        $words = preg_split('/\s+/', strtolower($allText));

        $count = 0;
        foreach ($words as $word) {
            if (!empty($word) && preg_match('/[a-z]/', $word)) {
                $count++;
            }
        }
        return $count;
    }

    private function analyzeWordUsage($paragraph, $phrases, $originalWordCount) {
        $paraWords = preg_split('/\s+/', strtolower($paragraph));
        $origWords = preg_split('/\s+/', strtolower(implode(' ', $phrases)));

        $connectorWords = array(
            'the','a','an','and','but','or','so','because',
            'however','therefore','moreover','is','are','was',
            'were','in','on','at','to','for','of','with'
        );

        $paraWords = array_diff($paraWords, $connectorWords);
        $origWords = array_diff($origWords, $connectorWords);

        $overlap = array_intersect($paraWords, $origWords);

        $percentage = count($origWords) > 0
            ? (count($overlap) / count($origWords)) * 100
            : 0;

        return array(
            'original_words' => $originalWordCount,
            'paragraph_words' => count($paraWords),
            'overlap_percentage' => round($percentage, 1),
            'meets_90_percent' => $percentage >= 90
        );
    }
}