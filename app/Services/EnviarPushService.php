<?php

namespace App\Services;

use App\Models\DispositivoPush;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push pelo Firebase Cloud Messaging (API HTTP v1), direto do servidor: a
 * conta de serviço do projeto Firebase assina o pedido de acesso. Sem a conta
 * configurada (FIREBASE_CREDENTIALS) o envio é ignorado e o resto segue igual.
 */
class EnviarPushService
{
    private const ESCOPO = 'https://www.googleapis.com/auth/firebase.messaging';

    public function configurado(): bool
    {
        return $this->credenciais() !== null;
    }

    /** @param  int  $perfilId  id em motoristas ou passageiros, conforme o perfil */
    public function temAparelho(int $perfilId, string $perfil): bool
    {
        return DispositivoPush::where('perfil', $perfil)->where('perfil_id', $perfilId)->exists();
    }

    /**
     * @param  array<string, string>  $dados  vão junto para o app (ex.: corrida_id)
     * @param  string|null  $agrupar  tag da notificação no Android: reenviar com a mesma tag substitui a anterior
     */
    public function paraUsuario(int $perfilId, string $perfil, string $titulo, string $mensagem, array $dados = [], ?string $agrupar = null): void
    {
        $credenciais = $this->credenciais();

        if ($credenciais === null) {
            return;
        }

        $tokens = DispositivoPush::where('perfil', $perfil)->where('perfil_id', $perfilId)->pluck('token');

        foreach ($tokens as $token) {
            $this->enviar($credenciais, (string) $token, $titulo, $mensagem, $dados, $agrupar);
        }
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credenciais
     * @param  array<string, string>  $dados
     */
    private function enviar(array $credenciais, string $token, string $titulo, string $mensagem, array $dados, ?string $agrupar): void
    {
        try {
            $resposta = Http::withToken($this->tokenDeAcesso($credenciais))
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$credenciais['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $titulo, 'body' => $mensagem],
                        // vazio iria como lista ([]) e o FCM recusa: data precisa ser objeto
                        ...($dados === [] ? [] : ['data' => array_map('strval', $dados)]),
                        'android' => [
                            'priority' => 'HIGH',
                            // canal criado pelos apps (usePushNotificacoes)
                            'notification' => array_filter([
                                'channel_id' => 'corridas',
                                'sound' => 'default',
                                'tag' => $agrupar,
                            ]),
                        ],
                    ],
                ]);

            // aparelho desinstalou o app ou trocou de token: não insiste mais
            $corpo = (string) $resposta->body();
            $tokenMorto = $resposta->status() === 404
                || ($resposta->status() === 400 && (str_contains($corpo, 'UNREGISTERED') || str_contains($corpo, 'registration token')));

            if ($tokenMorto) {
                DispositivoPush::where('token', $token)->delete();

                return;
            }

            if ($resposta->failed()) {
                Log::warning('Push não enviado', ['status' => $resposta->status(), 'corpo' => mb_substr((string) $resposta->body(), 0, 300)]);
            }
        } catch (Throwable $erro) {
            Log::warning('Push não enviado', ['erro' => $erro->getMessage()]);
        }
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credenciais
     */
    private function tokenDeAcesso(array $credenciais): string
    {
        return Cache::remember('fcm:token:'.$credenciais['client_email'], now()->addMinutes(50), function () use ($credenciais) {
            $agora = time();
            $cabecalho = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $corpo = $this->base64Url((string) json_encode([
                'iss' => $credenciais['client_email'],
                'scope' => self::ESCOPO,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $agora,
                'exp' => $agora + 3600,
            ]));

            openssl_sign("{$cabecalho}.{$corpo}", $assinatura, $credenciais['private_key'], 'sha256WithRSAEncryption');

            return (string) Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$cabecalho}.{$corpo}.".$this->base64Url($assinatura),
            ])->throw()->json('access_token');
        });
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    private function credenciais(): ?array
    {
        $caminho = (string) config('services.fcm.credenciais');

        if ($caminho === '' || ! is_readable($caminho)) {
            return null;
        }

        $dados = json_decode((string) file_get_contents($caminho), true);

        if (! is_array($dados) || empty($dados['project_id']) || empty($dados['client_email']) || empty($dados['private_key'])) {
            return null;
        }

        return [
            'project_id' => (string) $dados['project_id'],
            'client_email' => (string) $dados['client_email'],
            'private_key' => (string) $dados['private_key'],
        ];
    }

    private function base64Url(string $texto): string
    {
        return rtrim(strtr(base64_encode($texto), '+/', '-_'), '=');
    }
}
