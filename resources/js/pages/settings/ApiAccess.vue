<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Send, Trash2, Webhook } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeAgo } from '@/lib/format';
import { edit } from '@/routes/api-access';
import {
    destroy as destroyToken,
    store as storeToken,
} from '@/routes/api-access/tokens';
import {
    destroy as destroyEndpoint,
    ping,
    store as storeEndpoint,
} from '@/routes/api-access/webhooks';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    created_at: string | null;
    last_used_at: string | null;
};

type Endpoint = { id: number; url: string; created_at: string };

type Delivery = {
    id: number;
    event: string;
    url: string;
    submission_id: number | null;
    status: 'pending' | 'retrying' | 'delivered' | 'failed';
    attempts: number;
    response_status: number | null;
    error: string | null;
    created_at: string;
};

defineProps<{
    apiBase: string;
    abilities: string[];
    tokens: Token[];
    endpoints: Endpoint[];
    maxEndpoints: number;
    deliveries: Delivery[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API & webhooks', href: edit() }],
    },
});

const page = usePage();
const secret = computed(
    () => page.flash.secret as { label: string; value: string } | undefined,
);
const copied = ref(false);
const copy = async () => {
    if (!secret.value) {
        return;
    }

    await navigator.clipboard.writeText(secret.value.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
};

const tokenForm = useForm({
    name: '',
    abilities: ['submissions:read', 'submissions:write'],
});
const createToken = () =>
    tokenForm.submit(storeToken(), {
        preserveScroll: true,
        onSuccess: () => tokenForm.reset('name'),
    });

const endpointForm = useForm({ url: '' });
const addEndpoint = () =>
    endpointForm.submit(storeEndpoint(), {
        preserveScroll: true,
        onSuccess: () => endpointForm.reset(),
    });

const remove = (url: { url: string; method: 'delete' }) =>
    router.delete(url.url, { preserveScroll: true });

const sendPing = (id: number) =>
    router.post(ping(id).url, {}, { preserveScroll: true });

const deliveryLook: Record<Delivery['status'], string> = {
    delivered:
        'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300',
    retrying:
        'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
    pending: 'bg-muted text-muted-foreground',
    failed: 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300',
};
</script>

<template>
    <Head title="API & webhooks" />

    <h1 class="sr-only">API and webhooks</h1>

    <div class="flex flex-col space-y-10">
        <div
            v-if="secret"
            class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10"
            role="status"
        >
            <p class="text-sm font-medium">
                {{ secret.label }}: copy it now. It will not be shown again.
            </p>
            <div class="flex items-center gap-2">
                <code
                    class="min-w-0 flex-1 overflow-x-auto rounded bg-background px-2 py-1.5 font-mono text-xs whitespace-nowrap"
                    >{{ secret.value }}</code
                >
                <Button size="sm" variant="outline" @click="copy">
                    <component :is="copied ? Check : Copy" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </div>

        <section class="space-y-6">
            <Heading
                variant="small"
                title="API tokens"
                description="Submit content and check decisions from your own systems. A token acts as you."
            />

            <div class="overflow-hidden rounded-lg border">
                <div
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium tracking-tight">
                            {{ token.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ token.abilities.join(', ') }}
                            <span class="mx-1 text-muted-foreground/50">/</span>
                            {{
                                token.last_used_at
                                    ? `Last used ${timeAgo(token.last_used_at)}`
                                    : 'Never used'
                            }}
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        @click="remove(destroyToken(token.id))"
                    >
                        <Trash2 class="size-4" />
                        <span class="sr-only">Revoke {{ token.name }}</span>
                    </Button>
                </div>
                <div
                    v-if="tokens.length === 0"
                    class="flex items-center gap-3 p-4 text-sm text-muted-foreground"
                >
                    <KeyRound class="size-4" aria-hidden="true" />
                    No tokens yet.
                </div>
            </div>

            <form class="space-y-4" @submit.prevent="createToken">
                <div class="grid gap-2">
                    <Label for="token-name">Token name</Label>
                    <Input
                        id="token-name"
                        v-model="tokenForm.name"
                        placeholder="e.g. Photo app production"
                        maxlength="60"
                        required
                    />
                    <InputError :message="tokenForm.errors.name" />
                </div>
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">Allowed to</legend>
                    <label
                        v-for="ability in abilities"
                        :key="ability"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="tokenForm.abilities"
                            type="checkbox"
                            :value="ability"
                            class="size-4 accent-primary"
                        />
                        <code class="font-mono text-xs">{{ ability }}</code>
                        <span class="text-muted-foreground">{{
                            ability.endsWith('write')
                                ? 'upload submissions'
                                : 'read your submissions and decisions'
                        }}</span>
                    </label>
                    <InputError :message="tokenForm.errors.abilities" />
                </fieldset>
                <Button type="submit" :disabled="tokenForm.processing">
                    Create token
                </Button>
            </form>

            <details class="rounded-lg border p-4 text-sm">
                <summary class="cursor-pointer font-medium">
                    Example request
                </summary>
                <pre
                    class="mt-3 overflow-x-auto rounded bg-muted p-3 font-mono text-xs leading-relaxed"
                >
curl -X POST {{ apiBase }}/submissions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -F caption="Evening at the harbour" \
  -F image=@photo.jpg \
  -F reference=your-own-id-123</pre>
                <p class="mt-2 text-muted-foreground">
                    Returns 202 with the submission. Check
                    <code class="font-mono text-xs"
                        >GET /api/submissions/{id}</code
                    >
                    or wait for the webhook. Re-sending the same
                    <code class="font-mono text-xs">reference</code> returns the
                    original instead of a duplicate.
                </p>
            </details>
        </section>

        <section class="space-y-6">
            <Heading
                variant="small"
                title="Webhooks"
                description="We POST a signed submission.decided event whenever one of your submissions is approved or rejected, including after review or appeal."
            />

            <div class="overflow-hidden rounded-lg border">
                <div
                    v-for="endpoint in endpoints"
                    :key="endpoint.id"
                    class="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-mono text-sm">
                            {{ endpoint.url }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Added {{ timeAgo(endpoint.created_at) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="sendPing(endpoint.id)"
                        >
                            <Send class="size-4" /> Test
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            @click="remove(destroyEndpoint(endpoint.id))"
                        >
                            <Trash2 class="size-4" />
                            <span class="sr-only"
                                >Remove {{ endpoint.url }}</span
                            >
                        </Button>
                    </div>
                </div>
                <div
                    v-if="endpoints.length === 0"
                    class="flex items-center gap-3 p-4 text-sm text-muted-foreground"
                >
                    <Webhook class="size-4" aria-hidden="true" />
                    No endpoints yet.
                </div>
            </div>

            <form
                v-if="endpoints.length < maxEndpoints"
                class="grid gap-2"
                @submit.prevent="addEndpoint"
            >
                <Label for="endpoint-url">Endpoint URL</Label>
                <div class="flex gap-2">
                    <Input
                        id="endpoint-url"
                        v-model="endpointForm.url"
                        type="url"
                        placeholder="https://example.com/hooks/moderation"
                        required
                    />
                    <Button type="submit" :disabled="endpointForm.processing">
                        Add
                    </Button>
                </div>
                <InputError :message="endpointForm.errors.url" />
            </form>

            <p class="text-sm text-muted-foreground">
                Verify each request: the
                <code class="font-mono text-xs">Moderation-Signature</code>
                header is
                <code class="font-mono text-xs"
                    >t=&lt;unix time&gt;,v1=&lt;hex&gt;</code
                >, where hex is the HMAC-SHA256 of
                <code class="font-mono text-xs"
                    >"&lt;t&gt;.&lt;raw body&gt;"</code
                >
                with your signing secret. Reject timestamps older than five
                minutes. Failed deliveries are retried for about three hours.
            </p>

            <div v-if="deliveries.length" class="space-y-2">
                <h3 class="text-sm font-medium">Recent deliveries</h3>
                <ul class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="d in deliveries"
                        :key="d.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 p-3"
                    >
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="deliveryLook[d.status]"
                            >{{ d.status }}</span
                        >
                        <code class="font-mono text-xs">{{ d.event }}</code>
                        <span
                            v-if="d.submission_id"
                            class="text-xs text-muted-foreground"
                            >#{{ d.submission_id }}</span
                        >
                        <span class="text-xs text-muted-foreground">
                            {{ d.attempts }}
                            {{ d.attempts === 1 ? 'attempt' : 'attempts' }}
                            <template v-if="d.response_status">
                                · HTTP {{ d.response_status }}</template
                            >
                        </span>
                        <span class="ml-auto text-xs text-muted-foreground">{{
                            timeAgo(d.created_at)
                        }}</span>
                        <p
                            v-if="d.error"
                            class="w-full text-xs text-red-700 dark:text-red-300"
                        >
                            {{ d.error }}
                        </p>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
