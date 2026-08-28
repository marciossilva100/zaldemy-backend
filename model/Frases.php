<?php

require_once '../server.php';
//session_start();

class Frases
{

    public $id;
    public $texto_nativo;
    public $texto_traduzido;
    public $categoriaId;
    public $correctIds;
    public $response = array();

    // Conta frases criadas hoje pelo usuário, usada pro limite diário de
    // criação (planos free/limitado) - premium não tem esse teto. Exclui
    // categorias tipo=3 (categorias de interesse geradas por IA no
    // onboarding, ver CategoriaIA::criarParaOnboarding - 8 frases por
    // interesse escolhido, inseridas com a data de hoje) - sem essa
    // exclusão, quem escolhe 2+ interesses no cadastro já chega na tela de
    // Frases com o limite diário estourado, sem ter criado nada sozinho.
    // Mesma exclusão já usada em Categorias::contarCategoriasAtivas.
    public static function contarFrasesCriadasHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM frases f
                INNER JOIN categorias c ON c.id = f.categoria_id
                WHERE f.usuario_id = :user_id
                  AND f.status_id > 0
                  AND DATE(f.data_criacao) = CURDATE()
                  AND (c.tipo IS NULL OR c.tipo <> 3)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    // $somenteEstudadas=true restringe a frases que já passaram por
    // id_treino >= 2 - usado pelos jogos (Chuva de Frases), que devem
    // praticar só conteúdo que o aluno já demonstrou conhecer, nunca
    // vocabulário ainda não estudado (ver ModaTreino/Frases.jsx normal, que
    // continua chamando com o padrão false pra listar tudo).
    public function listarFrases($user_id, bool $somenteEstudadas = false): array
    {

        global $pdo; // 👈 precisa disso

        $sql = "
           SELECT
            f.id,
            f.texto_nativo,
            f.texto_traduzido,
            f.categoria_id
        FROM frases f
        INNER JOIN categorias c ON c.id = f.categoria_id
        WHERE f.categoria_id = :categoria_id
        AND f.usuario_id = :id_user
        AND f.status_id <> 0
        AND c.status_id > 0"
        . ($somenteEstudadas ? " AND f.id_treino >= 2" : "") . "
        ORDER BY f.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria_id', $this->categoriaId, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {

        global $pdo; // 👈 precisa disso

        $sql = "
           SELECT 
            f.id,
            f.texto_nativo,
            f.texto_traduzido,
            f.categoria_id
        FROM frases f
        INNER JOIN categorias c ON c.id = f.categoria_id
        WHERE f.categoria_id = :categoria_id
        AND f.status_id <> 0
        AND c.status_id > 0
        ORDER BY f.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria_id', $this->categoriaId, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

     public function listarFrasesAprender($user_id): array
    {

        global $pdo; // 👈 precisa disso


         $sql = "SELECT quantidade_frases_aprender FROM configuracoes WHERE user_id = :id_user LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        // Filtra pelo par de idioma ATUAL do usuário (idioma_referencia) - sem
        // isso, uma categoria com frases de mais de um par (ex: usuário já
        // trocou o idioma que está aprendendo, ou tem frases de testes em
        // outro par) mostrava frases de um idioma diferente do configurado
        // como nativo/aprendendo agora. O texto_nativo então não batia com o
        // idioma nativo real do usuário, e o áudio (que sempre usa a voz do
        // idioma nativo configurado) saía errado.
        $sql = "
            SELECT
                f.id,
                f.texto_nativo,
                f.texto_traduzido,
                f.categoria_id
            FROM frases f
            INNER JOIN categorias c ON c.id = f.categoria_id
            INNER JOIN idioma_referencia ir
                ON ir.idioma_nativo = f.idioma_nativo
                AND ir.idioma_aprender = f.idioma_aprendendo
                AND ir.id_user = f.usuario_id
            WHERE f.categoria_id = :categoria_id
            AND f.usuario_id = :id_user
            AND f.status_id > 0
            AND f.id_treino = 1
            AND c.status_id > 0
        ";

        $params = [
            ':categoria_id' => $this->categoriaId,
            ':id_user' => $user_id,
            ':quantidade_frases_aprender' => $result['quantidade_frases_aprender']
        ];

        if (!empty($this->correctIds)) {
            // cria placeholders :id0, :id1, :id2...
            $placeholders = [];
            foreach ($this->correctIds as $index => $id) {
                $ph = ":id{$index}";
                $placeholders[] = $ph;
                $params[$ph] = $id;
            }

            $sql .= " AND f.id IN (" . implode(',', $placeholders) . ")";
        }

        $sql .= " ORDER BY f.id DESC LIMIT :quantidade_frases_aprender";

        $stmt = $pdo->prepare($sql);

        // bind dinâmico
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


      public function listarFrasesReview($user_id): array
    {

        global $pdo; // 👈 precisa disso

        // Mesmo filtro de par de idioma ATUAL do usuário usado em
        // listarFrasesAprender - ver comentário lá.
        $sql = "
            SELECT
                f.id,
                f.texto_nativo,
                f.texto_traduzido,
                f.categoria_id
            FROM frases f
            INNER JOIN categorias c ON c.id = f.categoria_id
            INNER JOIN idioma_referencia ir
                ON ir.idioma_nativo = f.idioma_nativo
                AND ir.idioma_aprender = f.idioma_aprendendo
                AND ir.id_user = f.usuario_id
            WHERE f.categoria_id = :categoria_id
            AND f.usuario_id = :id_user
            AND f.status_id > 0
            AND f.id_treino = 4
            AND c.status_id > 0
            ORDER BY f.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria_id', $this->categoriaId, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }



    public function excluirFrase($user_id)
    {
        global $pdo;

        $sql = "UPDATE frases 
                SET status_id = 0 
                WHERE id = :id 
                AND usuario_id = :usuario_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':usuario_id', $user_id, PDO::PARAM_INT);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            return [
                'success' => true,
                'message' => 'Frase removida com sucesso'
            ];
        }

        return [
            'success' => false,
            'message' => 'Frase não encontrada'
        ];
    }


  

     public function addFrases($user_id): array
    {
        date_default_timezone_set('America/Sao_Paulo');

        if (empty(trim($this->texto_nativo)) || empty(trim($this->texto_traduzido))) {
            return [
                'success' => false,
                'message' => 'Os textos não podem estar vazios.'
            ];
        }

        global $pdo; 

        $sql = "
            SELECT id,idioma_nativo,idioma_aprender
            FROM idioma_referencia
            WHERE id_user = :id_user 
            AND idioma_nativo > 0
            AND idioma_aprender > 0 LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        if (!empty($result['id'])) {

            $sql = "SELECT id FROM frases WHERE usuario_id = :id_user 
            AND texto_nativo = :texto_nativo 
            AND texto_traduzido = :texto_traduzido AND status_id > 0";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':texto_nativo', $this->texto_nativo, PDO::PARAM_STR);
            $stmt->bindValue(':texto_traduzido', $this->texto_traduzido, PDO::PARAM_STR);
            $stmt->execute();

            $result_frase = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if(!empty($result_frase)){
                return [
                'success' => false,
                'message' => 'O mesmo texto e tradução já estão cadastrados no sistema.',
             ];
            }


            // limite de caracteres
            if (mb_strlen($this->texto_nativo) > 100) {
                return [
                    'success' => false,
                    'message' => 'O texto nativo precisa ter no máximo 100 caracteres'
                ];
            }

             // limite de caracteres
            if (mb_strlen($this->texto_traduzido) > 100) {
                return [
                    'success' => false,
                    'message' => 'O texto traduzido precisa ter no máximo 100 caracteres'
                ];
            }

           $sql = "INSERT INTO frases 
            (usuario_id,texto_nativo,texto_traduzido,idioma_nativo,idioma_aprendendo,categoria_id,id_treino,status_id)
            VALUES (:user_id,:texto_nativo,:texto_traduzido,:idioma_nativo,:idioma_aprender,:categoria_id,1,1)
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':categoria_id', $this->categoriaId, PDO::PARAM_INT);
            $stmt->bindValue(':texto_nativo', $this->texto_nativo, PDO::PARAM_STR);
            $stmt->bindValue(':texto_traduzido', $this->texto_traduzido, PDO::PARAM_STR);
            $stmt->bindValue(':idioma_nativo', $result['idioma_nativo'], PDO::PARAM_INT);
            $stmt->bindValue(':idioma_aprender', $result['idioma_aprender'], PDO::PARAM_INT);
            $stmt->execute();

            $id_frase = (int) $pdo->lastInsertId();

            $sql = "INSERT INTO treino_data_atualizacao (id_frase,id_treino,status_id) VALUES (:id_frase,:id_treino,:status_id)";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':status_id', 1, PDO::PARAM_INT);
            $stmt->bindValue(':id_frase', $id_frase, PDO::PARAM_INT);
            $stmt->bindValue(':id_treino', 1, PDO::PARAM_INT);
            $stmt->execute();

            return [
                'success' => true,
                'message' => 'Idioma inserido com sucesso',
                'id' => $id_frase
             ];

        }else{
            return [
                'success' => false,
                'message' => 'Nenhum idioma de referência encontrado.'
            ];
        }

        
    }

    public function editarFrase($user_id): array
    {
        if (empty(trim($this->texto_nativo)) || empty(trim($this->texto_traduzido))) {
            return [
                'success' => false,
                'message' => 'Os textos não podem estar vazios.'
            ];
        }

        if (mb_strlen($this->texto_nativo) > 100) {
            return [
                'success' => false,
                'message' => 'O texto nativo precisa ter no máximo 100 caracteres'
            ];
        }

        if (mb_strlen($this->texto_traduzido) > 100) {
            return [
                'success' => false,
                'message' => 'O texto traduzido precisa ter no máximo 100 caracteres'
            ];
        }

        global $pdo;

        $sql = "SELECT id FROM frases WHERE id = :id AND usuario_id = :usuario_id AND status_id > 0";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':usuario_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        if (!$stmt->fetch()) {
            return [
                'success' => false,
                'message' => 'Frase não encontrada'
            ];
        }

        $sql = "SELECT id FROM frases WHERE usuario_id = :id_user
            AND texto_nativo = :texto_nativo
            AND texto_traduzido = :texto_traduzido
            AND status_id > 0
            AND id <> :id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':texto_nativo', $this->texto_nativo, PDO::PARAM_STR);
        $stmt->bindValue(':texto_traduzido', $this->texto_traduzido, PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->fetch()) {
            return [
                'success' => false,
                'message' => 'O mesmo texto e tradução já estão cadastrados no sistema.'
            ];
        }

        $sql = "UPDATE frases
                SET texto_nativo = :texto_nativo, texto_traduzido = :texto_traduzido
                WHERE id = :id
                AND usuario_id = :usuario_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':usuario_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':texto_nativo', $this->texto_nativo, PDO::PARAM_STR);
        $stmt->bindValue(':texto_traduzido', $this->texto_traduzido, PDO::PARAM_STR);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Frase editada com sucesso'
        ];
    }

}
