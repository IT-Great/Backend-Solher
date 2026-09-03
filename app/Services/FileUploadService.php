<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileUploadService
{
    /**
     * Mengunggah file ke AWS S3 dan mengembalikan URL publiknya.
     */
    public function uploadToS3($file, string $directory): string
    {
        try {
            $path = $file->store($directory, [
                'disk' => 's3',
                'visibility' => 'public',
            ]);

            return Storage::disk('s3')->url($path);
        } catch (\Exception $e) {
            Log::error('S3 Upload Failed: ' . $e->getMessage());
            throw new \Exception('Gagal mengunggah file ke server penyimpanan.');
        }
    }
}
