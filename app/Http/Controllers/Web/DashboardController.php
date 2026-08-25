<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $months = collect(range(5, 0))->map(function (int $offset) {
            $date = now()->startOfMonth()->subMonths($offset);

            return [
                'key' => $date->format('Y-m'),
                'label' => $date->format('m月'),
                'amount' => 0.0,
            ];
        });

        $paid = Invoice::query()
            ->where('status', 'Paid')
            ->where('paid_at', '>=', now()->startOfMonth()->subMonths(5))
            ->get(['total', 'paid_at'])
            ->groupBy(fn (Invoice $invoice) => $invoice->paid_at->format('Y-m'));

        $chart = $months->map(function (array $month) use ($paid) {
            $month['amount'] = (float) ($paid->get($month['key'])?->sum('total') ?? 0);

            return $month;
        });
        $maxRevenue = max(1, (float) $chart->max('amount'));

        return view('admin.dashboard', [
            'metrics' => [
                'clients' => User::query()->where('role', 'client')->count(),
                'activeServices' => Service::query()->where('status', 'Active')->count(),
                'outstanding' => Invoice::query()->where('status', 'Unpaid')->sum('total'),
                'monthlyRevenue' => Invoice::query()
                    ->where('status', 'Paid')
                    ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->sum('total'),
            ],
            'chart' => $chart,
            'maxRevenue' => $maxRevenue,
            'recentInvoices' => Invoice::query()->with('user')->latest()->limit(7)->get(),
            'recentTransactions' => Transaction::query()->with('user')->latest('paid_at')->limit(6)->get(),
            'recentAudits' => AuditLog::query()->with('actor')->latest()->limit(6)->get(),
        ]);
    }
}
