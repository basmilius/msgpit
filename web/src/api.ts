export interface Message {
    id: string;
    batchId: string;
    provider: string;
    channel: string;
    from: string | null;
    to: string;
    body: string;
    meta: { subject?: string; imported?: boolean; filename?: string; [name: string]: unknown };
    providerRef: string | null;
    status: string;
    encoding: string | null;
    segments: number | null;
    characters: number | null;
    units: number | null;
    ucs2Offsets: number[];
    createdAt: string;
    readAt: string | null;
    read: boolean;
}

export interface Detail extends Message {
    rawRequest: string;
    html?: string;
    deliveryReports: unknown[];
    headerList: { name: string; value: string }[];
    parts: {
        id: string;
        contentType: string;
        filename: string | null;
        contentId: string | null;
        size: number;
    }[];
}

export async function request<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await fetch(path, init);
    if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.error ?? `Request failed (${response.status}).`);
    }
    return response.status === 204 ? (undefined as T) : response.json();
}
