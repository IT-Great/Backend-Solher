<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class S3UploadController extends Controller
{
    public function presign(Request $request)
    {
        $request->validate([
            'filename' => 'required',
            'folder' => 'required'
        ]);

        $disk = Storage::disk('s3');

        $path = $request->folder . '/' . uniqid() . '_' . $request->filename;

        $client = $disk->getClient();

        $command = $client->getCommand('PutObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $path,
            'ACL' => 'public-read',
        ]);

        $url = $client->createPresignedRequest(
            $command,
            '+10 minutes'
        );

        return response()->json([
            'upload_url' => (string) $url->getUri(),
            'file_url' => $disk->url($path)
        ]);
    }
}
