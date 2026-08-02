<?php

// Wrapper pro gpt-4o-mini-transcribe via /v1/audio/transcriptions
// (multipart, formato compatível com Whisper).
class OpenAiTranscribe {

    private $apiKey;
    private $model = "gpt-4o-mini-transcribe";

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    // $caminhoArquivoTmp: caminho de um arquivo já salvo em disco (ex: $_FILES[...]['tmp_name'])
    public function transcrever(string $caminhoArquivoTmp, string $nomeArquivo = "audio.webm", string $mimeType = "audio/webm"): array {

        if (!file_exists($caminhoArquivoTmp)) {
            return ["erro" => true, "mensagem" => "Arquivo de áudio não encontrado"];
        }

        $url = "https://api.openai.com/v1/audio/transcriptions";

        $cfile = new CURLFile($caminhoArquivoTmp, $mimeType, $nomeArquivo);

        $data = [
            "file" => $cfile,
            "model" => $this->model,
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->apiKey}"
                // Sem Content-Type - o curl monta o multipart/form-data sozinho
                // quando CURLOPT_POSTFIELDS é um array (necessário pro CURLFile funcionar).
            ],
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $erroCurl = curl_error($ch);
            return ["erro" => true, "mensagem" => $erroCurl];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $result = json_decode($response, true);

        if ($httpCode !== 200 || !isset($result['text'])) {
            $erro = $result['error']['message'] ?? "Erro HTTP {$httpCode}";
            return ["erro" => true, "mensagem" => $erro];
        }

        return ["erro" => false, "texto" => trim($result['text'])];
    }
}
