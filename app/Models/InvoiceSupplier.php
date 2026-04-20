<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class InvoiceSupplier extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $table = 'invoice_suppliers';

    public function supplier()
    {
        return $this->belongsTo(SupplierData::class, 'supplier_id');
    }

    public function payments()
    {
        return $this->hasMany(PaymentHistory::class, 'invoice_id');
    }

    public function kreditCoa()
    {
        return $this->belongsTo(Coa::class, 'kredit_coa_id');
    }

    public function debitCoa()
    {
        return $this->belongsTo(Coa::class, 'debit_coa_id');
    }

    // Relasi untuk old_transfer_account_id

    public function oldAccount()
    {
        return $this->belongsTo(Coa::class, 'old_kredit_coa_id');
    }

    // Relasi untuk new_transfer_account_id

    public function newAccount()
    {
        return $this->belongsTo(Coa::class, 'new_kredit_coa_id');
    }
}
