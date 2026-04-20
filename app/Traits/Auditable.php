<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    // Fungsi ini akan otomatis dipanggil oleh Laravel setiap kali Model dimuat
    public static function bootAuditable()
    {
        static::created(function ($model) {
            self::logAudit($model, 'created');
        });

        static::updated(function ($model) {
            self::logAudit($model, 'updated');
        });

        static::deleted(function ($model) {
            self::logAudit($model, 'deleted');
        });
    }

    protected static function logAudit($model, $action)
    {
        // Jangan catat jika yang mengubah bukan user/admin yang sedang login (misal: sistem otomatis)
        if (!Auth::check()) {
            return;
        }

        // Ambil hanya kolom yang berubah (Dirty) dan nilai lamanya (Original)
        $oldValues = $action === 'updated' ? array_intersect_key($model->getOriginal(), $model->getDirty()) : null;
        $newValues = $action !== 'deleted' ? $model->getDirty() : null;

        // Abaikan jika tidak ada perubahan data yang berarti
        if ($action === 'updated' && empty($oldValues)) {
            return;
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'model_type' => class_basename($model),
            'model_id' => $model->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }
}
