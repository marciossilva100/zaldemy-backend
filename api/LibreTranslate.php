<?php
carregarEnv(__DIR__ . '/../.env');

class LibreTranslate
{
    public $text;
    public $sourceLang;
    public $targetLang;

    public function translateText():array
    {
        $url = "https://translate.googleapis.com/translate_a/single?" . http_build_query([
            "client" => "gtx",
            "sl" => $this->sourceLang,
            "tl" => $this->targetLang,
            "dt" => "t",
            "q" => $this->text
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "", 
            CURLOPT_USERAGENT => "Mozilla/5.0",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);

   // print_r($response);

        if ($response === false) {
            @curl_close($ch);
            return null;
        }

        @curl_close($ch);

        // remove caracteres inválidos que quebram json_decode
        $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');

        $json = json_decode($response, true);

        if (!$json) {
            return null;
        }

        $translated = '';

        foreach ($json[0] as $part) {
            $translated .= $part[0];
        }

         return [
            'success' => true,
            'message' => $translated,
        ];


    }

    function dividirTexto($texto, $limite = 200) {
        $frases = preg_split('/(?<=[.!?])\s+/', $texto);
        $partes = [];
        $buffer = '';

        foreach ($frases as $frase) {
            if (mb_strlen($buffer . ' ' . $frase) <= $limite) {
                $buffer .= ' ' . $frase;
            } else {
                $partes[] = trim($buffer);
                $buffer = $frase;
            }
        }

        if (!empty($buffer)) {
            $partes[] = trim($buffer);
        }

        return $partes;
    }

   // MÉTODO PARA GERAR ÁUDIO
    public function getAudio($lang)
    {
        if (!$this->text || !$lang) {
            return null;
        }

        // limite de segurança para evitar erro do Google TTS
        /* $text = mb_substr($this->text, 0, 200); */
$text = $this->text;
        $url = "https://translate.google.com/translate_tts?" . http_build_query([
            "ie" => "UTF-8",
            "q" => $text,
            "tl" => $lang,
            "client" => "tw-ob"
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_USERAGENT => "Mozilla/5.0",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10
        ]);

        $audio = curl_exec($ch);

        if ($audio === false) {
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        return $audio;
    } 

   /*  public function getAudio($lang = 'pt-BR')
    {

        if (!isset($_ENV['TTS'])) {
            die(json_encode([
                "erro" => true,
                "mensagem" => "API KEY não configurada"
            ]));
        }

        if (!$this->text) {
            return null;
        }

        $url = 'https://ttsforfree.com/api/tts';
        
        $data = [
            'text' => $this->text,
            'voice' => 'pt-BR-AntonioNeural',
            'api_key' => $_ENV['TTS']
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        
        // A API retorna a URL do arquivo de áudio gerado
        if (isset($result['audio_url'])) {
            return file_get_contents($result['audio_url']);
        }
        
        return null;
    } */
}