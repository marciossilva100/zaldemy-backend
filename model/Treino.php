<?php
require_once '../server.php';
session_start();

class Treino {
    public $category_id;
    public $id_frase = array(); // array de ids
    public $data;
    public $updatedList;
    public $updatedIncorrectList;
    public $acertos;
    public $erros;
    public $total;
    public $porcentagem;
    public $acerto;

    public function treino($idTreino,$user_id) {

        date_default_timezone_set('America/Sao_Paulo');

        global $pdo;

        // if (empty($this->updatedList)) {

        //     return false;
        // }

        try {

            $pdo->beginTransaction();

                $placeholdersUpdate = implode(',', array_fill(0, count($this->updatedList), '?'));

                    $sqlUpdate = "
                        UPDATE frases 
                        SET id_treino = ? 
                        WHERE id IN ($placeholdersUpdate)
                        AND status_id > 0
                        AND categoria_id = ?
                        AND usuario_id = ?
                    ";

                    $stmtUpdate = $pdo->prepare($sqlUpdate);

                    $paramsUpdate = array_merge(
                        [$idTreino],               // primeiro ? do SET
                        $this->updatedList,           // ids do IN (...)
                        [
                            $this->category_id, 
                            $user_id
                        ]
                    );

                    $stmtUpdate->execute($paramsUpdate);

                    // =========================
                    // 2️⃣ INSERT
                    // =========================
                    $placeholdersInsert = [];
                    $paramsInsert = [];

                    foreach ($this->updatedList as $index => $id) {
                        $placeholdersInsert[] = "(?, ?, ?)";
                        $paramsInsert[] = $id;
                        $paramsInsert[] = $idTreino;
                        $paramsInsert[] = 1; // status_id
                    }

                    $sqlInsert = "
                        INSERT INTO treino_data_atualizacao 
                        (id_frase, id_treino, status_id) 
                        VALUES " . implode(',', $placeholdersInsert);

                    $stmtInsert = $pdo->prepare($sqlInsert);
                    $stmtInsert->execute($paramsInsert);


            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Atualizado',
            ];

        } catch (Exception $e) {
            $pdo->rollBack();

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }


 public function retornarTreino($idTreino, $user_id)
{
    date_default_timezone_set('America/Sao_Paulo');

    global $pdo;

    try {

        $pdo->beginTransaction();

        // =========================
        // 1️⃣ SELECT com métricas
        // =========================
        $sqlSelect = "
            SELECT 
                tda.id_frase,
                f.categoria_id,
                COALESCE(AVG(m.acertou), 0) as media_acertos,
                tda.data_atualizacao as ultima_data

            FROM treino_data_atualizacao tda

            INNER JOIN (
                SELECT id_frase, MAX(data_atualizacao) as max_data
                FROM treino_data_atualizacao
                GROUP BY id_frase
            ) ult 
                ON ult.id_frase = tda.id_frase 
                AND ult.max_data = tda.data_atualizacao

            INNER JOIN frases f 
                ON f.id = tda.id_frase

            LEFT JOIN metricas m 
                ON m.frase_id = tda.id_frase
                AND m.user_id = ?

            WHERE 
                tda.id_treino = ?
                
            GROUP BY tda.id_frase, f.categoria_id, tda.data_atualizacao

            HAVING ultima_data <= NOW() - INTERVAL 
                CASE 
                    WHEN media_acertos >= 0.7 THEN 15
                    ELSE 7
                END DAY
        ";

        $stmtSelect = $pdo->prepare($sqlSelect);
        $stmtSelect->execute([$user_id, $idTreino]);

        $dados = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

        if (empty($dados)) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Nenhuma frase encontrada'
            ];
        }

        // =========================
        // 2️⃣ Agrupar por categoria
        // =========================
        $agrupado = [];

        foreach ($dados as $row) {
            $agrupado[$row['categoria_id']][] = $row['id_frase'];
        }

        // =========================
        // 3️⃣ UPDATE por categoria
        // =========================
        foreach ($agrupado as $categoria_id => $ids) {

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $sqlUpdate = "
                UPDATE frases 
                SET id_treino = 2
                WHERE id IN ($placeholders)
                AND status_id > 0
                AND categoria_id = ?
                AND usuario_id = ?
            ";

            $stmtUpdate = $pdo->prepare($sqlUpdate);

            $params = array_merge(
                $ids,
                [
                    $categoria_id,
                    $user_id
                ]
            );

            $stmtUpdate->execute($params);
        }

        // =========================
        // 4️⃣ INSERT (id_treino = 2)
        // =========================
        $placeholdersInsert = [];
        $paramsInsert = [];

        foreach ($dados as $row) {
            $placeholdersInsert[] = "(?, ?, ?)";
            $paramsInsert[] = $row['id_frase'];
            $paramsInsert[] = 2;
            $paramsInsert[] = 1;
        }

