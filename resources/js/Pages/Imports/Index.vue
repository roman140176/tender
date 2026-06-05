<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    runs: Array,
    maxFileSizeKb: Number,
    maxRows: Number,
});

const form = useForm({ file: null });

const submit = () => {
    form.post(route('imports.store'), {
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
};

const STATUS = { ok: 'Готово', error: 'Ошибка', running: 'Выполняется' };

const formatDate = (value) => (value ? new Date(value).toLocaleString('ru-RU') : '—');
</script>

<template>
    <Head title="Импорт закупок" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Импорт закупок</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="text-lg font-medium text-gray-900">Загрузка файла</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        CSV (разделитель «;»), XLSX или JSON, до {{ Math.round(maxFileSizeKb / 1024) }} МБ
                        и {{ maxRows }} строк.
                        <a :href="route('imports.template')" class="text-indigo-600 hover:underline">Скачать шаблон CSV</a>.
                    </p>

                    <form class="mt-4 flex items-center gap-3" @submit.prevent="submit">
                        <input
                            type="file"
                            accept=".csv,.xlsx,.json"
                            class="block text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
                            @change="form.file = $event.target.files[0]"
                        />
                        <PrimaryButton :disabled="form.processing || !form.file">
                            {{ form.processing ? 'Импортируем…' : 'Импортировать' }}
                        </PrimaryButton>
                    </form>
                    <InputError class="mt-2" :message="form.errors.file" />
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg">
                    <h3 class="p-6 pb-0 text-lg font-medium text-gray-900">История импортов</h3>

                    <p v-if="!runs.length" class="p-6 text-gray-500">Импортов ещё не было.</p>

                    <table v-else class="mt-4 min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Когда</th>
                                <th class="px-6 py-3">Статус</th>
                                <th class="px-6 py-3">Строк</th>
                                <th class="px-6 py-3">Новых</th>
                                <th class="px-6 py-3">Обновлено</th>
                                <th class="px-6 py-3">Ошибок</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="run in runs" :key="run.id" class="align-top">
                                <td class="px-6 py-3 whitespace-nowrap">{{ formatDate(run.started_at) }}</td>
                                <td class="px-6 py-3">
                                    <span
                                        :class="{
                                            'bg-green-100 text-green-800': run.status === 'ok',
                                            'bg-red-100 text-red-800': run.status === 'error',
                                            'bg-yellow-100 text-yellow-800': run.status === 'running',
                                        }"
                                        class="rounded px-2 py-1 text-xs font-medium"
                                    >
                                        {{ STATUS[run.status] ?? run.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-3">{{ run.fetched_count }}</td>
                                <td class="px-6 py-3 font-medium text-green-700">{{ run.new_count }}</td>
                                <td class="px-6 py-3">{{ run.updated_count }}</td>
                                <td class="px-6 py-3">
                                    <span :class="run.error_count ? 'font-medium text-red-700' : ''">{{ run.error_count }}</span>
                                    <details v-if="run.log" class="mt-1 text-xs text-gray-500">
                                        <summary class="cursor-pointer">подробности</summary>
                                        <pre class="mt-1 whitespace-pre-wrap">{{ run.log }}</pre>
                                    </details>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
