import { useEffect, useState } from "react";
import type { Message } from "./api";

function saved(): boolean {
    try {
        return localStorage.getItem("msgpit.notifications") === "on";
    } catch {
        return false;
    }
}
export function useNotifications(select: (id: string) => void) {
    const available = "Notification" in window && window.isSecureContext;
    const [wanted, setWanted] = useState(saved);
    const [permission, setPermission] = useState(available ? Notification.permission : "denied");
    const active = available && wanted && permission === "granted";
    useEffect(() => {
        try {
            localStorage.setItem("msgpit.notifications", wanted ? "on" : "off");
        } catch {
            /* Storage may be unavailable in a private window. */
        }
    }, [wanted]);
    useEffect(() => {
        let timer: number | undefined;
        const queued: Message[] = [];
        function received(event: Event) {
            if (!active || !document.hidden) return;
            queued.push((event as CustomEvent<Message>).detail);
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                const messages = queued.splice(0);
                const single = messages.length === 1 ? messages[0] : null;
                try {
                    const notification = new Notification(
                        single
                            ? `${single.provider} to ${single.to}`
                            : `${messages.length} new messages`,
                        {
                            body: single
                                ? single.body.slice(0, 250)
                                : messages
                                      .map((m) => m.to)
                                      .join(", ")
                                      .slice(0, 250),
                            tag: "msgpit",
                        },
                    );
                    notification.onclick = () => {
                        window.focus();
                        notification.close();
                        if (single) select(single.id);
                    };
                } catch {
                    setWanted(false);
                }
            }, 400);
        }
        window.addEventListener("msgpit:message", received);
        return () => {
            window.removeEventListener("msgpit:message", received);
            window.clearTimeout(timer);
        };
    }, [active, select]);
    async function toggle() {
        if (!available || permission === "denied") return;
        if (permission === "default") {
            const answer = await Notification.requestPermission();
            setPermission(answer);
            setWanted(answer === "granted");
        } else setWanted(!wanted);
    }
    return {
        active,
        toggle,
        disabled: !available || permission === "denied",
        label: !available
            ? "Notifications require HTTPS or localhost"
            : permission === "denied"
              ? "Notifications are blocked by your browser"
              : active
                ? "Turn off notifications"
                : "Turn on notifications",
    };
}
