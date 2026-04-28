<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EventController extends Controller
{
    // =====================================================================
    // SISI USER / PUBLIK
    // =====================================================================

    /**
     * Menampilkan semua event yang berstatus 'published' untuk halaman User
     */
    public function indexPublic()
    {
        // Hanya tarik yang 'published', urutkan dari tanggal event paling baru
        $events = Event::where('status', 'published')
                       ->orderBy('event_date', 'desc')
                       ->get();

        return response()->json($events);
    }

    // =====================================================================
    // SISI ADMIN (CMS)
    // =====================================================================

    /**
     * Menampilkan SEMUA event (termasuk draft) di tabel Admin
     */
    public function index()
    {
        $events = Event::orderBy('event_date', 'desc')->get();
        return response()->json($events);
    }

    /**
     * Menyimpan event baru ke database
     */
    public function store(Request $request)
    {
        $request->validate([
            'title'      => 'required|string|max:255',
            'description'=> 'nullable|string',
            'event_date' => 'required|date',
            'season'     => 'nullable|string|max:255',
            'status'     => 'required|in:published,draft',
            'image'      => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // Maks 5MB
        ]);

        $data = $request->except('image');

        // Logika Upload Gambar
        if ($request->hasFile('image')) {
            // Simpan gambar ke storage/app/public/events
            $data['image'] = $request->file('image')->store('events', 'public');
        }

        $event = Event::create($data);

        return response()->json([
            'message' => 'Event created successfully',
            'data' => $event
        ], 201);
    }

    /**
     * Memperbarui data event yang sudah ada
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $request->validate([
            'title'      => 'required|string|max:255',
            'description'=> 'nullable|string',
            'event_date' => 'required|date',
            'season'     => 'nullable|string|max:255',
            'status'     => 'required|in:published,draft',
            // Gambar menjadi opsional saat update (kalau tidak upload, pakai yang lama)
            'image'      => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $data = $request->except('image');

        // Jika Admin mengupload gambar baru
        if ($request->hasFile('image')) {
            // 1. Hapus gambar lama dari server agar tidak memenuhi harddisk
            if ($event->image && Storage::disk('public')->exists($event->image)) {
                Storage::disk('public')->delete($event->image);
            }

            // 2. Simpan gambar baru
            $data['image'] = $request->file('image')->store('events', 'public');
        }

        $event->update($data);

        return response()->json([
            'message' => 'Event updated successfully',
            'data' => $event
        ]);
    }

    /**
     * Menghapus event beserta gambar fisiknya dari server
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        // Hapus file gambar fisik dari folder storage
        if ($event->image && Storage::disk('public')->exists($event->image)) {
            Storage::disk('public')->delete($event->image);
        }

        $event->delete();

        return response()->json(['message' => 'Event deleted successfully']);
    }
}
