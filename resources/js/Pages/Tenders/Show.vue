<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import StatusButtons from './Partials/StatusButtons.vue';
import CommentSection from './Partials/CommentSection.vue';

defineProps({
    companyTender: { type: Object, required: true },
    tender: { type: Object, required: true },
    lawLabel: { type: String, required: true },
    match: { type: Object, default: null },
    comments: { type: Array, required: true },
    history: { type: Array, required: true },
    statusOptions: { type: Object, required: true },
    assignableUsers: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const money = (v) => (v === null ? '—' : Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽');
const date = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '—');
</script>

<template>
    <Head :title="tender.title" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ tender.title }}</h2>
                <Link :href="route('tenders.index')" class="text-sm text-indigo-600 hover:underline">← К списку</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-7xl grid-cols-1 gap-6 sm:px-6 lg:grid-cols-3 lg:px-8">
                <!-- Основные данные -->
                <div class="space-y-6 lg:col-span-2">
                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="mb-4 font-semibold text-gray-800">Данные закупки</h3>
                        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            <div><dt class="text-gray-500">Источник</dt><dd>{{ tender.source?.name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">Заказчик</dt><dd>{{ tender.customer_name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">Регион</dt><dd>{{ tender.region ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">Сумма</dt><dd>{{ money(tender.price) }}</dd></div>
                            <div><dt class="text-gray-500">Закон</dt><dd>{{ lawLabel }}</dd></div>
                            <div><dt class="text-gray-500">Тип процедуры</dt><dd>{{ tender.procedure_type ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">Номер</dt><dd>{{ tender.number ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">Опубликовано</dt><dd>{{ date(tender.published_at) }}</dd></div>
                            <div><dt class="text-gray-500">Срок подачи</dt><dd>{{ date(tender.deadline_at) }}</dd></div>
                        </dl>
                        <p v-if="tender.description" class="mt-4 whitespace-pre-line text-sm text-gray-700">{{ tender.description }}</p>
                        <a :href="tender.source_url" target="_blank" rel="noopener"
                           class="mt-4 inline-block text-sm text-indigo-600 hover:underline">Открыть оригинал →</a>
                    </div>

                    <!-- Объяснение релевантности -->
                    <div v-if="match" class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="mb-3 font-semibold text-gray-800">Релевантность: {{ match.score }} ({{ match.levelLabel }})</h3>
                        <p class="mb-2 text-sm font-medium text-gray-700">Рекомендация: {{ match.recommendation }}</p>
                        <div v-if="match.profiles.length" class="mb-3 text-xs text-gray-500">
                            Направления: <span v-for="(p, i) in match.profiles" :key="i">{{ p.name }} ({{ p.score }})<span v-if="i < match.profiles.length - 1">, </span></span>
                        </div>
                        <ul class="list-inside list-disc space-y-1 text-sm text-gray-700">
                            <li v-for="(line, i) in match.reasonLines" :key="i">{{ line }}</li>
                        </ul>
                        <div v-if="match.risks.length" class="mt-3 flex flex-wrap gap-2">
                            <span v-for="(r, i) in match.risks" :key="i" class="rounded bg-red-100 px-2 py-1 text-xs text-red-800">{{ r }}</span>
                        </div>
                        <div v-if="match.matchedKeywords.length" class="mt-3">
                            <p class="text-xs font-medium text-gray-600">Найденные ключи:</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <span v-for="(k, i) in match.matchedKeywords" :key="i" class="rounded bg-green-100 px-2 py-1 text-xs text-green-800">{{ k.value }}</span>
                            </div>
                        </div>
                        <div v-if="match.matchedNegativeKeywords.length" class="mt-2">
                            <p class="text-xs font-medium text-gray-600">Минус-слова:</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <span v-for="(k, i) in match.matchedNegativeKeywords" :key="i" class="rounded bg-yellow-100 px-2 py-1 text-xs text-yellow-800">{{ k.value }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- AI-блок (этап B) -->
                    <div class="bg-white p-6 text-sm text-gray-400 shadow-sm sm:rounded-lg">
                        AI-анализ закупки — этап B.
                    </div>

                    <!-- Комментарии -->
                    <CommentSection :company-tender="companyTender" :comments="comments" :can-manage="canManage" />
                </div>

                <!-- Боковая колонка: статус, ответственный, история -->
                <div class="space-y-6">
                    <StatusButtons
                        :company-tender="companyTender"
                        :status-options="statusOptions"
                        :assignable-users="assignableUsers"
                        :can-manage="canManage"
                    />
                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="mb-3 font-semibold text-gray-800">История статусов</h3>
                        <ul class="space-y-2 text-sm">
                            <li v-for="h in history" :key="h.id" class="text-gray-600">
                                {{ statusOptions[h.old_status] ?? '—' }} → <strong>{{ statusOptions[h.new_status] }}</strong>
                                <span class="block text-xs text-gray-400">{{ date(h.created_at) }}</span>
                            </li>
                            <li v-if="!history.length" class="text-gray-400">Изменений статуса пока нет.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
