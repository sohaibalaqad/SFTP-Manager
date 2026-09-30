{{-- Connection form (used by the connect page and the "switch server" window). Needs x-data="connectPage". --}}
<form @submit.prevent="connect" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900" autocomplete="off">
    <h2 class="mb-5 flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-white">
        <x-icon name="plug" class="size-4 text-sky-600" /> السيرفر البعيد
    </h2>

    <div x-show="error" x-cloak class="mb-4 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300" role="alert">
        <x-icon name="alert" class="mt-0.5 shrink-0" /><span x-text="error"></span>
    </div>
    <div x-show="notice && !error" x-cloak class="mb-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300">
        <x-icon name="check" /><span x-text="notice"></span>
    </div>

    <div class="grid gap-4 sm:grid-cols-6">
        <div class="sm:col-span-6">
            <label class="label" for="name">اسم السيرفر</label>
            <input id="name" x-model="form.name" class="input" placeholder="My File Server" maxlength="100">
        </div>
        <div class="sm:col-span-4">
            <label class="label" for="host">Host</label>
            <input id="host" x-model="form.host" class="input" dir="ltr" placeholder="192.168.1.100" required autofocus spellcheck="false">
            <p x-show="currentKnownHost" x-cloak class="mt-1 flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400">
                <x-icon name="check" class="size-3" /> سيرفر موثوق ·
                <bdi dir="ltr" class="truncate font-mono" x-text="currentKnownHost?.fingerprint.slice(0, 26) + '…'"></bdi>
            </p>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="port">Port</label>
            <input id="port" x-model.number="form.port" type="number" min="1" max="65535" class="input" dir="ltr" required>
        </div>
        <div class="sm:col-span-3">
            <label class="label" for="username">Username</label>
            <input id="username" x-model="form.username" class="input" dir="ltr" placeholder="root" required spellcheck="false" autocomplete="off">
        </div>
        <div class="sm:col-span-3">
            <span class="label">طريقة الدخول</span>
            <div class="grid grid-cols-2 rounded-lg border border-slate-300 bg-slate-100 p-0.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                <button type="button" @click="form.auth = 'password'" class="rounded-md py-1.5 transition"
                        :class="form.auth === 'password' ? 'bg-white font-medium text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-500'">كلمة المرور</button>
                <button type="button" @click="form.auth = 'key'" class="rounded-md py-1.5 transition"
                        :class="form.auth === 'key' ? 'bg-white font-medium text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-500'">مفتاح SSH</button>
            </div>
        </div>

        {{-- Password --}}
        <div class="sm:col-span-6" x-show="form.auth === 'password'">
            <label class="label" for="password">Password</label>
            <div class="relative">
                <input id="password" x-ref="password" x-model="form.password" :type="showPassword ? 'text' : 'password'" class="input pl-9" dir="ltr" placeholder="Password" autocomplete="off">
                <button type="button" @click="showPassword = !showPassword" class="absolute inset-y-0 left-0 grid w-9 place-items-center text-slate-400 hover:text-slate-600" tabindex="-1" title="إظهار/إخفاء">
                    <x-icon name="eye" x-show="!showPassword" /><x-icon name="eye-off" x-show="showPassword" x-cloak />
                </button>
            </div>
        </div>

        {{-- SSH key --}}
        <div class="space-y-3 sm:col-span-6" x-show="form.auth === 'key'" x-cloak>
            <div>
                <span class="label">المفتاح الخاص (Private key)</span>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" x-ref="keyButton" class="btn-outline py-1.5" @click="pickKeyFile()"><x-icon name="lock" />اختيار ملف المفتاح</button>
                    <span x-show="form.keyName" class="flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1 text-xs text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <x-icon name="check" class="size-3.5" /><bdi dir="ltr" class="font-mono" x-text="form.keyName"></bdi>
                        <button type="button" @click="clearKey()" class="opacity-60 hover:opacity-100" title="إزالة"><x-icon name="x" class="size-3" /></button>
                    </span>
                    <button type="button" x-show="!form.keyName" class="text-xs text-sky-600 hover:underline" @click="pasteKey = !pasteKey">أو لصق المفتاح نصيًا</button>
                </div>
                <textarea x-show="pasteKey && !form.keyName" x-model="form.privateKey" rows="4" dir="ltr" spellcheck="false" autocomplete="off"
                          class="input mt-2 font-mono text-[11px]" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                <input type="file" x-ref="keyFile" class="hidden" @change="onKeyFile($event.target.files[0])">
                <p class="mt-1 text-[11px] text-slate-400">يدعم OpenSSH و PEM و PuTTY (.ppk). مثال: <bdi dir="ltr" class="font-mono">~/.ssh/id_ed25519</bdi></p>
            </div>
            <div>
                <label class="label" for="passphrase">كلمة سر المفتاح (Passphrase) <span class="font-normal text-slate-400">— اتركها فارغة إن لم يكن للمفتاح كلمة سر</span></label>
                <input id="passphrase" x-model="form.passphrase" type="password" class="input" dir="ltr" autocomplete="off">
            </div>
        </div>

        <div class="sm:col-span-6">
            <label class="label" for="path">Remote Path <span class="font-normal text-slate-400">(فارغ = مجلد المستخدم)</span></label>
            <input id="path" x-model="form.path" class="input" dir="ltr" placeholder="/var/www" spellcheck="false">
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
            <input type="checkbox" x-model="remember" class="size-4 rounded border-slate-300 accent-sky-600">
            حفظ بيانات السيرفر (بدون كلمة المرور أو المفتاح)
        </label>
        <button type="submit" class="btn-primary min-w-32" :disabled="loading">
            <svg x-show="loading" x-cloak class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <span x-text="loading ? 'جارٍ الاتصال…' : 'اتصال'"></span>
        </button>
    </div>

    <p class="mt-5 flex items-start gap-2 border-t border-slate-100 pt-4 text-xs leading-relaxed text-slate-500 dark:border-slate-800 dark:text-slate-500">
        <x-icon name="lock" class="mt-0.5 size-3.5 shrink-0" />
        لا يتم حفظ كلمة المرور أو مفتاح SSH في أي مكان دائم. يُستخدمان فقط خلال جلسة الاتصال الحالية (مشفّرين) ويُحذفان عند قطع الاتصال أو انتهاء الجلسة أو إغلاق المتصفح.
    </p>
</form>
