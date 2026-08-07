<?php

// Controla em quais países o cadastro está liberado. Quem já tem conta
// continua acessando normalmente de qualquer lugar - isso só entra na hora
// de criar uma conta nova (cadastro tradicional e primeiro login via
// Google).
class PaisesLiberados
{
    public static function listar(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT codigo, nome FROM paises_liberados ORDER BY nome ASC");
        return $stmt->fetchAll();
    }

    public static function adicionar(PDO $pdo, string $codigo, string $nome): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO paises_liberados (codigo, nome) VALUES (:codigo, :nome)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome)"
        );
        $stmt->execute([
            ':codigo' => strtoupper(trim($codigo)),
            ':nome' => trim($nome),
        ]);
    }

    public static function remover(PDO $pdo, string $codigo): void
    {
        $stmt = $pdo->prepare("DELETE FROM paises_liberados WHERE codigo = :codigo");
        $stmt->execute([':codigo' => strtoupper(trim($codigo))]);
    }

    private static function estaLiberado(PDO $pdo, string $codigo): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM paises_liberados WHERE codigo = :codigo LIMIT 1");
        $stmt->execute([':codigo' => strtoupper(trim($codigo))]);
        return (bool) $stmt->fetchColumn();
    }

    // IP de quem está fazendo a requisição - X-Forwarded-For primeiro
    // (comum atrás de proxy/CDN), REMOTE_ADDR como fallback.
    private static function ipDaRequisicao(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($forwarded) {
            $partes = explode(',', $forwarded);
            return trim($partes[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    // Geolocalização por IP via ip-api.com (gratuito, sem chave). IP
    // local/privado (dev) devolve null de propósito - sem isso, testar em
    // localhost sempre bloquearia o cadastro.
    private static function paisPorIp(string $ip): ?string
    {
        if (
            $ip === '' || $ip === '127.0.0.1' || $ip === '::1'
            || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)
        ) {
            return null;
        }

        $ch = curl_init("http://ip-api.com/json/" . urlencode($ip) . "?fields=status,countryCode");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return null;

        $data = json_decode($response);
        if (!$data || ($data->status ?? '') !== 'success') return null;

        return $data->countryCode ?? null;
    }

    // Verdadeiro só quando o país foi identificado COM CERTEZA e não está
    // na lista - falha do serviço de geolocalização ou IP local nunca
    // bloqueia (fail-open, pra um serviço externo fora do ar não impedir
    // cadastros de verdade).
    public static function cadastroBloqueado(PDO $pdo): bool
    {
        $codigo = self::paisPorIp(self::ipDaRequisicao());
        if ($codigo === null) return false;

        return !self::estaLiberado($pdo, $codigo);
    }
}
