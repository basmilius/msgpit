import { useState, type RefObject } from "react";
import { useTranslation } from "react-i18next";
import { CheckCheck, Plug, Search, Trash2, Upload } from "lucide-react";
import { IconButton, Input, Pill, PromptDialog, Segmented, Tooltip } from "@basmilius/desktop-ui";

export function MessagesToolbar({
    channel,
    search,
    unread,
    count,
    scenario,
    setupOpen,
    live,
    busy,
    searchRef,
    onChannel,
    onSearch,
    onSetup,
    onReadAll,
    onClear,
    onImport,
}: {
    channel: string;
    search: string;
    unread: number;
    count: number;
    scenario: string;
    setupOpen: boolean;
    live: boolean;
    busy: boolean;
    searchRef: RefObject<HTMLInputElement | null>;
    onChannel(value: string): void;
    onSearch(value: string): void;
    onSetup(): void;
    onReadAll(): void;
    onClear(): Promise<void>;
    onImport(): void;
}) {
    const { t } = useTranslation("messages");
    const [confirming, setConfirming] = useState(false);
    return (
        <div className="messages-toolbar flex h-12 shrink-0 items-center gap-2 border-b border-border bg-surface px-3">
            <Segmented
                label={t("toolbar.channel")}
                value={channel || "all"}
                onValueChange={(next) => onChannel(next === "all" ? "" : next)}
                options={[
                    { id: "all", label: t("channels.all") },
                    { id: "email", label: t("channels.email") },
                    { id: "sms", label: t("channels.sms") },
                ]}
            />
            <Input
                ref={searchRef}
                size="sm"
                icon={Search}
                aria-label={t("toolbar.search")}
                placeholder={t("toolbar.search")}
                className="w-48"
                value={search}
                onChange={(event) => onSearch(event.target.value)}
            />
            <span className="grow" />
            {scenario && <Pill tone="needsYou">{t(`scenario.${scenario}`)}</Pill>}
            <div className="listener-pills flex items-center gap-2">
                <Tooltip
                    label={live ? `Listening on ${location.host}` : "Reconnecting to the container"}
                >
                    <Pill tone={live ? "idle" : "needsYou"} mono>
                        HTTP {location.port || (location.protocol === "https:" ? "443" : "80")}
                    </Pill>
                </Tooltip>
                <Tooltip label="SMTP listener inside the container">
                    <Pill tone={live ? "idle" : "muted"} mono>
                        SMTP 1025
                    </Pill>
                </Tooltip>
            </div>
            <IconButton icon={Upload} label="Import .eml" disabled={busy} onClick={onImport} />
            <IconButton
                icon={Plug}
                label={t("toolbar.setup")}
                active={setupOpen}
                onClick={onSetup}
            />
            <IconButton
                icon={CheckCheck}
                label={t("toolbar.markAllRead")}
                disabled={busy || unread === 0}
                onClick={onReadAll}
            />
            <IconButton
                icon={Trash2}
                label={t("toolbar.clear")}
                disabled={busy || count === 0}
                onClick={() => setConfirming(true)}
            />
            <PromptDialog
                open={confirming}
                danger
                title={t("toolbar.clear")}
                description={t("toolbar.clearConfirm", { scope: "this container" })}
                confirmLabel={t("toolbar.clear")}
                onConfirm={async () => {
                    await onClear();
                    setConfirming(false);
                }}
                onOpenChange={setConfirming}
            />
        </div>
    );
}
