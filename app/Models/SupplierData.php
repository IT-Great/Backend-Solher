<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class SupplierData extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $table = 'supplier_data';

    public function invoices()
    {
        return $this->hasMany(InvoiceSupplier::class, 'supplier_id', 'id');
    }
}
