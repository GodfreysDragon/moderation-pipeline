import { configureEcho, echo, echoIsConfigured } from '@laravel/echo-vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, readonly, ref } from 'vue';

const connected = ref(false);

/**
 * Connect to Reverb when the build has a key for it. Without one (or while the
 * socket is down) `connected` stays false and pages keep polling instead.
 */
export function initializeLive(): void {
    if (!import.meta.env.VITE_REVERB_APP_KEY) {
        return;
    }

    configureEcho({ broadcaster: 'reverb' });

    const connector = echo<'reverb'>().connector;
    connected.value = connector.connectionStatus() === 'connected';
    connector.onConnectionChange(
        (status) => (connected.value = status === 'connected'),
    );
}

/**
 * Whether live updates are flowing right now.
 */
export const liveConnected = readonly(connected);

/**
 * Reload the given props when any of the events arrives on a private channel.
 * Events carry no content: the reload fetches what this viewer may see.
 * Bursts (a pipeline finishing several stages at once) collapse into one reload.
 */
export function useLiveReload(
    channel: () => string | null,
    events: string[],
    only: string[],
): void {
    let subscribed: string | null = null;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const reload = () => {
        clearTimeout(timer);
        timer = setTimeout(() => router.reload({ only }), 250);
    };

    onMounted(() => {
        const name = channel();

        if (!name || !echoIsConfigured()) {
            return;
        }

        const private_ = echo().private(name);

        for (const event of events) {
            private_.listen(`.${event}`, reload);
        }

        subscribed = name;
    });

    onUnmounted(() => {
        clearTimeout(timer);

        if (subscribed) {
            for (const event of events) {
                echo().private(subscribed).stopListening(`.${event}`, reload);
            }
        }
    });
}

/**
 * Reload the given props when a notification is broadcast to this user.
 */
export function useLiveNotifications(
    userId: () => number | null,
    only: string[],
): void {
    let channel: string | null = null;
    const reload = () => router.reload({ only });

    onMounted(() => {
        const id = userId();

        if (id === null || !echoIsConfigured()) {
            return;
        }

        channel = `App.Models.User.${id}`;
        echo().private(channel).notification(reload);
    });

    onUnmounted(() => {
        if (channel) {
            echo().private(channel).stopListeningForNotification(reload);
        }
    });
}
