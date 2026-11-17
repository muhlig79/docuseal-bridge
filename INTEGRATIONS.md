# Formularintegrationen – Beta 0.8.0

Unter **Einstellungen → DocuSeal Bridge** je Zuordnung das Formularsystem wählen: Elementor Pro, Contact Form 7 oder Gravity Forms. Anschließend Formular, DocuSeal-Vorlage, E-Mail-Feld und Dokumentfelder zuordnen. Bestehende Elementor-Zuordnungen werden weiterhin als Elementor behandelt.

- **Elementor Pro:** Zuordnung über den Formularnamen und die Feld-IDs.
- **Contact Form 7:** Zuordnung über die numerische Formular-ID. Feld-IDs entsprechen den Namen der CF7-Form-Tags, z. B. `your-email`. Die DocuSeal-Anfrage erfolgt vor dem Mailversand; bei API-Fehler wird der Mailversand nicht automatisch gestoppt.
- **Gravity Forms:** Zuordnung über die numerische Formular-ID. Feld-IDs sind die numerischen Gravity-Forms-Feldnummern, z. B. `3`. Die DocuSeal-Anfrage erfolgt nach dem Speichern der Einsendung. Bei API-Fehler wird die Einsendung nicht zurückgerollt.

## Einschränkungen / vor öffentlichem Release prüfen

- Diese Version ist ein **Beta-Build**, nicht WordPress.org-freigegeben. Live-Tests für alle drei Systeme und DocuSeal sind noch ausstehend.
- CF7-Dateiuploads und Gravity-Forms-Dateiuploads werden nicht an DocuSeal angehängt; nur Textwerte werden übertragen.
- Mehrteilige Gravity-Forms-Felder werden für die Zuordnung als zusammengefasster Text bereitgestellt.
- Fehler bei CF7/GF erscheinen nur im PHP-Fehlerprotokoll (ohne personenbezogene Inhalte); eine Admin-Fehlerliste und Retry-Queue fehlen.
- Die bisherige Strong-her-Vertragsnummerierung ist noch fest codiert. Vor der allgemeinen Veröffentlichung muss sie konfigurierbar werden.
- Die aktuelle Duplikat- und Timeout-Behandlung ist nicht ausreichend für garantierte Exactly-once-Übertragung. Für produktive Vertragsabschlüsse ist ein persistentes Auftragsprotokoll mit Statusabgleich erforderlich.
- Backup der WordPress-Datenbank und Plugin-Dateien vor Installation erstellen und zunächst in einer Staging-Umgebung testen.
