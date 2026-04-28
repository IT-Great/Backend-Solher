<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
// Pastikan Intervention Image sudah di-install via Composer
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class EventController extends Controller
{
    public function indexPublic()
    {
        $events = Event::where('status', 'published')->orderBy('event_date', 'desc')->get();
        return response()->json($events);
    }

    public function index()
    {
        $events = Event::orderBy('event_date', 'desc')->get();
        return response()->json($events);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'      => 'required|string|max:255',
            'description'=> 'nullable|string',
            'event_date' => 'required|date',
            'season'     => 'nullable|string|max:255',
            'status'     => 'required|in:published,draft',
            'images'     => 'required|array', // Harus array
            'images.*'   => 'image|mimes:jpeg,png,jpg,webp|max:8192', // Maks 8MB per gambar
        ]);

        $data = $request->except('images');
        $imagePaths = [];

        // Logika Auto-Kompresi Multi-Image
        if ($request->hasFile('images')) {
            $manager = new ImageManager(new Driver());

            foreach ($request->file('images') as $file) {
                $filename = uniqid() . '_' . time() . '.webp'; // Paksa jadi WebP agar ringan

                // Baca gambar dan kompres
                $img = $manager->read($file);
                $img->scaleDown(width: 1080); // Kecilkan jika lebarnya > 1080px
                $encoded = $img->toWebp(75);  // Kualitas 75%

                Storage::disk('public')->put('events/' . $filename, (string) $encoded);
                $imagePaths[] = 'events/' . $filename;
            }
        }

        $data['images'] = $imagePaths; // Akan otomatis jadi JSON karena model casting
        $event = Event::create($data);

        return response()->json(['message' => 'Event created successfully', 'data' => $event], 201);
    }

    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $request->validate([
            'title'      => 'required|string|max:255',
            'description'=> 'nullable|string',
            'event_date' => 'required|date',
            'season'     => 'nullable|string|max:255',
            'status'     => 'required|in:published,draft',
            'images'     => 'nullable|array',
            'images.*'   => 'image|mimes:jpeg,png,jpg,webp|max:8192',
        ]);

        $data = $request->except('images');

        // Jika mengupload gambar baru, kita ganti yang lama
        if ($request->hasFile('images')) {
            // Hapus gambar lama dari Harddisk
            if ($event->images && is_array($event->images)) {
                foreach ($event->images as $oldPath) {
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
            }

            // Kompres & Simpan yang baru
            $imagePaths = [];
            $manager = new ImageManager(new Driver());

            foreach ($request->file('images') as $file) {
                $filename = uniqid() . '_' . time() . '.webp';
                $img = $manager->read($file);
                $img->scaleDown(width: 1080);
                $encoded = $img->toWebp(75);

                Storage::disk('public')->put('events/' . $filename, (string) $encoded);
                $imagePaths[] = 'events/' . $filename;
            }
            $data['images'] = $imagePaths;
        }

        $event->update($data);
        return response()->json(['message' => 'Event updated successfully', 'data' => $event]);
    }

    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        // Hapus fisik gambar-gambar
        if ($event->images && is_array($event->images)) {
            foreach ($event->images as $path) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
        }

        $event->delete();
        return response()->json(['message' => 'Event deleted successfully']);
    }
}
