Rota: `/auth/valida-email-cadastro`

Método: `POST`

Usada na tela de e-mail do cadastro, antes de avançar. Aplica a mesma regra de e-mail do `/auth/register`, que continua validando no final.

Body:
```json
{
    "email": "" // String | Obrigatório
}
```

Respostas:

- `200`: `{ "disponivel": true }`
- `422`: e-mail inválido ou já cadastrado, com a mensagem em `errors.email`
- `429`: limite de 10 consultas por minuto, junto com `/auth/verifica-se-conta-existe`
