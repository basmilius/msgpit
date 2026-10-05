import { useTranslation } from "react-i18next";
import { Check, X } from "lucide-react";
import { Button, FormError, Pill, SectionLabel, useAsyncAction } from "@basmilius/desktop-ui";
import { formatMoment } from "@basmilius/desktop-ui/format";
import { request, type Detail } from "./api";
import { CodeBlock } from "./components/CodeBlock";
import { statusTone } from "./model";

export function DeliveryPanel({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    const action = useAsyncAction();
    if (!message.deliveryReportsSupported) return null;
    const report = (status: string): void => {
        void action.run(async () => {
            await request(`/api/messages/${message.id}/dlr`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ status }),
            });
        });
    };
    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <SectionLabel>{t("delivery.title")}</SectionLabel>
                <p className="text-xs text-text-muted">
                    {message.callbackConfigured
                        ? "Calls the configured application webhook and records its response."
                        : "No webhook is configured, so only the status changes. Configure MSGPIT_SPRYNG_DLR_URL in the container environment."}
                </p>
            </div>
            <div className="flex gap-2">
                <Button
                    variant="secondary"
                    disabled={action.busy}
                    onClick={() => report("delivered")}
                >
                    <Check size={14} />
                    {t("delivery.delivered")}
                </Button>
                <Button
                    variant="danger-outline"
                    disabled={action.busy}
                    onClick={() => report("failed")}
                >
                    <X size={14} />
                    {t("delivery.failed")}
                </Button>
            </div>
            {action.failure && <FormError>{action.failure}</FormError>}
            {message.deliveryReports.length > 0 && (
                <div className="flex flex-col gap-3">
                    <SectionLabel>{t("delivery.reports")}</SectionLabel>
                    {message.deliveryReports.map((entry, index) => (
                        <div
                            key={`${entry.sentAt}:${index}`}
                            className="flex flex-col gap-1.5 rounded-lg border border-border p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                <Pill tone={entry.status === "delivered" ? "idle" : "error"}>
                                    {t(`status.${entry.status}`)}
                                </Pill>
                                <span className="min-w-0 grow truncate font-mono text-text-muted">
                                    {entry.url}
                                </span>
                                <Pill tone={statusTone(entry.responseStatus)}>
                                    {entry.responseStatus === null
                                        ? t("delivery.noResponse")
                                        : t("delivery.response", { status: entry.responseStatus })}
                                </Pill>
                                <span className="text-text-faint tabular-nums">
                                    {formatMoment(new Date(entry.sentAt))}
                                </span>
                            </div>
                            <CodeBlock label={t("delivery.request")} wrap>
                                {entry.requestBody}
                            </CodeBlock>
                            {entry.responseBody && (
                                <CodeBlock label={t("delivery.answer")} wrap>
                                    {entry.responseBody}
                                </CodeBlock>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
