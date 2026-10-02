<?php

namespace App\Controllers\Api;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Pager\Pager;

/**
 * Base da API interna (JSON). A autenticação é feita pelo filtro 'auth'.
 */
abstract class BaseApiController extends Controller
{
    use ResponseTrait;

    protected const MAX_PER_PAGE = 100;

    /**
     * @var IncomingRequest
     */
    protected $request;

    protected $format = 'json';

    protected function perPage(int $default = 20): int
    {
        $perPage = (int) $this->request->getGet('per_page');

        return $perPage > 0 ? min($perPage, self::MAX_PER_PAGE) : $default;
    }

    protected function paginated(array $data, ?Pager $pager): array
    {
        return [
            'data' => $data,
            'meta' => [
                'page'      => $pager?->getCurrentPage() ?? 1,
                'per_page'  => $pager?->getPerPage() ?? count($data),
                'total'     => $pager?->getTotal() ?? count($data),
                'last_page' => $pager?->getPageCount() ?? 1,
            ],
        ];
    }

    protected function queryString(string $key): ?string
    {
        $value = $this->request->getGet($key);

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 100) : null;
    }
}
