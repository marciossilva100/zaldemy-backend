<?php

require_once __DIR__ . '/Totp.php';

// Login do /dashboard-admin - totalmente separado da tabela `usuarios` do
// app (ver migration_dashboard_admin_auth.sql). Fluxo em 2 etapas:
// 1) usuário/senha -> sessão 'pendente_totp' (expira em poucos minutos)
// 2) código do app autenticador -> sessão vira 'ativa' (token real de admin)
class DashboardAuth
{
    const DURACAO_SESSAO_ATIVA_HORAS = 24;
    const DURACAO_PENDENTE_TOTP_MINUTOS = 10;
    const MAX_TENTATIVAS_FALHAS = 5;
    const BLOQUEIO_MINUTOS = 15;

    // Primeiro acesso: só funciona enquanto não existir NENHUMA conta admin
    // ainda - autolimitado de propósito, pra não sobrar como uma rota que
    // qualquer um poderia usar depois pra criar uma conta extra sem precisar
    // estar logado em nada. Depois da 1ª conta criada, essa action sempre
    // retorna erro.
    public static function criarContaInicial(PDO $pdo, string $usuario, string $senha): array
    {
        $totalContas = (int) $pdo->query("SELECT COUNT(*) FROM dashboard_admin_contas")->fetchColumn();

        if ($totalContas > 0) {
            return ['success' => false, 'message' => 'Já existe uma conta de admin configurada.'];
        }

        $usuario = trim($usuario);

        if ($usuario === '' || strlen($senha) < 10) {
            return ['success' => false, 'message' => 'Usuário obrigatório e senha com no mínimo 10 caracteres.'];
        }

        $hash = password_hash($senha, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO dashboard_admin_contas (usuario, senha_hash) VALUES (:usuario, :hash)"
        );
        $stmt->execute([':usuario' => $usuario, ':hash' => $hash]);

        return ['success' => true];
    }

