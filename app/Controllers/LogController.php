<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\SyncLogModel;

class LogController extends BaseController
{
    public function index(): string
    {
        $tab = $this->request->getGet('tab') === 'sync' ? 'sync' : 'audit';

        if ($tab === 'sync') {
            $model   = new SyncLogModel();
            $filters = $this->filters(['status', 'integration_type']);

            if (isset($filters['status'])) {
                $model->where('status', $filters['status']);
            }

            if (isset($filters['integration_type'])) {
                $model->where('integration_type', $filters['integration_type']);
            }
            $rows = $model->orderBy('started_at', 'DESC')->paginate($this->perPage());
        } else {
            $model   = new AuditLogModel();
            $filters = $this->filters(['entity_type', 'action']);
            $model->withUser();

            if (isset($filters['entity_type'])) {
                $model->where('audit_logs.entity_type', $filters['entity_type']);
            }

            if (isset($filters['action'])) {
                $model->where('audit_logs.action', $filters['action']);
            }
            $rows = $model->orderBy('audit_logs.id', 'DESC')->paginate($this->perPage());
        }

        return view('logs/index', [
            'title'               => 'Logs',
            'tab'                 => $tab,
            'rows'                => $rows,
            'filters'             => $filters,
            'pager'               => $model->pager,
            'marketplaceAdapters' => service('marketplaces')->all(),
            'breadcrumbs'         => ['Sistema' => null, 'Logs' => null],
        ]);
    }
}
