<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profile: Object,
    canActivate: Boolean,
});

const error = computed(() => usePage().props.errors?.activation);

const toggle = () => {
    router.post(
        route(props.profile.is_active ? 'profiles.deactivate' : 'profiles.activate', props.profile.id),
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <section class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-medium text-gray-900">
                {{ profile.is_active ? 'Направление активно' : 'Направление выключено' }}
            </h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ profile.is_active
                    ? 'Участвует в поиске закупок при запуске радара.'
                    : canActivate
                        ? 'Готово к активации.'
                        : 'Для активации добавьте хотя бы один позитивный ключ.' }}
            </p>
            <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
        </div>
        <PrimaryButton v-if="!profile.is_active" :disabled="!canActivate" @click="toggle">
            Активировать
        </PrimaryButton>
        <SecondaryButton v-else @click="toggle">Выключить</SecondaryButton>
    </section>
</template>
