@extends('layouts.app')

@section('title', $server['name'].' — مدير ملفات SFTP')
@section('body-class', 'overflow-hidden')

@section('content')
<div x-data="fileManager(@js(['server' => $server, 'chunkSize' => $chunkSize]))"
     class="flex h-full flex-col"
     @click="closeMenu()"
     @contextmenu.self.prevent>

    {{-- ============================== Header ============================== --}}
    <header class="flex h-12 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="grid size-7 place-items-center rounded-lg bg-sky-600 text-white"><x-icon name="server" class="size-4" /></div>
        {{-- Current server + quick switch --}}
        <div class="relative min-w-0" @click.outside="serverMenu = false">
            <button type="button" @click.stop="toggleServerMenu()" title="التبديل إلى سيرفر آخر"
                    class="flex min-w-0 items-baseline gap-2 rounded-lg px-2 py-1 hover:bg-slate-100 dark:hover:bg-slate-800">
                <span class="text-xs text-slate-500">متصل بـ:</span>
                <span class="truncate text-sm font-semibold text-slate-900 dark:text-white" x-text="server.name"></span>
                <span class="hidden truncate text-xs text-slate-400 md:inline" dir="ltr" x-text="`${server.username}@${server.host}:${server.port}`"></span>
                <x-icon name="chevron" class="size-3 -rotate-90 self-center text-slate-400" />
            </button>
            <div x-show="serverMenu" x-cloak class="absolute start-0 top-full z-50 mt-1 w-80 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-xl dark:border-slate-700 dark:bg-slate-800">
                <div class="px-3 py-1.5 text-[11px] font-medium text-slate-400">التبديل إلى سيرفر محفوظ</div>
                <template x-for="s in savedServers" :key="s.name + s.host + s.port + s.username">
                    <button type="button" class="menu-item" @click="switchServer(s)" :disabled="isCurrentServer(s)">
                        <x-icon name="server" />
                        <span class="min-w-0 flex-1 text-start">
                            <span class="block truncate" x-text="s.name"></span>
                            <span class="block truncate text-[11px] opacity-60" dir="ltr" x-text="`${s.username}@${s.host}:${s.port}`"></span>
                        </span>
                        <span x-show="isCurrentServer(s)" class="text-[11px] text-emerald-600">الحالي</span>
                    </button>
                </template>
                <p x-show="!savedServers.length" class="px-3 py-2 text-xs text-slate-400">لا توجد سيرفرات محفوظة بعد.</p>
                <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>
                <button type="button" class="menu-item" @click="switchServer(null)"><x-icon name="plug" />اتصال بسيرفر جديد…</button>
            </div>
        </div>
        <span class="size-2 rounded-full bg-emerald-500 shadow-[0_0_0_3px] shadow-emerald-500/20" title="متصل"></span>
        <div class="ms-auto flex items-center gap-1">
            <span class="hidden font-mono text-[10px] text-slate-400 lg:inline" dir="ltr" :title="'Host key fingerprint'" x-text="server.fingerprint"></span>
            <button type="button" class="btn-ghost relative py-1.5" @click="openLog()" title="سجل عمليات الرفع والتنزيل لهذه الجلسة">
                <x-icon name="history" /> سجل النقل
                <span x-show="logFailures" x-cloak class="absolute -top-0.5 -start-0.5 grid min-w-4 place-items-center rounded-full bg-rose-600 px-1 text-[10px] leading-4 text-white" x-text="logFailures"></span>
            </button>
            <button type="button" class="btn-ghost px-2" @click="toggleTheme()" title="الوضع الليلي">
                <x-icon name="moon" class="dark:hidden" /><x-icon name="sun" class="hidden dark:block" />
            </button>
            <form x-ref="disconnectForm" method="POST" action="{{ route('disconnect') }}">
                @csrf
                <button type="button" class="btn-outline py-1.5 text-rose-600 dark:text-rose-400" @click="disconnect()">
                    <x-icon name="logout" /> قطع الاتصال
                </button>
            </form>
        </div>
    </header>

    {{-- ============================== Toolbar ============================== --}}
    <div class="flex shrink-0 flex-wrap items-center gap-0.5 border-b border-slate-200 bg-slate-50/80 px-2 py-1 dark:border-slate-800 dark:bg-slate-900/60">
        <button class="tool" @click="goBack()" :disabled="!history.length && !path" title="رجوع (Backspace)"><x-icon name="back" /></button>
        <button class="tool" @click="goForward()" :disabled="!future.length" title="تقدم"><x-icon name="forward" /></button>
        <button class="tool" @click="goUp()" :disabled="!path" title="المجلد الأعلى"><x-icon name="up" /></button>
        <button class="tool" @click="refresh()" title="تحديث (F5)"><x-icon name="refresh" ::class="loading && 'animate-spin'" /></button>
        <span class="mx-1 h-5 w-px bg-slate-300 dark:bg-slate-700"></span>
        <button class="tool" @click="newFolder()"><x-icon name="folder-plus" class="text-amber-500" />مجلد جديد</button>
        <button class="tool" @click="newFile()"><x-icon name="file-plus" class="text-sky-600" />ملف جديد</button>
        <button class="tool" @click="pickFiles()"><x-icon name="upload" class="text-emerald-600" />رفع</button>
        <button class="tool" @click="download()" :disabled="!selectedItems.some(i => !i.dir)"><x-icon name="download" />تنزيل</button>
        <span class="mx-1 h-5 w-px bg-slate-300 dark:bg-slate-700"></span>
        <button class="tool" @click="copy('copy')" :disabled="!selected.length" title="نسخ (Ctrl+C)"><x-icon name="copy" />نسخ</button>
        <button class="tool" @click="copy('cut')" :disabled="!selected.length" title="قص (Ctrl+X)"><x-icon name="cut" />قص</button>
        <button class="tool relative" @click="paste()" :disabled="!clipboard" title="لصق (Ctrl+V)">
            <x-icon name="paste" />لصق
            <span x-show="clipboard" x-cloak class="rounded-full bg-sky-600 px-1.5 text-[10px] leading-4 text-white" x-text="clipboard?.items.length"></span>
        </button>
        <button class="tool" @click="rename()" :disabled="!single" title="إعادة تسمية (F2)"><x-icon name="rename" />إعادة تسمية</button>
        <button class="tool hover:text-rose-600" @click="remove()" :disabled="!selected.length" title="حذف (Delete)"><x-icon name="trash" />حذف</button>
        <button class="tool" @click="properties()" :disabled="!single" title="خصائص"><x-icon name="info" />خصائص</button>
        <button class="tool" @click="isArchive(single) ? extractArchive(single, 'folder') : compressItems()" :disabled="!selected.length"
                :title="isArchive(single) ? 'استخراج الأرشيف إلى مجلد باسمه' : 'ضغط المحدد إلى ملف ZIP على السيرفر'">
            <x-icon name="archive" /><span x-text="isArchive(single) ? 'استخراج' : 'ضغط'"></span>
        </button>
        <span class="mx-1 h-5 w-px bg-slate-300 dark:bg-slate-700"></span>
        <button class="tool" @click="toggleLocal()" :class="localVisible && 'bg-slate-200/80 dark:bg-slate-800'" title="إظهار/إخفاء لوحة الملفات المحلية"><x-icon name="laptop" />الملفات المحلية</button>

        {{-- Folder watch: periodic check + alert on new files --}}
        <div class="relative" @click.outside="watchMenu = false">
            <button class="tool" @click="watchMenu = !watchMenu" :class="watch.path !== null && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'"
                    title="تنبيه عند وصول ملفات جديدة إلى مجلد">
                <span class="relative">
                    <x-icon name="bell" />
                    <span x-show="watch.path !== null" class="absolute -top-0.5 -end-0.5 size-2 animate-pulse rounded-full bg-emerald-500"></span>
                </span>
                <span x-text="watch.path !== null ? 'مراقبة: تعمل' : 'مراقبة'"></span>
            </button>
            <div x-show="watchMenu" x-cloak class="absolute start-0 top-full z-50 mt-1 w-80 rounded-lg border border-slate-200 bg-white p-3 text-sm shadow-xl dark:border-slate-700 dark:bg-slate-800">
                <template x-if="watch.path === null">
                    <div>
                        <p class="mb-3 text-xs leading-relaxed text-slate-500">يفحص البرنامج المجلد تلقائيًا وينبّهك عند وصول ملفات جديدة، حتى لو كنت في مجلد آخر.</p>
                        <button class="btn-primary w-full" @click="startWatch()">
                            <x-icon name="bell" /><span>مراقبة هذا المجلد: <bdi dir="ltr" class="font-mono" x-text="absPath(path)"></bdi></span>
                        </button>
                    </div>
                </template>
                <template x-if="watch.path !== null">
                    <div>
                        <p class="text-xs text-slate-500">يُراقَب الآن:</p>
                        <button class="mt-0.5 block max-w-full truncate font-mono text-sm text-emerald-700 hover:underline dark:text-emerald-400" dir="ltr"
                                @click="watchMenu = false; navigate(watch.path)" x-text="absPath(watch.path)"></button>
                        <p class="mt-1 text-[11px] text-slate-400" x-text="watch.error || watchStatus()"></p>
                        <div class="mt-3 flex gap-2">
                            <button class="btn-outline flex-1 py-1.5 text-xs" x-show="watch.path !== path" @click="startWatch()">مراقبة المجلد الحالي بدلًا منه</button>
                            <button class="btn-outline flex-1 py-1.5 text-xs text-rose-600 dark:text-rose-400" @click="stopWatch()">إيقاف المراقبة</button>
                        </div>
                    </div>
                </template>

                <div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-700">
                    <span class="text-xs text-slate-500">الفحص كل:</span>
                    <div class="mt-1 grid grid-cols-4 gap-1">
                        <template x-for="sec in watchIntervals" :key="sec">
                            <button type="button" @click="setWatchInterval(sec)" class="rounded-md border py-1 text-xs"
                                    :class="watch.interval === sec ? 'border-sky-500 bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-700'"
                                    x-text="sec < 60 ? `${sec} ث` : `${sec / 60} د`"></button>
                        </template>
                    </div>
                    <label class="mt-3 flex cursor-pointer items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                        <input type="checkbox" x-model="watch.sound" @change="saveWatchPrefs()" class="size-3.5 accent-sky-600"> صوت تنبيه
                    </label>
                    <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                        <input type="checkbox" :checked="desktopNotify" @change="toggleDesktopNotify()" class="size-3.5 accent-sky-600"> إشعار على سطح المكتب عندما تكون في برنامج آخر
                    </label>
                </div>
            </div>
        </div>

        <div class="ms-auto flex items-center gap-2 py-0.5">
            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-slate-500" title="إظهار الملفات المخفية">
                <input type="checkbox" x-model="showHidden" class="size-3.5 accent-sky-600"> المخفية
            </label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute inset-y-0 start-2.5 my-auto size-3.5 text-slate-400" />
                <input x-ref="search" x-model="filter" @input="onFilterInput()" @keydown.enter.prevent="runSearch()" @keydown.escape="clearSearch(); $el.blur()"
                       class="input h-8 w-56 py-1 ps-8 text-xs" placeholder="بحث عن اسم ملف… (Ctrl+F)" spellcheck="false">
                <button x-show="filter" x-cloak @click="clearSearch()" class="absolute inset-y-0 end-1.5 my-auto grid size-5 place-items-center rounded text-slate-400 hover:text-slate-700"><x-icon name="x" class="size-3" /></button>
            </div>
            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-slate-500" title="اضغط Enter للبحث داخل المجلدات الفرعية">
                <input type="checkbox" x-model="deep" class="size-3.5 accent-sky-600"> المجلدات الفرعية
            </label>
        </div>
    </div>

    <div class="flex min-h-0 flex-1">
    {{-- ============================== Remote pane (server) ============================== --}}
    <section class="@container relative flex min-w-0 flex-1 flex-col">
    {{-- ============================== Breadcrumb ============================== --}}
    <nav class="flex h-9 shrink-0 items-center gap-1 overflow-x-auto border-b border-slate-200 bg-white px-3 text-[13px] dark:border-slate-800 dark:bg-slate-900" dir="ltr" aria-label="breadcrumb">
        <template x-for="(crumb, i) in breadcrumbs" :key="crumb.path">
            <div class="flex shrink-0 items-center gap-1">
                <span x-show="i > 0" class="text-slate-300 dark:text-slate-600">/</span>
                <button type="button" @click="navigate(crumb.path)"
                        :class="i === breadcrumbs.length - 1 ? 'font-semibold text-slate-900 dark:text-white' : 'text-slate-500 hover:text-sky-600 dark:text-slate-400'"
                        class="flex items-center gap-1 rounded px-1.5 py-0.5 font-mono hover:bg-slate-100 dark:hover:bg-slate-800">
                    <template x-if="i === 0"><x-icon name="home" class="size-3.5" /></template>
                    <span x-text="crumb.label"></span>
                </button>
            </div>
        </template>
    </nav>

    {{-- ============================== File list ============================== --}}
    <main class="relative min-h-0 flex-1 overflow-auto bg-white dark:bg-slate-900"
          @contextmenu="openMenu($event, null)"
          @click.self="selected = []"
          @dragenter.prevent="onDragEnter($event)"
          @dragleave="onDragLeave($event)"
          @dragover.prevent="onDragOver($event)"
          @drop.prevent="onDrop($event)">

        <template x-if="searchResults !== null">
            <div class="sticky top-0 z-20 flex items-center gap-2 border-b border-sky-200 bg-sky-50 px-4 py-1.5 text-xs text-sky-800 dark:border-sky-900 dark:bg-sky-950/60 dark:text-sky-200">
                <x-icon name="search" class="size-3.5" />
                <span>نتائج البحث عن "<b x-text="filter"></b>" داخل المجلد الحالي والمجلدات الفرعية: <span x-text="searchResults.length"></span></span>
                <span x-show="searchTruncated" class="text-amber-700 dark:text-amber-400">(تم عرض جزء من النتائج فقط)</span>
                <button class="ms-auto underline" @click="clearSearch()">إغلاق البحث</button>
            </div>
        </template>

        <table class="w-full table-fixed border-separate border-spacing-0 text-[13px]" @click.self="selected = []">
            <thead class="sticky z-10 bg-slate-50 text-xs text-slate-500 dark:bg-slate-900 dark:text-slate-400" :class="searchResults !== null ? 'top-[29px]' : 'top-0'">
                <tr>
                    @foreach (['name' => 'الاسم', 'type' => 'النوع', 'owner' => 'المالك', 'size' => 'الحجم', 'mtime' => 'آخر تعديل'] as $key => $label)
                        <th class="border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800 {{ ['name' => '', 'type' => 'hidden w-32 @2xl:table-cell', 'owner' => 'hidden w-28 @3xl:table-cell', 'size' => 'w-24', 'mtime' => 'hidden w-40 @lg:table-cell'][$key] }}">
                            <button type="button" class="flex items-center gap-1 hover:text-slate-800 dark:hover:text-slate-200" @click="sortBy('{{ $key }}')">
                                {{ $label }}
                                <span x-show="sortKey === '{{ $key }}'" x-text="sortDir === 1 ? '▲' : '▼'" class="text-[9px]"></span>
                            </button>
                        </th>
                        @if ($key === 'name')
                            <th x-show="searchResults !== null" class="w-48 border-b border-slate-200 px-3 py-2 text-start font-medium dark:border-slate-800">المجلد</th>
                        @endif
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <template x-for="item in visibleItems" :key="item.path">
                    <tr :data-path="item.path"
                        draggable="true"
                        @dragstart="onRemoteDragStart($event, item)"
                        @click.stop="click($event, item)"
                        @dblclick="open(item)"
                        @contextmenu.stop="openMenu($event, item)"
                        @dragover.prevent.stop="onDragOver($event, item)"
                        @drop.prevent.stop="onDrop($event, item)"
                        :class="[
                            dropTarget === item.path ? 'bg-emerald-100 outline-2 -outline-offset-2 outline-emerald-500 dark:bg-emerald-900/40' : isSelected(item) ? 'bg-sky-100 dark:bg-sky-900/50' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50',
                            isCut(item) && 'opacity-50'
                        ]"
                        class="cursor-default select-none">
                        <td class="truncate px-3 py-1.5">
                            <div class="flex items-center gap-2">
                                <span class="relative shrink-0">
                                    <svg class="size-[18px]" :class="iconColor(item)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" x-html="rowIcon(item)"></svg>
                                    <span x-show="item.link" class="absolute -bottom-1 -end-1 rounded-full bg-white text-sky-600 dark:bg-slate-900"><x-icon name="link" class="size-2.5" /></span>
                                </span>
                                <span class="truncate" dir="auto" x-text="item.name" :class="item.dir && 'font-medium'"></span>
                                <span x-show="isWatchNew(item)" class="shrink-0 rounded bg-emerald-500 px-1.5 text-[10px] leading-4 font-medium text-white" title="وصل منذ بدء المراقبة — يختفي عند النقر عليه">جديد</span>
                                <span x-show="isGrowing(item)" class="shrink-0 rounded bg-amber-400 px-1.5 text-[10px] leading-4 font-medium text-amber-950" title="حجمه ما زال يتغير — ربما لم يكتمل رفعه بعد">قيد الوصول</span>
                            </div>
                        </td>
                        <td x-show="searchResults !== null" class="truncate px-3 py-1.5 font-mono text-xs text-slate-400" dir="ltr" x-text="'/' + parentOf(item.path)"></td>
                        <td class="hidden truncate px-3 py-1.5 text-slate-500 @2xl:table-cell dark:text-slate-400" x-text="typeLabel(item)"></td>
                        <td class="hidden truncate px-3 py-1.5 text-slate-500 @3xl:table-cell dark:text-slate-400" :title="ownerTitle(item)">
                            <bdi dir="ltr" x-text="ownerLabel(item)"></bdi>
                        </td>
                        <td class="px-3 py-1.5 text-slate-500 tabular-nums dark:text-slate-400" dir="ltr" style="text-align: right" x-text="formatSize(item.size)"></td>
                        <td class="hidden px-3 py-1.5 text-slate-500 tabular-nums @lg:table-cell dark:text-slate-400" dir="ltr" style="text-align: right" x-text="formatDate(item.mtime)"></td>
                    </tr>
                </template>
            </tbody>
        </table>

        {{-- Empty / loading / error states --}}
        <div x-show="!loading && !visibleItems.length" x-cloak class="pointer-events-none absolute inset-0 top-10 flex flex-col items-center justify-center gap-2 text-sm text-slate-400">
            <x-icon name="folder" class="size-10 opacity-40" />
            <span x-text="loadError || (searchResults !== null ? 'لا توجد نتائج.' : (filter ? 'لا توجد ملفات مطابقة.' : 'هذا المجلد فارغ.'))"></span>
        </div>
        <div x-show="loading && !items.length" class="absolute inset-0 top-10 flex items-center justify-center text-sm text-slate-400">جارٍ التحميل…</div>

    </main>

        {{-- Drag & drop overlay (files from the device or the local panel) --}}
        <div x-show="dragOver" x-cloak class="pointer-events-none absolute inset-x-1 bottom-1 top-10 z-30 rounded-lg border-2 border-dashed"
             :class="dropTarget ? 'border-emerald-500' : 'border-sky-500 bg-sky-50/40 dark:bg-sky-950/30'">
            <div class="absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap text-white shadow-lg"
                 :class="dropTarget ? 'bg-emerald-600' : 'bg-sky-600'">
                <x-icon name="upload" />
                <span x-text="dropTarget ? `أفلت للرفع داخل المجلد: ${dropTarget.split('/').pop()}` : `أفلت للرفع إلى: ${absPath(path)}`"></span>
            </div>
        </div>
    </section>

    @include('partials.local-pane')
    </div>

    @include('partials.transfers')
    @include('partials.sync')
    @include('partials.archive')

    {{-- ============================== Status bar ============================== --}}
    <footer class="flex h-7 shrink-0 items-center gap-4 border-t border-slate-200 bg-slate-50 px-3 text-[11px] text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
        <span x-text="`${visibleItems.length} عنصر`"></span>
        <span x-show="selected.length" x-text="`${selected.length} محدد`"></span>
        <span x-show="clipboard" x-cloak class="text-sky-600 dark:text-sky-400" x-text="clipboard && `الحافظة: ${clipboard.items.length} عنصر (${clipboard.mode === 'cut' ? 'قص' : 'نسخ'})`"></span>
        <span x-show="busy" x-cloak class="flex items-center gap-1.5 text-amber-600"><x-icon name="refresh" class="size-3 animate-spin" /><span x-text="busy"></span></span>
        <button x-show="watch.path !== null" x-cloak @click="navigate(watch.path)" class="flex items-center gap-1.5 text-emerald-600 hover:underline dark:text-emerald-400"
                :title="`يُراقَب: ${absPath(watch.path ?? '')}`">
            <span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>
            <span>مراقبة <bdi dir="ltr" class="font-mono" x-text="absPath(watch.path ?? '')"></bdi> · <span x-text="watchStatus()"></span></span>
            <span x-show="watchNew.length" class="rounded-full bg-emerald-500 px-1.5 text-white" x-text="`${watchNew.length} جديد`"></span>
        </button>
        <span class="ms-auto truncate font-mono" dir="ltr" x-text="absPath(path)"></span>
    </footer>

    <input type="file" x-ref="fileInput" multiple class="hidden" @change="addUploads($event.target.files)">
    <input type="file" x-ref="localInput" webkitdirectory multiple class="hidden" @change="onLocalSnapshot($event.target.files)">
    <input type="file" x-ref="resumeInput" class="hidden" @change="onResumeFile($event.target.files[0])">

    {{-- ============================== Context menu ============================== --}}
    <div x-show="menu.open" x-cloak x-ref="menu" @click.stop @contextmenu.prevent
         class="fixed z-50 min-w-52 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-xl dark:border-slate-700 dark:bg-slate-800"
         :style="`left:${menu.x}px; top:${menu.y}px`">
        <template x-if="menu.item">
            <div>
                <button class="menu-item" @click="menuAction(() => open(menu.item))"><x-icon name="eye" />فتح <span class="kbd">Enter</span></button>
                <button class="menu-item" x-show="!menu.item.dir" @click="menuAction(() => download())"><x-icon name="download" />تنزيل (مجلد التنزيلات)</button>
                <button class="menu-item" x-show="!menu.item.dir && localMode === 'fsa'" @click="menuAction(() => downloadHere())"><x-icon name="laptop" />تنزيل إلى المجلد المحلي المفتوح</button>
                <button class="menu-item" x-show="menu.item.dir || selected.length > 1" @click="menuAction(() => downloadAsZip())"><x-icon name="download" />تنزيل كملف ZIP</button>
                <template x-if="isArchive(menu.item) && single">
                    <div>
                        <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>
                        <button class="menu-item" @click="menuAction(() => openArchive(menu.item))"><x-icon name="archive" />عرض محتوى الأرشيف</button>
                        <button class="menu-item" @click="menuAction(() => extractArchive(menu.item, 'here'))"><x-icon name="folder-open" />استخراج هنا</button>
                        <button class="menu-item" @click="menuAction(() => extractArchive(menu.item, 'folder'))"><x-icon name="folder-plus" /><span>استخراج إلى «<bdi x-text="menu.item.name.replace(/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2?)$/i, '')"></bdi>»</span></button>
                    </div>
                </template>
                <button class="menu-item" @click="menuAction(() => compressItems())"><x-icon name="archive" />ضغط إلى ZIP</button>
                <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>
                <button class="menu-item" @click="menuAction(() => copy('copy'))"><x-icon name="copy" />نسخ <span class="kbd" dir="ltr">Ctrl+C</span></button>
                <button class="menu-item" @click="menuAction(() => copy('cut'))"><x-icon name="cut" />قص <span class="kbd" dir="ltr">Ctrl+X</span></button>
                <button class="menu-item" x-show="clipboard && menu.item.dir && single" @click="menuAction(() => { const t = menu.item; navigate(t.path).then(() => paste()); })"><x-icon name="paste" />لصق داخل المجلد</button>
                <button class="menu-item" :disabled="!single" @click="menuAction(() => rename())"><x-icon name="rename" />إعادة تسمية <span class="kbd">F2</span></button>
                <button class="menu-item hover:!bg-rose-600" @click="menuAction(() => remove())"><x-icon name="trash" />حذف <span class="kbd">Delete</span></button>
                <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>
                <button class="menu-item" :disabled="!single" @click="menuAction(() => properties())"><x-icon name="info" />خصائص</button>
            </div>
        </template>
        <template x-if="!menu.item">
            <div>
                <button class="menu-item" @click="menuAction(() => newFolder())"><x-icon name="folder-plus" />مجلد جديد</button>
                <button class="menu-item" @click="menuAction(() => newFile())"><x-icon name="file-plus" />ملف جديد</button>
                <button class="menu-item" @click="menuAction(() => pickFiles())"><x-icon name="upload" />رفع ملفات</button>
                <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>
                <button class="menu-item" :disabled="!clipboard" @click="menuAction(() => paste())"><x-icon name="paste" />لصق <span class="kbd" dir="ltr">Ctrl+V</span></button>
                <button class="menu-item" @click="menuAction(() => refresh())"><x-icon name="refresh" />تحديث <span class="kbd">F5</span></button>
            </div>
        </template>
    </div>

    {{-- ============================== Prompt / confirm dialog ============================== --}}
    <div x-show="dialog.open" x-cloak class="fixed inset-0 z-[70] flex items-start justify-center bg-slate-900/40 p-4 pt-[15vh] backdrop-blur-[2px]" @mousedown.self="closeDialog(false)">
        <form @submit.prevent="closeDialog(true)" class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-700 dark:bg-slate-900"
