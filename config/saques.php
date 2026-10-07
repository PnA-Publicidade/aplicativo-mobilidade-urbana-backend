<?php

return [
    // provedor dos saques do motorista. "simulado" não move dinheiro: o saque
    // conclui sozinho depois de alguns segundos e é recusado em produção
    'gateway' => env('SAQUES_GATEWAY', 'simulado'),
    // AbacatePay: mínimo de R$ 3,50 por saque
    'valor_minimo' => (float) env('SAQUES_VALOR_MINIMO', 3.50),
    'taxa' => (float) env('SAQUES_TAXA', 0),
    'simulado_segundos_para_concluir' => (int) env('SAQUES_SIMULADO_SEGUNDOS', 10),
    // sem provedor de SMS ainda: o código da conta bancária volta na resposta
    'codigo_sms_na_resposta' => (bool) env('SAQUES_CODIGO_SMS_NA_RESPOSTA', env('APP_ENV') === 'local'),
    'codigo_sms_validade_minutos' => 10,
];
