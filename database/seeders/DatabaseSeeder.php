<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLimit;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->each(function (User $user): void {
            $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
            WalletLimit::firstOrCreate(['wallet_id' => $wallet->id]);
        });
    }
}
