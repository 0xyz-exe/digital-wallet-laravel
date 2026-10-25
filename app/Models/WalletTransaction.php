<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = ['reference', 'idempotency_user_id', 'idempotency_key', 'source_wallet_id', 'destination_wallet_id', 'type', 'amount_minor', 'status', 'description', 'reviewed_by', 'reviewed_at'];
    protected function casts(): array { return ['amount_minor' => 'integer', 'reviewed_at' => 'datetime']; }
    public function sourceWallet() { return $this->belongsTo(Wallet::class, 'source_wallet_id'); }
    public function destinationWallet() { return $this->belongsTo(Wallet::class, 'destination_wallet_id'); }
    public function events() { return $this->hasMany(WalletTransactionEvent::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
