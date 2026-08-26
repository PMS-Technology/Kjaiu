<?php

namespace App\Http\Controllers\Web\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $userId = $request->user()->id;
        $unpaid = Invoice::query()->where('user_id', $userId)->where('status', 'Unpaid');
        $services = Service::query()->where('user_id', $userId);

        return view('portal.dashboard', [
            'metrics' => [
                'balance' => $request->user()->credit,
                'unpaidCount' => (clone $unpaid)->count(),
                'unpaidTotal' => (clone $unpaid)->sum('total'),
                'activeServices' => (clone $services)->where('status', 'Active')->count(),
                'pendingServices' => (clone $services)->where('status', 'Pending')->count(),
            ],
            'recentInvoices' => Invoice::query()
                ->where('user_id', $userId)
                ->select(['id', 'user_id', 'number', 'status', 'total', 'currency', 'due_at', 'created_at'])
                ->latest('id')
                ->limit(5)
                ->get(),
            'recentServices' => Service::query()
                ->where('user_id', $userId)
                ->select([
                    'id', 'user_id', 'product_id', 'name', 'type', 'status', 'billing_cycle',
                    'renew_amount', 'next_due_at', 'auto_renew', 'created_at',
                ])
                ->with('product:id,name')
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }
}
