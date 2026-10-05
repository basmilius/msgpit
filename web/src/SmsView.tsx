import clsx from "clsx";
import { useTranslation } from "react-i18next";
import { SectionLabel } from "@basmilius/desktop-ui";
import type { Detail } from "./api";
import { DeliveryPanel } from "./DeliveryPanel";
import { CodeBlock } from "./components/CodeBlock";
import { KeyValueList } from "./components/KeyValueList";
import { bodyRuns, segmentBars } from "./model";

export function SmsView({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    const bars = segmentBars(message);
    const unit = t(message.encoding === "UCS-2" ? "sms.codeUnits" : "sms.septets");
    const flagged = message.ucs2Offsets.length > 0;
    return (
        <div className="flex flex-col gap-6 p-6">
            <section className="flex flex-col gap-2">
                <SectionLabel>{t("sms.body")}</SectionLabel>
                <p className="rounded-lg border border-border bg-surface p-4 text-sm break-words whitespace-pre-wrap text-text">
                    {bodyRuns(message.body, message.ucs2Offsets).map((run, index) =>
                        run.flagged ? (
                            <mark
                                key={index}
                                className="rounded-sm bg-status-needs-you/25 text-text"
                            >
                                {run.text}
                            </mark>
                        ) : (
                            <span key={index}>{run.text}</span>
                        ),
                    )}
                </p>
                {flagged && <p className="text-xs text-text-muted">{t("sms.forced")}</p>}
            </section>
            <section className="flex flex-col gap-2">
                <SectionLabel>{t("sms.segments")}</SectionLabel>
                <p className="text-sm text-text">
                    {t(message.segments === 1 ? "sms.summaryOne" : "sms.summary", {
                        encoding: message.encoding,
                        count: message.segments,
                        units: message.units,
                        unit,
                        characters: message.characters,
                    })}
                </p>
                <ol className="flex flex-col gap-1.5">
                    {bars.map((bar, index) => (
                        <li key={index} className="flex items-center gap-3 text-xs text-text-muted">
                            <span className="w-20 shrink-0">
                                {t("sms.segmentLabel", { number: index + 1 })}
                            </span>
                            <span
                                className="h-2 grow overflow-hidden rounded-full bg-surface-sunken"
                                role="img"
                                aria-label={t("sms.units", {
                                    used: bar.used,
                                    capacity: bar.capacity,
                                    unit,
                                })}
                            >
                                <span
                                    className={clsx(
                                        "block h-full rounded-full",
                                        flagged ? "bg-status-needs-you" : "bg-text-muted",
                                    )}
                                    style={{ width: `${bar.ratio * 100}%` }}
                                />
                            </span>
                            <span className="w-40 shrink-0 text-right whitespace-nowrap tabular-nums">
                                {t("sms.units", { used: bar.used, capacity: bar.capacity, unit })}
                            </span>
                        </li>
                    ))}
                </ol>
            </section>
            <DeliveryPanel message={message} />
            <KeyValueList
                rows={[
                    { key: t("sms.encoding"), value: message.encoding },
                    { key: t("detail.provider"), value: message.provider },
                    ...Object.entries(message.meta)
                        .filter(
                            ([, value]) => typeof value === "string" || typeof value === "number",
                        )
                        .map(([key, value]) => ({ key, value: String(value), mono: true })),
                ]}
            />
            <section className="flex flex-col gap-2">
                <SectionLabel>{t("raw.title")}</SectionLabel>
                <CodeBlock label={t("raw.title")} copyLabel={t("detail.copy")} wrap>
                    {message.rawRequest}
                </CodeBlock>
                <p className="text-xs text-text-faint">{t("raw.masked")}</p>
            </section>
        </div>
    );
}
