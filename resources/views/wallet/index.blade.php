<x-app-layout>
<x-slot name="header"><h2 class="font-semibold text-xl">Digital Wallet Simulation</h2></x-slot>
<div class="py-8 max-w-6xl mx-auto space-y-6 px-4">
@if(session('status'))<div class="p-3 bg-green-100 text-green-900 rounded">{{ session('status') }}</div>@endif
@if($errors->any())<div class="p-3 bg-red-100 text-red-900 rounded">{{ $errors->first() }}</div>@endif
<section class="p-6 bg-white shadow rounded"><h3 class="text-lg font-bold">Available simulated balance</h3><p class="text-3xl">{{ number_format(intdiv($wallet->balance_minor, 100)).'.'.str_pad((string) ($wallet->balance_minor % 100), 2, '0', STR_PAD_LEFT) }} credits</p><p class="text-sm text-gray-600">Simulation only. No real money, bank, card, or payment processor.</p></section>
<div class="grid md:grid-cols-3 gap-4">
@foreach(['deposit'=>'Request deposit','withdraw'=>'Request withdrawal','transfer'=>'Transfer credits'] as $action=>$label)
<form method="POST" action="{{ route('wallet.'.$action) }}" class="p-5 bg-white shadow rounded space-y-3">@csrf<h3 class="font-bold">{{ $label }}</h3>
@if($action==='transfer')<label>Recipient email<input required type="email" name="recipient_email" class="block w-full rounded border-gray-300"></label>@endif
<label>Amount<input required name="amount" inputmode="decimal" placeholder="25.00" class="block w-full rounded border-gray-300"></label>
<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}"><button class="px-4 py-2 rounded bg-indigo-700 text-white">{{ $label }}</button></form>
@endforeach
</div>
<section class="p-5 bg-white shadow rounded"><h3 class="font-bold text-lg">Transactions</h3><div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Reference</th><th>Type</th><th>From</th><th>To</th><th>Amount</th><th>Status</th><th>Created</th></tr></thead><tbody>
@forelse($transactions as $tx)<tr class="border-t"><td>{{ $tx->reference }}</td><td>{{ ucfirst($tx->type) }}</td><td>{{ $tx->sourceWallet?->user?->name ?? '—' }}</td><td>{{ $tx->destinationWallet?->user?->name ?? '—' }}</td><td>{{ number_format(intdiv($tx->amount_minor, 100)).'.'.str_pad((string) ($tx->amount_minor % 100), 2, '0', STR_PAD_LEFT) }}</td><td>{{ ucfirst($tx->status) }}</td><td>{{ $tx->created_at->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="7" class="py-3">No transactions yet.</td></tr>@endforelse
</tbody></table></div>{{ $transactions->links() }}</section>
</div></x-app-layout>
