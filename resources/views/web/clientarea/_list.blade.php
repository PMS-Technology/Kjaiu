{{-- Resource list fragment injected into #sourceListBox by /clientarea?action=list. --}}
@php
    $pager = $ClientArea;
    $query = request()->query();
    $link = fn (int $target) => '#';
@endphp

@if (empty($rows))
    <div class="px-5 py-10 text-center text-sm text-slate-400">
        暂无产品，<a href="/cart" class="text-brand-600 hover:underline">立即订购</a>
    </div>
@else
    <table class="data-table">
        <thead>
        <tr>
            <th>机器状态</th>
            <th>主机名</th>
            <th>
                <button type="button" data-orderby="nextduedate" data-sort="ASC" class="inline-flex items-center gap-1 hover:text-slate-700">
                    到期时间
                </button>
            </th>
            <th>费用</th>
            <th>IP</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>
                    <span class="badge-{{ $row['domainstatus_color'] }}">{{ $row['domainstatus_desc'] }}</span>
                </td>
                <td>
                    <a href="/servicedetail?id={{ $row['id'] }}" class="font-medium text-brand-600 hover:underline">
                        {{ $row['productname'] }}
                    </a>
                    @if (! empty($row['domain']))
                        <div class="text-xs text-slate-400">{{ $row['domain'] }}</div>
                    @endif
                </td>
                <td>
                    @if (in_array($row['billingcycle'], ['free', 'onetime'], true) || $row['cycle_desc'] === '一次性')
                        <span class="text-slate-400">—</span>
                    @else
                        {{ $row['nextduedate'] ? date('Y-m-d H:i', $row['nextduedate']) : '—' }}
                    @endif
                </td>
                <td>
                    @if ($row['billingcycle'] !== 'free')
                        {{ $Currency['prefix'] }}{{ $row['price_desc'] }}/{{ $row['cycle_desc'] }}
                    @else
                        免费
                    @endif
                </td>
                <td>
                    @if (! empty($row['dedicatedip']))
                        <button type="button" data-copy="{{ $row['dedicatedip'] }}"
                                class="font-mono text-xs text-slate-600 hover:text-brand-600">
                            {{ $row['dedicatedip'] }}
                        </button>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if (($pager['Pages'] ?? 1) > 1)
        <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3">
            <span class="text-xs text-slate-500">共 {{ $pager['Total'] }} 条</span>
            <div class="flex gap-1">
                @for ($i = 1; $i <= $pager['Pages']; $i++)
                    <a href="#" data-page="{{ $i }}" data-orderby="{{ request('orderby', 'nextduedate') }}" data-sort="{{ request('sort', 'ASC') }}"
                       class="rounded-md px-2.5 py-1.5 text-xs {{ $i === (int) $pager['Page'] ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                        {{ $i }}
                    </a>
                @endfor
            </div>
        </div>
    @endif
@endif
