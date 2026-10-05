import { useCallback, useEffect, useRef, useState } from "react";
import { request, type Detail, type Message } from "./api";

export function useInbox(channel: string, recipient: string) {
    const [messages, setMessages] = useState<Message[]>([]);
    const [unread, setUnread] = useState(0);
    const [scenario, setScenario] = useState("");
    const [status, setStatus] = useState<"connecting" | "live" | "reconnecting">("connecting");
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [revision, setRevision] = useState(0);
    const pending = useRef<AbortController | null>(null);
    const refreshRef = useRef<() => Promise<void>>(async () => {});

    const refresh = useCallback(async () => {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        const query = new URLSearchParams();
        if (channel) query.set("channel", channel);
        if (recipient) query.set("to", recipient);
        try {
            const result = await request<{
                messages: Message[];
                unread: number;
                scenario: string | null;
            }>(`/api/messages?${query}`, { signal: controller.signal });
            setMessages(result.messages);
            setUnread(result.unread);
            setScenario(result.scenario ?? "");
            setError(null);
            setRevision((value) => value + 1);
        } catch (error) {
            if (!controller.signal.aborted) setError((error as Error).message);
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }, [channel, recipient]);

    useEffect(() => {
        refreshRef.current = refresh;
        setLoading(true);
        void refresh();
        return () => pending.current?.abort();
    }, [refresh]);

    useEffect(() => {
        let burst: number | undefined;
        const started = Date.now();
        const notified = new Set<string>();
        const timer = window.setInterval(() => void refreshRef.current(), 15_000);
        const stream = new EventSource("/api/stream");
        stream.onopen = () => {
            setStatus("live");
            void refreshRef.current();
        };
        stream.onerror = () => setStatus("reconnecting");
        stream.onmessage = (event) => {
            try {
                const payload = JSON.parse(event.data);
                if (
                    payload.type === "message" &&
                    Date.parse(payload.message.createdAt) >= started &&
                    !notified.has(payload.message.id)
                ) {
                    notified.add(payload.message.id);
                    if (notified.size > 2000) notified.delete(notified.values().next().value!);
                    window.dispatchEvent(
                        new CustomEvent("msgpit:message", { detail: payload.message }),
                    );
                }
            } catch {
                /* A malformed event still triggers a reload from the authoritative inbox. */
            }

            window.clearTimeout(burst);
            burst = window.setTimeout(() => void refreshRef.current(), 80);
        };
        return () => {
            stream.close();
            window.clearInterval(timer);
            window.clearTimeout(burst);
            pending.current?.abort();
        };
    }, []);

    useEffect(() => {
        document.title = unread ? `(${unread}) msgpit` : "msgpit";
    }, [unread]);
    return { messages, unread, scenario, status, error, setError, loading, refresh, revision };
}

export function useMessage(id: string | null, revision: number) {
    const [detail, setDetail] = useState<Detail | null>(null);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        setDetail((previous) => (previous?.id === id ? previous : null));
        setError(null);
        if (!id) return;
        const controller = new AbortController();
        void request<Detail>(`/api/messages/${id}`, { signal: controller.signal })
            .then(async (detail) => {
                if (!detail.read)
                    await request(`/api/messages/${id}/read`, {
                        method: "POST",
                        signal: controller.signal,
                    });
                if (!controller.signal.aborted) setDetail(detail);
            })
            .catch((error) => {
                if (!controller.signal.aborted) setError(error.message);
            });
        return () => controller.abort();
    }, [id, revision]);
    return { detail, error };
}
