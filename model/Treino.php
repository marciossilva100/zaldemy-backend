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

    public function treino($idTreino,$user_id) {

        date_default_timezone_set('America/Sao_Paulo');

        global $pdo;

        // if (empty($this->updatedList)) {

        //     return false;
        // }

        try {

            $pdo->beginTransaction();


                $placeholdersUpdate = implode(',', array_fill(0, count($this->updatedIncorrectList), '?'));

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
                        $this->updatedIncorrectList,           // ids do IN (...)
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

                    foreach ($this->updatedIncorrectList as $index => $id) {
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

        // $sql = "
        //     SELECT  
        //         t.status,
        //         t.id,
        //         t.id AS id_treino,
        //         COUNT(DISTINCT f.id) AS total,
        //         MIN(tda.data_atualizacao) AS data_atualizacao
        //     FROM treino t

        //     LEFT JOIN frases f 
        //         ON f.id_treino = t.id
        //         AND f.categoria_id = ?
        //         AND f.usuario_id = ?
        //         AND f.status_id > ?

        //     LEFT JOIN treino_data_atualizacao tda
        //         ON tda.id_frase = f.id
        //         AND tda.id_treino = t.id

        //     WHERE t.id BETWEEN ? AND ?

        //     GROUP BY t.id, t.status
        //     ORDER BY t.id;
        // ";

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
                $date->modify('+4 hours');

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
                AND tda.data_atualizacao <= NOW() - INTERVAL 4 HOUR
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