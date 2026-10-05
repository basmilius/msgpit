import { useTranslation } from "react-i18next";
import { ArrowLeft } from "lucide-react";
import { Button, EmptyState, Pill, Spinner } from "@basmilius/desktop-ui";
import { formatDayClock } from "@basmilius/desktop-ui/format";
import type { Detail } from "./api";
import { MailView } from "./MailView";
import { SmsView } from "./SmsView";
import { titleOf } from "./model";

const STATUS_TONE = { accepted: "muted", delivered: "idle", failed: "error" } as const;

export function MessageDetail({
    detail,
    selectedId,
    error,
    close,
}: {
    detail: Detail | null;
    selectedId: string | null;
    error: string | null;
    close(): void;
}) {
    const { t } = useTranslation("messages");
    if (!selectedId)
        return (
            <div className="grid h-full place-items-center">
                <EmptyState>{t("detail.pick")}</EmptyState>
            </div>
        );
    if (error)
        return (
            <div className="grid h-full place-items-center">
                <EmptyState>{error}</EmptyState>
            </div>
        );
    if (!detail || detail.id !== selectedId)
        return (
            <div className="grid h-full place-items-center">
                <Spinner size={20} />
            </div>
        );
    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex shrink-0 flex-col gap-1 border-b border-border bg-surface px-6 py-3">
                <div className="flex items-center gap-3">
                    <Button onClick={close} className="min-[761px]:hidden">
                        <ArrowLeft size={14} />
                        Back
                    </Button>
                    <h2 className="min-w-0 grow truncate text-base font-medium text-text">
                        {titleOf(detail)}
                    </h2>
                    {detail.meta.imported && <Pill>Imported</Pill>}
                    <Pill tone={STATUS_TONE[detail.status as keyof typeof STATUS_TONE] ?? "muted"}>
                        {t(`status.${detail.status}`)}
                    </Pill>
                </div>
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
                    {detail.from && (
                        <span>
                            {t("detail.from")} <span className="text-text">{detail.from}</span>
                        </span>
                    )}
                    <span>
                        {t("detail.to")} <span className="text-text">{detail.to}</span>
                    </span>
                    <span>{formatDayClock(new Date(detail.createdAt))}</span>
                </p>
            </header>
            <div className="flex min-h-0 grow flex-col overflow-auto">
                {detail.channel === "email" ? (
                    <MailView key={detail.id} message={detail} />
                ) : (
                    <SmsView message={detail} />
                )}
            </div>
        </div>
    );
}
