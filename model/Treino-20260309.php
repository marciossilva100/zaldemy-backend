<?php
require_once '../server.php';
session_start();

class Treino {
    public $category_id;
    public $id_frase = array(); // array de ids
    public $data;
    public $updatedList;
    public $updatedIncorrectList;

    public function treino($idTreino) {
        global $pdo;

        if (empty($this->updatedList)) {
            return false;
        }


        try {
            $pdo->beginTransaction();

            // =========================
            // 1️⃣ UPDATE
            // =========================
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
                    $_SESSION['user_id']
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


    
   // 🔥 NOVO MÉTODO
    public function estatisticasTreino() {
        date_default_timezone_set('America/Sao_Paulo');
        global $pdo;

        $sql = "
            SELECT 
                t.status,
                t.id AS id_treino,
                COUNT(f.id) AS total,
                tda.data_atualizacao
            FROM treino t

            LEFT JOIN frases f 
                ON f.id_treino = t.id
                AND f.categoria_id = ?
                AND f.usuario_id = ?
                AND f.status_id > ?

            LEFT JOIN (
                SELECT t1.id_treino, t1.data_atualizacao
                FROM treino_data_atualizacao t1
                INNER JOIN (
                    SELECT id_treino, MIN(id) AS max_id
                    FROM treino_data_atualizacao
                    GROUP BY id_treino
                ) t2 
                    ON t1.id = t2.max_id
            ) tda ON tda.id_treino = t.id

            WHERE t.id BETWEEN ? AND ?

            GROUP BY t.id, t.status, tda.data_atualizacao
            ORDER BY t.id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $this->category_id,        // categoria_id
            $_SESSION['user_id'],      // usuario_id
            0,                         // status_id > 0
            1,                         // inicio
            5                          // fim
        ]);

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($result as &$row) {

            if (!empty($row['data_atualizacao'])) {

                // Converte para DateTime
                $date = new DateTime($row['data_atualizacao']);

                // Soma 5 horas
                $date->modify('+5 hours');

                // Salva já no formato ISO 8601 (melhor para JS)
                $row['data_liberacao'] = $date->format(DateTime::ATOM);

                // Opcional: já informa se está disponível
                $now = new DateTime();
                $row['disponivel'] = $now >= $date;
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


      public function repeatPhrases(): array
    {

        global $pdo; // 👈 precisa disso

        $sql = "
            SELECT 
                id,
                texto_nativo,
                texto_traduzido,
                categoria_id
            FROM frases
            WHERE categoria_id = :categoria_id
            AND usuario_id = :id_user
            AND status_id > 0
            AND id_treino = 3
            ORDER BY id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':categoria_id', $this->category_id, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $_SESSION['user_id'], PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateRepeat($set_id_treino, $id_treino) {
        global $pdo;

        try {

            $pdo->beginTransaction();

            // 1️⃣ Atualiza treino_data_atualizacao
            $sql1 = "
                UPDATE treino_data_atualizacao tda
                INNER JOIN frases f 
                    ON f.id = tda.id_frase
                SET tda.id_treino = ?
                WHERE tda.id_treino = ?
                AND f.usuario_id = ?
                AND f.categoria_id = ?
                AND tda.data_atualizacao <= NOW() - INTERVAL 4 HOUR
            ";

            $stmt1 = $pdo->prepare($sql1);
            $stmt1->execute([
                $set_id_treino,
                $id_treino,
                $_SESSION['user_id'],
                $this->category_id
            ]);

            // 2️⃣ Atualiza frases SOMENTE das que foram atualizadas acima
            $sql2 = "
                UPDATE frases f
                INNER JOIN treino_data_atualizacao tda
                    ON tda.id_frase = f.id
                SET f.id_treino = ?
                WHERE tda.id_treino = ?
                AND f.id_treino = ?
                AND f.usuario_id = ?
                AND f.categoria_id = ?
            ";

            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute([
                $set_id_treino,   // SET
                $set_id_treino,   // WHERE tda.id_treino
                $id_treino,       // WHERE f.id_treino
                $_SESSION['user_id'],
                $this->category_id
            ]);

            $pdo->commit();

            return [
                'sucesso' => true,
                'movidos' => $stmt1->rowCount()
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
        $data->modify('+5 hours');

        return $agora >= $data;
    }
}