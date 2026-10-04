{{-- ============================== Archive viewer (contents of a .zip / .tar / .tar.gz …) ============================== --}}
<div x-show="archive.open" x-cloak class="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/50 p-4 pt-[5vh] backdrop-blur-[2px]"
     @mousedown.self="archive.open = false">
    <div class="flex max-h-[88vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">

        {{-- Header --}}
        <div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-950/50"><x-icon name="archive" class="size-5" /></span>
            <div class="min-w-0 flex-1">
                <h3 class="truncate text-base font-semibold text-slate-900 dark:text-white"><bdi x-text="archive.item?.name"></bdi></h3>
                <p class="mt-0.5 text-xs text-slate-500" x-show="!archive.loading && !archive.error">
                    <span x-text="`${archiveFileCount} ملف`"></span> ·
                    <span dir="ltr" x-text="`${formatSize(archive.size)} بعد الاستخراج`"></span> ·
                    <span class="uppercase" x-text="archive.format"></span>
                    <span x-show="archive.skipped" class="text-amber-600" x-text="` · ${archive.skipped} عنصر غير آمن أو رابط رمزي سيُتجاهل`"></span>
                </p>
            </div>
            <button class="btn-ghost px-2" @click="archive.open = false" title="إغلاق"><x-icon name="x" class="size-5" /></button>
        </div>

        {{-- Search --}}
        <div class="border-b border-slate-100 px-5 py-2.5 dark:border-slate-800" x-show="!archive.loading && !archive.error && archive.entries.length > 8">
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute inset-y-0 start-2.5 my-auto size-3.5 text-slate-400" />
                <input x-model="archive.filter" class="input h-8 py-1 ps-8 text-xs" placeholder="بحث داخل الأرشيف…" spellcheck="false">
            </div>
        </div>

        {{-- Body --}}
        <div class="min-h-48 flex-1 overflow-auto">
            <div x-show="archive.loading" class="flex h-48 flex-col items-center justify-center gap-2 text-sm text-slate-400">
                <x-icon name="refresh" class="size-6 animate-spin" />
                جارٍ قراءة محتوى الأرشيف…
            </div>
            <div x-show="archive.error" x-cloak class="m-5 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300">
                <x-icon name="alert" class="mt-0.5 shrink-0" /><span x-text="archive.error"></span>
            </div>
            <p x-show="!archive.loading && !archive.error && !archive.entries.length" x-cloak class="p-8 text-center text-sm text-slate-400">الأرشيف فارغ.</p>

            <table x-show="!archive.loading && !archive.error && archive.entries.length" class="w-full table-fixed border-separate border-spacing-0 text-[13px]">
                <thead class="sticky top-0 z-10 bg-slate-50 text-xs text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                    <tr>
                        <th class="border-b border-slate-200 px-4 py-2 text-start font-medium dark:border-slate-800">المسار داخل الأرشيف</th>
                        <th class="w-24 border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800">الحجم</th>
                        <th class="w-36 border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800">التاريخ</th>
                        <th class="w-12 border-b border-slate-200 px-3 py-2 dark:border-slate-800"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="entry in archiveRows" :key="entry.path">
                        <tr class="group hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="truncate px-4 py-1.5" :title="entry.path">
                                <div class="flex items-center gap-2" :style="`padding-inline-start:${Math.min(entry.path.split('/').length - 1, 8) * 14}px`">
                                    <svg class="size-4 shrink-0" :class="iconColor({ name: entry.path, dir: entry.dir })" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" x-html="rowIcon({ name: entry.path, dir: entry.dir })"></svg>
                                    <bdi class="truncate" :class="entry.dir && 'font-medium'" x-text="entry.path.split('/').pop()"></bdi>
                                </div>
                            </td>
                            <td class="px-3 py-1.5 text-xs text-slate-500 tabular-nums" dir="ltr" style="text-align:right" x-text="entry.dir ? '' : formatSize(entry.size)"></td>
                            <td class="px-3 py-1.5 text-xs text-slate-500 tabular-nums" dir="ltr" style="text-align:right" x-text="formatDate(entry.mtime)"></td>
                            <td class="px-2 py-1 text-center">
                                <button x-show="!entry.dir" @click="downloadArchiveEntry(entry)" title="تنزيل هذا الملف فقط"
                                        class="grid size-7 place-items-center rounded text-slate-400 opacity-0 group-hover:opacity-100 hover:bg-slate-100 hover:text-sky-600 dark:hover:bg-slate-700">
                                    <x-icon name="download" class="size-3.5" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p x-show="archive.entries.length > 3000 && !archive.filter" class="px-4 py-2 text-center text-[11px] text-slate-400">يُعرض أول 3000 عنصر — استخدم البحث للوصول إلى البقية.</p>
        </div>

        {{-- Footer --}}
        <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 px-5 py-3 dark:border-slate-800">
            <p class="text-[11px] leading-relaxed text-slate-500">لا يتم استخراج أي ملف خارج المجلد الهدف، والروابط الرمزية تُتجاهل.</p>
            <div class="ms-auto flex items-center gap-2">
                <button class="btn-outline" @click="extractArchive(archive.item, 'here')" :disabled="archive.loading || !!archive.error">
                    <x-icon name="folder-open" />استخراج هنا
                </button>
                <button class="btn-primary" @click="extractArchive(archive.item, 'folder')" :disabled="archive.loading || !!archive.error">
                    <x-icon name="folder-plus" /><span>استخراج إلى «<bdi x-text="archive.item ? archive.item.name.replace(/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2?)$/i, '') : ''"></bdi>»</span>
                </button>
            </div>
        </div>
    </div>
</div>
