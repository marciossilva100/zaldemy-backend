<?php
require_once '../server.php';

class Metricas {
    private $pdo;

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }

    // ========== MÉTODOS PRINCIPAIS ==========
    
    public function getDesempenho($user_id, $periodo = '30d') {
        $intervalo = $this->getIntervaloPeriodo($periodo);

        $sql = "
            SELECT 
                DATE(created_at) as data,
                COUNT(*) as total_questoes,
                SUM(acertou) as acertos,
                ROUND((SUM(acertou) / COUNT(*)) * 100, 2) as taxa_acerto
            FROM metricas
            WHERE user_id = :user_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL $intervalo)
            GROUP BY DATE(created_at)
            ORDER BY data ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getResumo($user_id) {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(acertou) as acertos,
                ROUND((SUM(acertou) / COUNT(*)) * 100, 2) as taxa_acerto
            FROM metricas
            WHERE user_id = :user_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
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
    
    public function getComparativoSemanal($user_id) {
        $sql = "
            SELECT 
                DAYNAME(created_at) as nome,
                ROUND(AVG(CASE WHEN DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN acertou ELSE NULL END) * 100, 2) as atual,
                ROUND(AVG(CASE WHEN DATE(created_at) BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY) THEN acertou ELSE NULL END) * 100, 2) as anterior
            FROM metricas
            WHERE user_id = :user_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            GROUP BY DAYOFWEEK(created_at), DAYNAME(created_at)
            ORDER BY DAYOFWEEK(created_at)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCategorias($user_id) {
        $sql = "
            SELECT 
                COALESCE(c.categoria, 'Sem categoria') as name,
                COUNT(m.id) as value
            FROM metricas m
            INNER JOIN frases f ON m.frase_id = f.id
            LEFT JOIN categorias c ON f.categoria_id = c.id
            WHERE m.user_id = :user_id
            GROUP BY c.id, c.categoria
            ORDER BY value DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ========== MÉTODOS PARA FRASES COM DESEMPENHO ==========
    
   public function listarFrasesComMetricas($user_id) {
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
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    // ========== MÉTODOS AUXILIARES ==========
    
    public function getStreak($user_id) {
        $sql = "
            WITH RECURSIVE dias_estudo AS (
                SELECT DISTINCT DATE(created_at) as data_estudo
                FROM metricas
                WHERE user_id = :user_id
            ),
            streak_atual AS (
                SELECT 
                    data_estudo,
                    @streak := IF(@prev_data = data_estudo - INTERVAL 1 DAY, @streak + 1, 1) as streak,
                    @prev_data := data_estudo
                FROM dias_estudo
                CROSS JOIN (SELECT @prev_data := NULL, @streak := 0) r
                ORDER BY data_estudo DESC
                LIMIT 1
            )
            SELECT COALESCE(streak, 0) as streak FROM streak_atual
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['streak'] ?? 0;
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