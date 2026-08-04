<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CampaignLog extends Model
{
    protected $guarded = ['id'];

    public function campaign() {
        return $this->belongsTo(Campaign::class);
    }
}