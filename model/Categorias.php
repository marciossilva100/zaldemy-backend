<?php
session_start();

class Categorias
{
    public static function listarComQuantidade(PDO $pdo,$user_id): array
    {
        $sql = "
            SELECT 
                c.id,
                c.categoria,
                COUNT(f.id) AS total_frases,
                COALESCE(idioma_nativo_ref.sigla, '') AS idioma_nativo,
                COALESCE(idioma_aprendendo_ref.sigla, '') AS idioma_aprendendo
            FROM categorias c
            LEFT JOIN frases f 
                ON f.categoria_id = c.id
                AND f.status_id > 0
            LEFT JOIN idiomas AS idioma_nativo_ref
                ON idioma_nativo_ref.id = c.idioma_nativo
            LEFT JOIN idiomas AS idioma_aprendendo_ref
                ON idioma_aprendendo_ref.id = c.idioma_aprendendo
            WHERE c.id_user = :id_user
            AND c.status_id > 0
            GROUP BY c.id, c.categoria, idioma_nativo_ref.sigla, idioma_aprendendo_ref.sigla
            ORDER BY c.id ASC;
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }


    public static function getAllFrases(PDO $pdo, $categoriaId): array
    {
        $sql = "
            SELECT 
                f.id,
                f.texto_nativo,
                f.texto_traduzido,
                f.categoria_id,
                f.idioma_nativo,
                f.idioma_aprendendo
            FROM frases f
            INNER JOIN categorias c ON c.id = f.categoria_id
            WHERE f.categoria_id = :categoria_id
            AND f.status_id <> 0
            AND c.public > 0
            AND c.status_id > 0
            ORDER BY f.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria_id', $categoriaId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

     public static function addFrases(PDO $pdo,$user_id, array $frases,$categoria_id): array
    {
        date_default_timezone_set('America/Sao_Paulo');

        $inseridas = 0;
        $ignoradas = 0;

        foreach ($frases as $frase) {

            $texto_nativo = trim($frase['texto_nativo'] ?? '');
            $texto_traduzido = trim($frase['texto_traduzido'] ?? '');

            // validação
            if ($texto_nativo === '' || $texto_traduzido === '') {
                $ignoradas++;
                continue;
            }

            if (mb_strlen($texto_nativo) > 100 || mb_strlen($texto_traduzido) > 100) {
                $ignoradas++;
                continue;
            }

            // verifica duplicado
            $sql = "SELECT f.id 
            FROM frases f
            INNER JOIN categorias c ON c.id = f.categoria_id
            WHERE f.usuario_id = :id_user 
            AND f.texto_nativo = :texto_nativo 
            AND f.texto_traduzido = :texto_traduzido 
            AND f.status_id > 0
            AND c.status_id > 0";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':texto_nativo', $texto_nativo, PDO::PARAM_STR);
            $stmt->bindValue(':texto_traduzido', $texto_traduzido, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->fetch()) {
                $ignoradas++;
                continue;
            }

            // insert frase
            $sql = "INSERT INTO frases 
            (usuario_id, texto_nativo, texto_traduzido, idioma_nativo, idioma_aprendendo, categoria_id, id_treino, status_id)
            VALUES (:user_id, :texto_nativo, :texto_traduzido, :idioma_nativo, :idioma_aprendendo, :categoria_id, 1, 1)";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':texto_nativo', $texto_nativo, PDO::PARAM_STR);
            $stmt->bindValue(':texto_traduzido', $texto_traduzido, PDO::PARAM_STR);
            $stmt->bindValue(':idioma_nativo', $frase['idioma_nativo'], PDO::PARAM_INT);
            $stmt->bindValue(':idioma_aprendendo', $frase['idioma_aprendendo'], PDO::PARAM_INT);
            $stmt->bindValue(':categoria_id', $categoria_id, PDO::PARAM_INT);
            $stmt->execute();

            $id_frase = (int) $pdo->lastInsertId();

            // insert treino
            $sql = "INSERT INTO treino_data_atualizacao 
                    (id_frase, id_treino, status_id) 
                    VALUES (:id_frase, 1, 1)";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id_frase', $id_frase, PDO::PARAM_INT);
            $stmt->execute();

            $inseridas++;
        }

        return [
            'success' => true,
            'message' => 'Processamento concluído.',
            'inseridas' => $inseridas,
            'ignoradas' => $ignoradas
        ];
    }


    public static function addFrasesFromJson(PDO $pdo, $user_id, string $jsonPath): array
    {
        date_default_timezone_set('America/Sao_Paulo');

        $inseridas = 0;
        $ignoradas = 0;
        $categoriasCriadas = 0;

        // Lê o JSON
        if (!file_exists($jsonPath)) {
            return [
                'success' => false,
                'message' => 'Arquivo JSON não encontrado.'
            ];
        }

        $json = file_get_contents($jsonPath);
        $categorias = json_decode($json, true);

        if (!is_array($categorias)) {
            return [
                'success' => false,
                'message' => 'JSON inválido.'
            ];
        }

        // mapa id => sigla e sigla => id para todos os idiomas cadastrados
        $stmt = $pdo->query("SELECT id, sigla FROM idiomas");
        $idPorSigla = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $idioma) {
            $idPorSigla[$idioma['sigla']] = (int) $idioma['id'];
        }

        foreach ($categorias as $categoriaData) {

            $titles = $categoriaData['title'] ?? [];
            $translations = $categoriaData['translations'] ?? [];

            if (!is_array($titles) || !is_array($translations)) {
                continue;
            }

            // só considera siglas que existem de fato na tabela idiomas
            $siglasDisponiveis = array_values(array_intersect(array_keys($titles), array_keys($idPorSigla)));

            // gera todas as combinações ordenadas nativo -> aprendendo (pt->en, en->pt, es->en, ...)
            foreach ($siglasDisponiveis as $siglaNativo) {
                foreach ($siglasDisponiveis as $siglaAprendendo) {

                    if ($siglaNativo === $siglaAprendendo) {
                        continue;
                    }

                    $titulo = trim($titles[$siglaNativo] ?? '');

                    if ($titulo === '') {
                        continue;
                    }

                    $idiomaNativoId = $idPorSigla[$siglaNativo];
                    $idiomaAprendendoId = $idPorSigla[$siglaAprendendo];

                    // cria a categoria para esse par de idiomas (tipo 2 = importada via JSON, public = 1)
                    $resultadoCategoria = self::cadastrarCategoria(
                        $pdo, $titulo, $user_id, null, 1, 2, $idiomaNativoId, $idiomaAprendendoId
                    );
                    $categoria_id = $resultadoCategoria['id'] ?? null;

                    if (empty($categoria_id)) {
                        continue;
                    }

                    if ($resultadoCategoria['success']) {
                        $categoriasCriadas++;
                    }

                    foreach ($translations as $frase) {

                        $texto_nativo = trim($frase[$siglaNativo] ?? '');
                        $texto_traduzido = trim($frase[$siglaAprendendo] ?? '');

                        // validação
                        if ($texto_nativo === '' || $texto_traduzido === '') {
                            $ignoradas++;
                            continue;
                        }

                        if (mb_strlen($texto_nativo) > 100 || mb_strlen($texto_traduzido) > 100) {
                            $ignoradas++;
                            continue;
                        }

                        // verifica duplicado (mesmo usuário, mesmo par de idiomas, mesmo texto)
                        $sql = "SELECT f.id
                        FROM frases f
                        INNER JOIN categorias c ON c.id = f.categoria_id
                        WHERE f.usuario_id = :id_user
                        AND f.texto_nativo = :texto_nativo
                        AND f.texto_traduzido = :texto_traduzido
                        AND f.idioma_nativo = :idioma_nativo
                        AND f.idioma_aprendendo = :idioma_aprendendo
                        AND f.status_id > 0
                        AND c.status_id > 0";

                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
                        $stmt->bindValue(':texto_nativo', $texto_nativo, PDO::PARAM_STR);
                        $stmt->bindValue(':texto_traduzido', $texto_traduzido, PDO::PARAM_STR);
                        $stmt->bindValue(':idioma_nativo', $idiomaNativoId, PDO::PARAM_INT);
                        $stmt->bindValue(':idioma_aprendendo', $idiomaAprendendoId, PDO::PARAM_INT);
                        $stmt->execute();

                        if ($stmt->fetch()) {
                            $ignoradas++;
                            continue;
                        }

                        // insert frase
                        $sql = "INSERT INTO frases
                        (usuario_id, texto_nativo, texto_traduzido, idioma_nativo, idioma_aprendendo, categoria_id, id_treino, status_id)
                        VALUES (:user_id, :texto_nativo, :texto_traduzido, :idioma_nativo, :idioma_aprendendo, :categoria_id, 3, 1)";

                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                        $stmt->bindValue(':texto_nativo', $texto_nativo, PDO::PARAM_STR);
                        $stmt->bindValue(':texto_traduzido', $texto_traduzido, PDO::PARAM_STR);
                        $stmt->bindValue(':idioma_nativo', $idiomaNativoId, PDO::PARAM_INT);
                        $stmt->bindValue(':idioma_aprendendo', $idiomaAprendendoId, PDO::PARAM_INT);
                        $stmt->bindValue(':categoria_id', $categoria_id, PDO::PARAM_INT);
                        $stmt->execute();

                        $id_frase = (int) $pdo->lastInsertId();

                        // insert treino
                        $sql = "INSERT INTO treino_data_atualizacao
                                (id_frase, id_treino, status_id)
                                VALUES (:id_frase, 3, 1)";

                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':id_frase', $id_frase, PDO::PARAM_INT);
                        $stmt->execute();

                        $inseridas++;
                    }
                }
            }
        }

