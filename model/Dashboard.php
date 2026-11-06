<?php

class Dashboard
{
    public function getResumo(PDO $pdo): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_usuarios,
                SUM(email_verified = 1) AS usuarios_verificados,
                SUM(create_date >= NOW() - INTERVAL 7 DAY) AS novos_7d,
                SUM(create_date >= NOW() - INTERVAL 30 DAY) AS novos_30d
            FROM usuarios
            WHERE status_id IS NULL OR status_id <> 0
        ";
        $usuarios = $pdo->query($sql)->fetch();

        $totalCategorias = $pdo->query(
            "SELECT COUNT(*) AS total FROM categorias WHERE status_id > 0"
        )->fetch()['total'];

        $totalFrases = $pdo->query(
            "SELECT COUNT(*) AS total FROM frases WHERE status_id > 0"
        )->fetch()['total'];

        return [
            'total_usuarios' => (int) ($usuarios['total_usuarios'] ?? 0),
            'usuarios_verificados' => (int) ($usuarios['usuarios_verificados'] ?? 0),
            'novos_usuarios_7d' => (int) ($usuarios['novos_7d'] ?? 0),
            'novos_usuarios_30d' => (int) ($usuarios['novos_30d'] ?? 0),
            'total_categorias' => (int) $totalCategorias,
            'total_frases' => (int) $totalFrases,
        ];
    }

    public function getCrescimentoUsuarios(PDO $pdo, int $dias = 30): array
    {
        $sql = "
            SELECT DATE(create_date) AS data, COUNT(*) AS total
            FROM usuarios
            WHERE create_date >= CURDATE() - INTERVAL :dias DAY
            GROUP BY DATE(create_date)
            ORDER BY data ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
        $stmt->execute();
        $porDia = [];
        foreach ($stmt->fetchAll() as $row) {
            $porDia[$row['data']] = (int) $row['total'];
        }

        // Preenche os dias sem cadastro com 0 para o gráfico não quebrar
        $resultado = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $resultado[] = [
                'data' => date('d/m', strtotime($data)),
                'novos_usuarios' => $porDia[$data] ?? 0,
            ];
        }

        return $resultado;
    }

    public function getUsuariosPorPlano(PDO $pdo): array
    {
        $sql = "
            SELECT COALESCE(p.plano, 'sem plano') AS plano, COUNT(u.id) AS total
            FROM usuarios u
            LEFT JOIN planos p ON p.id = u.plano
            WHERE u.status_id IS NULL OR u.status_id <> 0
            GROUP BY plano
            ORDER BY total DESC
        ";

        $resultado = [];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $resultado[] = ['name' => $row['plano'], 'value' => (int) $row['total']];
        }
        return $resultado;
    }

    public function getCanaisAquisicao(PDO $pdo): array
    {
        $sql = "
            SELECT r.nome, COUNT(*) AS total
            FROM canal_aquisicao c
            JOIN redes_sociais r ON r.id = c.rede_social_id
            GROUP BY r.nome
            ORDER BY total DESC
        ";

        $resultado = [];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $resultado[] = ['name' => $row['nome'], 'value' => (int) $row['total']];
        }
        return $resultado;
    }

    // Usuários únicos por dia em 3 grupos de atividade - pra responder
    // "estão treinando diariamente, jogando, treinando no modo IA?" de
    // forma visual. Treino "geral" cobre Flashcards e os outros modos que
    // só avançam o estágio da frase (treino_data_atualizacao não distingue
    // QUAL tela, só que alguma atualização de treino aconteceu); jogos e
    // treino com IA têm log próprio por chamada (ver migrations
    // jogo_chuva_uso, tiro_certeiro_uso, perguntas_ia, frase_dia_ia,
    // traducao_reversa_ia).
    public function getAtividadeDiariaPorTipo(PDO $pdo, int $dias = 30): array
    {
        $dias = max(1, $dias);

        $porDiaGeral = $this->contarUsuariosUnicosPorDia($pdo, "
            SELECT DATE(t.data_atualizacao) AS dia, f.usuario_id AS user_id
            FROM treino_data_atualizacao t
            JOIN frases f ON f.id = t.id_frase
            WHERE t.data_atualizacao >= CURDATE() - INTERVAL {$dias} DAY
        ");

        $porDiaJogos = $this->contarUsuariosUnicosPorDia($pdo, "
            SELECT DATE(data_criacao) AS dia, user_id FROM jogo_chuva_uso WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT DATE(data_criacao) AS dia, user_id FROM tiro_certeiro_uso WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
        ");

        $porDiaIa = $this->contarUsuariosUnicosPorDia($pdo, "
            SELECT DATE(data_criacao) AS dia, user_id FROM perguntas_ia WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT DATE(data_criacao) AS dia, user_id FROM frase_dia_ia WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT DATE(data_criacao) AS dia, user_id FROM traducao_reversa_ia WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
        ");

        $resultado = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $resultado[] = [
                'data' => date('d/m', strtotime($data)),
                'treino_geral' => $porDiaGeral[$data] ?? 0,
                'jogos' => $porDiaJogos[$data] ?? 0,
                'treino_ia' => $porDiaIa[$data] ?? 0,
            ];
        }

        return $resultado;
    }

    // $sqlDiaEUsuario precisa devolver as colunas `dia` e `user_id` (1 linha
    // por evento) - agrupa aqui por COUNT(DISTINCT user_id) por dia.
    private function contarUsuariosUnicosPorDia(PDO $pdo, string $sqlDiaEUsuario): array
    {
        $sql = "SELECT dia, COUNT(DISTINCT user_id) AS total FROM ({$sqlDiaEUsuario}) base GROUP BY dia";
        $porDia = [];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $porDia[$row['dia']] = (int) $row['total'];
        }
        return $porDia;
    }

    // Total de interações por modo de treino especifico no período - mesmos
    // grupos do gráfico diário, mas aqui detalhado por tela em vez de só
    // "geral/jogos/IA", pra responder "e outros" (qual jogo, qual treino de
    // IA especificamente).
    public function getUsoPorModoTreino(PDO $pdo, int $dias = 30): array
    {
        $dias = max(1, $dias);

        $sql = "
            SELECT 'Flashcards e treino geral' AS name, COUNT(*) AS total
                FROM treino_data_atualizacao WHERE data_atualizacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Chuva de Frases', COUNT(*) FROM jogo_chuva_uso WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Tiro Certeiro', COUNT(*) FROM tiro_certeiro_uso WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Perguntas com IA', COUNT(*) FROM perguntas_ia WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Frase do Dia (IA)', COUNT(*) FROM frase_dia_ia WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Tradução Reversa (IA)', COUNT(*) FROM traducao_reversa_ia WHERE data_criacao >= CURDATE() - INTERVAL {$dias} DAY
        ";

        $resultado = [];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $resultado[] = ['name' => $row['name'], 'value' => (int) $row['total']];
        }

        usort($resultado, fn($a, $b) => $b['value'] - $a['value']);

        return $resultado;
    }

    public function getIdiomasMaisAprendidos(PDO $pdo, int $limite = 6): array
    {
        $sql = "
            SELECT i.idioma, COUNT(*) AS total
            FROM idioma_referencia ir
            JOIN idiomas i ON i.id = ir.idioma_aprender
            WHERE ir.idioma_aprender > 0
            GROUP BY i.idioma
            ORDER BY total DESC
            LIMIT :limite
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $resultado = [];
        foreach ($stmt->fetchAll() as $row) {
            $resultado[] = ['name' => $row['idioma'], 'value' => (int) $row['total']];
        }
        return $resultado;
    }
}
