<?php
require_once '../server.php';

class Metricas {
    private $pdo;

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }

    // ========== MÉTODOS PRINCIPAIS ==========

    // Retorna o par de idiomas (IDs) atualmente ativo para o usuário,
    // ou null se ele ainda não tiver escolhido nenhum.
    public function getIdiomaAtual($user_id) {
        $sql = "
            SELECT idioma_nativo, idioma_aprender AS idioma_aprendendo
            FROM idioma_referencia
            WHERE id_user = :user_id
                AND idioma_nativo > 0
                AND idioma_aprender > 0
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $idioma = $stmt->fetch(PDO::FETCH_ASSOC);

        return $idioma ?: null;
    }

    // Monta a condição opcional de filtro por idioma sobre a tabela `frases` (alias $aliasFrase)
    private function filtroIdiomaSql($aliasFrase, $idioma_nativo, $idioma_aprendendo) {
        if (!$idioma_nativo || !$idioma_aprendendo) {
            return '';
        }

        return " AND {$aliasFrase}.idioma_nativo = :idioma_nativo AND {$aliasFrase}.idioma_aprendendo = :idioma_aprendendo ";
    }

    private function bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo) {
        if ($idioma_nativo && $idioma_aprendendo) {
            $stmt->bindParam(':idioma_nativo', $idioma_nativo, PDO::PARAM_INT);
            $stmt->bindParam(':idioma_aprendendo', $idioma_aprendendo, PDO::PARAM_INT);
        }
    }

    public function getDesempenho($user_id, $periodo = '30d', $idioma_nativo = null, $idioma_aprendendo = null) {
        $intervalo = $this->getIntervaloPeriodo($periodo);
        $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

        $sql = "
            SELECT
                DATE(m.created_at) as data,
                COUNT(*) as total_questoes,
                SUM(m.acertou) as acertos,
                ROUND((SUM(m.acertou) / COUNT(*)) * 100, 2) as taxa_acerto
            FROM metricas m
            INNER JOIN frases f ON m.frase_id = f.id
            WHERE m.user_id = :user_id
                AND m.created_at >= DATE_SUB(NOW(), INTERVAL $intervalo)
                $filtroIdioma
            GROUP BY DATE(m.created_at)
            ORDER BY data ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getResumo($user_id, $idioma_nativo = null, $idioma_aprendendo = null) {
        $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

        $sql = "
            SELECT
                COUNT(*) as total,
                SUM(m.acertou) as acertos,
                ROUND((SUM(m.acertou) / COUNT(*)) * 100, 2) as taxa_acerto
            FROM metricas m
            INNER JOIN frases f ON m.frase_id = f.id
            WHERE m.user_id = :user_id
                $filtroIdioma
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Agregado das features de IA (Perguntas e Frase do Dia) - tabelas
    // separadas de `metricas`, não ligadas a uma categoria (as perguntas
    // são geradas a partir de frases de várias categorias, e a Frase do
    // Dia não pertence a nenhuma), então entram como um resumo geral em
    // vez de item do breakdown por categoria.
    public function getTreinoIA($user_id) {
        $sqlPerguntas = "
            SELECT COUNT(*) as total, AVG(nota) as media, SUM(CASE WHEN nota >= 5 THEN 1 ELSE 0 END) as acertos
            FROM perguntas_ia
            WHERE user_id = :user_id AND status_id = 1 AND nota IS NOT NULL
        ";
        $stmt = $this->pdo->prepare($sqlPerguntas);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $perguntas = $stmt->fetch(PDO::FETCH_ASSOC);

        $sqlFrases = "
            SELECT COUNT(*) as total, AVG(nota) as media, SUM(CASE WHEN nota >= 5 THEN 1 ELSE 0 END) as acertos
            FROM frase_dia_ia
            WHERE user_id = :user_id AND status_id = 1 AND nota IS NOT NULL
        ";
        $stmt2 = $this->pdo->prepare($sqlFrases);
        $stmt2->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt2->execute();
        $frases = $stmt2->fetch(PDO::FETCH_ASSOC);

        // Tradução Reversa não entrava aqui (feature adicionada depois desse
        // método já existir) - o termômetro da Home mostrava Perguntas e
        // Frase do Dia, mas nunca Tradução Reversa.
        $sqlTraducaoReversa = "
            SELECT COUNT(*) as total, AVG(nota) as media, SUM(CASE WHEN nota >= 5 THEN 1 ELSE 0 END) as acertos
            FROM traducao_reversa_ia
            WHERE user_id = :user_id AND status_id = 1 AND nota IS NOT NULL
        ";
        $stmt3 = $this->pdo->prepare($sqlTraducaoReversa);
        $stmt3->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt3->execute();
        $traducaoReversa = $stmt3->fetch(PDO::FETCH_ASSOC);

        $totalGeral = (int) $perguntas['total'] + (int) $frases['total'] + (int) $traducaoReversa['total'];
        $acertosGeral = (int) $perguntas['acertos'] + (int) $frases['acertos'] + (int) $traducaoReversa['acertos'];

        return [
            'perguntas' => [
                'total' => (int) $perguntas['total'],
                'media_nota' => $perguntas['media'] !== null ? round((float) $perguntas['media'], 1) : null,
                'taxa_acerto' => $perguntas['total'] > 0 ? round(($perguntas['acertos'] / $perguntas['total']) * 100, 2) : null,
            ],
            'frase_do_dia' => [
                'total' => (int) $frases['total'],
                'media_nota' => $frases['media'] !== null ? round((float) $frases['media'], 1) : null,
                'taxa_acerto' => $frases['total'] > 0 ? round(($frases['acertos'] / $frases['total']) * 100, 2) : null,
            ],
            'traducao_reversa' => [
                'total' => (int) $traducaoReversa['total'],
                'media_nota' => $traducaoReversa['media'] !== null ? round((float) $traducaoReversa['media'], 1) : null,
                'taxa_acerto' => $traducaoReversa['total'] > 0 ? round(($traducaoReversa['acertos'] / $traducaoReversa['total']) * 100, 2) : null,
            ],
            'total_geral' => $totalGeral,
            'taxa_acerto_geral' => $totalGeral > 0 ? round(($acertosGeral / $totalGeral) * 100, 2) : null,
        ];
    }

    public function registrar($user_id, $frase_id, $acertou) {
        $sql = "
            INSERT INTO metricas (frase_id, user_id, acertou, created_at)
            VALUES (:frase_id, :user_id, :acertou, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':frase_id', $frase_id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':acertou', $acertou, PDO::PARAM_BOOL);
        $stmt->execute();

        return ["success" => true];
    }

    // ========== MÉTODOS PARA GRÁFICOS ==========
    
    public function getComparativoSemanal($user_id, $idioma_nativo = null, $idioma_aprendendo = null) {
        $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

        $sql = "
            SELECT
                DAYNAME(m.created_at) as nome,
                ROUND(AVG(CASE WHEN DATE(m.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN m.acertou ELSE NULL END) * 100, 2) as atual,
                ROUND(AVG(CASE WHEN DATE(m.created_at) BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY) THEN m.acertou ELSE NULL END) * 100, 2) as anterior
            FROM metricas m
            INNER JOIN frases f ON m.frase_id = f.id
            WHERE m.user_id = :user_id
                AND m.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                $filtroIdioma
            GROUP BY DAYOFWEEK(m.created_at), DAYNAME(m.created_at)
            ORDER BY DAYOFWEEK(m.created_at)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCategorias($user_id, $idioma_nativo = null, $idioma_aprendendo = null) {
        $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

        $sql = "
            SELECT
                COALESCE(c.categoria, 'Sem categoria') as name,
                COUNT(m.id) as value
            FROM metricas m
            INNER JOIN frases f ON m.frase_id = f.id
            LEFT JOIN categorias c ON f.categoria_id = c.id
            WHERE m.user_id = :user_id
                $filtroIdioma
            GROUP BY c.id, c.categoria
            ORDER BY value DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ========== MÉTODOS PARA FRASES COM DESEMPENHO ==========
    
   public function listarFrasesComMetricas($user_id, $idioma_nativo = null, $idioma_aprendendo = null) {
    $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

    $sql = "
        SELECT
            f.id,
            f.texto_nativo as frase,
            f.texto_traduzido as traducao,
            COALESCE(c.categoria, 'Sem categoria') as categoria,
            COUNT(m.id) as total_tentativas,
            SUM(m.acertou) as total_acertos,
            ROUND((SUM(m.acertou) / NULLIF(COUNT(m.id), 0)) * 100, 2) as taxa_acerto,
            MAX(m.created_at) as ultima_tentativa,
            (
                SELECT acertou
                FROM metricas m2
                WHERE m2.frase_id = f.id
                AND m2.user_id = :user_id
                ORDER BY m2.created_at DESC
                LIMIT 1
            ) as ultima_resposta
        FROM frases f
        LEFT JOIN categorias c ON f.categoria_id = c.id
        LEFT JOIN metricas m ON f.id = m.frase_id AND m.user_id = :user_id
        WHERE f.usuario_id = :user_id
            $filtroIdioma
        GROUP BY f.id, f.texto_nativo, f.texto_traduzido, c.categoria
        ORDER BY
            CASE
                WHEN COUNT(m.id) > 0 THEN COALESCE(ROUND((SUM(m.acertou) / COUNT(m.id)) * 100, 2), 101)
                ELSE 101
            END ASC,
            CASE
                WHEN MAX(m.created_at) IS NULL THEN 1
                ELSE 0
            END ASC,
            ultima_tentativa DESC
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    // ========== MÉTODOS AUXILIARES ==========
    
    // "Dia de estudo" pra fins de streak - antes contava só flashcards
    // clássicos (Aprender/Repetir/Revisar, via a tabela metricas). Usuário
    // reportou o streak zerando/ficando baixo mesmo estudando todo dia -
    // treinos de IA (Perguntas/Frase do Dia/Tradução Reversa) e os jogos
    // (Chuva de Frases/Tiro Certeiro) nunca contavam, mesmo sendo uso real
    // do app. status_id=1 nas tabelas de IA garante que só conta quando a
    // atividade foi concluída de verdade (respondida ou esgotada), não só
    // gerada/aberta e abandonada. Os jogos não têm status_id (a tabela
    // *_uso só registra quando uma partida de verdade começa a ser
    // gerada), então toda linha já é sinal de uso real.
    // Sem filtro de idioma nas tabelas de IA/jogos (diferente de metricas)
    // porque elas não guardam o par de idioma por linha, só o texto -
    // não dá pra aplicar o mesmo filtro sem essa informação.
    private function sqlDiasEstudo($filtroIdioma) {
        return "
            SELECT DISTINCT DATE(m.created_at) as data_estudo
            FROM metricas m
            INNER JOIN frases f ON m.frase_id = f.id
            WHERE m.user_id = :user_id
                $filtroIdioma
            UNION
            SELECT DATE(data_criacao) FROM perguntas_ia WHERE user_id = :user_id AND status_id = 1
            UNION
            SELECT DATE(data_criacao) FROM frase_dia_ia WHERE user_id = :user_id AND status_id = 1
            UNION
            SELECT DATE(data_criacao) FROM traducao_reversa_ia WHERE user_id = :user_id AND status_id = 1
            UNION
            SELECT DATE(data_criacao) FROM jogo_chuva_uso WHERE user_id = :user_id
            UNION
            SELECT DATE(data_criacao) FROM tiro_certeiro_uso WHERE user_id = :user_id
        ";
    }

    public function getStreak($user_id, $idioma_nativo = null, $idioma_aprendendo = null) {
        $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

        $sql = "
            WITH RECURSIVE dias_estudo AS (
                {$this->sqlDiasEstudo($filtroIdioma)}
            ),
            streak_atual AS (
                -- Mesmo cálculo (comprovado correto) de getMelhorStreak:
                -- varre em ordem ASCENDENTE, comparando cada dia com o
                -- anterior (data_estudo - 1). A versão antiga tentava
                -- calcular a sequência ATUAL varrendo em ordem DESCENDENTE
                -- e pegando só a primeira linha (LIMIT 1) - mas a primeira
                -- linha processada NUNCA tem um @prev_data anterior pra
                -- comparar (começa NULL), então o streak dessa linha É
                -- SEMPRE 1, não importa quantos dias seguidos o usuário
                -- realmente tivesse estudado (bug reportado pelo usuário:
                -- sequência sempre aparecendo como 1 dia). Em vez disso,
                -- varre ASC (construindo a sequência corretamente, dia a
                -- dia) e só DEPOIS pega o valor do dia mais recente - que é
                -- a sequência que está valendo até agora.
                SELECT
                    data_estudo,
                    @streak := IF(@prev_data = data_estudo - INTERVAL 1 DAY, @streak + 1, 1) as streak,
                    @prev_data := data_estudo
                FROM dias_estudo
                CROSS JOIN (SELECT @prev_data := NULL, @streak := 0) r
                ORDER BY data_estudo ASC
            )
            SELECT COALESCE(streak, 0) as streak
            FROM streak_atual
            ORDER BY data_estudo DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['streak'] ?? 0;
    }

    // Total de dias distintos (não precisam ser seguidos, diferente do
    // streak) em que o usuário acessou o app - baseado em acessos_usuario,
    // registrado a cada login bem-sucedido (ver model/AcessoUsuario.php).
    public function getDiasAcesso($user_id) {
        $sql = "SELECT COUNT(DISTINCT DATE(data_acesso)) as total FROM acessos_usuario WHERE user_id = :user_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['total'] ?? 0);
    }

    // Maior sequência de dias seguidos estudando já alcançada (não só a atual).
    public function getMelhorStreak($user_id, $idioma_nativo = null, $idioma_aprendendo = null) {
        $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

        $sql = "
            WITH RECURSIVE dias_estudo AS (
                {$this->sqlDiasEstudo($filtroIdioma)}
            ),
            streaks AS (
                SELECT
                    data_estudo,
                    @streak := IF(@prev_data = data_estudo - INTERVAL 1 DAY, @streak + 1, 1) as streak,
                    @prev_data := data_estudo
                FROM dias_estudo
                CROSS JOIN (SELECT @prev_data := NULL, @streak := 0) r
                ORDER BY data_estudo ASC
            )
            SELECT COALESCE(MAX(streak), 0) as melhor_streak FROM streaks
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['melhor_streak'] ?? 0);
    }

    // Maior sequência de respostas corretas seguidas (em qualquer frase/dia).
    public function getMelhorSequenciaAcertos($user_id, $idioma_nativo = null, $idioma_aprendendo = null) {
        $filtroIdioma = $this->filtroIdiomaSql('f', $idioma_nativo, $idioma_aprendendo);

        $sql = "
            SELECT COALESCE(MAX(sequencia), 0) as melhor_sequencia
            FROM (
                SELECT
                    @sequencia := IF(m.acertou = 1, @sequencia + 1, 0) as sequencia
                FROM metricas m
                INNER JOIN frases f ON m.frase_id = f.id
                CROSS JOIN (SELECT @sequencia := 0) r
                WHERE m.user_id = :user_id
                    $filtroIdioma
                ORDER BY m.created_at ASC
            ) t
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $this->bindFiltroIdioma($stmt, $idioma_nativo, $idioma_aprendendo);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['melhor_sequencia'] ?? 0);
    }

    public function getTempoMedio($user_id) {
        // Como não tem campo de tempo, retorna null
        return null;
    }

    private function getIntervaloPeriodo($periodo) {
        $mapa = [
            '7d' => '7 DAY',
            '30d' => '30 DAY',
            '90d' => '90 DAY',
            '12m' => '12 MONTH'
        ];
        return $mapa[$periodo] ?? '30 DAY';
    }

    // Método para buscar totais por idioma
    public function getDesempenhoPorIdioma($user_id) {
        $sql = "
            SELECT 
                i.nome as idioma,
                COUNT(m.id) as total_tentativas,
                SUM(m.acertou) as total_acertos,
                ROUND((SUM(m.acertou) / COUNT(m.id)) * 100, 2) as taxa_acerto
            FROM metricas m
            INNER JOIN frases f ON m.frase_id = f.id
            INNER JOIN idiomas i ON f.idioma_aprendendo = i.id
            WHERE m.user_id = :user_id
            GROUP BY i.id, i.nome
            ORDER BY taxa_acerto DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Método para buscar categorias do usuário (para filtros)
    public function listarCategoriasDoUsuario($user_id) {
        $sql = "
            SELECT DISTINCT 
                c.id,
                c.categoria,
                c.public,
                COUNT(f.id) as total_frases
            FROM categorias c
            LEFT JOIN frases f ON f.categoria_id = c.id AND f.usuario_id = :user_id
            WHERE c.id_user = :user_id OR c.public = 1
            GROUP BY c.id, c.categoria, c.public
            ORDER BY c.categoria ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}