    private static function buscarConta(PDO $pdo, string $usuario): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM dashboard_admin_contas WHERE usuario = :usuario LIMIT 1");
        $stmt->execute([':usuario' => $usuario]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Retorna sempre a MESMA forma de resposta (success/message) pra usuário
    // inexistente, senha errada ou conta bloqueada - não dá pra quem está
    // tentando adivinhar saber qual dessas está acontecendo.
    public static function login(PDO $pdo, string $usuario, string $senha): array
    {
        $conta = self::buscarConta($pdo, trim($usuario));

        // Mesmo sem achar a conta, roda um password_verify contra um hash
        // qualquer - sem isso, a resposta de "usuário não existe" chega
        // mais rápido que "senha errada", e essa diferença de tempo dá pra
        // enumerar quais usuários existem.
        if (!$conta) {
            password_verify($senha, '$2y$10$' . str_repeat('a', 53));
            return ['success' => false, 'message' => 'Usuário ou senha inválidos.'];
        }

        if (!empty($conta['bloqueado_until']) && strtotime($conta['bloqueado_until']) > time()) {
            return ['success' => false, 'message' => 'Conta temporariamente bloqueada por muitas tentativas. Tente novamente mais tarde.'];
        }

        if (!password_verify($senha, $conta['senha_hash'])) {
            self::registrarTentativaFalha($pdo, (int) $conta['id'], (int) $conta['tentativas_falhas']);
            return ['success' => false, 'message' => 'Usuário ou senha inválidos.'];
        }

        // Senha certa - zera o contador de tentativas falhas.
        $pdo->prepare("UPDATE dashboard_admin_contas SET tentativas_falhas = 0, bloqueado_until = NULL WHERE id = :id")
            ->execute([':id' => $conta['id']]);

        $token = bin2hex(random_bytes(32));
        $expiraEm = (new DateTime())->modify('+' . self::DURACAO_PENDENTE_TOTP_MINUTOS . ' minutes')->format('Y-m-d H:i:s');

        $pdo->prepare(
            "INSERT INTO dashboard_admin_sessoes (conta_id, token, status, expira_em) VALUES (:conta_id, :token, 'pendente_totp', :expira)"
        )->execute([':conta_id' => $conta['id'], ':token' => $token, ':expira' => $expiraEm]);

        // Ainda não confirmou o TOTP (1º login de verdade) - manda o
        // segredo + URI de provisionamento pra montar o QR code no
        // frontend. Já tem um segredo gerado mas nunca confirmado (ex:
        // usuário saiu no meio do setup) - reaproveita o mesmo em vez de
        // gerar outro, senão um QR já escaneado deixaria de funcionar.
        if (!$conta['totp_confirmado']) {
            $segredo = $conta['totp_secret'] ?: Totp::gerarSegredo();

            if (!$conta['totp_secret']) {
                $pdo->prepare("UPDATE dashboard_admin_contas SET totp_secret = :s WHERE id = :id")
                    ->execute([':s' => $segredo, ':id' => $conta['id']]);
            }

            return [
                'success' => true,
                'precisa_configurar_totp' => true,
                'token' => $token,
                'totp_secret' => $segredo,
                'totp_uri' => Totp::uriProvisionamento($segredo, $conta['usuario']),
            ];
        }

        return ['success' => true, 'precisa_totp' => true, 'token' => $token];
    }

    private static function registrarTentativaFalha(PDO $pdo, int $contaId, int $tentativasAtuais): void
    {
        $novasTentativas = $tentativasAtuais + 1;
        $bloqueado = $novasTentativas >= self::MAX_TENTATIVAS_FALHAS;

        $sql = "UPDATE dashboard_admin_contas
                SET tentativas_falhas = :tentativas,
                    bloqueado_until = " . ($bloqueado ? "DATE_ADD(NOW(), INTERVAL " . self::BLOQUEIO_MINUTOS . " MINUTE)" : "bloqueado_until") . "
                WHERE id = :id";

        $pdo->prepare($sql)->execute([':tentativas' => $novasTentativas, ':id' => $contaId]);
    }

    // Etapa 2: confere o código de 6 dígitos contra o segredo da conta
    // dona da sessão 'pendente_totp' informada.
    public static function verificarTotp(PDO $pdo, string $token, string $codigo): array
    {
        $stmt = $pdo->prepare(
            "SELECT s.id AS sessao_id, s.conta_id, c.totp_secret, c.totp_confirmado
             FROM dashboard_admin_sessoes s
             JOIN dashboard_admin_contas c ON c.id = s.conta_id
             WHERE s.token = :token AND s.status = 'pendente_totp' AND s.expira_em > NOW()
             LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        $sessao = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sessao || !$sessao['totp_secret']) {
            return ['success' => false, 'message' => 'Sessão expirada. Faça login de novo.'];
        }

        if (!Totp::codigoValido($sessao['totp_secret'], $codigo)) {
            return ['success' => false, 'message' => 'Código inválido.'];
        }

        $expiraEm = (new DateTime())->modify('+' . self::DURACAO_SESSAO_ATIVA_HORAS . ' hours')->format('Y-m-d H:i:s');

        $pdo->prepare(
            "UPDATE dashboard_admin_sessoes SET status = 'ativa', expira_em = :expira WHERE id = :id"
        )->execute([':expira' => $expiraEm, ':id' => $sessao['sessao_id']]);

        if (!$sessao['totp_confirmado']) {
            $pdo->prepare("UPDATE dashboard_admin_contas SET totp_confirmado = 1 WHERE id = :id")
                ->execute([':id' => $sessao['conta_id']]);
        }

        return ['success' => true];
    }

    // Usado pelo middleware do dashboard.php - só sessão 'ativa' e dentro
    // da validade conta como admin autenticado de verdade.
    public static function validarSessaoAtiva(PDO $pdo, string $token): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM dashboard_admin_sessoes WHERE token = :token AND status = 'ativa' AND expira_em > NOW() LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        return (bool) $stmt->fetchColumn();
    }

    public static function logout(PDO $pdo, string $token): void
    {
        $pdo->prepare("DELETE FROM dashboard_admin_sessoes WHERE token = :token")->execute([':token' => $token]);
    }
}
