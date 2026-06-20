<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TagInput from '@/Components/TagInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';

const props = defineProps({ company: Object });

const form = useForm({
    name: props.company.name ?? '',
    inn: props.company.inn ?? '',
    website: props.company.website ?? '',
    description: props.company.description ?? '',
    industries: props.company.industries ?? [],
    products: props.company.products ?? [],
    services: props.company.services ?? [],
    notification_email: props.company.notification_email ?? '',
    telegram_chat_id: props.company.telegram_chat_id ?? '',
    max_chat_id: props.company.max_chat_id ?? '',
});

const sendTestMessage = () => {
    router.post(route('company.test-message'), {}, { preserveScroll: true });
};

const sendTestEmail = () => {
    router.post(route('company.test-email'), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Компания" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Настройки компании</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form
                    class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg"
                    @submit.prevent="form.patch(route('company.update'), { preserveScroll: true })"
                >
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Название" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                        <div>
                            <InputLabel for="inn" value="ИНН" />
                            <TextInput id="inn" v-model="form.inn" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.inn" />
                        </div>
                    </div>

                    <div>
                        <InputLabel for="website" value="Сайт" />
                        <TextInput id="website" v-model="form.website" type="url" class="mt-1 block w-full" placeholder="https://" />
                        <InputError class="mt-2" :message="form.errors.website" />
                    </div>

                    <div>
                        <InputLabel for="description" value="Описание деятельности" />
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <InputError class="mt-2" :message="form.errors.description" />
                    </div>

                    <div>
                        <InputLabel value="Отрасли" />
                        <TagInput v-model="form.industries" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Основные товары" />
                        <TagInput v-model="form.products" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Основные услуги" />
                        <TagInput v-model="form.services" class="mt-1" />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <InputLabel for="notification_email" value="Email для уведомлений" />
                            <TextInput id="notification_email" v-model="form.notification_email" type="email" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.notification_email" />
                            <button
                                type="button"
                                class="mt-2 text-sm text-indigo-600 hover:underline disabled:opacity-50"
                                :disabled="!form.notification_email"
                                @click="sendTestEmail"
                            >
                                Отправить тестовое письмо
                            </button>
                        </div>
                        <div>
                            <InputLabel for="telegram_chat_id" value="Telegram chat_id" />
                            <TextInput id="telegram_chat_id" v-model="form.telegram_chat_id" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.telegram_chat_id" />
                        </div>
                        <div>
                            <InputLabel for="max_chat_id" value="MAX chat_id" />
                            <TextInput id="max_chat_id" v-model="form.max_chat_id" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.max_chat_id" />
                            <button
                                type="button"
                                class="mt-2 text-sm text-indigo-600 hover:underline disabled:opacity-50"
                                :disabled="!form.max_chat_id"
                                @click="sendTestMessage"
                            >
                                Отправить тестовое
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="form.processing">Сохранить</PrimaryButton>
                        <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0">
                            <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Сохранено.</p>
                        </Transition>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
