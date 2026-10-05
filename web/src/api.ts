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

export interface Part {
    id: string;
    contentType: string;
    filename: string | null;
    contentId: string | null;
    size: number;
    disposition: "body" | "inline" | "attachment" | "source";
}
export interface LinkCheckResult {
    url: string;
    kind: string;
    status: number | null;
    reason: string | null;
    redirect: string | null;
}
export interface Finding {
    id: string;
    section: string;
    title: string;
    status: "pass" | "warn" | "fail" | "skip";
    penalty: number;
    explanation: string;
    evidence: string[];
}
export interface Report {
    score: number;
    max: number;
    applicable: number;
    skipped: number;
    passed: number;
    findings: Finding[];
}
export interface DeliveryReport {
    sentAt: string;
    status: string;
    url: string;
    responseStatus: number | null;
    requestBody: string;
    responseBody: string | null;
}
export interface Detail extends Message {
    rawRequest: string;
    html?: string;
    text?: string | null;
    deliveryReports: DeliveryReport[];
    deliveryReportsSupported: boolean;
    callbackConfigured: boolean;
    headerList: { name: string; value: string }[];
    parts: Part[];
    sourcePart?: Part | null;
    report?: Report;
    dnsEnabled: boolean;
    spamConfigured: boolean;
    links?: { url: string; kind: string }[];
    spam?: {
        spam: boolean;
        score: number;
        threshold: number;
        rules: { name: string; points: number; description: string }[];
    } | null;
    htmlCheck?: {
        supported: number;
        partial: number;
        unsupported: number;
        features: number;
        dataUpdated: string | null;
        warnings: {
            slug: string;
            title: string;
            category: string;
            supported: number;
            partial: number;
            unsupported: number;
        }[];
    } | null;
}

export async function request<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await fetch(path, init);
    if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.error ?? `Request failed (${response.status}).`);
    }
    return response.status === 204 ? (undefined as T) : response.json();
}
