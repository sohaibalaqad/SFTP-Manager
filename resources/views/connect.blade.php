@extends('layouts.app')

@section('title', 'الاتصال بالسيرفر — مدير ملفات SFTP')

@section('content')
<div x-data="connectPage" data-status="{{ session('status') }}" data-error="{{ session('error') }}" class="flex min-h-full items-center justify-center p-6">
    <div class="w-full max-w-4xl">
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="grid size-10 place-items-center rounded-xl bg-sky-600 text-white shadow-sm">
                    <x-icon name="server" class="size-5" />
                </div>
                <div>
                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">مدير ملفات SFTP</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">اتصل بسيرفر الملفات وتصفّح ملفاتك من المتصفح</p>
                </div>
            </div>
            <button type="button" class="btn-ghost" x-data="themeToggle" @click="toggle()" title="الوضع الليلي">
                <x-icon name="moon" class="dark:hidden" /><x-icon name="sun" class="hidden dark:block" />
            </button>
        </div>

        <div class="grid gap-5 md:grid-cols-[1fr_16rem]">
            {{-- Connection form --}}
            @include('partials.connect-form')

            {{-- Saved servers (browser localStorage only, never passwords) --}}
            <aside class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="mb-3 flex items-center gap-2 px-1 text-sm font-semibold text-slate-900 dark:text-white">
                    <x-icon name="star" class="text-amber-500" /> السيرفرات المحفوظة
                </h2>
                <template x-if="!servers.length">
                    <p class="px-1 py-6 text-center text-xs leading-relaxed text-slate-400">لا توجد سيرفرات محفوظة.<br>فعّل "حفظ بيانات السيرفر" عند الاتصال.</p>
                </template>
                <ul class="space-y-1">
                    <template x-for="(server, i) in servers" :key="server.name + i">
                        <li class="group relative">
                            <button type="button" @click="select(server)"
                                :class="isSelected(server) ? 'bg-sky-50 ring-1 ring-sky-200 dark:bg-sky-950/40 dark:ring-sky-900' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60'"
                                class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 pe-8 text-start">
                                <x-icon name="server" class="size-4 shrink-0 text-slate-400" />
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100" x-text="server.name"></span>
                                    <span class="block truncate text-[11px] text-slate-400" dir="ltr" x-text="`${server.username}@${server.host}:${server.port}`"></span>
                                </span>
                            </button>
                            <button type="button" @click="removeServer(i)" title="حذف من القائمة"
                                class="absolute inset-y-0 end-1 my-auto grid size-6 place-items-center rounded text-slate-400 opacity-0 hover:bg-rose-50 hover:text-rose-600 group-hover:opacity-100 dark:hover:bg-rose-950/40">
                                <x-icon name="x" class="size-3.5" />
                            </button>
                        </li>
                    </template>
                </ul>
                <p class="mt-3 border-t border-slate-100 px-1 pt-3 text-[11px] leading-relaxed text-slate-400 dark:border-slate-800">
                    تُحفظ في متصفحك فقط (localStorage): الاسم، Host، Port، Username، Remote Path. كلمة المرور والمفتاح لا يُحفظان أبدًا.
                </p>

                <div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <button type="button" class="flex w-full items-center gap-2 px-1 text-xs font-medium text-slate-600 dark:text-slate-300" @click="showKnownHosts = !showKnownHosts">
                        <x-icon name="lock" class="size-3.5 text-emerald-600" />
                        البصمات الموثوقة
                        <span class="text-slate-400" x-text="`(${knownHostList.length})`"></span>
                        <x-icon name="chevron" class="ms-auto size-3 transition-transform" ::class="showKnownHosts && '-rotate-90'" />
                    </button>
                    <ul x-show="showKnownHosts" x-cloak class="mt-2 space-y-1">
                        <template x-for="h in knownHostList" :key="h.id">
                            <li class="group flex items-center gap-2 rounded-md px-1 py-1 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <div class="min-w-0 flex-1" dir="ltr">
                                    <div class="truncate font-mono text-[11px] text-slate-700 dark:text-slate-200" x-text="h.id"></div>
                                    <div class="truncate font-mono text-[10px] text-slate-400" :title="h.fingerprint" x-text="`${h.algorithm} ${h.fingerprint}`"></div>
                                </div>
                                <button type="button" @click="forgetHost(h.id)" class="opacity-0 group-hover:opacity-100 text-slate-400 hover:text-rose-600" title="نسيان البصمة"><x-icon name="x" class="size-3.5" /></button>
                            </li>
                        </template>
                        <li x-show="!knownHostList.length" class="px-1 text-[11px] text-slate-400">لا توجد بصمات بعد.</li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>

    @include('partials.host-key-dialog')
</div>
@endsection
