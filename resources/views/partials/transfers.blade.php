{{-- ============================== Transfers dock (uploads & downloads with progress) ============================== --}}
<section x-show="transfers.length" x-cloak class="shrink-0 border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="flex h-9 items-center gap-3 px-3">
        <button class="flex items-center gap-2 text-[13px] font-semibold text-slate-700 dark:text-slate-200" @click="transfersCollapsed = !transfersCollapsed">
            <x-icon name="chevron" class="size-3.5 transition-transform" ::class="transfersCollapsed ? '' : '-rotate-90'" />
            النقل
        </button>
        <span class="text-xs text-slate-500" x-text="activeTransfers ? `${activeTransfers} جارٍ` : 'اكتملت كل العمليات'"></span>
        <span class="text-xs text-emerald-600" x-show="transfers.some(t => t.status === 'done')" x-text="`✓ ${transfers.filter(t => t.status === 'done').length}`"></span>
        <span class="text-xs text-rose-600" x-show="transfers.some(t => t.status === 'error')" x-text="`✗ ${transfers.filter(t => t.status === 'error').length}`"></span>
        <button class="ms-auto text-xs text-slate-500 hover:text-slate-800 dark:hover:text-slate-200" x-show="transfers.length > activeTransfers" @click="clearTransfers()"
                x-text="activeTransfers ? 'مسح المكتملة' : 'إغلاق'"></button>
    </div>

    <ul x-show="!transfersCollapsed" class="max-h-52 divide-y divide-slate-100 overflow-y-auto border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800">
        <template x-for="t in [...transfers].reverse()" :key="t.id">
            <li class="flex items-center gap-3 px-3 py-2">
                {{-- direction --}}
                <span class="grid size-7 shrink-0 place-items-center rounded-full"
                      :class="t.kind === 'upload' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50' : 'bg-sky-50 text-sky-600 dark:bg-sky-950/50'"
                      :title="t.kind === 'upload' ? 'رفع: جهازك ← السيرفر' : 'تنزيل: السيرفر ← جهازك'">
                    <template x-if="t.kind === 'upload'"><x-icon name="upload" class="size-3.5" /></template>
                    <template x-if="t.kind === 'download'"><x-icon name="download" class="size-3.5" /></template>
                </span>

                {{-- name + from → to --}}
                <div class="w-72 min-w-0 shrink-0">
                    <bdi class="block truncate text-[13px] font-medium" x-text="t.name"></bdi>
                    <div class="truncate text-[10px] text-slate-400">
                        <span x-text="t.kind === 'upload' ? 'من جهازك: ' : 'من السيرفر: '"></span><bdi dir="ltr" class="font-mono" x-text="t.from"></bdi>
                    </div>
                    <div class="truncate text-[10px] text-slate-400">
                        <span x-text="t.kind === 'upload' ? 'إلى السيرفر: ' : 'إلى جهازك: '"></span><bdi dir="ltr" class="font-mono" x-text="t.to"></bdi>
                    </div>
                </div>

                {{-- progress --}}
                <div class="min-w-0 flex-1">
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full transition-[width] duration-300"
                             :class="{
                                'bg-rose-500': t.status === 'error',
                                'bg-emerald-500': t.status === 'done',
                                'bg-slate-400': t.status === 'canceled' || t.status === 'unknown',
                                'bg-amber-500': t.status === 'paused' || t.status === 'retrying',
                                'bg-sky-500': !['error', 'done', 'canceled', 'unknown', 'paused', 'retrying'].includes(t.status),
                                'animate-pulse': t.status === 'waiting',
                             }"
                             :style="`width:${t.status === 'waiting' ? 100 : percent(t)}%; opacity:${t.status === 'waiting' ? .25 : 1}`"></div>
                    </div>
                    <div class="mt-1 flex justify-between gap-2 text-[10px] text-slate-400" dir="ltr">
                        <span x-text="`${formatSize(t.loaded)} / ${formatSize(t.size)}`"></span>
                        <span x-text="transferRate(t)"></span>
                    </div>
                    <p x-show="t.error" class="mt-0.5 truncate text-[11px]" :class="['paused', 'retrying'].includes(t.status) ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600'" :title="t.error"
                       x-text="t.status === 'retrying' ? `${t.error} — محاولة ${t.attempt} من 8` : t.error"></p>
                </div>

                {{-- status --}}
                <span class="w-24 shrink-0 text-end text-xs font-medium tabular-nums"
                      :class="{ 'text-emerald-600': t.status === 'done', 'text-rose-600': t.status === 'error', 'text-slate-400': ['canceled', 'queued', 'unknown', 'waiting'].includes(t.status), 'text-sky-600': ['uploading', 'downloading'].includes(t.status), 'text-amber-600': ['paused', 'retrying'].includes(t.status) }"
                      x-text="transferStatus(t)"></span>

                {{-- resume (paused uploads) --}}
                <button x-show="t.status === 'paused'" @click="resumeTransfer(t)" class="btn-outline shrink-0 px-2 py-1 text-xs"
                        :title="t.orphan ? 'اختر نفس الملف من جهازك لإكمال رفعه' : 'إكمال الرفع من حيث توقف'">
                    <x-icon name="refresh" class="size-3.5" /><span x-text="t.orphan ? 'اختيار الملف للاستئناف' : 'استئناف'"></span>
                </button>

                {{-- cancel --}}
                <div class="w-6 shrink-0">
                    <button x-show="['queued', 'uploading', 'downloading', 'retrying', 'paused'].includes(t.status) && t.mode !== 'browser'" @click="cancelTransfer(t)"
                            class="grid size-6 place-items-center rounded text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40" title="إلغاء">
                        <x-icon name="x" class="size-3.5" />
                    </button>
                    <span x-show="t.mode === 'browser' && ['waiting', 'downloading'].includes(t.status)" class="cursor-help text-slate-300" title="هذا التنزيل يديره المتصفح؛ للإلغاء استخدم قائمة التنزيلات في المتصفح">ⓘ</span>
                </div>
            </li>
        </template>
    </ul>
</section>
