<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Services\Settings\SettingsService;
use CodeIgniter\Cache\CacheInterface;
use Throwable;

/**
 * Preferências logísticas da conta (somente leitura) e resolução do
 * contexto usado em listing_prices / shipping_options/free.
 *
 * Não inventa mode/logistic_type: usa .env, configuração confirmada no
 * painel ou o único/default ativo devolvido por shipping_preferences.
 */
class MercadoLivreLogisticsService
{
    public function __construct(
        private readonly ?MercadoLivreService $meli = null,
        private readonly ?SettingsService $settings = null,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * @return array{
     *   modes: list<string>,
     *   logistics: list<array{mode: string, types: list<array{type: string, default: bool, status: string}>}>,
     *   local_pick_up: bool|null,
     *   tags: list<string>,
     *   raw_keys: list<string>,
     *   fetched_at: string
     * }
     */
    public function preferences(array $account, bool $fresh = false): array
    {
        $key = 'meli_ship_prefs_' . (int) $account['id'];
        $cache = $this->cache ?? service('cache');

        if (! $fresh) {
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $meli = $this->meli ?? service('mercadoLivre');
        $raw  = $meli->get(
            (int) $account['id'],
            '/users/' . rawurlencode((string) $account['external_user_id']) . '/shipping_preferences',
        );

        $logistics = [];
        foreach ($raw['logistics'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $types = [];
            foreach ($row['types'] ?? [] as $type) {
                if (! is_array($type) || ! is_string($type['type'] ?? null)) {
                    continue;
                }
                $types[] = [
                    'type'   => (string) $type['type'],
                    'default'=> (bool) ($type['default'] ?? false),
                    'status' => (string) ($type['status'] ?? ''),
                ];
            }
            $logistics[] = [
                'mode'  => (string) ($row['mode'] ?? ''),
                'types' => $types,
            ];
        }

        $normalized = [
            'modes'         => array_values(array_filter($raw['modes'] ?? [], 'is_string')),
            'logistics'     => $logistics,
            'local_pick_up' => array_key_exists('local_pick_up', $raw) ? (bool) $raw['local_pick_up'] : null,
            'tags'          => array_values(array_filter($raw['tags'] ?? [], 'is_string')),
            'raw_keys'      => array_slice(array_keys($raw), 0, 30),
            'fetched_at'    => date('Y-m-d H:i:s'),
        ];

        $cache->save($key, $normalized, ($this->meli ?? service('mercadoLivre'))->config()->sellerCacheSeconds);

        return $normalized;
    }

    /**
     * Contexto de cotação: mode + logistic_type resolvidos sem chute.
     *
     * @return array{
     *   shipping_mode: ?string,
     *   logistic_type: ?string,
     *   source: string,
     *   ready: bool,
     *   reason: string,
     *   options: list<array{mode: string, type: string, default: bool}>,
     *   preferences: ?array
     * }
     */
    public function resolveQuoteContext(array $account): array
    {
        $prefs   = null;
        $options = [];
        try {
            $prefs   = $this->preferences($account);
            $options = $this->activeOptions($prefs);
        } catch (Throwable $e) {
            // Preferências falharam; ainda dá para usar env/settings.
            $prefsFail = $e->getMessage();
        }

        $envMode = trim(($this->meli ?? service('mercadoLivre'))->config()->shippingMode);
        $envType = trim(($this->meli ?? service('mercadoLivre'))->config()->logisticType);
        if ($envMode !== '' && $envType !== '') {
            return $this->context($envMode, $envType, 'env', true, 'MELI_SHIPPING_MODE / MELI_LOGISTIC_TYPE do .env.', $options, $prefs);
        }

        $settings = $this->settings ?? service('settings');
        $setMode  = trim((string) $settings->get('meli.shipping_mode', ''));
        $setType  = trim((string) $settings->get('meli.logistic_type', ''));
        if ($setMode !== '' && $setType !== '') {
            if ($options !== [] && ! $this->optionExists($options, $setMode, $setType)) {
                return $this->context(null, null, 'settings_invalid', false, 'Configuração do painel (' . $setMode . '/' . $setType . ') não aparece nas preferências ativas da conta. Corrija em Configurações ou Logística ML.', $options, $prefs);
            }

            return $this->context($setMode, $setType, 'settings', true, 'Confirmado no painel (meli.shipping_mode / meli.logistic_type).', $options, $prefs);
        }

        if ($prefs === null) {
            return $this->context(null, null, 'unavailable', false, 'Não foi possível ler shipping_preferences: ' . ($prefsFail ?? 'erro desconhecido'), $options, null);
        }

        $me2 = array_values(array_filter($options, static fn (array $o): bool => $o['mode'] === 'me2'));
        if ($me2 === []) {
            return $this->context(null, null, 'no_me2', false, 'A conta não tem logística me2 ativa nas preferências. Sem mode/logistic_type confirmados o frete do vendedor permanece desconhecido.', $options, $prefs);
        }

        $defaults = array_values(array_filter($me2, static fn (array $o): bool => $o['default']));
        if (count($defaults) === 1) {
            return $this->context($defaults[0]['mode'], $defaults[0]['type'], 'preferences_default', true, 'Único tipo me2 marcado como default em GET /users/{id}/shipping_preferences.', $options, $prefs);
        }

        if (count($me2) === 1) {
            return $this->context($me2[0]['mode'], $me2[0]['type'], 'preferences_single', true, 'Único tipo me2 ativo em shipping_preferences.', $options, $prefs);
        }

        $list = implode(', ', array_map(static fn (array $o): string => $o['mode'] . '/' . $o['type'], $me2));

        return $this->context(null, null, 'ambiguous', false, 'Há vários tipos me2 ativos (' . $list . '). Confirme meli.shipping_mode e meli.logistic_type no painel escolhendo uma opção real da conta.', $options, $prefs);
    }

    /**
     * @return list<array{mode: string, type: string, default: bool}>
     */
    public function activeOptions(array $preferences): array
    {
        $out = [];
        foreach ($preferences['logistics'] ?? [] as $row) {
            $mode = (string) ($row['mode'] ?? '');
            if ($mode === '') {
                continue;
            }
            foreach ($row['types'] ?? [] as $type) {
                if (($type['status'] ?? '') !== '' && ($type['status'] ?? '') !== 'active') {
                    continue;
                }
                $out[] = [
                    'mode'    => $mode,
                    'type'    => (string) $type['type'],
                    'default' => (bool) ($type['default'] ?? false),
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array{mode: string, type: string, default: bool}> $options
     */
    private function optionExists(array $options, string $mode, string $type): bool
    {
        foreach ($options as $option) {
            if ($option['mode'] === $mode && $option['type'] === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{mode: string, type: string, default: bool}> $options
     */
    private function context(?string $mode, ?string $type, string $source, bool $ready, string $reason, array $options, ?array $preferences): array
    {
        return [
            'shipping_mode' => $mode,
            'logistic_type' => $type,
            'source'        => $source,
            'ready'         => $ready,
            'reason'        => $reason,
            'options'       => $options,
            'preferences'   => $preferences,
        ];
    }
}
