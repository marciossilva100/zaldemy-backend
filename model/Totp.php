<?php

// Implementação de TOTP (RFC 6238) usando só hash_hmac nativo do PHP - sem
// adicionar dependência nova pra um algoritmo padrão e bem documentado.
// Usado exclusivamente pelo login do /dashboard-admin (ver DashboardAuth.php).
class Totp
{
    private const PERIODO_SEGUNDOS = 30;
    private const DIGITOS = 6;
    // Tolerância de 1 período pra antes/depois (±30s) - relógios de celular
    // e servidor nunca estão perfeitamente sincronizados; sem essa folga,
    // um código gerado bem na borda do período vira inválido por 1-2
    // segundos de diferença de relógio.
    private const JANELA_TOLERANCIA = 1;

    // Gera um segredo aleatório em Base32 (formato exigido pelo padrão TOTP
    // e pelos apps autenticadores) - 20 bytes de entropia (160 bits),
    // acima do mínimo de 128 bits recomendado pela RFC 4226.
    public static function gerarSegredo(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    // URI padrão "otpauth://" que os apps autenticadores leem via QR code -
    // o QR em si é renderizado no frontend (qrcode.react), aqui só monta a
    // string.
    public static function uriProvisionamento(string $segredo, string $conta, string $emissor = 'Zaldemy Admin'): string
    {
        return 'otpauth://totp/' . rawurlencode($emissor) . ':' . rawurlencode($conta)
            . '?secret=' . $segredo
            . '&issuer=' . rawurlencode($emissor)
            . '&digits=' . self::DIGITOS
            . '&period=' . self::PERIODO_SEGUNDOS;
    }

    public static function codigoValido(string $segredo, string $codigo): bool
    {
        $codigo = trim($codigo);

        if (!ctype_digit($codigo) || strlen($codigo) !== self::DIGITOS) {
            return false;
        }

        $contadorAtual = (int) floor(time() / self::PERIODO_SEGUNDOS);

        for ($offset = -self::JANELA_TOLERANCIA; $offset <= self::JANELA_TOLERANCIA; $offset++) {
            if (hash_equals(self::gerarCodigo($segredo, $contadorAtual + $offset), $codigo)) {
                return true;
            }
        }

        return false;
    }

    private static function gerarCodigo(string $segredoBase32, int $contador): string
    {
        $chave = self::base32Decode($segredoBase32);
        $contadorBin = pack('N*', 0, $contador); // 8 bytes big-endian (RFC 6238)

        $hash = hash_hmac('sha1', $contadorBin, $chave, true);

        // Truncamento dinâmico (RFC 4226, seção 5.3)
        $offset = ord($hash[19]) & 0x0F;
        $parte = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($parte % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $dados): string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binario = '';
        foreach (str_split($dados) as $byte) {
            $binario .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $resultado = '';
        foreach (str_split($binario, 5) as $grupo) {
            $grupo = str_pad($grupo, 5, '0', STR_PAD_RIGHT);
            $resultado .= $alfabeto[bindec($grupo)];
        }

        return $resultado;
    }

    private static function base32Decode(string $segredo): string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $segredo = strtoupper(rtrim($segredo, '='));
        $binario = '';

        foreach (str_split($segredo) as $char) {
            $posicao = strpos($alfabeto, $char);
            if ($posicao === false) {
                continue;
            }
            $binario .= str_pad(decbin($posicao), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($binario, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }
}
