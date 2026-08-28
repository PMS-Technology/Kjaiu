<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $keyword = trim((string) $request->query('q'));
        $logs = AuditLog::query()->with('actor')
            ->when($keyword !== '', fn ($query) => $query->where(function ($search) use ($keyword): void {
                $search->where('action', 'like', "%{$keyword}%")
                    ->orWhere('subject_id', $keyword)
                    ->orWhere('ip_address', 'like', "%{$keyword}%")
                    ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', "%{$keyword}%")->orWhere('email', 'like', "%{$keyword}%"));
            }))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.audit-logs.index', compact('logs', 'keyword'));
    }
}
