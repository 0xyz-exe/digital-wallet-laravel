<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false): User
    {
        $user = User::factory()->create();
        if ($admin) { $user->is_admin = true; $user->save(); }
        return $user;
    }

    public function test_registration_creates_an_empty_wallet_and_limits(): void
    {
        $user = $this->user();
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_minor' => 0]);
        $this->assertDatabaseHas('wallet_limits', ['wallet_id' => $user->wallet->id]);
    }

    public function test_deposit_requires_review_and_approval_updates_balance_with_audit(): void
    {
        $user = $this->user(); $admin = $this->user(true);
        $this->actingAs($user)->post(route('wallet.deposit'), ['amount' => '20.50', 'idempotency_key' => 'deposit-key-0001'])->assertRedirect();
        $tx = WalletTransaction::firstOrFail();
        $this->assertSame('pending', $tx->status);
        $this->assertSame(0, $user->wallet->fresh()->balance_minor);
        $this->actingAs($admin)->post(route('wallet.review', $tx), ['decision' => 'approve', 'note' => 'Simulation approval'])->assertRedirect();
        $this->assertSame(2050, $user->wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('wallet_transaction_events', ['wallet_transaction_id' => $tx->id, 'to_status' => 'completed', 'actor_user_id' => $admin->id]);
    }

    public function test_transfer_is_atomic_idempotent_and_visible_to_both_wallets(): void
    {
        $sender = $this->user(); $recipient = $this->user();
        $sender->wallet->update(['balance_minor' => 10000]);
        $payload = ['recipient_email' => $recipient->email, 'amount' => '35.25', 'idempotency_key' => 'transfer-key-0001'];
        $this->actingAs($sender)->post(route('wallet.transfer'), $payload)->assertRedirect();
        $this->actingAs($sender)->post(route('wallet.transfer'), $payload)->assertRedirect();
        $this->assertSame(6475, $sender->wallet->fresh()->balance_minor);
        $this->assertSame(3525, $recipient->wallet->fresh()->balance_minor);
        $this->assertSame(1, WalletTransaction::where('type', 'transfer')->count());
        $this->assertSame(1, WalletTransaction::where('type', 'transfer')->whereNotNull('source_wallet_id')->whereNotNull('destination_wallet_id')->count());
    }

    public function test_insufficient_or_invalid_transfer_does_not_change_either_balance(): void
    {
        $sender = $this->user(); $recipient = $this->user();
        $sender->wallet->update(['balance_minor' => 500]);
        $payload = ['recipient_email' => $recipient->email, 'amount' => '10.00', 'idempotency_key' => 'transfer-key-0002'];
        $this->actingAs($sender)->post(route('wallet.transfer'), $payload)->assertSessionHasErrors('amount');
        $this->assertSame(500, $sender->wallet->fresh()->balance_minor);
        $this->assertSame(0, $recipient->wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $payload['amount'] = '0.001';
        $this->actingAs($sender)->post(route('wallet.transfer'), $payload)->assertSessionHasErrors('amount');
    }

    public function test_withdrawal_only_debits_when_approved_and_limits_apply(): void
    {
        $user = $this->user(); $admin = $this->user(true);
        $user->wallet->update(['balance_minor' => 5000]);
        $user->wallet->limits->update(['per_transaction_minor' => 4000]);
        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => '45.00', 'idempotency_key' => 'withdraw-key-0001'])->assertSessionHasErrors('amount');
        $this->assertSame(5000, $user->wallet->fresh()->balance_minor);
        $this->actingAs($user)->post(route('wallet.withdraw'), ['amount' => '30.00', 'idempotency_key' => 'withdraw-key-0002'])->assertRedirect();
        $tx = WalletTransaction::firstOrFail();
        $this->assertSame(5000, $user->wallet->fresh()->balance_minor);
        $this->actingAs($admin)->post(route('wallet.review', $tx), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame(2000, $user->wallet->fresh()->balance_minor);
    }

    public function test_pending_requests_reserve_daily_limit_until_decided(): void
    {
        $user = $this->user();
        $user->wallet->limits->update(['daily_minor' => 5000]);
        $this->actingAs($user)->post(route('wallet.deposit'), ['amount' => '30.00', 'idempotency_key' => 'daily-limit-key-01'])->assertRedirect();
        $this->actingAs($user)->post(route('wallet.deposit'), ['amount' => '30.00', 'idempotency_key' => 'daily-limit-key-02'])->assertSessionHasErrors('amount');
        $this->assertSame(1, WalletTransaction::count());
    }

    public function test_non_admin_cannot_review_or_download_reports(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('wallet.admin'))->assertForbidden();
        $this->actingAs($user)->get(route('wallet.report'))->assertForbidden();
        $this->actingAs($user)->get(route('wallet.balances'))->assertForbidden();
    }
}
