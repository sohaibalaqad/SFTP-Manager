{{-- ============================== Compare & sync (local folder → server folder) ============================== --}}
<div x-show="syncOpen" x-cloak class="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/50 p-4 pt-[4vh] backdrop-blur-[2px]"
     @mousedown.self="syncOpen = false" @keydown.escape.window="syncOpen = false">
    <div class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">

        {{-- Header --}}
        <div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <x-icon name="compare" class="mt-0.5 size-5 text-sky-600" />
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white">مقارنة ومزامنة</h3>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                    <span>من جهازك:</span><bdi dir="ltr" class="font-mono text-slate-700 dark:text-slate-300" x-text="localDisplayPath"></bdi>
                    <span>←</span>
                    <span>إلى السيرفر:</span><bdi dir="ltr" class="font-mono text-slate-700 dark:text-slate-300" x-text="absPath(syncTarget)"></bdi>
                </p>
            </div>
            <button class="btn-ghost px-2" @click="syncOpen = false" title="إغلاق"><x-icon name="x" class="size-5" /></button>
        </div>

        {{-- Ignore patterns --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-2.5 dark:border-slate-800">
            <label class="text-xs text-slate-500" for="sync-ignore">تجاهل:</label>
            <input id="sync-ignore" x-model="syncIgnore" @keydown.enter.prevent="runCompare()" dir="ltr" spellcheck="false"
                   class="input h-8 min-w-0 flex-1 py-1 font-mono text-xs" placeholder=".git, node_modules, *.log">
            <button class="btn-outline py-1 text-xs" @click="runCompare()" :disabled="syncLoading">
                <x-icon name="refresh" ::class="syncLoading && 'animate-spin'" />إعادة الفحص
            </button>
        </div>

        {{-- Summary / filters --}}
        <div class="flex flex-wrap items-center gap-1.5 px-5 py-2.5 text-xs" x-show="!syncLoading && !syncError">
            @foreach ([
                'todo' => ['يحتاج رفع', 'bg-sky-600 text-white', null],
                'new' => ['جديد', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300', 'new'],
                'changed' => ['معدّل', 'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300', 'changed'],
                'remote-newer' => ['السيرفر أحدث', 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300', 'remote-newer'],
                'remote-only' => ['على السيرفر فقط', 'bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-300', 'remote-only'],
                'same' => ['متطابق', 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', 'same'],
                'all' => ['الكل', 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', null],
            ] as $key => [$label, $classes, $countKey])
                <button type="button" @click="syncFilter = '{{ $key }}'"
                        :class="syncFilter === '{{ $key }}' ? 'ring-2 ring-sky-500 ring-offset-1 dark:ring-offset-slate-900' : 'opacity-80 hover:opacity-100'"
                        class="rounded-full px-2.5 py-1 font-medium {{ $classes }}">
                    {{ $label }}
                    @if ($countKey)
                        <span x-text="syncCounts['{{ $countKey }}']"></span>
                    @elseif ($key === 'todo')
                        <span x-text="syncCounts.new + syncCounts.changed + syncCounts['remote-newer'] + syncCounts.conflict"></span>
                    @else
                        <span x-text="syncRows.length"></span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Body --}}
        <div class="min-h-40 flex-1 overflow-auto border-y border-slate-100 dark:border-slate-800">
            <div x-show="syncLoading" class="flex h-40 flex-col items-center justify-center gap-2 text-sm text-slate-400">
                <x-icon name="refresh" class="size-6 animate-spin" />
                <span x-text="syncProgress || 'جارٍ فحص المجلد المحلي ومجلد السيرفر…'"></span>
            </div>
            <div x-show="syncError" x-cloak class="m-5 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300">
                <x-icon name="alert" class="mt-0.5 shrink-0" /><span x-text="syncError"></span>
            </div>
            <div x-show="!syncLoading && !syncError && syncFilter === 'todo' && !syncVisibleRows.length && syncRows.length" x-cloak
                 class="flex h-40 flex-col items-center justify-center gap-2 text-sm text-emerald-600">
                <x-icon name="check" class="size-8" />
                المجلدان متطابقان — لا يوجد ما يحتاج رفعًا.
            </div>

            <table x-show="!syncLoading && !syncError && syncVisibleRows.length" class="w-full table-fixed border-separate border-spacing-0 text-[13px]">
                <thead class="sticky top-0 z-10 bg-slate-50 text-xs text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                    <tr>
                        <th class="w-10 border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                            <input type="checkbox" class="size-3.5 accent-sky-600" title="تحديد الكل"
                                   @change="toggleSyncAll($event.target.checked)">
                        </th>
                        <th class="w-32 border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800">الحالة</th>
                        <th class="border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800">الملف</th>
                        <th class="w-48 border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800">على جهازك</th>
                        <th class="w-48 border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800">على السيرفر</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in syncVisibleRows" :key="row.rel">
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-3 py-1.5 text-center">
                                <input type="checkbox" x-model="row.checked" class="size-3.5 accent-sky-600"
                                       :disabled="!['new', 'changed', 'remote-newer'].includes(row.status)">
                            </td>
                            <td class="px-3 py-1.5">
                                <span class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                                      :class="{
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300': row.status === 'new',
                                        'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300': row.status === 'changed',
                                        'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300': row.status === 'remote-newer',
                                        'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300': row.status === 'conflict',
                                        'bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-300': row.status === 'remote-only',
                                        'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400': row.status === 'same',
                                      }"
                                      :title="{
                                        'remote-newer': 'نسخة السيرفر أحدث من نسختك — لن تُرفع إلا إذا حددتها بنفسك',
                                        conflict: 'ملف على جهازك ومجلد بنفس الاسم على السيرفر — لن يُرفع',
                                        'remote-only': 'موجود على السيرفر فقط — لن يُحذف',
                                      }[row.status] ?? ''"
                                      x-text="syncStatusLabel(row.status)"></span>
                                <span x-show="row.verified" class="ms-1 text-[10px] text-emerald-600 dark:text-emerald-400" title="تمت مقارنة محتوى الملف نفسه (SHA-256)، وليس التاريخ فقط">✓ محتوى</span>
                            </td>
                            <td class="truncate px-3 py-1.5 font-mono text-xs" dir="ltr" style="text-align:right" :title="row.rel" x-text="row.rel + (row.remote?.dir && !row.local ? '/' : '')"></td>
                            <td class="px-3 py-1.5 text-[11px] text-slate-500 tabular-nums" dir="ltr" style="text-align:right"
                                x-text="row.local ? `${formatSize(row.local.size)} · ${formatDate(Math.floor(row.local.mtime / 1000))}` : '—'"></td>
                            <td class="px-3 py-1.5 text-[11px] text-slate-500 tabular-nums" dir="ltr" style="text-align:right"
                                x-text="row.remote ? (row.remote.dir ? 'مجلد' : `${formatSize(row.remote.size)} · ${formatDate(row.remote.mtime)}`) : '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Footer --}}
        <div class="flex flex-wrap items-center gap-3 px-5 py-3">
            <p class="text-[11px] leading-relaxed text-slate-500">
                يرفع الملفات المحددة فقط ويستبدل نسخها على السيرفر. <b>لا يُحذف أي شيء من السيرفر.</b>
                الملفات متساوية الحجم ومختلفة التاريخ تُقارَن بمحتواها (✓ محتوى).
                <span x-show="syncTruncated" class="text-amber-600">المجلد كبير جدًا: تم فحص جزء منه فقط.</span>
            </p>
            <div class="ms-auto flex items-center gap-2">
                <button class="btn-outline" @click="syncOpen = false">إغلاق</button>
                <button class="btn-primary" @click="runSync()" :disabled="syncLoading || !syncSelected.length">
                    <x-icon name="upload" />
                    <span x-text="`رفع المحدد (${syncSelected.length} ملف · ${formatSize(syncSelectedBytes)})`"></span>
                </button>
            </div>
        </div>
    </div>
</div>
