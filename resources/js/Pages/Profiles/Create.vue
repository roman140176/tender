<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProfileForm from './Partials/ProfileForm.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Новое направление" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Новое направление</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <ProfileForm />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
