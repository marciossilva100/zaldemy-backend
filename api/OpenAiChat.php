<?php

// Wrapper genérico pra modelos de raciocínio da família gpt-5 via
// /v1/chat/completions. Usado pra gerar frase/pergunta do dia e pra corrigir
// as respostas do aluno. Modelo padrão é o gpt-5-nano (mais barato); pra
// geração de frase/pergunta usamos gpt-5-mini via o 2º parâmetro do
// construtor - testado direto na API, o mini combina os trechos das frases
// do aluno de forma bem mais coerente que o nano, que às vezes misturava
// trechos sem relação (referências soltas a "you"/outra pessoa do nada).
class OpenAiChat {

    private $apiKey;
    private $model;

    public function __construct($apiKey, $model = "gpt-5-nano") {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    // $messages: array no formato [{role, content}, ...] (igual chat/completions padrão)
    public function completar(array $messages, bool $jsonMode = false, int $maxTokens = 400): array {

        $url = "https://api.openai.com/v1/chat/completions";

        $data = [
            "model" => $this->model,
            "messages" => $messages,
            // gpt-5-nano é um modelo de raciocínio - sem isso ele pode gastar
            // todo o max_completion_tokens só "pensando" e nunca responder de
            // fato (finish_reason=length, content vazio). Validado via teste
            // real na API antes de implementar isso.
            "reasoning_effort" => "minimal",
            "max_completion_tokens" => $maxTokens,
        ];

        if ($jsonMode) {
            $data["response_format"] = ["type" => "json_object"];
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer {$this->apiKey}"
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $erroCurl = curl_error($ch);
            return ["erro" => true, "mensagem" => $erroCurl];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $result = json_decode($response, true);

        if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
            $erro = $result['error']['message'] ?? "Erro HTTP {$httpCode}";
            return ["erro" => true, "mensagem" => $erro];
        }

        $texto = trim($result['choices'][0]['message']['content']);

        if ($texto === '') {
            return ["erro" => true, "mensagem" => "Resposta vazia da API (possível estouro de max_completion_tokens)"];
        }

        return ["erro" => false, "texto" => $texto];
    }
}
