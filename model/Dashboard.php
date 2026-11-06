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

    // Lista paginada de usuários pro admin conseguir abrir qualquer um e ver
    // detalhes - o dashboard até agora só tinha números agregados, nunca um
    // usuário individual. Busca por nome/apelido/e-mail opcional.
    public function getUsuarios(PDO $pdo, int $pagina = 1, int $porPagina = 20, string $busca = ''): array
    {
        $pagina = max(1, $pagina);
        $porPagina = max(1, min(100, $porPagina));
        $offset = ($pagina - 1) * $porPagina;

        $buscaLike = '%' . $busca . '%';
        $filtroBusca = $busca !== ''
            ? "AND (u.nome LIKE :busca OR u.email LIKE :busca OR u.apelido LIKE :busca)"
            : "";

        $sql = "
            SELECT u.id, u.nome, u.apelido, u.email, u.create_date, u.email_verified,
                   COALESCE(p.plano, 'sem plano') AS plano_nome,
                   (SELECT COUNT(*) FROM categorias c WHERE c.id_user = u.id AND c.status_id > 0) AS total_categorias,
                   (SELECT COUNT(*) FROM frases f WHERE f.usuario_id = u.id AND f.status_id > 0) AS total_frases
            FROM usuarios u
            LEFT JOIN planos p ON p.id = u.plano
            WHERE (u.status_id IS NULL OR u.status_id <> 0)
            {$filtroBusca}
            ORDER BY u.create_date DESC
            LIMIT :limite OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        if ($busca !== '') {
            $stmt->bindValue(':busca', $buscaLike, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sqlTotal = "SELECT COUNT(*) FROM usuarios u WHERE (u.status_id IS NULL OR u.status_id <> 0) {$filtroBusca}";
        $stmtTotal = $pdo->prepare($sqlTotal);
        if ($busca !== '') {
            $stmtTotal->bindValue(':busca', $buscaLike, PDO::PARAM_STR);
        }
        $stmtTotal->execute();
        $total = (int) $stmtTotal->fetchColumn();

        foreach ($usuarios as &$u) {
            $u['id'] = (int) $u['id'];
            $u['email_verified'] = (bool) $u['email_verified'];
            $u['total_categorias'] = (int) $u['total_categorias'];
            $u['total_frases'] = (int) $u['total_frases'];
        }

        return [
            'usuarios' => $usuarios,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_paginas' => (int) ceil($total / $porPagina),
        ];
    }

    // Detalhe de 1 usuário - "evolução" dele: em que estágio de treino estão
    // as frases (nao_conheco/memorizando/em_treino/memorizado), quanto
    // categoria/frase tem, e atividade recente nos mesmos 3 grupos do
    // gráfico geral (treino, jogos, IA), pra ver se esse usuário específico
    // está engajado ou sumiu.
    public function getDetalhesUsuario(PDO $pdo, int $userId, int $dias = 30): ?array
    {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nome, u.apelido, u.email, u.create_date, u.email_verified,
                   COALESCE(p.plano, 'sem plano') AS plano_nome,
                   inat.idioma AS idioma_nativo, iapr.idioma AS idioma_aprendendo
            FROM usuarios u
            LEFT JOIN planos p ON p.id = u.plano
            LEFT JOIN idioma_referencia ir ON ir.id_user = u.id
            LEFT JOIN idiomas inat ON inat.id = ir.idioma_nativo
            LEFT JOIN idiomas iapr ON iapr.id = ir.idioma_aprender
            WHERE u.id = :id AND (u.status_id IS NULL OR u.status_id <> 0)
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            return null;
        }

        $usuario['id'] = (int) $usuario['id'];
        $usuario['email_verified'] = (bool) $usuario['email_verified'];

        $totais = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM categorias c WHERE c.id_user = :id1 AND c.status_id > 0) AS total_categorias,
                (SELECT COUNT(*) FROM frases f WHERE f.usuario_id = :id2 AND f.status_id > 0) AS total_frases
        ");
        $totais->execute([':id1' => $userId, ':id2' => $userId]);
        $totaisRow = $totais->fetch(PDO::FETCH_ASSOC);

        $distribuicaoStmt = $pdo->prepare("
            SELECT t.status, COUNT(*) AS total
            FROM frases f
            JOIN treino t ON t.id = f.id_treino
            WHERE f.usuario_id = :id AND f.status_id > 0
            GROUP BY t.status, t.id
            ORDER BY t.id ASC
        ");
        $distribuicaoStmt->execute([':id' => $userId]);
        // treino.status vem em snake_case (nao_conheco/memorizando/em_treino/
        // memorizado) - traduz pro rótulo que o admin vê, em vez de mostrar
        // o valor cru da coluna.
        $rotulosTreino = [
            'nao_conheco' => 'Não conheço',
            'memorizando' => 'Memorizando',
            'em_treino' => 'Em treino',
            'memorizado' => 'Memorizado',
        ];
        $distribuicao = [];
        foreach ($distribuicaoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $distribuicao[] = ['name' => $rotulosTreino[$row['status']] ?? $row['status'], 'value' => (int) $row['total']];
        }

        $ultimoTreinoStmt = $pdo->prepare("
            SELECT MAX(t.data_atualizacao) AS ultimo
            FROM treino_data_atualizacao t
            JOIN frases f ON f.id = t.id_frase
            WHERE f.usuario_id = :id
        ");
        $ultimoTreinoStmt->execute([':id' => $userId]);
        $ultimoTreino = $ultimoTreinoStmt->fetchColumn();

        $dias = max(1, $dias);
        $usoPorModoStmt = $pdo->prepare("
            SELECT 'Flashcards e treino geral' AS name, COUNT(*) AS total
                FROM treino_data_atualizacao t JOIN frases f ON f.id = t.id_frase
                WHERE f.usuario_id = :id1 AND t.data_atualizacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Chuva de Frases', COUNT(*) FROM jogo_chuva_uso WHERE user_id = :id2 AND data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Tiro Certeiro', COUNT(*) FROM tiro_certeiro_uso WHERE user_id = :id3 AND data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Perguntas com IA', COUNT(*) FROM perguntas_ia WHERE user_id = :id4 AND data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Frase do Dia (IA)', COUNT(*) FROM frase_dia_ia WHERE user_id = :id5 AND data_criacao >= CURDATE() - INTERVAL {$dias} DAY
            UNION ALL
            SELECT 'Tradução Reversa (IA)', COUNT(*) FROM traducao_reversa_ia WHERE user_id = :id6 AND data_criacao >= CURDATE() - INTERVAL {$dias} DAY
        ");
        $usoPorModoStmt->execute([
            ':id1' => $userId, ':id2' => $userId, ':id3' => $userId,
            ':id4' => $userId, ':id5' => $userId, ':id6' => $userId,
        ]);
        $usoPorModo = [];
        foreach ($usoPorModoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usoPorModo[] = ['name' => $row['name'], 'value' => (int) $row['total']];
        }
        usort($usoPorModo, fn($a, $b) => $b['value'] - $a['value']);

        return [
            'usuario' => $usuario,
            'total_categorias' => (int) $totaisRow['total_categorias'],
            'total_frases' => (int) $totaisRow['total_frases'],
            'distribuicao_treino' => $distribuicao,
            'ultimo_treino' => $ultimoTreino,
            'uso_por_modo' => $usoPorModo,
            'dias_considerados' => $dias,
        ];
    }
}
