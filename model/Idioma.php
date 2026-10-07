<?php

require_once '../server.php';

session_start();

class Idioma
{

    public $idioma_nativo;
    public $idioma_aprender;
    public $user_id;

    public static function listarIdiomas($modo = null,$user_id): array
    {

        global $pdo; // 👈 precisa disso

        $sql = "
            SELECT 
                id,
                idioma,
                sigla
            FROM idiomas
        ";

        if($modo =='learning'){
            // COALESCE(..., 0) é essencial aqui: se o usuário ainda não tem
            // idioma_nativo salvo em idioma_referencia, a subquery retorna NULL,
            // e "id <> NULL" nunca é verdadeiro pra nenhuma linha - a lista
            // inteira vinha vazia em vez de simplesmente não excluir nada.
            $sql .=" WHERE id <> COALESCE((SELECT idioma_nativo FROM idioma_referencia WHERE id_user = :id_user AND idioma_nativo IS NOT NULL LIMIT 1), 0)";
        }

        $sql .=" ORDER BY id ASC";

       // print_r($sql);

        $stmt = $pdo->prepare($sql);

        if($modo =='learning')
            $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    public function setIdiomaNativo($user_id): array
    {

        global $pdo; // 👈 precisa disso

        // Bug real encontrado: havia uma guarda aqui que, quando
        // usuarios.step já era > 0 (ou seja, o usuário tinha passado por
        // essa tela antes), retornava [] sem tocar em idioma_referencia -
        // "código comentado, nunca implementado" no lugar do UPDATE. Isso
        // quebrava silenciosamente o fluxo de "voltar e escolher outro
        // idioma nativo" (EscolherIdiomaNativo.jsx com fromBack=true): o
        // frontend achava que tinha salvo (sem data.erro), mas o
        // checkAuth(true) seguinte trazia de volta o idioma antigo do
        // banco, nunca atualizado. O upsert abaixo (INSERT ... ON
        // DUPLICATE KEY UPDATE) já cobre corretamente tanto a primeira
        // escolha quanto uma re-escolha depois, então essa guarda era
        // redundante além de quebrada.

        // alguns fluxos (ex: login com Google) já criam a linha em idioma_referencia
        // (com idioma_nativo/idioma_aprender NULL) antes desse passo. Usa upsert
        // atômico (INSERT ... ON DUPLICATE KEY UPDATE, com UNIQUE KEY em id_user)
        // em vez de "SELECT existe? -> decide" -- essa segunda forma tem uma
        // brecha de corrida (duas requisições quase simultâneas podem ver "não
        // existe" ao mesmo tempo e ambas inserirem, duplicando a linha).
        $sql = "
            INSERT INTO idioma_referencia (idioma_nativo, id_user)
            VALUES (:idioma_nativo, :id_user)
            ON DUPLICATE KEY UPDATE idioma_nativo = VALUES(idioma_nativo)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_nativo', $this->idioma_nativo, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        // Categoria automática única foi substituída pela escolha de 3
        // categorias de interesse no onboarding (EscolherCategoriasInteresse.jsx
        // + CategoriaIA::criarParaOnboarding) - não cadastra mais nada aqui.

        $sql = 'UPDATE usuarios SET step = 1 WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['step'] = 1;


        $sql = "SELECT sigla FROM idiomas WHERE id = :idioma_id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_id', $this->idioma_nativo, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        $_SESSION['native_language'] = $result['sigla'];

        return [
            'success' => true,
            'message' => 'Idioma inserido com sucesso',
            'id' => (int) $pdo->lastInsertId()
        ];

    }

    public function setIdiomaAprender($user_id): array
    {

        global $pdo; // 👈 precisa disso

        // Mesmo bug de setIdiomaNativo() (ver comentário lá): guarda morta
        // que retornava [] sem atualizar idioma_referencia quando o usuário
        // já tinha step>1, quebrando a re-escolha do idioma a aprender ao
        // voltar nessa tela. Removida - o UPDATE abaixo já cobre o caso de
        // re-escolha normalmente (idioma_referencia já existe nesse ponto,
        // criada no passo do idioma nativo).

        $sql = 'UPDATE idioma_referencia SET idioma_aprender = :idioma_aprender 
        WHERE id_user = :id_user AND idioma_nativo > 0 LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_aprender', $this->idioma_aprender, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $sql = 'UPDATE usuarios SET step = 2 WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['step'] = 2;

        $sql = "SELECT sigla FROM idiomas WHERE id = :idioma_id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_id', $this->idioma_aprender, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        $_SESSION['learning_language'] =  $result['sigla'];

        return [
            'success' => true,
            'message' => 'Idioma inserido com sucesso',
            'id' => (int) $pdo->lastInsertId()
        ];

    }


    public static function buscarPorId(int $id): ?array
    {

        global $pdo; 

        $sql = "SELECT * FROM idiomas WHERE id = :id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $resultado = $stmt->fetch();

        return $resultado ?: null;

    }

    public function setIdiomaReferencia($user_id): array
    {
        return $this->atualizarReferenciaAprendizado($user_id);
    }

    public function atualizarReferenciaAprendizado($user_id): array
    {
        global $pdo;

        $sql = "UPDATE idioma_referencia
                SET idioma_aprender = :idioma_aprender";

        if ($this->idioma_nativo !== null) {
            $sql .= ", idioma_nativo = :idioma_nativo";
        }

        $sql .= " WHERE id_user = :id_user LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_aprender', $this->idioma_aprender, PDO::PARAM_INT);

        if ($this->idioma_nativo !== null) {
            $stmt->bindValue(':idioma_nativo', $this->idioma_nativo, PDO::PARAM_INT);
        }

        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $sql = "SELECT sigla FROM idiomas WHERE id = :idioma_id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_id', $this->idioma_aprender, PDO::PARAM_INT);
        $stmt->execute();

        $resultado = $stmt->fetch();

        if (!empty($resultado['sigla'])) {
            $_SESSION['learning_language'] = $resultado['sigla'];
        }

        return [
            'success' => true,
            'message' => 'Idioma atualizado com sucesso'
        ];
    }

}