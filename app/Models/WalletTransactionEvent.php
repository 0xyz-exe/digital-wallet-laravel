<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransactionEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['wallet_transaction_id', 'actor_user_id', 'event', 'from_status', 'to_status', 'note', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    public function transaction() { return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }
}
