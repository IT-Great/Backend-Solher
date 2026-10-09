<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ConsultationIntake;
use Illuminate\Support\Facades\Log;

class ConsultationController extends Controller
{
    /**
     * Menyimpan data form persiapan konsultasi (Intake) dari User VIP
     */
    public function storeIntake(Request $request)
    {
        $request->validate([
            'intent' => 'required|string',
            'styles' => 'nullable|array',
            'complaintType' => 'nullable|string',
            'bagTypes' => 'nullable|array',
            'colors' => 'nullable|array',
            'specificNeeds' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        // Keamanan: Hanya member Héritage (Point >= 10.000) yang boleh
        // Ini adalah validasi ganda dari sisi Server.
        if (!$user->is_membership || $user->point < 10000) {
            return response()->json(['message' => 'Layanan ini eksklusif untuk member tingkat Héritage.'], 403);
        }

        try {
            // Tandai intake lama milik user ini sebagai 'resolved' agar admin fokus ke yang terbaru
            ConsultationIntake::where('user_id', $user->id)
                ->where('status', 'pending')
                ->update(['status' => 'resolved']);

            // Simpan Intake Baru
            $intake = ConsultationIntake::create([
                'user_id' => $user->id,
                'intent' => $request->intent,
                'complaint_type' => $request->complaintType,
                'preferred_styles' => $request->styles ?? [],
                'preferred_bag_types' => $request->bagTypes ?? [],
                'preferred_colors' => $request->colors ?? [],
                'specific_needs' => $request->specificNeeds,
                'status' => 'pending',
            ]);

            return response()->json([
                'message' => 'Data konsultasi berhasil disimpan',
                'intake_id' => $intake->id
            ], 201);

        } catch (\Exception $e) {
            Log::error('Consultation Intake Error: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal memproses data.'], 500);
        }
    }

    /**
     * (Untuk Admin / Chat Engine) Mengambil ringkasan profil klien
     */
    public function getActiveIntake(Request $request, $userId)
    {
        // Fitur ini nantinya dipanggil di panel admin atau saat membuka ruang chat
        $intake = ConsultationIntake::where('user_id', $userId)
                    ->where('status', 'pending')
                    ->latest()
                    ->first();

        return response()->json([
            'data' => $intake
        ]);
    }

    /**
     * [ADMIN] Mendapatkan list semua konsultasi intake
     */
    public function indexAdmin(Request $request)
    {
        $intakes = ConsultationIntake::with('user')->orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $intakes]);
    }

    /**
     * [ADMIN] Menandai masalah selesai
     */
    public function resolveAdmin($id)
    {
        $intake = ConsultationIntake::findOrFail($id);
        $intake->update(['status' => 'resolved']);
        return response()->json(['message' => 'Tandai Selesai']);
    }
}
