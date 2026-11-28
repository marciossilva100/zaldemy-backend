<?php

// Resolve IP -> país/região/cidade via ip-api.com (gratuito, sem necessidade
// de chave). Usado só uma vez, no cadastro, pra registrar de onde o usuário
// se cadastrou - timeout curto pra nunca travar o fluxo de registro caso o
// serviço esteja fora do ar.
class GeoLocalizacao
{
    public static function localizarIp(?string $ip): array
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP) || self::ehIpPrivado($ip)) {
            return [];
        }

        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $resposta = curl_exec($ch);
        curl_close($ch);

        if (!$resposta) {
            return [];
        }

        $dados = json_decode($resposta, true);
        if (!is_array($dados) || ($dados['status'] ?? '') !== 'success') {
            return [];
        }

        return [
            'pais' => $dados['country'] ?? null,
            'regiao' => $dados['regionName'] ?? null,
            'cidade' => $dados['city'] ?? null,
        ];
    }

    private static function ehIpPrivado(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
