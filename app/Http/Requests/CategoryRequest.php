<?php

// namespace App\Http\Requests;

// use Illuminate\Validation\Rule;
// use Illuminate\Foundation\Http\FormRequest;

// class CategoryRequest extends FormRequest
// {
//     /**
//      * Determine if the user is authorized to make this request.
//      */
//     public function authorize(): bool
//     {
//         return true;
//     }

//     /**
//      * Get the validation rules that apply to the request.
//      *
//      * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
//      */
//     public function rules(): array
//     {
//         $categoryId = $this->route('id'); // Ambil ID dari URL jika ada

//         return [
//             'code' => [
//                 'required',
//                 'string',
//                 'max:50',
//                 Rule::unique('categories', 'code')->ignore($categoryId),
//             ],
//             'name' => 'required|string|max:255',
//             'description' => 'nullable|string',
//         ];
//     }
// }

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // public function rules(): array
    // {
    //     $categoryId = $this->route('id');

    //     return [
    //         'code' => [
    //             'required', 'string', 'max:50',
    //             Rule::unique('categories', 'code')->ignore($categoryId),
    //         ],
    //         'name' => 'required|string|max:255',
    //         'description' => 'nullable|string',

    //         // [BARU] Aturan ketat untuk Bundle Promo
    //         'bundle_qty' => 'nullable|integer|min:2',
    //         'bundle_price' => 'nullable|numeric|min:0|required_with:bundle_qty',
    //         'bundle_start_date' => 'nullable|date',
    //         'bundle_end_date' => 'nullable|date|after_or_equal:bundle_start_date',
    //     ];
    // }

    public function rules(): array
    {
        $categoryId = $this->route('id');

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('categories', 'code')->ignore($categoryId)
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',

            'bundle_qty' => 'nullable|integer|min:2',
            // [PERBAIKAN] Validasi untuk JSON Array Multi-Currency
            'bundle_price' => 'nullable|array|required_with:bundle_qty',
            'bundle_start_date' => 'nullable|date',
            'bundle_end_date' => 'nullable|date|after_or_equal:bundle_start_date',
        ];
    }
}
