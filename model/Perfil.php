<?php

// Upload de foto de perfil - arquivo salvo em disco (pasta avatars/ na raiz
// do backend, mesmo padrão de api/ElevenLabs.php pro cache de áudio) e só o
// caminho relativo fica salvo em usuarios.foto_perfil. O front monta a URL
// completa prefixando com VITE_API_URL.
class Perfil
{
    const TAMANHO_MAX_BYTES = 3 * 1024 * 1024; // 3MB

    const MIME_PERMITIDOS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private static function diretorioAvatars(): string
    {
        $dir = __DIR__ . '/../avatars/';

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    public static function uploadFoto(PDO $pdo, int $user_id, array $arquivo): array
    {
        if (!isset($arquivo['tmp_name']) || $arquivo['error'] !== UPLOAD_ERR_OK) {
            return ["success" => false, "message" => "Falha no envio do arquivo."];
        }

        if ($arquivo['size'] > self::TAMANHO_MAX_BYTES) {
            return ["success" => false, "message" => "A imagem precisa ter no máximo 3MB."];
        }

        // Confere o tipo real do arquivo (não só a extensão/nome enviado,
        // que o cliente pode forjar) via finfo lendo os bytes de verdade.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($arquivo['tmp_name']);

        if (!isset(self::MIME_PERMITIDOS[$mime])) {
            return ["success" => false, "message" => "Formato inválido. Use uma imagem JPG, PNG ou WEBP."];
        }

        $extensao = self::MIME_PERMITIDOS[$mime];
        $nomeArquivo = $user_id . '_' . time() . '.' . $extensao;
        $caminhoRelativo = 'avatars/' . $nomeArquivo;
        $caminhoAbsoluto = self::diretorioAvatars() . $nomeArquivo;

        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoAbsoluto)) {
            return ["success" => false, "message" => "Não foi possível salvar a imagem."];
        }

        self::removerFotoAntiga($pdo, $user_id);

        $stmt = $pdo->prepare("UPDATE usuarios SET foto_perfil = :foto WHERE id = :id");
        $stmt->execute([':foto' => $caminhoRelativo, ':id' => $user_id]);

        return ["success" => true, "foto_perfil" => $caminhoRelativo];
    }

    public static function removerFoto(PDO $pdo, int $user_id): array
    {
        self::removerFotoAntiga($pdo, $user_id);

        $stmt = $pdo->prepare("UPDATE usuarios SET foto_perfil = NULL WHERE id = :id");
        $stmt->execute([':id' => $user_id]);

        return ["success" => true];
    }

    // Apaga o arquivo antigo do disco (se existir) antes de salvar um novo -
    // sem isso, cada troca de foto deixava um arquivo órfão acumulando.
    private static function removerFotoAntiga(PDO $pdo, int $user_id): void
    {
        $stmt = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $atual = $stmt->fetch(PDO::FETCH_ASSOC)['foto_perfil'] ?? null;

        if (!$atual) {
            return;
        }

        $caminhoAbsoluto = __DIR__ . '/../' . $atual;

        if (is_file($caminhoAbsoluto)) {
            @unlink($caminhoAbsoluto);
        }
    }
}
