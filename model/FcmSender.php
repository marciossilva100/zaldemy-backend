<?php

// Cliente mínimo da API HTTP v1 do Firebase Cloud Messaging - sem SDK
// oficial do Firebase (pesado só pra mandar notificação). Assina o JWT da
// conta de serviço com openssl nativo do PHP e troca por um access token
// OAuth2, seguindo o fluxo padrão documentado pelo Google pra contas de
// serviço (RFC 7523 - JWT Bearer Grant).
class FcmSender
{
    private string $projectId;
    private string $clientEmail;
    private string $privateKey;
    private ?string $accessToken = null;
    private int $accessTokenExpira = 0;

    public function __construct(string $serviceAccountPath)
    {
        if (!file_exists($serviceAccountPath)) {
            throw new \RuntimeException("Arquivo de conta de serviço do Firebase não encontrado: {$serviceAccountPath}");
        }

        $conta = json_decode(file_get_contents($serviceAccountPath), true);

        if (!$conta || empty($conta['project_id']) || empty($conta['client_email']) || empty($conta['private_key'])) {
            throw new \RuntimeException("Arquivo de conta de serviço do Firebase inválido.");
        }

        $this->projectId = $conta['project_id'];
        $this->clientEmail = $conta['client_email'];
        $this->privateKey = $conta['private_key'];
    }

    private function obterAccessToken(): string
    {
        // Reaproveita o token enquanto não expirar - evita trocar um token
        // OAuth2 novo a cada notificação quando manda em lote (cron de
        // reengajamento/streak percorre vários usuários na mesma execução).
        if ($this->accessToken && time() < $this->accessTokenExpira - 60) {
            return $this->accessToken;
        }

        $agora = time();
        $base64url = fn($dados) => rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');

        $jwtSemAssinatura = $base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.' . $base64url(json_encode([
                'iss' => $this->clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $agora,
                'exp' => $agora + 3600,
            ]));

        $assinatura = '';
        if (!openssl_sign($jwtSemAssinatura, $assinatura, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Falha ao assinar JWT da conta de serviço do Firebase.');
        }

        $jwt = $jwtSemAssinatura . '.' . $base64url($assinatura);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $resposta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $erroCurl) {
            throw new \RuntimeException('Erro ao obter access token do Firebase: ' . ($erroCurl ?: 'sem resposta'));
        }

        $dados = json_decode($resposta, true);

        if ($httpCode !== 200 || empty($dados['access_token'])) {
            throw new \RuntimeException('Falha ao obter access token do Firebase: ' . ($dados['error_description'] ?? $resposta));
        }

        $this->accessToken = $dados['access_token'];
        $this->accessTokenExpira = $agora + (int) ($dados['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    // tokenInvalido=true indica que o token FCM não existe mais (app
    // desinstalado, token trocado) e deve ser removido do banco - mesma
    // ideia do isSubscriptionExpired() do Web Push (ver PushNotification.php).
    public function enviar(string $fcmToken, string $titulo, string $corpo, string $url): array
    {
        $accessToken = $this->obterAccessToken();

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $titulo,
                    'body' => $corpo,
                ],
                'data' => [
                    'url' => $url,
                ],
            ],
        ];

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10,
        ]);
        $resposta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $erroCurl) {
            return ['sucesso' => false, 'motivo' => $erroCurl ?: 'sem resposta', 'tokenInvalido' => false];
        }

        if ($httpCode === 200) {
            return ['sucesso' => true, 'motivo' => null, 'tokenInvalido' => false];
        }

        $dados = json_decode($resposta, true);
        $status = $dados['error']['status'] ?? null;

        // UNREGISTERED/NOT_FOUND = token não existe mais (app desinstalado,
        // trocou de token) - mesmo conceito do 404/410 do Web Push, limpa
        // do banco em vez de tentar pra sempre.
        $tokenInvalido = in_array($status, ['UNREGISTERED', 'NOT_FOUND'], true);

        return [
            'sucesso' => false,
            'motivo' => $dados['error']['message'] ?? $resposta,
            'tokenInvalido' => $tokenInvalido,
        ];
    }
}
