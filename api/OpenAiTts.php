<?php

class OpenAiTts {

    private $apiKey;
    private $model = "gpt-4o-mini-tts";
    private $cacheDir;

    // Vozes nativas do gpt-4o-mini-tts - mesma lista de Configuracoes::VOZES_TTS_VALIDAS.
    const VOZES_VALIDAS = [
        'alloy', 'ash', 'ballad', 'cedar', 'coral', 'echo', 'fable',
        'marin', 'nova', 'onyx', 'sage', 'shimmer', 'verse'
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

    public function gerarAudio($texto, $idioma = "pt", $usarCache = true, $vozPreferida = null, $velocidade = null) {

        $voice = in_array($vozPreferida, self::VOZES_VALIDAS, true) ? $vozPreferida : self::VOZ_PADRAO;
        $speed = is_numeric($velocidade) ? (float) $velocidade : 1.0;
        $speed = max(0.25, min(4.0, $speed));

        // 🔥 gera hash único - inclui voz e velocidade pra não misturar áudios diferentes
        // .wav (não .mp3) - ver response_format abaixo.
        $hash = md5($texto . $idioma . $voice . $speed);
        $file = $this->cacheDir . $hash . ".wav";

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
            // wav (não mp3) - usuário reportou áudio cortado na metade no
            // Safari/iOS. MP3 gerado on-the-fly não tem cabeçalho VBR/Xing
            // completo, e o decodificador do Safari às vezes "acha" que o
            // áudio acabou antes da hora (confirmado que a geração em si
            // estava completa - transcrevi o mp3 de volta e batia 100% com
            // o texto original, então não era truncamento na origem). WAV
            // não tem essa ambiguidade de duração - funciona igual em
            // qualquer navegador/SO, ao custo de arquivo bem maior.
            "response_format" => "wav",
            "speed" => $speed
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
            return [
                "erro" => true,
                "mensagem" => $erroCurl
            ];
        }

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
