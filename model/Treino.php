<?php
require_once '../server.php';
session_start();

class Treino {
    // Usados em retornarTreino() - ver comentário lá.
    const MAX_TENTATIVAS_RECENTES_RETORNO = 5;
    const MIN_TENTATIVAS_PARA_EXTREMOS = 2;

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

        // Sem isso, updatedList vazio virava "WHERE id IN ()" - erro de
        // sintaxe SQL (já causou uma tela inteira nunca gravar id_treino
        // direito, sem erro visível pro usuário - ver comentário em
        // controller/treino.php sobre o bug do Emparelhar). Guarda
        // existia comentada, nunca tinha sido ativada.
        if (empty($this->updatedList)) {
            return [
                'success' => false,
                'message' => 'Nenhuma frase informada',
            ];
        }

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
        // 1️⃣ Candidatas: já paradas há pelo menos 3 dias (o menor prazo
        // possível dos três níveis abaixo) - filtro barato em SQL, mesmo
        // formato/agregação de antes. A decisão fina de qual prazo cada
        // frase precisa (3, 7 ou 15 dias) é feita depois, em PHP, olhando
        // só as tentativas recentes de cada uma - ver bloco 2 abaixo.
        //
        // Filtra direto por f.id_treino (o campo real/atual da frase), não
        // pelo último registro em treino_data_atualizacao - esse histórico
        // pode ficar dessincronizado do campo atual (ex: frase em id_treino=4
        // cujo último log ainda mostra um estágio anterior), o que fazia essa
        // consulta não encontrar quase nenhuma frase vencida na prática.
        $sqlCandidatas = "
            SELECT
                f.id AS id_frase,
                f.categoria_id,
                MAX(tda.data_atualizacao) as ultima_data

            FROM frases f

            LEFT JOIN treino_data_atualizacao tda
                ON tda.id_frase = f.id

            WHERE
                f.id_treino = ?
                AND f.usuario_id = ?
                AND f.status_id > 0

            GROUP BY f.id, f.categoria_id

            HAVING ultima_data <= NOW() - INTERVAL 3 DAY
        ";

        $stmtCandidatas = $pdo->prepare($sqlCandidatas);
        $stmtCandidatas->execute([$idTreino, $user_id]);

