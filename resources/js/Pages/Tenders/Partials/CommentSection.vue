<script setup>
import { useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    companyTender: { type: Object, required: true },
    comments: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const form = useForm({ comment: '' });

const submit = () => {
    form.post(route('tenders.comment', props.companyTender.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('comment'),
    });
};

const date = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '');
</script>

<template>
    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
        <h3 class="mb-4 font-semibold text-gray-800">Комментарии</h3>

        <form v-if="canManage" class="mb-4" @submit.prevent="submit">
            <textarea
                v-model="form.comment"
                rows="2"
                class="block w-full rounded-md border-gray-300 text-sm"
                placeholder="Добавить комментарий…"
            />
            <InputError class="mt-1" :message="form.errors.comment" />
            <PrimaryButton class="mt-2" :disabled="form.processing">Отправить</PrimaryButton>
        </form>

        <ul class="space-y-3">
            <li v-for="c in comments" :key="c.id" class="border-l-2 border-gray-200 pl-3 text-sm">
                <p class="text-gray-700">{{ c.comment }}</p>
                <p class="text-xs text-gray-400">{{ c.user?.name ?? 'Система' }} · {{ date(c.created_at) }}</p>
            </li>
            <li v-if="!comments.length" class="text-sm text-gray-400">Комментариев пока нет.</li>
        </ul>
    </div>
</template>
