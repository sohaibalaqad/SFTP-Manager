{{-- Host key confirmation (first connection) / warning (key changed) --}}
<div x-show="hostPrompt" x-cloak class="fixed inset-0 z-[90] flex items-start justify-center bg-slate-900/50 p-4 pt-[10vh] backdrop-blur-[2px]" @keydown.escape.window="hostPrompt = null">
    <div class="w-full max-w-lg rounded-xl border bg-white p-5 shadow-2xl dark:bg-slate-900"
         :class="hostPrompt?.status === 'changed' ? 'border-rose-300 dark:border-rose-800' : 'border-slate-200 dark:border-slate-700'">
        <template x-if="hostPrompt">
            <div>
                <h3 class="mb-3 flex items-center gap-2 text-base font-semibold"
                    :class="hostPrompt.status === 'changed' ? 'text-rose-700 dark:text-rose-400' : 'text-slate-900 dark:text-white'">
                    <x-icon name="alert" x-show="hostPrompt.status === 'changed'" />
                    <x-icon name="lock" class="text-sky-600" x-show="hostPrompt.status !== 'changed'" />
                    <span x-text="hostPrompt.status === 'changed' ? 'تحذير أمني: تغيّرت بصمة السيرفر!' : 'أول اتصال بهذا السيرفر'"></span>
                </h3>

                <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300" x-show="hostPrompt.status !== 'changed'">
                    لم يسبق الاتصال بـ <bdi dir="ltr" class="font-mono" x-text="hostPrompt.host"></bdi> من هذا المتصفح.
                    تأكد أن البصمة أدناه تطابق بصمة السيرفر الحقيقية قبل إرسال كلمة المرور أو المفتاح إليه.
                </p>
                <p class="text-sm leading-relaxed text-rose-700 dark:text-rose-300" x-show="hostPrompt.status === 'changed'">
                    البصمة التي يقدّمها <bdi dir="ltr" class="font-mono" x-text="hostPrompt.host"></bdi> الآن تختلف عن البصمة التي وثقت بها سابقًا.
                    قد يكون السبب إعادة تثبيت السيرفر أو تغيير مفاتيحه، وقد يكون محاولة اعتراض للاتصال (MITM).
                    لم يتم إرسال كلمة المرور أو المفتاح.
                </p>

                <dl class="mt-4 space-y-2 rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800/60">
                    <div x-show="hostPrompt.status === 'changed'">
                        <dt class="text-slate-500">البصمة الموثوقة سابقًا</dt>
                        <dd class="font-mono break-all text-slate-500 line-through" dir="ltr" x-text="hostPrompt.expected"></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500" x-text="hostPrompt.status === 'changed' ? 'البصمة الجديدة' : 'بصمة مفتاح السيرفر'"></dt>
                        <dd class="font-mono break-all text-slate-900 dark:text-white" dir="ltr" x-text="hostPrompt.fingerprint"></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">نوع المفتاح</dt>
                        <dd class="font-mono" dir="ltr" x-text="hostPrompt.algorithm"></dd>
                    </div>
                </dl>

                <p class="mt-3 text-[11px] leading-relaxed text-slate-500">
                    للتحقق، نفّذ على السيرفر (أو اطلبه من مدير السيرفر) وقارن السطر المطابق لنوع المفتاح:
                    <code class="mt-1 block rounded bg-slate-100 px-2 py-1 font-mono text-[11px] text-slate-700 dark:bg-slate-800 dark:text-slate-200" dir="ltr">for f in /etc/ssh/ssh_host_*_key.pub; do ssh-keygen -lf "$f"; done</code>
                </p>

                <label x-show="hostPrompt.status === 'changed'" class="mt-4 flex cursor-pointer items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input type="checkbox" x-model="hostChecked" class="mt-0.5 size-4 accent-rose-600">
                    تحققت من البصمة الجديدة مع مدير السيرفر وأعرف سبب تغيّرها.
                </label>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-outline" @click="hostPrompt = null" x-ref="hostCancel">إلغاء</button>
                    <button type="button" @click="trustHost()"
                            :class="hostPrompt.status === 'changed' ? 'btn-danger' : 'btn-primary'"
                            :disabled="hostPrompt.status === 'changed' && !hostChecked"
                            x-text="hostPrompt.status === 'changed' ? 'الوثوق بالمفتاح الجديد والاتصال' : 'أثق بهذا السيرفر — اتصال'"></button>
                </div>
            </div>
        </template>
    </div>
</div>
