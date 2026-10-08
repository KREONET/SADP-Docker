// Marks each Livewire request as user activity or background traffic for the
// server-side idle timeout (App\Http\Middleware\EnforceSessionTimeout).
// wire:poll, Echo-triggered refreshes and other background requests carry no
// user gesture, so they must not keep an unattended session alive.
export const USER_ACTIVITY_HEADER = 'X-User-Activity';

const USER_ACTIVITY_EVENTS = ['pointerdown', 'click', 'submit', 'keydown', 'input', 'change'];

export function registerSessionActivityHeader(Livewire, documentObject = document, { now = Date.now } = {}) {
    let lastGestureAt = Number.NEGATIVE_INFINITY;
    let lastReportedGestureAt = Number.NEGATIVE_INFINITY;

    const markUserGesture = (event) => {
        if (event.isTrusted) {
            lastGestureAt = now();
        }
    };

    USER_ACTIVITY_EVENTS.forEach((eventName) => {
        documentObject.addEventListener(eventName, markUserGesture, true);
    });

    Livewire.hook('request', ({ options }) => {
        // A gesture counts once: the first request after it reports activity,
        // later background requests report none until the next gesture.
        const isUserActivity = lastGestureAt > lastReportedGestureAt;
        if (isUserActivity) {
            lastReportedGestureAt = lastGestureAt;
        }

        options.headers = options.headers ?? {};
        options.headers[USER_ACTIVITY_HEADER] = isUserActivity ? '1' : '0';
    });
}

document.addEventListener('livewire:init', () => {
    registerSessionActivityHeader(window.Livewire);
});
