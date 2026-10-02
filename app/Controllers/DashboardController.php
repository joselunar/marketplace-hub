<?php

namespace App\Controllers;

use App\Services\Dashboard\DashboardService;

class DashboardController extends BaseController
{
    public function index(): string
    {
        return view('dashboard/index', [
            'title'               => 'Dashboard',
            'summary'             => (new DashboardService())->summary(),
            'marketplaceAdapters' => service('marketplaces')->all(),
        ]);
    }
}
