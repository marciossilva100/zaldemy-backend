<?php

class Frases
{
    /**
     * Lista todas as frases de uma categoria, adicionando o áudio TTS
     */
    public static function listarFrases(PDO $pdo, int $categoriaId): array
    {
        $sql = "
            SELECT 
                id,
                texto_nativo,
                texto_traduzido,
                categoria_id
            FROM frases
            WHERE categoria_id = :categoria_id
            ORDER BY id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria_id', $categoriaId, PDO::PARAM_INT);
        $stmt->execute();

        $frases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($frases as &$frase) {
            $frase['audio_path'] = self::gerarAudio($frase['texto_traduzido']);
        }

        return $frases;
    }

    /**
     * Usa a API gratuita text2audio.cc para gerar TTS
     */
    private static function gerarAudio(string $texto): string
{
   
    if (!$texto) return "";

    $url = "https://text2audio.cc/api/audio";

    $data = [
        "language" => "pt-BR",
        "paragraphs" => $texto
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Erro TTS: " . curl_error($ch));
        return "";
    }

    if (!$response) {
        error_log("Resposta TTS vazia para o texto: $texto");
        return "";
    }

    // Mostra exatamente o que a API retornou
    error_log("Resposta TTS: " . $response);

    $result = json_decode($response, true);
    if (!$result) {
        error_log("Falha ao decodificar JSON TTS: " . $response);
        return "";
    }

    if (isset($result[0]['url']) && $result[0]['url']) {
        return $result[0]['url'];
    }

    error_log("API TTS não retornou URL: " . $response);
    return "";
}

}
