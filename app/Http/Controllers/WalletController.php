<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLimit;
use App\Models\WalletTransaction;
use App\Models\WalletTransactionEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    private function ensureWallet(User $user): Wallet
    {
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
        WalletLimit::firstOrCreate(['wallet_id' => $wallet->id]);
        return $wallet;
    }

    public function index(Request $request)
    {
        $wallet = $this->ensureWallet($request->user());
        $transactions = WalletTransaction::with(['sourceWallet.user', 'destinationWallet.user', 'events'])
            ->where(fn ($q) => $q->where('source_wallet_id', $wallet->id)->orWhere('destination_wallet_id', $wallet->id))
            ->latest()->paginate(20);
        return view('wallet.index', compact('wallet', 'transactions'));
    }

    private function amountMinor(string $amount): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount with up to two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($minor < 1 || $minor > 100000000000) throw ValidationException::withMessages(['amount' => 'Amount is outside allowed bounds.']);
        return $minor;
    }

    private function validateLimit(Wallet $wallet, int $minor): void
    {
        $limits = $wallet->limits;
        if (!$limits) $limits = WalletLimit::firstOrCreate(['wallet_id' => $wallet->id]);
        if ($minor > $limits->per_transaction_minor) throw ValidationException::withMessages(['amount' => 'Amount exceeds the per-transaction simulation limit.']);
        $today = WalletTransaction::where('idempotency_user_id', $wallet->user_id)->whereIn('status', ['pending', 'completed'])->whereDate('created_at', today())->sum('amount_minor');
        if ($today + $minor > $limits->daily_minor) throw ValidationException::withMessages(['amount' => 'Daily simulation limit exceeded.']);
    }

    private function idempotent(User $user, string $key): ?WalletTransaction
    {
        return WalletTransaction::where('idempotency_user_id', $user->id)->where('idempotency_key', $key)->first();
    }

    private function recordEvent(WalletTransaction $transaction, ?int $actor, string $event, ?string $from, string $to, ?string $note = null): void
    {
        WalletTransactionEvent::create(['wallet_transaction_id' => $transaction->id, 'actor_user_id' => $actor, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }

    public function deposit(Request $request)
    {
        $data = $request->validate(['amount' => 'required|string|max:13', 'idempotency_key' => 'required|string|min:8|max:100']);
        $minor = $this->amountMinor($data['amount']); $user = $request->user();
        $result = DB::transaction(function () use ($user, $data, $minor) {
            if ($existing = $this->idempotent($user, $data['idempotency_key'])) return $existing;
            $wallet = Wallet::whereKey($this->ensureWallet($user)->id)->lockForUpdate()->firstOrFail();
            $this->validateLimit($wallet, $minor);
            $tx = WalletTransaction::create(['reference' => Str::upper(Str::random(16)), 'idempotency_user_id' => $user->id, 'idempotency_key' => $data['idempotency_key'], 'destination_wallet_id' => $wallet->id, 'type' => 'deposit', 'amount_minor' => $minor, 'status' => 'pending', 'description' => 'Simulated deposit request']);
            $this->recordEvent($tx, $user->id, 'created', null, 'pending');
            return $tx;
        });
        return back()->with('status', 'Deposit request recorded for administrator review. Reference: '.$result->reference);
    }

    public function withdraw(Request $request)
    {
        $data = $request->validate(['amount' => 'required|string|max:13', 'idempotency_key' => 'required|string|min:8|max:100']);
        $minor = $this->amountMinor($data['amount']); $user = $request->user();
        $result = DB::transaction(function () use ($user, $data, $minor) {
            if ($existing = $this->idempotent($user, $data['idempotency_key'])) return $existing;
            $wallet = Wallet::whereKey($this->ensureWallet($user)->id)->lockForUpdate()->firstOrFail();
            $this->validateLimit($wallet, $minor);
            if ($wallet->balance_minor < $minor) throw ValidationException::withMessages(['amount' => 'Insufficient simulated balance.']);
            $tx = WalletTransaction::create(['reference' => Str::upper(Str::random(16)), 'idempotency_user_id' => $user->id, 'idempotency_key' => $data['idempotency_key'], 'source_wallet_id' => $wallet->id, 'type' => 'withdrawal', 'amount_minor' => $minor, 'status' => 'pending', 'description' => 'Simulated withdrawal request']);
            $this->recordEvent($tx, $user->id, 'created', null, 'pending');
            return $tx;
        });
        return back()->with('status', 'Withdrawal request recorded for administrator review. Reference: '.$result->reference);
    }

    public function transfer(Request $request)
    {
        $data = $request->validate(['recipient_email' => 'required|email|exists:users,email', 'amount' => 'required|string|max:13', 'idempotency_key' => 'required|string|min:8|max:100']);
        $minor = $this->amountMinor($data['amount']); $user = $request->user();
        $result = DB::transaction(function () use ($user, $data, $minor) {
            if ($existing = $this->idempotent($user, $data['idempotency_key'])) return $existing;
            $target = User::where('email', $data['recipient_email'])->firstOrFail();
            if ($target->id === $user->id) throw ValidationException::withMessages(['recipient_email' => 'Choose another wallet.']);
            $sourceId = $this->ensureWallet($user)->id; $destId = $this->ensureWallet($target)->id;
            $wallets = Wallet::whereIn('id', [$sourceId, $destId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $wallets[$sourceId]; $dest = $wallets[$destId];
            $this->validateLimit($source, $minor);
            if ($source->balance_minor < $minor) throw ValidationException::withMessages(['amount' => 'Insufficient simulated balance.']);
            $source->decrement('balance_minor', $minor); $dest->increment('balance_minor', $minor);
            $tx = WalletTransaction::create(['reference' => Str::upper(Str::random(16)), 'idempotency_user_id' => $user->id, 'idempotency_key' => $data['idempotency_key'], 'source_wallet_id' => $sourceId, 'destination_wallet_id' => $destId, 'type' => 'transfer', 'amount_minor' => $minor, 'status' => 'completed', 'description' => 'Internal simulated transfer']);
            $this->recordEvent($tx, $user->id, 'completed', null, 'completed');
            return $tx;
        });
        return back()->with('status', 'Simulated transfer completed. Reference: '.$result->reference);
    }

    public function admin(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        $transactions = WalletTransaction::with(['sourceWallet.user', 'destinationWallet.user', 'reviewer', 'events.actor'])->latest()->paginate(40);
        $wallets = Wallet::with(['user', 'limits'])->orderByDesc('balance_minor')->get();
        return view('wallet.admin', compact('transactions', 'wallets'));
    }

    public function review(Request $request, WalletTransaction $transaction)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:500']);
        DB::transaction(function () use ($request, $transaction, $data) {
            $tx = WalletTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($tx->status !== 'pending') throw ValidationException::withMessages(['decision' => 'Only pending transactions can be reviewed.']);
            $walletIds = array_filter([$tx->source_wallet_id, $tx->destination_wallet_id]);
            $wallets = Wallet::whereIn('id', $walletIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $to = $data['decision'] === 'approve' ? 'completed' : 'rejected';
            if ($to === 'completed' && $tx->type === 'deposit') $wallets[$tx->destination_wallet_id]->increment('balance_minor', $tx->amount_minor);
            if ($to === 'completed' && $tx->type === 'withdrawal') {
                $wallet = $wallets[$tx->source_wallet_id];
                if ($wallet->balance_minor < $tx->amount_minor) $to = 'rejected';
                else $wallet->decrement('balance_minor', $tx->amount_minor);
            }
            $tx->update(['status' => $to, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $this->recordEvent($tx, $request->user()->id, 'reviewed', 'pending', $to, $data['note'] ?? null);
        });
        return back()->with('status', 'Transaction reviewed.');
    }

    public function updateLimit(Request $request, Wallet $wallet)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['per_transaction_minor' => 'required|integer|min:1|max:100000000000', 'daily_minor' => 'required|integer|min:1|max:100000000000']);
        WalletLimit::updateOrCreate(['wallet_id' => $wallet->id], $data);
        return back()->with('status', 'Simulation limits updated.');
    }

    public function balances(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w'); fputcsv($out, ['wallet_id', 'user_id', 'email', 'balance_minor', 'per_transaction_limit_minor', 'daily_limit_minor']);
            Wallet::with(['user', 'limits'])->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $wallet) fputcsv($out, [$wallet->id, $wallet->user_id, $wallet->user->email, $wallet->balance_minor, $wallet->limits->per_transaction_minor, $wallet->limits->daily_minor]);
            }); fclose($out);
        }, 'wallet-balances.csv', ['Content-Type' => 'text/csv']);
    }

    public function report(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w'); fputcsv($out, ['reference', 'type', 'source_user_id', 'destination_user_id', 'amount_minor', 'status', 'created_at']);
            WalletTransaction::with(['sourceWallet', 'destinationWallet'])->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $tx) fputcsv($out, [$tx->reference, $tx->type, $tx->sourceWallet?->user_id, $tx->destinationWallet?->user_id, $tx->amount_minor, $tx->status, $tx->created_at->toIso8601String()]);
            }); fclose($out);
        }, 'wallet-report.csv', ['Content-Type' => 'text/csv']);
    }
}
