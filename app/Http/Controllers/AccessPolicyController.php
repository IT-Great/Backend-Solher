<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccessPolicyController extends Controller
{
    public function getPolicies()
    {
        $permissionsData = DB::table('role_permissions')->get();

        $formattedPermissions = [];

        foreach ($permissionsData as $perm) {
            $formattedPermissions[$perm->role][$perm->module][] = $perm->action;
        }

        return response()->json([
            'status' => 'success',
            // [PERBAIKAN 1]: Paksa casting (object) agar PHP selalu merender '{}' saat kosong, bukan '[]'
            'permissions' => (object) $formattedPermissions,
        ], 200);
    }

    public function savePolicies(Request $request)
    {
        $request->validate(['permissions' => 'present|array']);

        $permissions = $request->permissions ?? [];
        $insertData = [];

        foreach ($permissions as $role => $modules) {
            foreach ($modules as $module => $actions) {
                foreach ($actions as $action) {
                    $insertData[] = [
                        'role' => $role,
                        'module' => $module,
                        'action' => $action,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        try {
            DB::transaction(function () use ($insertData) {
                // Matikan pengecekan FK sementara
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');

                // [PERBAIKAN UTAMA] Gunakan DELETE daripada TRUNCATE
                // DELETE adalah perintah DML yang aman di dalam transaksi
                DB::table('role_permissions')->delete();

                if (! empty($insertData)) {
                    DB::table('role_permissions')->insert($insertData);
                }

                // Hidupkan kembali FK checks
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Kebijakan hak akses berhasil disimpan.',
            ], 200);

        } catch (\Exception $e) {
            report($e);
            // Jika error, pastikan FK checks menyala kembali
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            Log::error('Error saat menyimpan policy: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memperbarui: '.$e->getMessage(),
            ], 500);
        }
    }
}