        return [
            'success' => true,
            'message' => 'Processamento concluído.',
            'categorias_criadas' => $categoriasCriadas,
            'inseridas' => $inseridas,
            'ignoradas' => $ignoradas
        ];
    }

    public static function getAll(PDO $pdo, $user_id, int $page = 1, int $perPage = 20): array
    {
        $page = max($page, 1);
        $offset = ($page - 1) * $perPage;

        $sql = "
           SELECT 
                c.id,
                c.categoria,
                COUNT(CASE 
                    WHEN f.id IS NOT NULL THEN 1 
                END) AS total_frases
            FROM categorias c

            LEFT JOIN frases f 
                ON f.categoria_id = c.id
                AND f.status_id > 0

            LEFT JOIN categorias uc
                ON uc.id_categoria_publica = c.id
                AND uc.id_user = :id_user

            WHERE c.public > 0
            AND c.status_id > 0
            AND c.id_user <> :id_user
            AND uc.id IS NULL

            GROUP BY c.id, c.categoria
            ORDER BY c.id ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById(PDO $pdo, int $categoria): ? string
    {
        $sql = "
            SELECT id, categoria 
            FROM categorias WHERE id = :categoria 
            LIMIT 1;
        ";

        $stmt = $pdo->prepare($sql);

        // Bind correto como inteiro
        $stmt->bindValue(':categoria', $categoria, PDO::PARAM_INT);

        $stmt->execute();

        // Retorna apenas um registro ou null
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['categoria'] ?: null;
    }

    public static function excluirCategoria(PDO $pdo,int $id,int $user_id): array
    {
        // verifica se a categoria existe e pertence ao usuário
        $sql = "SELECT id 
                FROM categorias 
                WHERE id = :id 
                AND id_user = :id_user
                AND status_id > 0
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $categoria = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$categoria) {
            return [
                'success' => false,
                'message' => 'Categoria não encontrada ou já excluída'
            ];
        }

        // faz o soft delete
        $sql = "UPDATE categorias 
                SET status_id = 0 
                WHERE id = :id 
                AND id_user = :id_user";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Categoria excluída com sucesso',
            'id' => $id
        ];
    }

    public static function editarCategoria(PDO $pdo, int $id, string $categoria, int $user_id ): array
    {

        // limite de caracteres
        if (mb_strlen($categoria) > 20) {
            return [
                'success' => false,
                'message' => 'A categoria deve ter no máximo 20 caracteres'
            ];
        }

        // atualiza direto
        $sql = "UPDATE categorias 
                SET categoria = :categoria 
                WHERE id = :id 
                AND id_user = :id_user";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria', $categoria, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            return [
                'success' => true,
                'message' => 'Categoria atualizada com sucesso',
                'id' => $id
            ];
        }

        return [
            'success' => false,
            'message' => 'Nenhuma alteração realizada'
        ];
    }


    public static function cadastrarCategoria(PDO $pdo, string $categoria,$user_id,$categoria_id = null,$categoria_publica = 0,$tipo = null,$idioma_nativo = null,$idioma_aprendendo = null): array
    {
        if ($idioma_nativo !== null || $idioma_aprendendo !== null) {
            // par de idiomas informado explicitamente (ex: importação em massa)
            $idiomaNativo = $idioma_nativo;
            $idiomaAprendendo = $idioma_aprendendo;
        } else {
            // busca idioma nativo e idioma aprendendo do usuário
            $sql = "
                SELECT idioma_nativo, idioma_aprender
                FROM idioma_referencia
                WHERE id_user = :id_user
                AND idioma_nativo > 0
                AND idioma_aprender > 0 LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
            $stmt->execute();

            $idiomaReferencia = $stmt->fetch(PDO::FETCH_ASSOC);

            $idiomaNativo = $idiomaReferencia['idioma_nativo'] ?? null;
            $idiomaAprendendo = $idiomaReferencia['idioma_aprender'] ?? null;
        }

        // verifica se já existe (não difere maiusculas de minusculas) para o mesmo par de idiomas
        $sql = "SELECT id
            FROM categorias
            WHERE LOWER(categoria) = LOWER(:categoria)
            AND id_user = :id_user
            AND status_id > 0
            AND idioma_nativo <=> :idioma_nativo
            AND idioma_aprendendo <=> :idioma_aprendendo
            LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria', $categoria, PDO::PARAM_STR);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':idioma_nativo', $idiomaNativo, $idiomaNativo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':idioma_aprendendo', $idiomaAprendendo, $idiomaAprendendo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        $existente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            return [
                'success' => false,
                'message' => 'Categoria já existe',
                'id' => $existente['id']
            ];
        }

        // limite de caracteres
        if (mb_strlen($categoria) > 20) {
            return [
                'success' => false,
                'message' => 'A categoria deve ter no máximo 20 caracteres'
            ];
        }

        // se não existe, insere
        $sql = "INSERT INTO categorias (categoria,id_user,public,id_categoria_publica,idioma_nativo,idioma_aprendendo,tipo) VALUES (:categoria,:id_user,:categoria_publica,:categoria_id,:idioma_nativo,:idioma_aprendendo,:tipo)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria', $categoria, PDO::PARAM_STR);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':categoria_publica', $categoria_publica, PDO::PARAM_INT);
        $stmt->bindValue(':categoria_id', $categoria_id, PDO::PARAM_INT);
        $stmt->bindValue(':idioma_nativo', $idiomaNativo, $idiomaNativo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':idioma_aprendendo', $idiomaAprendendo, $idiomaAprendendo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $tipo, $tipo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Categoria criada com sucesso',
            'id' => (int) $pdo->lastInsertId()
        ];
    }

}
