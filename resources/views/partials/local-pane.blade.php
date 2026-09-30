{{-- ============================== Local pane (this computer) ============================== --}}
<aside x-show="localVisible" x-cloak
       class="@container relative flex w-[26rem] max-w-[45%] shrink-0 flex-col border-s border-slate-200 bg-slate-50/70 dark:border-slate-800 dark:bg-slate-950/40"
       @dragover="onLocalDragOver($event)"
       @dragleave="onLocalDragLeave($event)"
       @drop="onLocalDrop($event)">

    {{-- Header --}}
    <div class="flex h-9 shrink-0 items-center gap-1 border-b border-slate-200 bg-white px-2 dark:border-slate-800 dark:bg-slate-900">
        <x-icon name="laptop" class="ms-1 text-slate-500" />
        <span class="text-[13px] font-semibold text-slate-700 dark:text-slate-200">جهازك</span>
        <div class="ms-auto flex items-center gap-0.5">
            <button class="tool" @click="localUp()" :disabled="!localMode || !localPath.length" title="المجلد الأعلى"><x-icon name="up" /></button>
            <button class="tool" @click="loadLocal()" :disabled="!localMode" title="تحديث"><x-icon name="refresh" ::class="localLoading && 'animate-spin'" /></button>
            <button class="tool text-sky-700 dark:text-sky-400" @click="openSync()" :disabled="!localMode" title="مقارنة هذا المجلد مع مجلد السيرفر المفتوح، ورفع الجديد والمعدّل فقط">
                <x-icon name="compare" />مقارنة
            </button>
            <button class="tool" @click="pickLocalFolder()" title="اختيار مجلد من جهازك"><x-icon name="folder-open" class="text-amber-500" /><span x-text="localMode ? 'تغيير' : 'اختيار مجلد'"></span></button>
            <button class="tool text-emerald-700 dark:text-emerald-400" @click="uploadLocal()" :disabled="!localSelected.length" title="رفع المحدد إلى مجلد السيرفر الحالي">
                <x-icon name="upload" />رفع →
            </button>
            <button class="tool" @click="toggleLocal()" title="إخفاء اللوحة"><x-icon name="x" /></button>
        </div>
    </div>

    {{-- Breadcrumb --}}
    <nav x-show="localMode" class="flex h-9 shrink-0 items-center gap-1 overflow-x-auto border-b border-slate-200 px-2 text-[13px] dark:border-slate-800" dir="ltr">
        <template x-for="(part, i) in [localRootName, ...localPath]" :key="i">
            <div class="flex shrink-0 items-center gap-1">
                <span x-show="i > 0" class="text-slate-300 dark:text-slate-600">/</span>
                <button type="button" @click="localGoTo(i)"
                        :class="i === localPath.length ? 'font-semibold text-slate-900 dark:text-white' : 'text-slate-500 hover:text-sky-600 dark:text-slate-400'"
                        class="flex items-center gap-1 rounded px-1.5 py-0.5 font-mono hover:bg-slate-100 dark:hover:bg-slate-800">
                    <template x-if="i === 0"><x-icon name="laptop" class="size-3.5" /></template>
                    <span x-text="part"></span>
                </button>
            </div>
        </template>
    </nav>

    {{-- No folder chosen yet --}}
    <div x-show="!localMode" class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
        <x-icon name="folder-open" class="size-10 text-slate-300 dark:text-slate-600" />
        <p class="text-sm text-slate-600 dark:text-slate-300">اختر مجلدًا من جهازك لعرض ملفاته هنا،<br>ثم اسحبها إلى السيرفر.</p>
        <button x-show="localRestorable" class="btn-primary" @click="restoreLocal()">
            <x-icon name="folder-open" /><span>إعادة فتح: <bdi x-text="localRestorable"></bdi></span>
        </button>
        <button class="btn-outline" @click="pickLocalFolder()"><x-icon name="folder-open" />اختيار مجلد محلي</button>
        <p class="max-w-xs text-[11px] leading-relaxed text-slate-400" x-show="localFsa">
            يمكنك أيضًا سحب ملفات من السيرفر إلى هذه اللوحة لتنزيلها داخل المجلد المفتوح.
        </p>
        <p class="max-w-xs text-[11px] leading-relaxed text-amber-600 dark:text-amber-400" x-show="!localFsa">
            متصفحك يعرض المجلد للقراءة فقط (للرفع). التنزيل إلى مجلد محدد يتطلب Chrome أو Edge عبر HTTPS.
        </p>
    </div>

    {{-- File list --}}
    <div x-show="localMode" class="relative min-h-0 flex-1 overflow-auto"
         :class="localDropTarget === '' && 'outline-2 -outline-offset-4 outline-dashed outline-emerald-500 bg-emerald-50/40 dark:bg-emerald-950/20'"
         @click.self="localSelected = []">
        <table class="w-full table-fixed border-separate border-spacing-0 text-[13px]">
            <thead class="sticky top-0 z-10 bg-slate-100 text-xs text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                <tr>
                    <th class="border-b border-slate-200 px-3 py-1.5 text-start font-medium dark:border-slate-800">الاسم</th>
                    <th class="w-20 border-b border-slate-200 px-3 py-1.5 text-start font-medium dark:border-slate-800">الحجم</th>
                    <th class="hidden w-36 border-b border-slate-200 px-3 py-1.5 text-start font-medium @md:table-cell dark:border-slate-800">آخر تعديل</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="item in localItems" :key="item.name">
                    <tr draggable="true"
                        @dragstart="onLocalDragStart($event, item)"
                        @click="localClick($event, item)"
                        @dblclick="localOpen(item)"
                        @dragover="onLocalDragOver($event, item)"
                        @drop="onLocalDrop($event, item)"
                        :title="item.dir ? 'انقر مرتين للفتح' : 'اسحب إلى السيرفر أو انقر مرتين للرفع'"
                        :class="localDropTarget === item.name && item.dir
                            ? 'bg-emerald-100 outline-2 -outline-offset-2 outline-emerald-500 dark:bg-emerald-900/40'
                            : localSelected.includes(item.name) ? 'bg-sky-100 dark:bg-sky-900/50' : 'hover:bg-white dark:hover:bg-slate-800/50'"
                        class="cursor-default select-none">
                        <td class="truncate px-3 py-1">
                            <div class="flex items-center gap-2">
                                <svg class="size-4 shrink-0" :class="iconColor(item)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" x-html="rowIcon(item)"></svg>
                                <span class="truncate" dir="auto" x-text="item.name" :class="item.dir && 'font-medium'"></span>
                            </div>
                        </td>
                        <td class="px-3 py-1 text-xs text-slate-500 tabular-nums" dir="ltr" style="text-align:right" x-text="formatSize(item.size)"></td>
                        <td class="hidden px-3 py-1 text-xs text-slate-500 tabular-nums @md:table-cell" dir="ltr" style="text-align:right" x-text="formatDate(item.mtime)"></td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="!localLoading && !localItems.length" class="pointer-events-none absolute inset-0 top-8 flex items-center justify-center text-sm text-slate-400"
             x-text="localError || 'المجلد فارغ.'"></div>
    </div>

    {{-- Footer --}}
    <div x-show="localMode" class="flex h-7 shrink-0 items-center gap-3 border-t border-slate-200 px-3 text-[11px] text-slate-500 dark:border-slate-800">
        <span x-text="`${localItems.length} عنصر`"></span>
        <span x-show="localSelected.length" x-text="`${localSelected.length} محدد`"></span>
        <span x-show="localMode === 'snapshot'" class="ms-auto text-amber-600 dark:text-amber-400" title="التنزيل إلى هنا يتطلب Chrome أو Edge">للقراءة فقط</span>
        <span x-show="localMode === 'fsa'" class="ms-auto">اسحب ملفات السيرفر إلى هنا لتنزيلها</span>
    </div>

    {{-- Drop hint when dragging server files in --}}
    <div x-show="localDropTarget !== null" x-cloak class="pointer-events-none absolute bottom-10 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full bg-emerald-600 px-4 py-2 text-sm font-medium whitespace-nowrap text-white shadow-lg">
        <x-icon name="download" />
        <span x-text="`أفلت للتنزيل إلى: ${[localRootName, ...localPath, ...(localDropTarget ? [localDropTarget] : [])].join('/')}`"></span>
    </div>
</aside>