        $candidatas = $stmtCandidatas->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidatas)) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Nenhuma frase encontrada'
            ];
        }

        // =========================
        // 2️⃣ Prazo real de cada candidata, com base só nas últimas
        // tentativas (não o histórico vitalício inteiro) - uma frase que
        // errava muito há meses mas vem acertando agora não deve continuar
        // sendo tratada como "difícil" pra sempre. Exige também um mínimo
        // de tentativas antes de confiar nos extremos (>=70%/<30%) - uma
        // única resposta (certa ou errada) não deveria travar a frase num
        // prazo extremo por acaso.
        $stmtUltimasTentativas = $pdo->prepare("
            SELECT acertou FROM metricas
            WHERE frase_id = ? AND user_id = ?
            ORDER BY id DESC
            LIMIT " . self::MAX_TENTATIVAS_RECENTES_RETORNO . "
        ");

        $agora = new DateTime();
        $dados = [];

        foreach ($candidatas as $candidata) {
            $stmtUltimasTentativas->execute([$candidata['id_frase'], $user_id]);
            $tentativas = $stmtUltimasTentativas->fetchAll(PDO::FETCH_COLUMN);

            $totalTentativas = count($tentativas);
            $mediaAcertos = $totalTentativas > 0 ? array_sum($tentativas) / $totalTentativas : 0;

            if ($totalTentativas < self::MIN_TENTATIVAS_PARA_EXTREMOS) {
                $prazoDias = 7;
            } elseif ($mediaAcertos >= 0.7) {
                $prazoDias = 15;
            } elseif ($mediaAcertos < 0.3) {
                $prazoDias = 3;
            } else {
                $prazoDias = 7;
            }

            $vence = new DateTime($candidata['ultima_data']);
            $vence->modify("+{$prazoDias} days");

            if ($vence <= $agora) {
                $dados[] = $candidata;
            }
        }

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
                SET id_treino = 3
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
        // 4️⃣ INSERT (id_treino = 3)
        // =========================
        $placeholdersInsert = [];
        $paramsInsert = [];

        foreach ($dados as $row) {
            $placeholdersInsert[] = "(?, ?, ?)";
            $paramsInsert[] = $row['id_frase'];
            $paramsInsert[] = 3;
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

    // Grava métricas em lote (acertos + erros de uma sessão inteira, ex:
    // Emparelhar) numa INSERT só. Método nunca tinha sido chamado por
    // ninguém até essa correção - estava incompleto: dava commit() numa
    // transação que ele mesmo nunca abria (PDOException "no active
    // transaction" sempre que alguém tentasse usar), sem try/catch nem
    // retorno. Reescrito autocontido, mesmo padrão de treino()/
    // retornarTreino().
    public function metricas($user_id){

        global $pdo;

        try {
            $pdo->beginTransaction();

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

            return ['success' => true];

        } catch (Exception $e) {
            $pdo->rollBack();

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }


    
   // 🔥 NOVO MÉTODO
    public function estatisticasTreino($user_id) {

        date_default_timezone_set('America/Sao_Paulo');
        global $pdo;


        // O JOIN com treino_data_atualizacao pega só a última linha de cada
        // frase (subquery correlacionada) - sem isso, uma frase que já
        // passou por esse nível antes (linha antiga sobrando no histórico,
        // já que a tabela só acumula) fazia o MIN() pegar a data errada,
        // de meses atrás, zerando a contagem regressiva pra frases que
        // acabaram de chegar nesse nível de novo (mesma causa raiz do bug
        // já corrigido em updateRepeat(), só que aqui na leitura em vez de
        // na escrita).
        $sql = "SELECT
            t.status,
            t.id,
            t.id AS id_treino,
            COUNT(DISTINCT f.id) AS total,
            MIN(ultima.data_atualizacao) AS data_atualizacao
        FROM treino t

        LEFT JOIN frases f
            ON f.id_treino = t.id
            AND f.categoria_id = ?
            AND f.usuario_id = ?
            AND f.status_id > ?

        LEFT JOIN categorias c
            ON c.id = f.categoria_id
            AND c.status_id > 0

        LEFT JOIN treino_data_atualizacao ultima
            ON ultima.id_frase = f.id
            AND ultima.id_treino = t.id
            AND ultima.id = (
                SELECT tda.id
                FROM treino_data_atualizacao tda
                WHERE tda.id_frase = f.id
                ORDER BY tda.id DESC
                LIMIT 1
            )

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

            // Duas versões anteriores dessa correção usavam JOIN com subquery
            // (com e sem escopo por usuário/categoria) pra achar a linha mais
            // recente de treino_data_atualizacao de cada frase - ambas
            // travaram "Repetir" em produção mesmo passando em teste local
            // isolado. Suspeita: um UPDATE...JOIN varrendo linhas via
            // subquery/GROUP BY tende a travar (lock) mais linhas do que o
            // necessário sob concorrência real (vários usuários estudando ao
            // mesmo tempo, escrevendo na mesma tabela) - algo que teste local
            // sem concorrência não reproduz. Trocado por uma estratégia sem
            // JOIN nenhum: um SELECT simples busca o pequeno conjunto de
            // frases candidatas (só as dessa categoria/usuário) junto da
            // ÚLTIMA linha de histórico de cada uma (subquery correlacionada
            // em SELECT, sem restrição de "mesma tabela"), decide em PHP
            // quais já esperaram as 2h, e só então faz updates diretos por
            // lista de IDs (WHERE id IN (...)) - trava só as linhas exatas
            // que de fato muda, nada além disso.
            $sqlCandidatas = "
                SELECT
                    f.id AS id_frase,
                    ultima.id AS id_tda,
                    ultima.data_atualizacao
                FROM frases f
                INNER JOIN treino_data_atualizacao ultima
                    ON ultima.id = (
                        SELECT tda.id
                        FROM treino_data_atualizacao tda
                        WHERE tda.id_frase = f.id
                        ORDER BY tda.id DESC
                        LIMIT 1
                    )
                WHERE f.usuario_id = ?
                AND f.categoria_id = ?
                AND f.id_treino = ?
                AND ultima.id_treino = ?
            ";

            $stmtCandidatas = $pdo->prepare($sqlCandidatas);
            $stmtCandidatas->execute([$user_id, $this->category_id, $id_treino, $id_treino]);
            $candidatas = $stmtCandidatas->fetchAll(PDO::FETCH_ASSOC);

            $agora = new DateTime();
            $idsFrasesElegiveis = [];
            $idsTdaElegiveis = [];

            foreach ($candidatas as $candidata) {
                $liberaEm = new DateTime($candidata['data_atualizacao']);
                $liberaEm->modify('+2 hours');

                if ($liberaEm <= $agora) {
                    $idsFrasesElegiveis[] = (int) $candidata['id_frase'];
                    $idsTdaElegiveis[] = (int) $candidata['id_tda'];
                }
            }

            if (empty($idsFrasesElegiveis)) {
                $pdo->commit();

                return [
                    'sucesso' => true,
                    'movidos' => 0
                ];
            }

            $placeholdersFrases = implode(',', array_fill(0, count($idsFrasesElegiveis), '?'));
            $stmtUpdateFrases = $pdo->prepare("UPDATE frases SET id_treino = ? WHERE id IN ($placeholdersFrases)");
            $stmtUpdateFrases->execute(array_merge([$set_id_treino], $idsFrasesElegiveis));

            $movidos = $stmtUpdateFrases->rowCount();

            $placeholdersTda = implode(',', array_fill(0, count($idsTdaElegiveis), '?'));
            $stmtUpdateTda = $pdo->prepare("UPDATE treino_data_atualizacao SET id_treino = ? WHERE id IN ($placeholdersTda)");
            $stmtUpdateTda->execute(array_merge([$set_id_treino], $idsTdaElegiveis));

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

    // Guarda que o usuário já dispensou o guia "toque em Treinar" na Home
    // (mesmo padrão de Categorias::dispensarGuiaPrimeiraCategoria) - não
    // volta a aparecer depois disso.
    public static function dispensarGuiaTreino(PDO $pdo, int $user_id): void
    {
        $stmt = $pdo->prepare("UPDATE usuarios SET guia_treino_dispensado = 1 WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
    }
}