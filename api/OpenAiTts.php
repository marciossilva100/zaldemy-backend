<?php

class OpenAiTts {

    private $apiKey;
    private $model = "tts-1";
    private $cacheDir;

    // Mapeia a preferência de voz do usuário (Configuracoes::VOZES_TTS_VALIDAS)
    // pras vozes reais da OpenAI.
    const VOZES = [
        'feminina' => 'nova',
        'masculina' => 'onyx',
    ];
    const VOZ_PADRAO = 'nova';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
        // Cache separado do ElevenLabs de propósito - evita que um mesmo
        // texto sirva áudio de um provedor diferente do configurado no
        // momento, caso a gente volte a trocar de provedor no futuro.
        $this->cacheDir = __DIR__ . "/../cache_openai/";

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public function gerarAudio($texto, $idioma = "pt", $usarCache = true, $vozPreferida = null) {

        $voice = self::VOZES[$vozPreferida] ?? self::VOZ_PADRAO;

        // 🔥 gera hash único - inclui a voz pra não misturar áudio de vozes diferentes
        $hash = md5($texto . $idioma . $voice);
        $file = $this->cacheDir . $hash . ".mp3";

        // =========================
        // 🔥 CACHE HIT
        // =========================
        if ($usarCache && file_exists($file)) {
            return [
                "erro" => false,
                "audio" => file_get_contents($file),
                "cache" => true
            ];
        }

        // =========================
        // 🌐 CHAMADA API
        // =========================
        $url = "https://api.openai.com/v1/audio/speech";

        $data = [
            "model" => $this->model,
            "input" => $texto,
            "voice" => $voice,
            "response_format" => "mp3"
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer {$this->apiKey}"
            ],
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $erroCurl = curl_error($ch);
            curl_close($ch);
            return [
                "erro" => true,
                "mensagem" => $erroCurl
            ];
        }

        curl_close($ch);

        // =========================
        // ❌ ERRO HTTP
        // =========================
        if ($httpCode !== 200) {
            return [
                "erro" => true,
                "mensagem" => "Erro HTTP {$httpCode}",
                "resposta" => $response
            ];
        }

        // =========================
        // ❌ API RETORNOU JSON (ERRO)
        // =========================
        if (strpos($response, '{') === 0) {
            return [
                "erro" => true,
                "mensagem" => "Erro da API",
                "resposta" => $response
            ];
        }

        // =========================
        // 💾 SALVAR CACHE
        // =========================
        if ($usarCache) {
            file_put_contents($file, $response);
        }

        return [
            "erro" => false,
            "audio" => $response,
            "cache" => false
        ];
    }
}
