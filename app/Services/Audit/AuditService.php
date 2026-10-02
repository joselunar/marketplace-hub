<?php

namespace App\Services\Audit;

use App\Models\AuditLogModel;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Registra quem alterou o quê. Campos sensíveis são mascarados antes de persistir.
 */
class AuditService
{
    private const SENSITIVE_KEY_PATTERN = '/pass(word)?|token|secret|api[_-]?key|authorization|credential/i';
    private const REDACTED              = '[REDACTED]';

    public function __construct(private readonly AuditLogModel $model = new AuditLogModel())
    {
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function log(string $action, string $entityType, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null, ?int $userId = null): void
    {
        if ($oldValues !== null && $newValues !== null) {
            [$oldValues, $newValues] = $this->diff($oldValues, $newValues);

            if ($oldValues === [] && $newValues === []) {
                return;
            }
        }

        $this->model->insert([
            'user_id'     => $userId ?? $this->currentUserId(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues !== null ? json_encode(self::sanitize($oldValues), JSON_UNESCAPED_UNICODE) : null,
            'new_values'  => $newValues !== null ? json_encode(self::sanitize($newValues), JSON_UNESCAPED_UNICODE) : null,
            'ip_address'  => $this->currentIp(),
        ]);
    }

    /**
     * Mascara recursivamente qualquer chave que pareça credencial.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = self::sanitize($value);
            }
        }

        return $values;
    }

    /**
     * Mantém apenas os campos que realmente mudaram.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diff(array $old, array $new): array
    {
        $ignored = ['created_at', 'updated_at'];
        $before  = [];
        $after   = [];

        foreach ($new as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }
            $previous = $old[$key] ?? null;

            if ((string) $previous !== (string) $value || ($previous === null) !== ($value === null)) {
                $before[$key] = $previous;
                $after[$key]  = $value;
            }
        }

        return [$before, $after];
    }

    public function currentUserId(): ?int
    {
        if (is_cli() && ENVIRONMENT !== 'testing') {
            return null;
        }

        $userId = session('user_id');

        return $userId !== null ? (int) $userId : null;
    }

    private function currentIp(): ?string
    {
        $request = service('request');

        return $request instanceof IncomingRequest ? $request->getIPAddress() : null;
    }
}
