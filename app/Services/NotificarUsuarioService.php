<?php

namespace App\Services;

use App\Models\Notificacao;

// registra o que aconteceu com a conta (corrida, pagamento) para aparecer na lista
// de notificações do aplicativo e manda o mesmo aviso por push para o celular;
// o texto nunca leva dados pessoais de terceiros
class NotificarUsuarioService
{
    public function __construct(private readonly EnviarPushService $push) {}

    /**
     * @param  int  $perfilId  id em motoristas ou passageiros, conforme o perfil
     * @param  'passageiro'|'motorista'  $perfil  app em que a notificação aparece
     * @param  array<string, string>  $dados  vão junto no push (ex.: corrida_id)
     */
    public function executar(int $perfilId, string $perfil, string $titulo, string $mensagem, array $dados = []): void
    {
        $titulo = mb_substr($titulo, 0, 120);
        $mensagem = mb_substr($mensagem, 0, 255);

        Notificacao::create([
            'perfil' => $perfil,
            'perfil_id' => $perfilId,
            'titulo' => $titulo,
            'mensagem' => $mensagem,
        ]);

        // depois da resposta: o FCM não atrasa quem mudou o status da corrida
        dispatch(fn () => $this->push->paraUsuario($perfilId, $perfil, $titulo, $mensagem, $dados))->afterResponse();
    }
}
