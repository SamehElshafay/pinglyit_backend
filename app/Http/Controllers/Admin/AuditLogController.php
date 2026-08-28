<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('admin:id,name', 'company:id,name')
            ->when($request->integer('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->latest()
            ->paginate(30);

        return response()->json($logs->through(fn (AuditLog $log) => [
            'id' => $log->id,
            'time' => $log->created_at,
            'admin' => $log->admin?->name ?? 'Unknown',
            'change' => $log->description,
            'client' => $log->company?->name,
        ]));
    }
}
