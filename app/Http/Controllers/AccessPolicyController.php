<?php

// namespace App\Http\Controllers;

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;

// class AccessPolicyController extends Controller
// {
//     // Mengambil data matriks akses saat ini
//     public function getPolicies()
//     {
//         $permissionsData = DB::table('role_permissions')->get();

//         // Memformat data agar sesuai dengan yang diharapkan oleh Vue (object grouping)
//         // Hasilnya: { "admin": ["dashboard", "products"], "cs": ["messages"] }
//         $formattedPermissions = $permissionsData->groupBy('role')->map(function ($items) {
//             return $items->pluck('module');
//         });

//         return response()->json([
//             'status' => 'success',
//             'permissions' => $formattedPermissions
//         ], 200);
//     }

//     // Menyimpan perubahan matriks akses dari Superadmin
//     public function savePolicies(Request $request)
//     {
//         $request->validate([
//             'permissions' => 'required|array'
//         ]);

//         $permissions = $request->permissions;
//         $insertData = [];

//         // Parsing data dari request menjadi array untuk bulk insert
//         foreach ($permissions as $role => $modules) {
//             foreach ($modules as $module) {
//                 $insertData[] = [
//                     'role' => $role,
//                     'module' => $module,
//                     'created_at' => now(),
//                     'updated_at' => now()
//                 ];
//             }
//         }

//         DB::beginTransaction();
//         try {
//             // Hapus semua data lama
//             DB::table('role_permissions')->truncate();

//             // Masukkan data matriks yang baru
//             if (!empty($insertData)) {
//                 DB::table('role_permissions')->insert($insertData);
//             }

//             DB::commit();

//             return response()->json([
//                 'status' => 'success',
//                 'message' => 'Kebijakan hak akses berhasil diperbarui.'
//             ], 200);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             return response()->json([
//                 'status' => 'error',
//                 'message' => 'Gagal memperbarui kebijakan akses: ' . $e->getMessage()
//             ], 500);
//         }
//     }
// }

// namespace App\Http\Controllers;

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;

// class AccessPolicyController extends Controller
// {
//     public function getPolicies()
//     {
//         $permissionsData = DB::table('role_permissions')->get();

//         $formattedPermissions = [];

//         // Menyusun ulang data dari database menjadi nested JSON
//         // Contoh Output: { "admin": { "products": ["create", "read", "update"] } }
//         foreach ($permissionsData as $perm) {
//             $formattedPermissions[$perm->role][$perm->module][] = $perm->action;
//         }

//         return response()->json([
//             'status' => 'success',
//             'permissions' => $formattedPermissions
//         ], 200);
//     }

//     public function savePolicies(Request $request)
//     {
//         $request->validate([
//             'permissions' => 'required|array'
//         ]);

//         $permissions = $request->permissions;
//         $insertData = [];

//         // Memecah nested JSON dari Vue kembali menjadi baris database
//         foreach ($permissions as $role => $modules) {
//             foreach ($modules as $module => $actions) {
//                 foreach ($actions as $action) {
//                     $insertData[] = [
//                         'role' => $role,
//                         'module' => $module,
//                         'action' => $action, // [BARU] Memasukkan kolom aksi (create/read/update/delete)
//                         'created_at' => now(),
//                         'updated_at' => now()
//                     ];
//                 }
//             }
//         }

//         DB::beginTransaction();
//         try {
//             DB::table('role_permissions')->truncate();

//             if (!empty($insertData)) {
//                 // Bulk insert untuk performa optimal
//                 DB::table('role_permissions')->insert($insertData);
//             }

//             DB::commit();

//             return response()->json([
//                 'status' => 'success',
//                 'message' => 'Kebijakan hak akses CRUD berhasil diperbarui.'
//             ], 200);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             return response()->json([
//                 'status' => 'error',
//                 'message' => 'Gagal memperbarui kebijakan akses: ' . $e->getMessage()
//             ], 500);
//         }
//     }
// }

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'permissions' => (object) $formattedPermissions
        ], 200);
    }

    public function savePolicies(Request $request)
    {
        $request->validate([
            // [PERBAIKAN 2]: Ganti 'required' menjadi 'present'.
            // Ini mengizinkan Superadmin menyimpan array kosong (mencabut semua akses)
            'permissions' => 'present|array'
        ]);

        // Beri fallback array kosong
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
                        'updated_at' => now()
                    ];
                }
            }
        }

        DB::beginTransaction();
        try {
            DB::table('role_permissions')->truncate();

            if (!empty($insertData)) {
                DB::table('role_permissions')->insert($insertData);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Kebijakan hak akses CRUD berhasil diperbarui.'
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
