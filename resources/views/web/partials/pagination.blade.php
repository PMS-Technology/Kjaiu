@php
    /** @var int $total */
    /** @var int $pages */
    /** @var int $page */
    $total = (int) ($total ?? 0);
    $pages = (int) ($pages ?? 1);
    $page = (int) ($page ?? 1);

    // Rebuild the query string, replacing only `page` so filters survive.
    $query = request()->query();
    unset($query['page']);
    $link = fn (int $target) => request()->url() . '?' . http_build_query(array_merge($query, ['page' => $target]));

    $window = 2;
    $start = max(1, $page - $window);
    $end = min($pages, $page + $window);
@endphp

@if ($pages > 1)
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3">
        <span class="text-xs text-slate-500">共 {{ $total }} 条记录，第 {{ $page }}/{{ $pages }} 页</span>

        <nav class="flex items-center gap-1">
            <a href="{{ $link(max(1, $page - 1)) }}"
               class="rounded-md px-2.5 py-1.5 text-xs {{ $page <= 1 ? 'pointer-events-none text-slate-300' : 'text-slate-600 hover:bg-slate-100' }}">
                上一页
            </a>

            @if ($start > 1)
                <a href="{{ $link(1) }}" class="rounded-md px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-100">1</a>
                @if ($start > 2)
                    <span class="px-1 text-xs text-slate-400">…</span>
                @endif
            @endif

            @for ($i = $start; $i <= $end; $i++)
                <a href="{{ $link($i) }}"
                   class="rounded-md px-2.5 py-1.5 text-xs {{ $i === $page ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    {{ $i }}
                </a>
            @endfor

            @if ($end < $pages)
                @if ($end < $pages - 1)
                    <span class="px-1 text-xs text-slate-400">…</span>
                @endif
                <a href="{{ $link($pages) }}" class="rounded-md px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-100">{{ $pages }}</a>
            @endif

            <a href="{{ $link(min($pages, $page + 1)) }}"
               class="rounded-md px-2.5 py-1.5 text-xs {{ $page >= $pages ? 'pointer-events-none text-slate-300' : 'text-slate-600 hover:bg-slate-100' }}">
                下一页
            </a>
        </nav>
    </div>
@endif
