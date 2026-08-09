<?php

// Apelido público do usuário - diferente de "nome" (privado, nunca exibido
// pra outros usuários). É o que aparece quando uma categoria é marcada como
// pública/compartilhada, como identificação de quem disponibilizou aquele
// conteúdo.
class Apelido
{
    const TAMANHO_MIN = 3;
    const TAMANHO_MAX = 20;
    const REGEX_VALIDO = '/^[a-zA-Z0-9_]+$/';

    // Retorna mensagem de erro, ou null se o formato é válido (não checa
    // unicidade aqui - ver estaDisponivel).
    public static function validarFormato(string $apelido): ?string
    {
        $tamanho = mb_strlen($apelido);

        if ($tamanho < self::TAMANHO_MIN || $tamanho > self::TAMANHO_MAX) {
            return "O apelido deve ter entre " . self::TAMANHO_MIN . " e " . self::TAMANHO_MAX . " caracteres.";
        }

        if (!preg_match(self::REGEX_VALIDO, $apelido)) {
            return "O apelido só pode ter letras, números e underscore, sem espaços.";
        }

        return null;
    }

    public static function estaDisponivel(PDO $pdo, string $apelido, ?int $excluirUserId = null): bool
    {
        $sql = "SELECT id FROM usuarios WHERE apelido = :apelido";
        $params = [':apelido' => $apelido];

        if ($excluirUserId !== null) {
            $sql .= " AND id != :excluir_id";
            $params[':excluir_id'] = $excluirUserId;
        }

        $stmt = $pdo->prepare($sql . " LIMIT 1");
        $stmt->execute($params);

        return $stmt->fetch() === false;
    }

    // "user_" + 4 caracteres hex aleatórios (ex: user_a3f9) - tenta algumas
    // vezes até achar um valor livre; com o espaço de ~65 mil combinações
    // por tentativa, colisão é rara o suficiente pra não precisar de mais
    // tentativas que isso na prática.
    public static function gerarAutomatico(PDO $pdo): string
    {
        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $candidato = 'user_' . substr(bin2hex(random_bytes(2)), 0, 4);

            if (self::estaDisponivel($pdo, $candidato)) {
                return $candidato;
            }
        }

        // Extremamente improvável de chegar aqui - usa mais caracteres pra
        // garantir unicidade de qualquer jeito.
        return 'user_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    // Valida e checa unicidade do apelido desejado; se vazio, gera um
    // automático. Retorna ["sucesso" => true, "apelido" => string] ou
    // ["sucesso" => false, "erro" => string].
    public static function normalizarOuGerar(PDO $pdo, ?string $apelidoDesejado, ?int $excluirUserId = null): array
    {
        $apelidoDesejado = trim((string) $apelidoDesejado);

        if ($apelidoDesejado === '') {
            return ["sucesso" => true, "apelido" => self::gerarAutomatico($pdo)];
        }

        $erroFormato = self::validarFormato($apelidoDesejado);
        if ($erroFormato !== null) {
            return ["sucesso" => false, "erro" => $erroFormato];
        }

        if (!self::estaDisponivel($pdo, $apelidoDesejado, $excluirUserId)) {
            return ["sucesso" => false, "erro" => "Esse apelido já está em uso."];
        }

        return ["sucesso" => true, "apelido" => $apelidoDesejado];
    }
}
