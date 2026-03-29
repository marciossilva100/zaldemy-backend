<?php

class ElevenLabs {

    private $apiKey;
    private $voiceId = "cgSgspJ2msm6clMCkdW9";
    private $cacheDir;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
        $this->cacheDir = __DIR__ . "/../cache/";

        // 🔥 cria pasta de cache se não existir
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public function gerarAudio($texto, $idioma = "pt", $usarCache = true) {

        // 🔥 gera hash único
        $hash = md5($texto . $idioma);
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
        $url = "https://api.elevenlabs.io/v1/text-to-speech/{$this->voiceId}";

        $data = [
            "text" => $texto,
            "model_id" => "eleven_multilingual_v2",
            "output_format" => "mp3_44100_128",
            "voice_settings" => [
                "stability" => 0.7,
                "similarity_boost" => 0.8,
                "style" => 0.2,
                "use_speaker_boost" => true
            ]
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "xi-api-key: {$this->apiKey}"
            ],
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            return [
                "erro" => true,
                "mensagem" => curl_error($ch)
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