<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KeywordManager from './Partials/KeywordManager.vue';
import ProfileForm from './Partials/ProfileForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    profile: Object,
    keywords: Array,
    canActivate: Boolean,
});
</script>

<template>
    <Head :title="`Направление: ${profile.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ profile.name }}</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <ProfileForm :profile="profile" />
                </div>
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <KeywordManager :profile-id="profile.id" :keywords="keywords" />
                </div>
                <!-- Task 7: ActivationPanel -->
            </div>
        </div>
    </AuthenticatedLayout>
</template>