>
            <h3 class="mb-3 flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-white">
                <x-icon name="alert" class="text-rose-600" x-show="dialog.danger" />
                <span x-text="dialog.title"></span>
            </h3>
            <div x-show="dialog.type === 'prompt'">
                <label class="label" x-text="dialog.label"></label>
                <input x-ref="dialogInput" x-model="dialog.value" class="input" dir="auto" spellcheck="false" autocomplete="off">
            </div>
            <p x-show="dialog.type === 'confirm'" class="text-sm leading-relaxed whitespace-pre-line text-slate-600 dark:text-slate-300" dir="auto" x-text="dialog.message"></p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" x-ref="dialogCancel" class="btn-outline" @click="closeDialog(false)" x-text="dialog.cancelText"></button>
                <button type="button" class="btn-outline" x-show="dialog.altText" @click="closeDialog('alt')" x-text="dialog.altText"></button>
                <button type="submit" x-ref="dialogConfirm" :class="dialog.danger ? 'btn-danger' : 'btn-primary'" x-text="dialog.confirmText"></button>
            </div>
        </form>
    </div>

    {{-- ============================== Properties ============================== --}}
    <div x-show="props" x-cloak class="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 p-4 pt-[15vh] backdrop-blur-[2px]" @mousedown.self="props = null">
        <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            <template x-if="props">
                <div>
                    <div class="mb-4 flex items-center gap-3">
                        <svg class="size-8" :class="iconColor(props)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" x-html="rowIcon(props)"></svg>
                        <h3 class="truncate text-base font-semibold text-slate-900 dark:text-white" dir="auto" x-text="props.name"></h3>
                    </div>
                    <dl class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-2.5 text-sm">
                        <dt class="text-slate-500">الاسم</dt><dd class="break-all"><bdi x-text="props.name"></bdi></dd>
                        <dt class="text-slate-500">المسار</dt><dd class="font-mono text-xs break-all"><bdi dir="ltr" x-text="absPath(props.path)"></bdi></dd>
                        <dt class="text-slate-500">النوع</dt><dd x-text="typeLabel(props)"></dd>
                        <dt class="text-slate-500">المالك</dt>
                        <dd><bdi dir="ltr" x-text="props.uid === null ? '—' : (props.owner ? `${props.owner} (UID ${props.uid})` : `UID ${props.uid}`)"></bdi></dd>
                        <dt class="text-slate-500">المجموعة</dt>
                        <dd><bdi dir="ltr" x-text="props.gid === null ? '—' : (props.group ? `${props.group} (GID ${props.gid})` : `GID ${props.gid}`)"></bdi></dd>
                        <dt class="text-slate-500">الحجم</dt>
                        <dd><span x-show="props.dir" x-text="`${props.children ?? 0} عنصر`"></span><bdi dir="ltr" x-show="!props.dir" x-text="`${formatSize(props.size)} (${(props.size ?? 0).toLocaleString('en')} bytes)`"></bdi></dd>
                        <dt class="text-slate-500">آخر تعديل</dt><dd><bdi dir="ltr" x-text="formatDate(props.mtime) || '—'"></bdi></dd>
                    </dl>
                    <div class="mt-5 flex justify-end"><button class="btn-primary" @click="props = null">إغلاق</button></div>
                </div>
            </template>
        </div>
    </div>

    {{-- ============================== Viewer / Editor ============================== --}}
    <div x-show="viewer.open" x-cloak class="fixed inset-0 z-[55] flex flex-col bg-white dark:bg-slate-950">
        <div class="flex h-12 shrink-0 items-center gap-3 border-b border-slate-200 px-4 dark:border-slate-800">
            <svg class="size-5 shrink-0" :class="viewer.item && iconColor(viewer.item)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" x-html="viewer.item && rowIcon(viewer.item)"></svg>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span class="truncate text-sm font-semibold text-slate-900 dark:text-white" dir="auto" x-text="viewer.item?.name"></span>
                    <span x-show="viewer.dirty" class="size-2 rounded-full bg-amber-500" title="تغييرات غير محفوظة"></span>
                </div>
                <div class="truncate font-mono text-[11px] text-slate-400" dir="ltr" style="text-align:right" x-text="viewer.item && absPath(viewer.item.path)"></div>
            </div>
            <div class="ms-auto flex items-center gap-2">
                <span x-show="viewer.dirty" class="text-xs text-amber-600">تغييرات غير محفوظة</span>
                <button x-show="viewer.kind === 'editor'" class="btn-primary py-1.5" @click="save()" :disabled="viewer.saving || !viewer.dirty">
                    <x-icon name="save" /><span x-text="viewer.saving ? 'جارٍ الحفظ…' : 'حفظ'"></span><span class="font-mono text-[10px] opacity-70" dir="ltr">Ctrl+S</span>
                </button>
                <button class="btn-outline py-1.5" @click="download([viewer.item])"><x-icon name="download" />تنزيل</button>
                <button class="btn-ghost px-2" @click="toggleTheme()" title="الوضع الليلي"><x-icon name="moon" class="dark:hidden" /><x-icon name="sun" class="hidden dark:block" /></button>
                <button class="btn-ghost px-2" @click="closeViewer()" title="إغلاق"><x-icon name="x" class="size-5" /></button>
            </div>
        </div>

        <div x-show="viewer.kind === 'editor' && viewer.remoteChanged" x-cloak
             class="flex shrink-0 flex-wrap items-center gap-3 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200">
            <x-icon name="alert" class="shrink-0" />
            <span x-text="viewer.remoteChanged?.deleted
                ? 'تحذير: تم حذف هذا الملف من السيرفر بعد فتحه. الحفظ سيعيد إنشاءه.'
                : 'تحذير: تم تعديل هذا الملف على السيرفر بعد فتحه. الحفظ الآن سيستبدل تلك التعديلات.'"></span>
            <div class="ms-auto flex gap-2">
                <button class="btn-outline py-1 text-xs" x-show="!viewer.remoteChanged?.deleted" @click="reloadFromServer()">تحميل نسخة السيرفر</button>
                <button class="btn-ghost py-1 text-xs" @click="viewer.remoteChanged = null">تجاهل</button>
            </div>
        </div>

        <div class="relative min-h-0 flex-1">
            <div x-show="viewer.kind === 'editor'" x-ref="editor" dir="ltr" class="absolute inset-0"></div>

            <div x-show="viewer.kind === 'loading'" class="absolute inset-0 flex items-center justify-center text-sm text-slate-400">جارٍ فتح الملف…</div>

            <template x-if="viewer.kind === 'image'">
                <div class="absolute inset-0 flex items-center justify-center overflow-auto bg-[repeating-conic-gradient(#f1f5f9_0_25%,#fff_0_50%)] bg-[length:20px_20px] p-6 dark:bg-[repeating-conic-gradient(#0f172a_0_25%,#1e293b_0_50%)]">
                    <img :src="viewer.src" :alt="viewer.item.name" class="max-h-full max-w-full object-contain shadow-lg" x-on:error="viewer.kind = 'error'; viewer.error = 'تعذر عرض الصورة.'">
                </div>
            </template>

            <template x-if="viewer.kind === 'pdf'">
                <iframe :src="viewer.src" class="absolute inset-0 size-full border-0" title="PDF"></iframe>
            </template>

            <div x-show="['binary', 'tooLarge', 'error'].includes(viewer.kind)" class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center">
                <x-icon name="file" class="size-12 text-slate-300 dark:text-slate-600" />
                <p class="text-base font-medium text-slate-700 dark:text-slate-200"
                   x-text="viewer.kind === 'binary' ? 'لا يمكن تعديل هذا الملف (File cannot be edited).' : viewer.kind === 'tooLarge' ? 'الملف كبير جدًا لفتحه في المحرر (الحد {{ intdiv($maxEditSize, 1048576) }} MB).' : viewer.error"></p>
                <p class="text-xs text-slate-400" x-show="viewer.kind === 'binary'">هذا ملف ثنائي (Binary) ولا يمكن عرضه كنص.</p>
                <p class="text-xs text-slate-400" dir="ltr" x-text="formatSize(viewer.size)"></p>
                <button class="btn-primary mt-2" @click="download([viewer.item])"><x-icon name="download" />تنزيل الملف</button>
            </div>
        </div>
    </div>

    {{-- ============================== Transfer log ============================== --}}
    <div x-show="logOpen" x-cloak class="fixed inset-0 z-[58] bg-slate-900/30" @mousedown.self="logOpen = false">
        <aside class="absolute inset-y-0 left-0 flex w-full max-w-lg flex-col border-e border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <x-icon name="history" class="size-5 text-sky-600" />
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">سجل النقل</h3>
                    <p class="text-[11px] text-slate-500">سجل مؤقت لهذه الجلسة فقط — يُحذف عند قطع الاتصال.</p>
                </div>
                <button class="btn-ghost ms-auto px-2" @click="logOpen = false" title="إغلاق"><x-icon name="x" class="size-5" /></button>
            </div>

            <div class="flex flex-wrap items-center gap-1 border-b border-slate-100 px-3 py-2 dark:border-slate-800">
                <button class="tool" @click="loadLog()"><x-icon name="refresh" ::class="logLoading && 'animate-spin'" />تحديث</button>
                <button class="tool" @click="exportLog()" :disabled="!logEntries.length"><x-icon name="download" />تصدير</button>
                <button class="tool hover:text-rose-600" @click="clearLog()" :disabled="!logEntries.length"><x-icon name="trash" />مسح</button>
                <button class="tool ms-auto" @click="toggleDesktopNotify()" :class="desktopNotify && 'text-sky-600 dark:text-sky-400'" title="إشعار على سطح المكتب عند انتهاء عملية والصفحة في الخلفية">
                    <x-icon name="bell" /><span x-text="desktopNotify ? 'إشعارات سطح المكتب: مفعّلة' : 'تفعيل إشعارات سطح المكتب'"></span>
                </button>
            </div>

            <div class="flex gap-4 px-4 py-2 text-xs" x-show="logEntries.length">
                <span class="text-emerald-600" x-text="`✓ ناجح: ${logStats.success}`"></span>
                <span class="text-rose-600" x-text="`✗ فشل: ${logStats.failed}`"></span>
                <span class="text-slate-500" x-show="logStats.canceled" x-text="`ملغى: ${logStats.canceled}`"></span>
                <span class="text-sky-600" x-show="logStats.started" x-text="`جارٍ: ${logStats.started}`"></span>
            </div>

            <ul x-show="logEntries.length" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                <template x-for="e in logEntries" :key="e.id">
                    <li class="flex gap-3 px-4 py-2.5">
                        <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full"
                              :class="{
                                'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50': e.status === 'success',
                                'bg-rose-50 text-rose-600 dark:bg-rose-950/50': e.status === 'failed',
                                'bg-slate-100 text-slate-500 dark:bg-slate-800': e.status === 'canceled',
                                'bg-sky-50 text-sky-600 dark:bg-sky-950/50': e.status === 'started',
                              }">
                            <template x-if="e.type === 'download'"><x-icon name="download" class="size-3.5" /></template>
                            <template x-if="e.type === 'upload'"><x-icon name="upload" class="size-3.5" /></template>
                            <template x-if="e.type === 'save'"><x-icon name="save" class="size-3.5" /></template>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <bdi class="truncate text-[13px] font-medium" x-text="e.name"></bdi>
                                <span class="ms-auto shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium"
                                      :class="{
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300': e.status === 'success',
                                        'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300': e.status === 'failed',
                                        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300': e.status === 'canceled',
                                        'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300': e.status === 'started',
                                      }"
                                      x-text="{ success: 'نجح', failed: 'فشل', canceled: 'ملغى', started: 'جارٍ…' }[e.status]"></span>
                            </div>
                            <div class="mt-0.5 text-[11px] text-slate-500" x-text="logTypeLabel(e)"></div>
                            <template x-if="e.from && e.to">
                                <div class="mt-1 space-y-0.5 rounded-md bg-slate-50 px-2 py-1.5 text-[11px] dark:bg-slate-800/60">
                                    <template x-for="[label, end] in [['من', e.from], ['إلى', e.to]]" :key="label">
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            <span class="w-6 shrink-0 text-slate-400" x-text="label"></span>
                                            <span class="shrink-0 rounded px-1.5 text-[10px]"
                                                  :class="end.side === 'server' ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-300' : 'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300'"
                                                  x-text="sideLabel(end)"></span>
                                            <bdi dir="ltr" class="truncate font-mono text-slate-600 dark:text-slate-300" :title="end.path" x-text="end.path"></bdi>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div x-show="!e.from" class="truncate font-mono text-[10px] text-slate-400"><bdi dir="ltr" x-text="absPath(e.path ?? e.name)"></bdi></div>
                            <div class="mt-0.5 flex gap-3 text-[10px] text-slate-400" dir="ltr" style="justify-content:flex-end">
                                <span x-show="e.duration != null" x-text="formatDuration(e.duration)"></span>
                                <span x-show="e.size != null" x-text="formatSize(e.size)"></span>
                                <span x-text="formatTime(e.started)"></span>
                            </div>
                            <p x-show="e.message" class="mt-1 text-[11px] text-rose-600 dark:text-rose-400" :class="e.status === 'canceled' && '!text-slate-500'" x-text="e.message"></p>
                        </div>
                    </li>
                </template>
            </ul>
            <div x-show="!logEntries.length && !logLoading" class="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-slate-400">
                <x-icon name="history" class="size-8 opacity-40" />
                لا توجد عمليات نقل في هذه الجلسة بعد.
            </div>
        </aside>
    </div>

    {{-- ============================== Switch server window ============================== --}}
    <div x-data="connectPage" x-show="switchOpen" x-cloak
         x-on:switch-server.window="openSwitch($event.detail)"
         @keydown.escape.window="if (!hostPrompt) switchOpen = false"
         class="fixed inset-0 z-[75] flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 pt-[6vh] backdrop-blur-[2px]"
         @mousedown.self="switchOpen = false">
        <div class="relative w-full max-w-xl">
            <button type="button" @click="switchOpen = false" class="absolute end-3 top-3 z-10 grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" title="إغلاق">
                <x-icon name="x" class="size-5" />
            </button>
            @include('partials.connect-form')
        </div>
        @include('partials.host-key-dialog')
    </div>

    {{-- ============================== Toasts ============================== --}}
    <div class="pointer-events-none fixed left-1/2 z-[80] flex w-full max-w-md -translate-x-1/2 flex-col items-center gap-2 px-4"
         :class="transfers.length ? (transfersCollapsed ? 'bottom-20' : 'bottom-[17rem]') : 'bottom-10'">
        <template x-for="t in toasts" :key="t.id">
            <div x-transition.opacity class="pointer-events-auto flex w-full items-start gap-2 rounded-lg px-3.5 py-2.5 text-sm whitespace-pre-line shadow-lg"
                 :class="{
                    'bg-emerald-600 text-white': t.type === 'success',
                    'bg-rose-600 text-white': t.type === 'error',
                    'bg-amber-500 text-white': t.type === 'warning',
                    'bg-slate-800 text-white dark:bg-slate-700': t.type === 'info',
                 }">
                <span class="flex-1" dir="auto" x-text="t.message"></span>
                <button @click="toasts = toasts.filter(x => x.id !== t.id)" class="opacity-70 hover:opacity-100"><x-icon name="x" class="size-3.5" /></button>
            </div>
        </template>
    </div>
</div>
@endsection
