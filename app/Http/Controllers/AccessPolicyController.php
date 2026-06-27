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

    // public function savePolicies(Request $request)
    // {
    //     $request->validate([
    //         // [PERBAIKAN 2]: Ganti 'required' menjadi 'present'.
    //         // Ini mengizinkan Superadmin menyimpan array kosong (mencabut semua akses)
    //         'permissions' => 'present|array'
    //     ]);

    //     // Beri fallback array kosong
    //     $permissions = $request->permissions ?? [];
    //     $insertData = [];

    //     foreach ($permissions as $role => $modules) {
    //         foreach ($modules as $module => $actions) {
    //             foreach ($actions as $action) {
    //                 $insertData[] = [
    //                     'role' => $role,
    //                     'module' => $module,
    //                     'action' => $action,
    //                     'created_at' => now(),
    //                     'updated_at' => now()
    //                 ];
    //             }
    //         }
    //     }

    //     DB::beginTransaction();
    //     try {
    //         DB::table('role_permissions')->truncate();

    //         if (!empty($insertData)) {
    //             DB::table('role_permissions')->insert($insertData);
    //         }

    //         DB::commit();

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Kebijakan hak akses CRUD berhasil diperbarui.'
    //         ], 200);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Gagal memperbarui kebijakan akses: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    // public function savePolicies(Request $request)
    // {
    //     $request->validate([
    //         'permissions' => 'present|array',
    //     ]);

    //     $permissions = $request->permissions ?? [];
    //     $insertData = [];

    //     foreach ($permissions as $role => $modules) {
    //         foreach ($modules as $module => $actions) {
    //             foreach ($actions as $action) {
    //                 $insertData[] = [
    //                     'role' => $role,
    //                     'module' => $module,
    //                     'action' => $action,
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ];
    //             }
    //         }
    //     }

    //     try {
    //         // Pindahkan beginTransaction ke dalam try
    //         DB::beginTransaction();

    //         // [CEK PENTING]: Pastikan tabel ada.
    //         // Jika tabel belum dibuat, ini akan memberi error yang lebih jelas di log
    //         DB::table('role_permissions')->truncate();

    //         if (! empty($insertData)) {
    //             DB::table('role_permissions')->insert($insertData);
    //         }

    //         DB::commit();

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Kebijakan hak akses CRUD berhasil diperbarui.',
    //         ], 200);

    //     } catch (\Exception $e) {
    //         // Log error asli agar kita tahu kenapa sebenarnya gagal
    //         Log::error('Gagal simpan policy: '.$e->getMessage());

    //         // [PERBAIKAN]: Cek apakah ada transaksi aktif sebelum melakukan rollback
    //         if (DB::transactionLevel() > 0) {
    //             DB::rollBack();
    //         }

    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Gagal memperbarui kebijakan akses: '.$e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function savePolicies(Request $request)
    // {
    //     $request->validate([
    //         'permissions' => 'present|array',
    //     ]);

    //     $permissions = $request->permissions ?? [];
    //     $insertData = [];

    //     foreach ($permissions as $role => $modules) {
    //         foreach ($modules as $module => $actions) {
    //             foreach ($actions as $action) {
    //                 $insertData[] = [
    //                     'role' => $role,
    //                     'module' => $module,
    //                     'action' => $action,
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ];
    //             }
    //         }
    //     }

    //     try {
    //         DB::beginTransaction();

    //         // 1. Matikan pengecekan Foreign Key sementara
    //         // Ini sering jadi penyebab utama 'truncate' gagal secara diam-diam
    //         DB::statement('SET FOREIGN_KEY_CHECKS=0;');

    //         DB::table('role_permissions')->truncate();

    //         if (! empty($insertData)) {
    //             DB::table('role_permissions')->insert($insertData);
    //         }

    //         // 2. Hidupkan kembali pengecekan Foreign Key
    //         DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    //         DB::commit();

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Kebijakan hak akses CRUD berhasil diperbarui.',
    //         ], 200);

    //     } catch (\Exception $e) {
    //         // [PENTING] Rollback hanya jika transaksi benar-benar aktif
    //         if (DB::transactionLevel() > 0) {
    //             DB::rollBack();
    //         }

    //         // Logging error yang SEBENARNYA (bukan pesan rollback)
    //         Log::error('Error Simpan Policy: '.$e->getMessage());

    //         return response()->json([
    //             'status' => 'error',
    //             // Kita kembalikan pesan asli dari exception agar kita tahu letak errornya
    //             'message' => 'Error: '.$e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function savePolicies(Request $request)
    // {
    //     $request->validate(['permissions' => 'present|array']);

    //     $permissions = $request->permissions ?? [];
    //     $insertData = [];

    //     foreach ($permissions as $role => $modules) {
    //         foreach ($modules as $module => $actions) {
    //             foreach ($actions as $action) {
    //                 $insertData[] = [
    //                     'role' => $role,
    //                     'module' => $module,
    //                     'action' => $action,
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ];
    //             }
    //         }
    //     }

    //     try {
    //         // [DEBUG] Log data yang akan dimasukkan
    //         Log::info('Data untuk diinsert:', $insertData);

    //         DB::beginTransaction();

    //         // Matikan FK Checks agar Truncate tidak terhalang
    //         DB::statement('SET FOREIGN_KEY_CHECKS=0;');

    //         DB::table('role_permissions')->truncate();

    //         if (! empty($insertData)) {
    //             DB::table('role_permissions')->insert($insertData);
    //         }

    //         DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    //         DB::commit();

    //         return response()->json(['status' => 'success', 'message' => 'Berhasil disimpan.'], 200);

    //     } catch (\Exception $e) {
    //         // [PERBAIKAN] Rollback hanya jika transaksi benar-benar aktif
    //         if (DB::transactionLevel() > 0) {
    //             DB::rollBack();
    //         }

    //         // [PENTING] Log error yang SEBENARNYA ke file laravel.log
    //         Log::error('DEBUG ERROR SIMPAN: '.$e->getMessage());

    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Error: '.$e->getMessage(), // Ini akan memberitahu Anda error asli di browser
    //         ], 500);
    //     }
    // }

    // public function savePolicies(Request $request)
    // {
    //     $request->validate(['permissions' => 'present|array']);

    //     $permissions = $request->permissions ?? [];
    //     $insertData = [];

    //     foreach ($permissions as $role => $modules) {
    //         foreach ($modules as $module => $actions) {
    //             foreach ($actions as $action) {
    //                 $insertData[] = [
    //                     'role' => $role,
    //                     'module' => $module,
    //                     'action' => $action,
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ];
    //             }
    //         }
    //     }

    //     // --- TANPA TRANSAKSI UNTUK DEBUGGING ---
    //     // Matikan FK Checks
    //     DB::statement('SET FOREIGN_KEY_CHECKS=0;');

    //     // Langsung jalankan query
    //     DB::table('role_permissions')->truncate();

    //     if (! empty($insertData)) {
    //         DB::table('role_permissions')->insert($insertData);
    //     }

    //     DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Berhasil disimpan tanpa transaksi.',
    //     ], 200);
    // }

    // public function savePolicies(Request $request)
    // {
    //     $request->validate(['permissions' => 'present|array']);

    //     $permissions = $request->permissions ?? [];
    //     $insertData = [];

    //     foreach ($permissions as $role => $modules) {
    //         foreach ($modules as $module => $actions) {
    //             foreach ($actions as $action) {
    //                 $insertData[] = [
    //                     'role' => $role,
    //                     'module' => $module,
    //                     'action' => $action,
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ];
    //             }
    //         }
    //     }

    //     try {
    //         // DB::transaction akan menangani begin, commit, dan rollback secara otomatis
    //         DB::transaction(function () use ($insertData) {
    //             DB::statement('SET FOREIGN_KEY_CHECKS=0;');

    //             DB::table('role_permissions')->truncate();

    //             if (! empty($insertData)) {
    //                 DB::table('role_permissions')->insert($insertData);
    //             }

    //             DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    //         });

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Berhasil disimpan dengan aman.',
    //         ], 200);

    //     } catch (\Exception $e) {
    //         Log::error('Error saat menyimpan policy: '.$e->getMessage());

    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Gagal memperbarui: '.$e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function savePolicies(Request $request)
    // {
    //     $request->validate(['permissions' => 'present|array']);

    //     $permissions = $request->permissions ?? [];
    //     $insertData = [];

    //     foreach ($permissions as $role => $modules) {
    //         foreach ($modules as $module => $actions) {
    //             foreach ($actions as $action) {
    //                 $insertData[] = [
    //                     'role' => $role,
    //                     'module' => $module,
    //                     'action' => $action,
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ];
    //             }
    //         }
    //     }

    //     try {
    //         // 1. Matikan pengecekan di luar transaksi agar aman
    //         DB::statement('SET FOREIGN_KEY_CHECKS=0;');

    //         // 2. Gunakan DB::transaction untuk pembungkusan yang benar
    //         DB::transaction(function () use ($insertData) {
    //             DB::table('role_permissions')->truncate();

    //             if (!empty($insertData)) {
    //                 DB::table('role_permissions')->insert($insertData);
    //             }
    //         });

    //         // 3. Hidupkan kembali
    //         DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Kebijakan hak akses berhasil disimpan.',
    //         ], 200);
    //     } catch (\Exception $e) {
    //         // Hidupkan kembali FK checks jika error (agar database tetap konsisten)
    //         DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    //         Log::error('Error saat menyimpan policy: ' . $e->getMessage());

    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Gagal memperbarui: ' . $e->getMessage(),
    //         ], 500);
    //     }
    // }

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
