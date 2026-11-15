/** JSON calls to the Laravel app using the session cookie + XSRF token. */
export async function api<T = unknown>(method: string, url: string, body?: unknown): Promise<T> {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(isForm ? {} : { 'Content-Type': 'application/json' }), 'X-XSRF-TOKEN': xsrf },
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(data.message ?? `Request failed (${response.status})`);
    }

    return data as T;
}
