<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ profiles: Array });

const destroy = (profile) => {
    if (confirm(`Удалить направление «${profile.name}»? Ключевые слова будут удалены.`)) {
        router.delete(route('profiles.destroy', profile.id));
    }
};
</script>

<template>
    <Head title="Направления" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Направления поиска</h2>
                <Link :href="route('profiles.create')">
                    <PrimaryButton>Добавить направление</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <p v-if="!profiles.length" class="p-6 text-gray-500">
                        Направлений пока нет. Создайте первое — это набор ключевых слов и фильтров,
                        по которым радар ищет закупки.
                    </p>

                    <table v-else class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Название</th>
                                <th class="px-6 py-3">Статус</th>
                                <th class="px-6 py-3">Ключей</th>
                                <th class="px-6 py-3">Порог</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white text-sm">
                            <tr v-for="profile in profiles" :key="profile.id">
                                <td class="px-6 py-4 font-medium text-gray-900">
                                    <Link :href="route('profiles.edit', profile.id)" class="hover:underline">
                                        {{ profile.name }}
                                    </Link>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="profile.is_active
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-gray-100 text-gray-600'"
                                        class="rounded px-2 py-1 text-xs font-medium"
                                    >
                                        {{ profile.is_active ? 'Активно' : 'Выключено' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">{{ profile.keywords_count }}</td>
                                <td class="px-6 py-4">{{ profile.notification_threshold }}</td>
                                <td class="px-6 py-4 text-right">
                                    <button class="text-sm text-red-600 hover:underline" @click="destroy(profile)">
                                        Удалить
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
