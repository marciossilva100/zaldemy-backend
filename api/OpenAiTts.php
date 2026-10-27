<?php

class OpenAiTts {

    private $apiKey;
    // tts-1-hd (não gpt-4o-mini-tts) de propósito - relatado em produção
    // que o gpt-4o-mini-tts, por ser um modelo generativo/autorregressivo
    // (não decodifica o texto letra por letra), às vezes "foge" do texto no
    // meio do áudio em frases mais longas/complexas (ex: perguntas de
    // treino em inglês com vocabulário técnico) - começa lendo certo e
    // deriva pra outro conteúdo. tts-1-hd é o modelo clássico da OpenAI,
    // sempre fiel ao texto de entrada, ao custo de uma voz um pouco menos
    // natural/expressiva.
    private $model = "tts-1-hd";
    private $cacheDir;

    // Vozes nativas do tts-1-hd - só 6 (lista fixa da OpenAI pra esse
    // modelo), diferente das vozes mais novas do gpt-4o-mini-tts (ver
    // Configuracoes::VOZES_TTS_VALIDAS).
    const VOZES_VALIDAS = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    const VOZ_PADRAO = 'nova';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
        // Pasta NOVA (não cache_openai/) de propósito - a troca de
        // gpt-4o-mini-tts pra tts-1-hd (ver comentário em $model acima) não
        // muda a chave de cache (md5 de texto+idioma+voz, sem o nome do
        // modelo), então manter a pasta antiga serviria pra sempre os
        // áudios já cacheados com a fuga/alucinação do modelo antigo,
        // mesmo depois da troca. Pasta nova = cache sempre gerado do zero
        // com o modelo fiel ao texto.
        $this->cacheDir = __DIR__ . "/../cache_openai_tts1hd/";

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    // Velocidade é SEMPRE 1.0 aqui de propósito - a preferência de velocidade
    // do usuário é aplicada só no cliente (audio.playbackRate, ver
    // audioPlayer.js), nunca gerada já "dentro" do áudio. Antes esse método
    // recebia a velocidade e mandava pra API da OpenAI (parâmetro nativo
    // "speed" dela) - só que o cliente TAMBÉM aplicava playbackRate por
    // cima do áudio já acelerado/desacelerado, dobrando o efeito (1.5
    // virava ~2.25x de verdade) - e como o cache aqui é por hash
    // texto+voz+velocidade, mudar a preferência não tinha efeito nenhum em
    // frases já geradas antes com a velocidade antiga, parecendo
    // "inconsistente" entre frases. Gerando sempre no ritmo normal (1.0) e
    // deixando 100% do controle de velocidade pro cliente, fica com a MESMA
    // arquitetura já usada pra voz padrão (Google), que nunca teve esse
    // problema por não aceitar velocidade na API dela.
    public function gerarAudio($texto, $idioma = "pt", $usarCache = true, $vozPreferida = null) {

        $voice = in_array($vozPreferida, self::VOZES_VALIDAS, true) ? $vozPreferida : self::VOZ_PADRAO;

        // .wav (não .mp3) - ver response_format abaixo.
        $hash = md5($texto . $idioma . $voice);
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
            "response_format" => "wav"
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
