<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletLimit extends Model
{
    protected $fillable = ['wallet_id', 'per_transaction_minor', 'daily_minor'];
    protected function casts(): array { return ['per_transaction_minor' => 'integer', 'daily_minor' => 'integer']; }
    public function wallet() { return $this->belongsTo(Wallet::class); }
}
