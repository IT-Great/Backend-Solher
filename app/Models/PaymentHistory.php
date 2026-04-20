<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentHistory extends Model
{
    use Auditable;

    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
    ];

    public function invoice()
    {
        return $this->belongsTo(InvoiceSupplier::class, 'invoice_id');
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->processed_by = auth()->id();
        });
    }
}
