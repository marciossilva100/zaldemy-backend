<?php
declare(strict_types=1);

class DailyQuestionIA
{
    private $apiKey;
    private $baseUrl = 'https://api.groq.com/openai/v1';

    private $pdo;

    public function __construct($apiKey, PDO $pdo)
    {
        $this->apiKey = $apiKey;
        $this->pdo = $pdo;
    }

    /* ======================================================
       1) GERAR PERGUNTA
    ====================================================== */
public function generateQuestion(array $phrases, $userId, $level = 'beginner', $statusId = 0)
{
    $phrases = array_filter($phrases, function ($p) {
        return str_word_count($p) >= 3;
    });

    $phrases = array_values($phrases);

    $totalWords = 0;

    foreach ($phrases as $phrase) {
        $totalWords += str_word_count($phrase);
    }

    if (count($phrases) < 3 || $totalWords < 20) {
        throw new Exception("Adicione mais frases aos flashcards com conteúdo para gerar perguntas melhores. $totalWords");
    }

    $inicioDia = strtotime("today");
    $fimDia = strtotime("tomorrow") - 1;

    $sqlCheck = "SELECT COUNT(*) as total 
                FROM perguntas_ia 
                WHERE user_id = :user_id 
                AND data_criacao BETWEEN :inicio AND :fim";

    $stmtCheck = $this->pdo->prepare($sqlCheck);
    $stmtCheck->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmtCheck->bindValue(':inicio', $inicioDia, PDO::PARAM_INT);
    $stmtCheck->bindValue(':fim', $fimDia, PDO::PARAM_INT);
    $stmtCheck->execute();

    $result = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    $total_today = (int)$result['total'];

    if ($total_today >= 4) {
        return [
            'question' => null,
            'total_today' => $total_today,
            'limit_reached' => true
        ];
    }

    $phrasesText = implode("\n", $phrases);

    $systemPrompt = "
    Você é um professor de inglês.

    Crie UMA pergunta em inglês baseada APENAS nas frases fornecidas.

    REGRAS:
    - Nada genérico
    - Use o contexto das frases
    - Adeque ao nível: {$level}
    - Máximo 1 pergunta
    - Não use aspas

    Responda apenas com a pergunta.
    ";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Frases:\n" . $phrasesText],
    ];

    $question = $this->callAPI($messages);

    $sql = "INSERT INTO perguntas_ia (user_id, status_id, question) 
            VALUES (:user_id, :status_id, :question)";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':status_id' => $statusId,
        ':question' => $question
    ]);

    return [
        'question' => $question,
        'total_today' => $total_today,
        'limit_reached' => false
    ];
}

    /* ======================================================
       2) AVALIAR RESPOSTA DO ALUNO
    ====================================================== */
public function evaluateAnswer(array $phrases, $question, $userAnswer)
{
    $answerLower = strtolower(trim($userAnswer));

    $invalid = ['i don\'t know', 'idk', 'não sei', 'sei lá'];

    if (in_array($answerLower, $invalid)) {
        return [
            'is_correct' => false,
            'feedback' => "Sua resposta está correta gramaticalmente, mas não responde à pergunta. Tente dizer algo que você não quer mais fazer 😊"
        ];
    }

    $phrasesText = implode("\n", $phrases);

    $systemPrompt = "
    Você é um professor de inglês.

    IMPORTANTE:
    - Responda SEMPRE em português (Brasil)

    Avalie a resposta do aluno considerando:
    1. Se responde corretamente à pergunta
    2. Se está gramaticalmente correta

    Regras:
    - Se NÃO responder → is_correct = false
    - Se responder corretamente → is_correct = true
    - Seja direto
    - Máximo 3 linhas

    Retorne JSON:
    {
    \"feedback\": \"texto\",
    \"is_correct\": true ou false
    }
    ";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        [
            'role' => 'user',
            'content' =>
                "Frases:\n{$phrasesText}\n\n" .
                "Pergunta:\n{$question}\n\n" .
                "Resposta:\n{$userAnswer}"
        ]
    ];

    $response = $this->callAPI($messages);

    $decoded = json_decode($response, true);

    if (!$decoded || !isset($decoded['is_correct'])) {
        return [
            'is_correct' => false,
            'feedback' => $response
        ];
    }

    return $decoded;
}

    /* ======================================================
       API (GROQ)
    ====================================================== */
private function callAPI(array $messages)
{
    $payload = [
        'model' => 'llama-3.1-8b-instant',
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => 400,
    ];

    $ch = curl_init($this->baseUrl . '/chat/completions');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('HTTP ' . $httpCode . ' - ' . $response);
    }

    $result = json_decode($response, true);

    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception('Formato inesperado: ' . $response);
    }

    return trim($result['choices'][0]['message']['content']);
}

}