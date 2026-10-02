<?php

namespace App\Services\Settings;

use App\Exceptions\ValidationException;
use App\Models\SettingModel;
use App\Services\Audit\AuditService;
use App\Support\Decimal;
use Config\SystemSettings;
use InvalidArgumentException;

/**
 * Leitura e gravação das configurações do sistema (tabela settings).
 */
class SettingsService
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    public function __construct(
        private readonly SettingModel $model = new SettingModel(),
        private readonly SystemSettings $config = new SystemSettings(),
        private readonly ?AuditService $audit = null,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        if (isset($this->config->definitions[$key])) {
            return $this->cast($this->config->definitions[$key]['default'], $this->config->definitions[$key]['type']);
        }

        return $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getDecimal(string $key, string $default = '0.00'): string
    {
        return (string) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /**
     * Todas as configurações já convertidas para o tipo definido.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];

        foreach ($this->model->findAll() as $row) {
            $this->cache[$row['key']] = $this->cast($row['value'], $row['type']);
        }

        return $this->cache;
    }

    /**
     * Valida e grava as configurações conhecidas recebidas do formulário.
     *
     * @param array<string, mixed> $input chave => valor
     *
     * @return list<string> chaves cujo valor mudou
     */
    public function updateMany(array $input, ?int $userId = null): array
    {
        $definitions = $this->config->definitions;
        $data        = [];
        $rules       = [];

        foreach ($definitions as $key => $definition) {
            $field = $this->fieldName($key);
            $value = $input[$key] ?? null;

            if ($definition['type'] === 'bool') {
                $value = ! empty($value) ? '1' : '0';
            } elseif ($definition['type'] === 'decimal') {
                $value = Decimal::normalize($value) ?? (is_scalar($value) ? (string) $value : '');
            } else {
                $value = is_scalar($value) ? trim((string) $value) : '';
            }

            $data[$field]  = $value;
            $rules[$field] = ['label' => $definition['label'], 'rules' => $definition['rules']];
        }

        $validation = service('validation');
        $validation->reset()->setRules($rules);

        if (! $validation->run($data)) {
            $errors = [];

            foreach ($validation->getErrors() as $field => $message) {
                $errors[$this->keyFromField($field)] = $message;
            }

            throw new ValidationException($errors);
        }

        $this->guardMarginConsistency($data);
        $this->guardRankingWeights($data, $definitions);

        $current = $this->all();
        $db      = $this->model->db;
        $db->transException(true)->transStart();

        foreach ($definitions as $key => $definition) {
            $this->persist($key, $definition, $data[$this->fieldName($key)], $userId);
        }

        $db->transComplete();

        $this->cache = null;
        $updated     = $this->all();
        $this->audit()->log('update', 'settings', null, $current, $updated, $userId);

        $changed = [];

        foreach (array_keys($definitions) as $key) {
            if (! array_key_exists($key, $current) || $current[$key] !== $updated[$key]) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Cria as chaves ausentes com o valor padrão (usado pelo seeder).
     */
    public function seedDefaults(): int
    {
        $created = 0;

        foreach ($this->config->definitions as $key => $definition) {
            if ($this->model->where('key', $key)->countAllResults() > 0) {
                continue;
            }
            $this->persist($key, $definition, $this->serialize($definition['default'], $definition['type']), null);
            $created++;
        }

        $this->cache = null;

        return $created;
    }

    /**
     * @return array<string, array{label: string, items: array<string, array<string, mixed>>}>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->config->groups as $group => $label) {
            $groups[$group] = ['label' => $label, 'items' => []];
        }

        foreach ($this->config->definitions as $key => $definition) {
            $groups[$definition['group']]['items'][$key] = $definition + ['value' => $this->get($key)];
        }

        return $groups;
    }

    private function guardMarginConsistency(array $data): void
    {
        $desired = $data[$this->fieldName('pricing.desired_margin_percentage')] ?? null;
        $minimum = $data[$this->fieldName('pricing.minimum_margin_percentage')] ?? null;

        if ($desired !== null && $minimum !== null && bccomp((string) $minimum, (string) $desired, 2) > 0) {
            throw new ValidationException([
                'pricing.minimum_margin_percentage' => 'A margem mínima não pode ser maior que a margem desejada.',
            ]);
        }
    }

    /**
     * Com todos os pesos zerados o ranking não teria como diferenciar as ofertas.
     */
    private function guardRankingWeights(array $data, array $definitions): void
    {
        $total = '0';

        foreach (array_keys($definitions) as $key) {
            if (str_starts_with($key, 'ranking.weight_')) {
                $total = bcadd($total, (string) ($data[$this->fieldName($key)] ?? '0'), 2);
            }
        }

        if (bccomp($total, '0', 2) <= 0) {
            throw new ValidationException([
                'ranking.weight_price' => 'Pelo menos um peso do ranking precisa ser maior que zero.',
            ]);
        }
    }

    private function persist(string $key, array $definition, string $value, ?int $userId): void
    {
        $existing = $this->model->where('key', $key)->first();
        $row      = [
            'key'        => $key,
            'value'      => $value,
            'type'       => $definition['type'],
            'group'      => $definition['group'],
            'updated_by' => $userId,
        ];

        if ($existing === null) {
            $this->model->insert($row);

            return;
        }

        $this->model->update($existing['id'], $row);
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int'     => (int) $value,
            'bool'    => in_array($value, [true, 1, '1', 'true'], true),
            'decimal' => Decimal::normalize($value) ?? '0.00',
            'json'    => is_string($value) ? json_decode($value, true) : $value,
            'string'  => (string) $value,
            default   => throw new InvalidArgumentException("Tipo de configuração desconhecido: {$type}"),
        };
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool'    => $value ? '1' : '0',
            'decimal' => Decimal::normalize($value) ?? '0.00',
            'json'    => json_encode($value, JSON_THROW_ON_ERROR),
            default   => (string) $value,
        };
    }

    /**
     * Pontos em nomes de campo conflitam com a notação de array da validação.
     */
    private function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function keyFromField(string $field): string
    {
        return str_replace('__', '.', $field);
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}
