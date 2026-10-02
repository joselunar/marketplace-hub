<?php

use App\Enums\SupplierIntegrationType;
use App\Enums\SupplierStatus;
use App\Enums\UserRole;
use App\Models\MarketplaceAccountModel;
use App\Services\Suppliers\SupplierSelectionService;
use CodeIgniter\I18n\Time;

if (! function_exists('money')) {
    /**
     * Formata um valor decimal (string) como moeda brasileira.
     */
    function money(string|int|float|null $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }
}

if (! function_exists('decimal_br')) {
    function decimal_br(string|int|float|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $decimals, ',', '.');
    }
}

if (! function_exists('format_datetime')) {
    function format_datetime(?string $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $empty : date('d/m/Y H:i', $timestamp);
    }
}

if (! function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return service('auth')->user();
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return service('auth')->isAdmin();
    }
}

if (! function_exists('badge')) {
    /**
     * Badge Bootstrap com texto escapado.
     */
    function badge(string $label, string $variant = 'secondary'): string
    {
        return '<span class="badge rounded-pill text-bg-' . esc($variant, 'attr') . '">' . esc($label) . '</span>';
    }
}

if (! function_exists('active_badge')) {
    function active_badge(int|string|bool|null $active): string
    {
        return (int) $active === 1 ? badge('Ativo', 'success') : badge('Inativo', 'secondary');
    }
}

if (! function_exists('supplier_status_badge')) {
    function supplier_status_badge(string $status): string
    {
        $enum = SupplierStatus::tryFrom($status);

        return match ($enum) {
            SupplierStatus::Active   => badge($enum->label(), 'success'),
            SupplierStatus::Inactive => badge($enum->label(), 'secondary'),
            SupplierStatus::Blocked  => badge($enum->label(), 'danger'),
            default                  => badge($status, 'light'),
        };
    }
}

if (! function_exists('integration_type_label')) {
    function integration_type_label(string $type): string
    {
        return SupplierIntegrationType::tryFrom($type)?->label() ?? $type;
    }
}

if (! function_exists('role_label')) {
    function role_label(string $role): string
    {
        return UserRole::tryFrom($role)?->label() ?? $role;
    }
}

if (! function_exists('stock_badge')) {
    function stock_badge(int|string|null $quantity): string
    {
        $quantity = (int) $quantity;

        return $quantity > 0 ? badge((string) $quantity, 'success') : badge('Sem estoque', 'warning');
    }
}

if (! function_exists('score_format')) {
    function score_format(float|string|null $score): string
    {
        return $score === null || $score === '' ? '—' : number_format((float) $score, 1, ',', '.');
    }
}

if (! function_exists('rating_badge')) {
    /**
     * Classificação qualitativa de uma nota (excelente, bom, regular, fraco).
     */
    function rating_badge(string $rating): string
    {
        $variant = match ($rating) {
            'excelente' => 'success',
            'bom'       => 'primary',
            'regular'   => 'warning',
            default     => 'danger',
        };

        return badge(ucfirst($rating), $variant);
    }
}

if (! function_exists('selection_position_badge')) {
    function selection_position_badge(?int $rank): string
    {
        return match (true) {
            $rank === null => badge('Inelegível', 'secondary'),
            $rank === 1    => badge('Principal', 'success'),
            $rank <= 3     => badge('Reserva #' . ($rank - 1), 'info'),
            default        => badge('Elegível', 'light'),
        };
    }
}

if (! function_exists('percent_format')) {
    function percent_format(float|string|null $value, int $decimals = 1, string $empty = '—'): string
    {
        return $value === null || $value === '' ? $empty : number_format((float) $value, $decimals, ',', '.') . '%';
    }
}

if (! function_exists('margin_badge')) {
    /**
     * Margem estimada com destaque quando fica abaixo da mínima.
     */
    function margin_badge(float|string|null $margin, ?string $status = null): string
    {
        if ($margin === null || $margin === '') {
            return '<span class="text-body-secondary">—</span>';
        }

        return $status === SupplierSelectionService::PRICE_STATUS_BELOW_MINIMUM
            ? badge('Margem baixa ' . percent_format($margin), 'danger')
            : '<span class="fw-semibold">' . esc(percent_format($margin)) . '</span>';
    }
}

if (! function_exists('selection_trigger_label')) {
    function selection_trigger_label(string $trigger): string
    {
        return SupplierSelectionService::TRIGGER_LABELS[$trigger] ?? $trigger;
    }
}

if (! function_exists('sync_status_badge')) {
    function sync_status_badge(string $status): string
    {
        return match ($status) {
            'success', 'completed'  => badge('Sucesso', 'success'),
            'warning', 'completed_with_errors' => badge('Concluído com erros', 'warning'),
            'error', 'failed'       => badge('Erro', 'danger'),
            'processing', 'running' => badge('Sincronizando', 'info'),
            default                 => badge($status, 'secondary'),
        };
    }
}

if (! function_exists('marketplace_account_badge')) {
    function marketplace_account_badge(array $account): string
    {
        $label = MarketplaceAccountModel::STATUS_LABELS[$account['status']] ?? $account['status'];

        return match (true) {
            $account['status'] === MarketplaceAccountModel::STATUS_REAUTHORIZE  => badge($label, 'danger'),
            $account['status'] === MarketplaceAccountModel::STATUS_DISCONNECTED => badge($label, 'secondary'),
            ! empty($account['token_expired'])                                  => badge('Token vencido', 'warning'),
            default                                                             => badge($label, 'success'),
        };
    }
}

if (! function_exists('relative_time')) {
    /**
     * "em 5h 10min" / "há 2 dias", em relação ao momento atual.
     */
    function relative_time(?string $value, string $empty = '—'): string
    {
        $timestamp = $value !== null && $value !== '' ? strtotime($value) : false;
        if ($timestamp === false) {
            return $empty;
        }

        $diff    = $timestamp - Time::now()->getTimestamp();
        $seconds = abs($diff);
        $text    = match (true) {
            $seconds < 60    => 'menos de 1 min',
            $seconds < 3600  => intdiv($seconds, 60) . ' min',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h' . (intdiv($seconds % 3600, 60) > 0 ? ' ' . intdiv($seconds % 3600, 60) . 'min' : ''),
            default          => intdiv($seconds, 86400) . ' dia(s)',
        };

        return $diff >= 0 ? 'em ' . $text : 'há ' . $text;
    }
}

if (! function_exists('nav_active')) {
    /**
     * Retorna "active" quando a URL atual começa com o prefixo informado.
     */
    function nav_active(string $prefix): string
    {
        $path = trim(service('request')->getUri()->getPath(), '/');

        return ($path === $prefix || str_starts_with($path, $prefix . '/')) ? 'active' : '';
    }
}

if (! function_exists('field_error')) {
    /**
     * Mensagem de erro de validação de um campo (vinda do redirect com 'errors').
     */
    function field_error(string $field): ?string
    {
        $errors = session()->getFlashdata('errors');

        return is_array($errors) ? ($errors[$field] ?? null) : null;
    }
}

if (! function_exists('invalid_class')) {
    function invalid_class(string $field): string
    {
        return field_error($field) !== null ? ' is-invalid' : '';
    }
}

if (! function_exists('query_with')) {
    /**
     * Query string atual com as alterações informadas (valores vazios são removidos).
     */
    function query_with(array $changes): string
    {
        $query = array_merge(service('request')->getGet() ?? [], $changes);
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
