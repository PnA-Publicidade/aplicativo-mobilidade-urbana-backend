<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class ObterNavegacaoService
{
    /**
     * Busca a rota passo a passo (manobras, distância, rua) entre dois
     * pontos, para a navegação dentro do app — diferente de
     * ObterTracadoRotaService, que só devolve o traçado pontilhado.
     *
     * @return array{
     *     distancia_m: int,
     *     duracao_s: int,
     *     polyline: list<array{latitude: float, longitude: float}>,
     *     passos: list<array{
     *         manobra: string,
     *         instrucao: string,
     *         rua: string|null,
     *         distancia_m: int,
     *         duracao_s: int,
     *         inicio: array{latitude: float, longitude: float},
     *         fim: array{latitude: float, longitude: float},
     *         polyline: list<array{latitude: float, longitude: float}>,
     *     }>,
     * }|null
     */
    public function executar(
        float $origemLat,
        float $origemLng,
        float $destinoLat,
        float $destinoLng,
    ): ?array {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->get(
                'https://maps.googleapis.com/maps/api/directions/json',
                [
                    'origin' => "$origemLat,$origemLng",
                    'destination' => "$destinoLat,$destinoLng",
                    'mode' => 'driving',
                    'language' => 'pt-BR',
                    'key' => config('services.google_maps.key'),
                ]
            );

            $rota = $response->json('routes.0');

            if (! is_array($rota)) {
                return null;
            }

            $perna = $rota['legs'][0] ?? null;

            if (! is_array($perna)) {
                return null;
            }

            $passosBrutos = is_array($perna['steps'] ?? null) ? $perna['steps'] : [];

            $overview = $this->decodificarPolyline((string) ($rota['overview_polyline']['points'] ?? ''));

            return [
                'distancia_m' => (int) ($perna['distance']['value'] ?? 0),
                'duracao_s' => (int) ($perna['duration']['value'] ?? 0),
                'polyline' => $overview,
                'passos' => array_values(array_map(fn (array $passo) => $this->normalizarPasso($passo), $passosBrutos)),
            ];
        } catch (Throwable $erro) {
            report($erro);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $passo
     * @return array{
     *     manobra: string,
     *     instrucao: string,
     *     rua: string|null,
     *     distancia_m: int,
     *     duracao_s: int,
     *     inicio: array{latitude: float, longitude: float},
     *     fim: array{latitude: float, longitude: float},
     *     polyline: list<array{latitude: float, longitude: float}>,
     * }
     */
    private function normalizarPasso(array $passo): array
    {
        $instrucaoCrua = (string) ($passo['html_instructions'] ?? '');
        $instrucao = trim(html_entity_decode(strip_tags($instrucaoCrua), ENT_QUOTES, 'UTF-8'));

        return [
            'manobra' => (string) ($passo['maneuver'] ?? 'straight'),
            'instrucao' => $instrucao,
            'rua' => $this->extrairRua($instrucaoCrua),
            'distancia_m' => (int) ($passo['distance']['value'] ?? 0),
            'duracao_s' => (int) ($passo['duration']['value'] ?? 0),
            'inicio' => [
                'latitude' => (float) ($passo['start_location']['lat'] ?? 0),
                'longitude' => (float) ($passo['start_location']['lng'] ?? 0),
            ],
            'fim' => [
                'latitude' => (float) ($passo['end_location']['lat'] ?? 0),
                'longitude' => (float) ($passo['end_location']['lng'] ?? 0),
            ],
            'polyline' => $this->decodificarPolyline((string) ($passo['polyline']['points'] ?? '')),
        ];
    }

    /**
     * O Google não separa o nome da rua num campo à parte: ele vem embutido
     * no HTML da instrução (ex. "Vire à esquerda na <b>R. Consagração</b>").
     * Pega o texto em negrito depois de "na"/"no"/"em direção a"/"onto".
     */
    private function extrairRua(string $htmlInstrucoes): ?string
    {
        if (preg_match_all('/<b>(.*?)<\/b>/i', $htmlInstrucoes, $negritos) === false) {
            return null;
        }

        $candidatos = array_map(
            fn (string $texto) => trim(html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8')),
            $negritos[1]
        );
        $candidatos = array_values(array_filter($candidatos, fn (string $texto) => $texto !== ''));

        if ($candidatos === []) {
            return null;
        }

        // o último trecho em negrito costuma ser a rua/destino da manobra;
        // os anteriores são a direção ("esquerda", "direita")
        return end($candidatos);
    }

    /**
     * @return list<array{latitude: float, longitude: float}>
     */
    private function decodificarPolyline(string $codificado): array
    {
        if ($codificado === '') {
            return [];
        }

        $pontos = [];
        $indice = 0;
        $latitude = 0;
        $longitude = 0;
        $tamanho = strlen($codificado);

        while ($indice < $tamanho) {
            foreach (['lat', 'lng'] as $eixo) {
                $deslocamento = 0;
                $resultado = 0;

                do {
                    $byte = ord($codificado[$indice++]) - 63;
                    $resultado |= ($byte & 0x1F) << $deslocamento;
                    $deslocamento += 5;
                } while ($byte >= 0x20 && $indice < $tamanho);

                $delta = ($resultado & 1) ? ~($resultado >> 1) : ($resultado >> 1);

                if ($eixo === 'lat') {
                    $latitude += $delta;
                } else {
                    $longitude += $delta;
                }
            }

            $pontos[] = [
                'latitude' => $latitude / 1e5,
                'longitude' => $longitude / 1e5,
            ];
        }

        return $pontos;
    }
}