        $sqlInsert = "
            INSERT INTO treino_data_atualizacao 
            (id_frase, id_treino, status_id) 
            VALUES " . implode(',', $placeholdersInsert);

        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->execute($paramsInsert);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Processo concluído com sucesso',
            'total' => count($dados)
        ];

    } catch (Exception $e) {
        $pdo->rollBack();

        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}


    public function metricasFrase($user_id){

        global $pdo;

        $sqlMetricas = "
                    INSERT INTO metricas (frase_id, user_id, acertou)
                    VALUES (:updatedList,:user_id,:acertou)";

        $stmt = $pdo->prepare($sqlMetricas);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':updatedList', (int)$this->updatedList[0], PDO::PARAM_INT);
        $stmt->bindValue(':acertou', $this->acerto, PDO::PARAM_INT);

        $stmt->execute();

        $id_metrica = (int) $pdo->lastInsertId();

    }

    public function metricas($user_id){

        global $pdo;

            // =========================
            // 3️⃣ INSERT MÉTRICAS
            // =========================

            // junta tudo (acertos + erros)
            $placeholdersMetricas = [];
            $paramsMetricas = [];

            // acertos
            foreach ($this->updatedList as $frase_id) {
                $placeholdersMetricas[] = "(?, ?, ?)";
                $paramsMetricas[] = $frase_id;
                $paramsMetricas[] = $user_id;
                $paramsMetricas[] = 1;
            }

            // erros
            foreach ($this->updatedIncorrectList as $frase_id) {
                $placeholdersMetricas[] = "(?, ?, ?)";
                $paramsMetricas[] = $frase_id;
                $paramsMetricas[] = $user_id;
                $paramsMetricas[] = 0;
            }

            // só executa se tiver algo
            if (!empty($placeholdersMetricas)) {

                $sqlMetricas = "
                    INSERT INTO metricas (frase_id, user_id, acertou)
                    VALUES " . implode(',', $placeholdersMetricas);

                $stmtMetricas = $pdo->prepare($sqlMetricas);
                $stmtMetricas->execute($paramsMetricas);
            }

            $pdo->commit();

    }


    
   // 🔥 NOVO MÉTODO
    public function estatisticasTreino($user_id) {

        date_default_timezone_set('America/Sao_Paulo');
        global $pdo;


        $sql = "SELECT  
            t.status,
            t.id,
            t.id AS id_treino,
            COUNT(DISTINCT f.id) AS total,
            MIN(tda.data_atualizacao) AS data_atualizacao
        FROM treino t

        LEFT JOIN frases f 
            ON f.id_treino = t.id
            AND f.categoria_id = ?
            AND f.usuario_id = ?
            AND f.status_id > ?

        LEFT JOIN categorias c
            ON c.id = f.categoria_id
            AND c.status_id > 0

        LEFT JOIN treino_data_atualizacao tda
            ON tda.id_frase = f.id
            AND tda.id_treino = t.id

        WHERE t.id BETWEEN ? AND ?

        GROUP BY t.id, t.status
        ORDER BY t.id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $this->category_id,        // categoria_id
            $user_id,      // usuario_id
            0,                         // status_id > 0
            1,                         // inicio
            5                          // fim
        ]);

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($result as &$row) {

            if (!empty($row['data_atualizacao'])) {

                $date = new DateTime($row['data_atualizacao']);
                $date->modify('+2 hours');

                $agora = new DateTime();

                $segundosRestantes = $date->getTimestamp() - $agora->getTimestamp();

                $row['segundos_restantes'] = $segundosRestantes > 0 ? $segundosRestantes : 0;
                $row['disponivel'] = $segundosRestantes <= 0;
            } else {
                $row['data_liberacao'] = null;
                $row['disponivel'] = true;
            }
        }

        return [
            'success' => true,
            'data' => $result
        ];
    }


      public function repeatPhrases($user_id): array
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
        AND f.status_id > 0
        AND f.id_treino = 3
        AND c.status_id > 0
        ORDER BY f.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria_id', $this->category_id, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateRepeat($set_id_treino, $id_treino,$user_id) {

        date_default_timezone_set('America/Sao_Paulo');

        global $pdo;

        try {

            $pdo->beginTransaction();

            $sql = "
                UPDATE frases f
                INNER JOIN treino_data_atualizacao tda
                    ON tda.id_frase = f.id
                SET 
                    f.id_treino = ?,
                    tda.id_treino = ?
                WHERE tda.id_treino = ?
                AND f.id_treino = ?
                AND f.usuario_id = ?
                AND f.categoria_id = ?
                AND tda.data_atualizacao <= NOW() - INTERVAL 2 hours
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                $set_id_treino,        // novo treino frases
                $set_id_treino,        // novo treino treino_data_atualizacao
                $id_treino,            // treino atual em treino_data_atualizacao
                $id_treino,            // treino atual em frases
                $user_id,
                $this->category_id
            ]);

            $movidos = $stmt->rowCount();

            $pdo->commit();

            return [
                'sucesso' => true,
                'movidos' => $movidos
            ];

        } catch (Exception $e) {

            $pdo->rollBack();

            return [
                'sucesso' => false,
                'erro' => $e->getMessage()
            ];
        }

    }

    private function jaPassaram5Horas($dataAtualizacao)
    {
        if (empty($dataAtualizacao)) {
            return true; // nunca treinou → pode liberar
        }

        $timezone = new DateTimeZone('UTC'); // use o mesmo padrão do banco

        $data = new DateTime($dataAtualizacao, $timezone);
        $agora = new DateTime('now', $timezone);

        // Soma 5 horas
        $data->modify('+4 hours');

        return $agora >= $data;
    }
}