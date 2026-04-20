<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        // Tarik data terbaru, load relasi user, batasi 50 per halaman agar tidak berat
        $logs = AuditLog::with('user')->latest()->paginate(50);
        return response()->json($logs);
    }
}
