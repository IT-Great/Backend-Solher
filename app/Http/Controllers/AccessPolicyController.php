<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessPolicyController extends Controller
{
    // Mengambil data matriks akses saat ini
    public function getPolicies()
    {
        $permissionsData = DB::table('role_permissions')->get();

        // Memformat data agar sesuai dengan yang diharapkan oleh Vue (object grouping)
        // Hasilnya: { "admin": ["dashboard", "products"], "cs": ["messages"] }
        $formattedPermissions = $permissionsData->groupBy('role')->map(function ($items) {
            return $items->pluck('module');
        });

        return response()->json([
            'status' => 'success',
            'permissions' => $formattedPermissions
        ], 200);
    }

    // Menyimpan perubahan matriks akses dari Superadmin
    public function savePolicies(Request $request)
    {
        $request->validate([
            'permissions' => 'required|array'
        ]);

        $permissions = $request->permissions;
        $insertData = [];

        // Parsing data dari request menjadi array untuk bulk insert
        foreach ($permissions as $role => $modules) {
            foreach ($modules as $module) {
                $insertData[] = [
                    'role' => $role,
                    'module' => $module,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        DB::beginTransaction();
        try {
            // Hapus semua data lama
            DB::table('role_permissions')->truncate();

            // Masukkan data matriks yang baru
            if (!empty($insertData)) {
                DB::table('role_permissions')->insert($insertData);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Kebijakan hak akses berhasil diperbarui.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memperbarui kebijakan akses: ' . $e->getMessage()
            ], 500);
        }
    }
}
