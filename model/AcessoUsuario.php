<?php

// Histórico de login do usuário (data/hora, IP, dispositivo/navegador) -
// registrado a cada login bem-sucedido (email/senha e Google), consultável
// pelo próprio usuário em Configurações, útil pra ele perceber acesso
// indevido à conta.
class AcessoUsuario
{
    public static function ipDoCliente(): ?string
    {
        // Atrás de proxy/load balancer, REMOTE_ADDR é o IP do proxy - X-Forwarded-For
        // (quando presente) traz o IP real do cliente na primeira posição.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $partes = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($partes[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    public static function registrar(PDO $pdo, int $user_id): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO acessos_usuario (user_id, ip, user_agent) VALUES (:user_id, :ip, :user_agent)"
        );
        $stmt->execute([
            ':user_id' => $user_id,
            ':ip' => self::ipDoCliente(),
            ':user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    public static function listar(PDO $pdo, int $user_id, int $limite = 20): array
    {
        $sql = "SELECT ip, user_agent, data_acesso
                FROM acessos_usuario
                WHERE user_id = :user_id
                ORDER BY id DESC
                LIMIT :limite";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
