<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, GraduationCap, Sparkles } from 'lucide-vue-next';
import TutorialVideoBlock from '@/components/tutorial/TutorialVideoBlock.vue';

export type TutorialLesson = {
    id: string;
    title: string;
    description: string;
    duration?: string;
    video_url?: string | null;
    poster_url?: string | null;
    embed_slug?: string | null;
    embed_type?: string;
    embed_height?: number;
    href: string;
    cta: string;
};

const props = defineProps<{
    lessons: TutorialLesson[];
    embedScriptUrl: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tutorial', href: '/tutorial' }],
    },
});
</script>

<template>
    <Head title="Tutorial" />

    <div class="min-h-full bg-[#F2EFEA] p-3 pb-14 md:p-5">
        <!-- Header -->
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div class="flex items-start gap-3">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-[#E8563A] shadow-lg shadow-orange-200">
                    <GraduationCap class="size-6 text-white" />
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-[#E8563A]">Video guides</p>
                    <h1 class="text-2xl font-black text-gray-900 md:text-3xl">Tutorial</h1>
                    <p class="mt-1 max-w-lg text-sm text-gray-500">
                        Watch each walkthrough, then jump straight into the app to complete that step.
                    </p>
                </div>
            </div>
            <Link
                href="/content/create"
                class="inline-flex items-center gap-1.5 rounded-xl bg-[#E8563A] px-4 py-2 text-sm font-semibold text-white shadow-md shadow-orange-200 transition hover:bg-[#d44a2f]"
            >
                <Sparkles class="size-4" />
                Start creating
            </Link>
        </div>

        <!-- Lesson video blocks -->
        <div class="mx-auto max-w-4xl space-y-8">
            <article
                v-for="(lesson, index) in props.lessons"
                :key="lesson.id"
                class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-black/[0.04]"
            >
                <TutorialVideoBlock
                    :lesson-id="lesson.id"
                    :title="lesson.title"
                    :duration="lesson.duration"
                    :video-url="lesson.video_url"
                    :poster-url="lesson.poster_url"
                    :embed-slug="lesson.embed_slug"
                    :embed-type="lesson.embed_type"
                    :embed-height="lesson.embed_height"
                    :embed-script-url="embedScriptUrl"
                />

                <div class="p-5 md:p-6">
                    <div class="mb-2 flex items-center gap-2">
                        <span
                            class="flex size-7 items-center justify-center rounded-lg bg-[#E8563A]/10 text-xs font-black text-[#E8563A]"
                        >
                            {{ index + 1 }}
                        </span>
                        <h2 class="text-lg font-bold text-gray-900">{{ lesson.title }}</h2>
                    </div>
                    <p class="text-sm leading-relaxed text-gray-500">{{ lesson.description }}</p>
                    <Link
                        :href="lesson.href"
                        class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-[#E8563A] transition hover:gap-2"
                    >
                        {{ lesson.cta }}
                        <ArrowRight class="size-4" />
                    </Link>
                </div>
            </article>
        </div>

        <p v-if="props.lessons.length === 0" class="rounded-2xl border border-dashed border-gray-200 bg-white p-10 text-center text-sm text-gray-400">
            No tutorial lessons configured. Add entries in <code>config/tutorial.php</code>.
        </p>
    </div>
</template>
