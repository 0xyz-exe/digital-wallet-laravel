<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance_minor'];
    protected function casts(): array { return ['balance_minor' => 'integer']; }
    public function user() { return $this->belongsTo(User::class); }
    public function limits() { return $this->hasOne(WalletLimit::class); }
    public function incomingTransactions() { return $this->hasMany(WalletTransaction::class, 'destination_wallet_id'); }
    public function outgoingTransactions() { return $this->hasMany(WalletTransaction::class, 'source_wallet_id'); }
}
