import assert from "node:assert/strict";
import { chromium } from "playwright";
const base = process.env.MSGPIT_URL ?? "http://localhost:18080";
const tag = `browser-${Date.now()}`;
const browser = await chromium.launch();
const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    colorScheme: "light",
});
const page = await context.newPage();
const errors = [];
page.on("pageerror", (error) => errors.push(error.message));
let remoteRequests = 0;
await page.route("https://blocked-image.test/**", (route) => {
    remoteRequests++;
    return route.abort();
});
const email = `From: Sender <sender@example.test>\r\nTo: ${tag}@example.test\r\nSubject: ${tag}\r\nDate: Mon, 14 Sep 2026 11:00:00 +0200\r\nMessage-ID: <${tag}@example.test>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary="outer"\r\n\r\n--outer\r\nContent-Type: multipart/alternative; boundary="inner"\r\n\r\n--inner\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHello ${tag}.\r\n--inner\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<html><body><h1>Hello ${tag}</h1><p><a href="https://example.test/link">Visit</a></p><img src="https://blocked-image.test/logo.png"><script>parent.postMessage('unsafe','*')</script></body></html>\r\n--inner--\r\n--outer\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Disposition: attachment; filename="sample.txt"\r\n\r\nExact attachment bytes.\r\n--outer--\r\n`;
try {
    assert.equal(
        (
            await context.request.post(`${base}/api/messages/import`, {
                headers: { "Content-Type": "message/rfc822", "X-Msgpit-Filename": `${tag}.eml` },
                data: email,
            })
        ).status(),
        201,
    );
    const sms = await context.request.post(`${base}/spryng/v2/messages`, {
        headers: { "X-Api-Key": "development" },
        data: {
            accountReference: "SPNL0000000",
            channel: "SMS",
            from: "Acme",
            body: { text: `${tag} 👋` },
            recipients: [{ msisdn: "+31612345678" }],
        },
    });
    assert.equal(sms.status(), 202);
    await page.goto(base);
    const list = page.getByRole("navigation", { name: "Messages", exact: true });
    await list.getByRole("button").filter({ hasText: tag }).first().waitFor();
    assert.equal(
        await page.locator(".app-sidebar").evaluate((el) => el.getBoundingClientRect().width),
        248,
    );
    assert.equal(
        await page.locator(".message-list").evaluate((el) => el.getBoundingClientRect().width),
        340,
    );
    await list
        .getByRole("button")
        .filter({ hasText: `${tag} 👋` })
        .first()
        .click();
    await page.getByRole("button", { name: "Mark delivered", exact: true }).click();
    await page.getByText("Delivered", { exact: true }).first().waitFor();
    assert.ok((await page.locator("mark").count()) > 0);
    await list
        .getByRole("button")
        .filter({ hasText: tag })
        .filter({ hasNotText: "👋" })
        .first()
        .click();
    await page
        .frameLocator("iframe")
        .getByRole("heading", { name: `Hello ${tag}` })
        .waitFor();
    assert.equal(remoteRequests, 0);
    assert.equal(await page.locator("iframe").getAttribute("sandbox"), "");
    await page.getByRole("tab", { name: "Text", exact: true }).click();
    await page.getByText(`Hello ${tag}.`, { exact: true }).last().waitFor();
    await page.getByRole("tab", { name: "Headers", exact: true }).click();
    await page.getByText("Message-ID", { exact: true }).waitFor();
    await page.getByRole("tab", { name: "Source", exact: true }).click();
    assert.ok((await page.locator(".raw-headers").count()) > 0);
    await page.getByRole("radio", { name: "HTML as sent", exact: true }).click();
    assert.ok((await page.locator(".tok-tag").count()) > 0);
    await page.getByRole("tab", { name: "HTML check", exact: true }).click();
    await page.getByText("Feature", { exact: true }).waitFor();
    await page.getByRole("tab", { name: "Links", exact: false }).click();
    await page.getByText("https://example.test/link", { exact: true }).waitFor();
    await page.route("**/api/messages/*/links", (route) =>
        route.fulfill({
            json: {
                links: [
                    {
                        url: "https://example.test/link",
                        kind: "link",
                        status: 200,
                        reason: null,
                        redirect: null,
                    },
                ],
            },
        }),
    );
    await page.getByRole("button", { name: "Check links", exact: true }).click();
    await page.getByText("200", { exact: true }).waitFor();
    await page.getByRole("tab", { name: "Spam", exact: true }).click();
    await page
        .getByText(/SpamAssassin/)
        .last()
        .waitFor();
    await page.getByRole("tab", { name: "Deliverability", exact: true }).click();
    await page.getByText(/applicable/).waitFor();
    await page.route("**/api/messages/*/authentication", (route) =>
        route.fulfill({
            json: {
                report: {
                    score: 9,
                    max: 10,
                    passed: 1,
                    applicable: 1,
                    skipped: 0,
                    findings: [
                        {
                            id: "spf",
                            section: "authentication",
                            title: "SPF passes",
                            status: "pass",
                            penalty: 0,
                            explanation: "Local fixture",
                            evidence: ["8.8.8.8"],
                        },
                    ],
                },
                lookups: 1,
            },
        }),
    );
    await page.getByRole("button", { name: "Check sender DNS", exact: true }).click();
    await page.getByText("SPF passes", { exact: true }).waitFor();
    await page.getByRole("tab", { name: "Attachments", exact: false }).click();
    await page.getByText("sample.txt", { exact: true }).waitFor();
    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page.getByRole("link", { name: "Download", exact: true }).click(),
    ]);
    assert.equal(download.suggestedFilename(), "sample.txt");
    await page.getByRole("button", { name: "Documentation", exact: true }).click();
    await page
        .getByRole("navigation", { name: "Documentation chapters" })
        .getByRole("button")
        .first()
        .waitFor();
    assert.equal(
        await page
            .getByRole("navigation", { name: "Documentation chapters" })
            .getByRole("button")
            .count(),
        8,
    );
    await page.getByRole("button", { name: "Back to messages", exact: true }).click();
    await page.getByRole("button", { name: "Import .eml", exact: true }).click();
    await page.locator("input[type=file]").setInputFiles([
        {
            name: `${tag}-a.eml`,
            mimeType: "message/rfc822",
            buffer: Buffer.from(email.replaceAll(tag, `${tag}-a`)),
        },
        {
            name: `${tag}-b.eml`,
            mimeType: "message/rfc822",
            buffer: Buffer.from(email.replaceAll(tag, `${tag}-b`)),
        },
    ]);
    await list
        .getByRole("button")
        .filter({ hasText: `${tag}-b` })
        .waitFor();
    await list
        .getByRole("button")
        .filter({ hasText: `${tag}-a` })
        .waitFor();
    await page.getByRole("button", { name: "Settings", exact: true }).click();
    await page.getByRole("radio", { name: "Dark", exact: true }).click();
    await page.waitForFunction(() => document.documentElement.dataset.theme === "dark");
    await page.keyboard.press("Escape");
    await page.reload();
    await page.waitForFunction(() => document.documentElement.dataset.theme === "dark");
    await page
        .getByRole("textbox", { name: "Filter by recipient" })
        .fill("does-not-exist@example.test");
    await page.getByText("Nothing matches this filter.").waitFor();
    await page.getByRole("textbox", { name: "Filter by recipient" }).fill("");
    const count = (await (await context.request.get(`${base}/api/messages`)).json()).messages
        .length;
    await page.getByRole("button", { name: "Clear messages", exact: true }).click();
    await page.getByRole("dialog").waitFor();
    await page.keyboard.press("Escape");
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    assert.equal(
        (await (await context.request.get(`${base}/api/messages`)).json()).messages.length,
        count,
    );
    await page.setViewportSize({ width: 390, height: 844 });
    await list.getByRole("button").first().click();
    await page.getByRole("button", { name: "Back", exact: true }).click();
    await list.waitFor({ state: "visible" });
    assert.deepEqual(errors, []);
    console.log(
        `PASS: Command Center layout, safe preview, all mail tabs, DLR, docs, multi-import, filters, themes, mobile and cancel-clear (${tag})`,
    );
} finally {
    await browser.close();
}
