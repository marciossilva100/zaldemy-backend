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
    // $idioma: sigla ISO-639-1 (ex: "en") do idioma que o aluno está falando -
    // sem isso o Whisper tenta adivinhar sozinho a partir do áudio, o que é
    // bem menos confiável em gravações curtas, com ruído de fundo ou sotaque
    // (reportado pelo usuário: "falo no microfone mas parece que não capta
    // corretamente" - o áudio podia estar sendo gravado bem e o problema ser
    // só a transcrição errando o idioma).
    public function transcrever(string $caminhoArquivoTmp, string $nomeArquivo = "audio.webm", string $mimeType = "audio/webm", ?string $idioma = null): array {

        if (!file_exists($caminhoArquivoTmp)) {
            return ["erro" => true, "mensagem" => "Arquivo de áudio não encontrado"];
        }

        $url = "https://api.openai.com/v1/audio/transcriptions";

        $cfile = new CURLFile($caminhoArquivoTmp, $mimeType, $nomeArquivo);

        $data = [
            "file" => $cfile,
            "model" => $this->model,
        ];

        if ($idioma) {
            $data["language"] = $idioma;
        }

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
