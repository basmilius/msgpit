import { createRoot } from "react-dom/client";
import i18next from "i18next";
import { ErrorBoundary, UIProvider } from "@basmilius/desktop-ui";
import "@fontsource-variable/geist";
import "./styles.css";
import { App } from "./App";
import { ThemeProvider } from "./theme";
import messages from "./locales/messages.json";

await i18next.init({
    lng: "en",
    fallbackLng: "en",
    resources: { en: { messages } },
    interpolation: { escapeValue: false },
});
createRoot(document.getElementById("root")!).render(
    <UIProvider i18n={i18next}>
        <ErrorBoundary label="The inbox could not be displayed." reload>
            <ThemeProvider>
                <App />
            </ThemeProvider>
        </ErrorBoundary>
    </UIProvider>,
);
