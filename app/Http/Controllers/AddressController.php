<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        $addresses = $request->user()->addresses()->latest()->get();

        $formattedAddresses = $addresses->map(function ($address) {
            return $this->formatAddressResponse($address);
        });

        return response()->json($formattedAddresses);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'region' => 'required|string|max:100',
            'first_name_address' => 'required|string|max:255',
            'last_name_address' => 'required|string|max:255',
            'address_location' => 'required|string',
            'city' => 'required|string',
            'province' => 'required|string',
            'postal_code' => 'required|string|max:10',
            'location_type' => 'nullable|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'is_default' => 'nullable|boolean'
        ]);

        $address = DB::transaction(function () use ($validated, $request) {
            $user = $request->user();

            if (!empty($validated['is_default'])) {
                $user->addresses()->where('is_default', true)->update(['is_default' => false]);
            }

            return $user->addresses()->create($validated);
        });

        return response()->json($this->formatAddressResponse($address), 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'region' => 'required|string|max:100',
            'first_name_address' => 'required|string|max:255',
            'last_name_address' => 'required|string|max:255',
            'address_location' => 'required|string',
            'city' => 'required|string',
            'province' => 'required|string',
            'postal_code' => 'required|string|max:10',
            'location_type' => 'nullable|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'is_default' => 'nullable|boolean'
        ]);

        $address = DB::transaction(function () use ($validated, $request, $id) {
            $user = $request->user();
            $address = $user->addresses()->findOrFail($id);

            if (!empty($validated['is_default'])) {
                $user->addresses()->where('is_default', true)->update(['is_default' => false]);
            }

            $address->update($validated);
            return $address->fresh();
        });

        return response()->json($this->formatAddressResponse($address));
    }

    public function destroy(Request $request, $id)
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $address->delete();

        return response()->json(['message' => 'Address successfully deleted']);
    }

    /**
     * Memformat objek Address ke dalam struktur JSON yang seragam (Pengganti AddressResource).
     */
    private function formatAddressResponse(Address $address): array
    {
        return [
            'id' => $address->id,
            'receiver' => [
                'first_name' => $address->first_name_address,
                'last_name' => $address->last_name_address,
                'full_name' => "{$address->first_name_address} {$address->last_name_address}",
            ],
            'details' => [
                'region' => $address->region,
                'location' => $address->address_location,
                'type' => $address->location_type ?? 'other',
                'city' => $address->city,
                'province' => $address->province,
                'postal_code' => $address->postal_code,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ],
            'is_default' => (bool) $address->is_default,
            'created_at' => $address->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
