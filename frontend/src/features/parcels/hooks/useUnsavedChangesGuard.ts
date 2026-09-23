import { useCallback, useEffect, useState } from 'react';

/**
 * Unsaved-changes guard for the parcel editor (frontend.md §7).
 *
 * The app uses a declarative `<BrowserRouter>`, so React Router's `useBlocker`
 * (data-router only) is unavailable. Instead this intercepts internal anchor
 * clicks in the capture phase and blocks them while edits are pending, showing
 * a "leave anyway?" modal. It also installs a `beforeunload` handler so a
 * refresh / tab close prompts too.
 *
 * `confirmLeave` must be called once the user opts to discard; it clears the
 * dirty flag before the Interrupted click lets the browser proceed.
 */

export function useUnsavedChangesGuard(dirty: boolean) {
    const [blockedHref, setBlockedHref] = useState<string | null>(null);

    useEffect(() => {
        if (!dirty) {
            return;
        }
        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    // Intercept left-click on same-tab internal anchors while dirty.
    useEffect(() => {
        if (!dirty) {
            return;
        }
        const onClickCapture = (event: MouseEvent) => {
            const target = event.target as Element | null;
            const anchor = target?.closest?.('a[href]');
            if (!anchor) {
                return;
            }
            const href = anchor.getAttribute('href') ?? '';
            const sameTab = !event.defaultPrevented
                && !event.metaKey
                && !event.ctrlKey
                && !event.shiftKey
                && !event.altKey
                && event.button === 0;
            const internal = href.startsWith('/') && !href.startsWith('//');
            if (!sameTab || !internal) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            setBlockedHref(href);
        };
        document.addEventListener('click', onClickCapture, true);
        return () => document.removeEventListener('click', onClickCapture, true);
    }, [dirty]);

    const reset = useCallback(() => setBlockedHref(null), []);
    return { blockedHref, reset };
}