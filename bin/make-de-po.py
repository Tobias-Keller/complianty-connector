#!/usr/bin/env python3
"""Builds translations/complianty-connector-de_DE.po from the .pot and the German texts below (formal "Sie").

The file is not shipped in the plugin. WordPress.org delivers translations from translate.wordpress.org,
so this file is the source to import there once the plugin is published.
Usage: python3 bin/make-de-po.py
Strings missing in the table are reported and left untranslated.
"""
import re
import sys

POT = "languages/complianty-connector.pot"
OUT = "translations/complianty-connector-de_DE.po"

T = {
"Complianty Connector": "Complianty Connector",
"Shows the status of your Complianty website scan in the WordPress admin and outputs your privacy policy from your Complianty account.": "Zeigt den Status Ihres Complianty-Website-Scans im WordPress-Admin und gibt Ihre Datenschutzerklärung aus Ihrem Complianty-Konto aus.",
"Complianty Connector: The installation is incomplete. Please reinstall the plugin.": "Complianty Connector: Die Installation ist unvollständig. Bitte installieren Sie das Plugin neu.",
"Complianty status": "Complianty-Status",
"Details and scan": "Details und Scan",
"Complianty dashboard": "Complianty-Dashboard",
"The connection was removed. The token and the cached data were deleted from this site.": "Die Verbindung wurde entfernt. Das Token und die zwischengespeicherten Daten wurden von dieser Website gelöscht.",
"The status was updated.": "Der Status wurde aktualisiert.",
"The privacy policy was checked.": "Die Datenschutzerklärung wurde geprüft.",
"The token was saved.": "Das Token wurde gespeichert.",
"The token was saved. The first status check runs when you open this page.": "Das Token wurde gespeichert. Die erste Statusabfrage erfolgt beim Öffnen dieser Seite.",
"This is not a valid connection token. Copy the token from your Complianty account without spaces.": "Das ist kein gültiges Verbindungstoken. Kopieren Sie das Token aus Ihrem Complianty-Konto ohne Leerzeichen.",
"The token is set in wp-config.php. Remove COMPLIANTY_API_TOKEN there to change it here.": "Das Token ist in der wp-config.php gesetzt. Entfernen Sie dort COMPLIANTY_API_TOKEN, um es hier zu ändern.",
"The token could not be stored securely because PHP libsodium is not available. Set COMPLIANTY_API_TOKEN in wp-config.php instead.": "Das Token konnte nicht sicher gespeichert werden, weil PHP libsodium nicht verfügbar ist. Setzen Sie stattdessen COMPLIANTY_API_TOKEN in der wp-config.php.",
"You are not allowed to access this page.": "Sie dürfen diese Seite nicht aufrufen.",
"JavaScript is needed for scan progress. The other functions work without it.": "Für den Scan-Fortschritt wird JavaScript benötigt. Die anderen Funktionen laufen auch ohne.",
"Connect": "Verbinden",
"Create a connection token for this website in your Complianty account and paste it here. Until you do, this plugin does not contact any external service.": "Erstellen Sie in Ihrem Complianty-Konto ein Verbindungstoken für diese Website und fügen Sie es hier ein. Bis dahin nimmt dieses Plugin keinen Kontakt zu externen Diensten auf.",
"Connection token": "Verbindungstoken",
"Alternative: define the token in wp-config.php. Then nothing is stored in the database.": "Alternative: Definieren Sie das Token in der wp-config.php. Dann wird nichts in der Datenbank gespeichert.",
"You do not have an account yet?": "Sie haben noch kein Konto?",
"Create one at Complianty": "Bei Complianty erstellen",
"Connection": "Verbindung",
"The token cannot be read. It is either invalid or, if it is stored in the database, the WordPress security keys changed. Disconnect and connect again with a new token.": "Das Token kann nicht gelesen werden. Es ist entweder ungültig oder, falls es in der Datenbank liegt, haben sich die WordPress-Sicherheitsschlüssel geändert. Trennen Sie die Verbindung und verbinden Sie sich erneut mit einem neuen Token.",
"Token:": "Token:",
"State:": "Status:",
"The token is provided by wp-config.php. To disconnect, remove COMPLIANTY_API_TOKEN there.": "Das Token kommt aus der wp-config.php. Zum Trennen entfernen Sie dort COMPLIANTY_API_TOKEN.",
"Disconnect": "Trennen",
"Disconnecting deletes the token, the cached status and the stored privacy policy copy from this site.": "Beim Trennen werden das Token, der zwischengespeicherte Status und die gespeicherte Kopie der Datenschutzerklärung von dieser Website gelöscht.",
"Privacy policy": "Datenschutzerklärung",
"A new version is waiting for approval in Complianty. Until then the last approved version stays on your website.": "Eine neue Fassung wartet in Complianty auf Freigabe. Bis dahin bleibt die zuletzt freigegebene Fassung auf Ihrer Website.",
"No privacy policy was found in your Complianty account.": "In Ihrem Complianty-Konto wurde keine Datenschutzerklärung gefunden.",
"The Complianty subscription has ended. The service now provides a notice instead of the policy, and your website shows that notice.": "Das Complianty-Abonnement ist beendet. Der Dienst liefert jetzt einen Hinweis statt der Datenschutzerklärung, und Ihre Website zeigt diesen Hinweis.",
"The stored copy is the notice of the service.": "Die gespeicherte Kopie ist der Hinweis des Dienstes.",
"Approved version from: %s": "Freigegebene Fassung vom: %s",
"Last checked: %s": "Zuletzt geprüft: %s",
"There is no approved privacy policy on this site yet.": "Auf dieser Website gibt es noch keine freigegebene Datenschutzerklärung.",
"Output the privacy policy from your Complianty account with the shortcode or the block \"Privacy policy (Complianty)\".": "Geben Sie die Datenschutzerklärung aus Ihrem Complianty-Konto mit dem Shortcode oder dem Block \"Datenschutzerklärung (Complianty)\" aus.",
"Update privacy policy": "Datenschutzerklärung aktualisieren",
"External services": "Externe Dienste",
"This plugin connects your server to the Complianty service at app.complianty.de.": "Dieses Plugin verbindet Ihren Server mit dem Dienst Complianty unter app.complianty.de.",
"Before you enter a token, nothing is sent. The only exception is the optional free check, which runs only after you press its button and sends the address of this website.": "Bevor Sie ein Token eingeben, wird nichts gesendet. Einzige Ausnahme ist der optionale kostenlose Check, der erst nach Klick auf seinen Button läuft und die Adresse dieser Website sendet.",
"With a token, your server sends the token and the plugin version. No website address, user name or WordPress version is sent.": "Mit Token sendet Ihr Server das Token und die Plugin-Version. Es werden keine Website-Adresse, kein Benutzername und keine WordPress-Version gesendet.",
"Requests happen when you open this page or the dashboard, press Refresh or Scan now, and in the background to keep the privacy policy copy current.": "Anfragen erfolgen, wenn Sie diese Seite oder das Dashboard öffnen, auf Aktualisieren oder Jetzt scannen klicken, und im Hintergrund, um die Kopie der Datenschutzerklärung aktuell zu halten.",
"Visitors of your website never contact Complianty. Your server fetches the data.": "Besucher Ihrer Website nehmen nie Kontakt zu Complianty auf. Ihr Server holt die Daten ab.",
"The token is stored encrypted with libsodium, using a key derived from your WordPress security keys. This protects against leaks of the database alone, not against access to wp-config.php.": "Das Token wird mit libsodium verschlüsselt gespeichert, mit einem aus Ihren WordPress-Sicherheitsschlüsseln abgeleiteten Schlüssel. Das schützt bei einem Datenbank-Leck allein, nicht bei Zugriff auf die wp-config.php.",
"Terms of service": "Allgemeine Geschäftsbedingungen",
"You are not allowed to do this.": "Sie dürfen das nicht ausführen.",
"Invalid request.": "Ungültige Anfrage.",
"unknown": "unbekannt",
"Status of your website": "Status Ihrer Website",
"No status is available yet. Press Refresh to load it.": "Es liegt noch kein Status vor. Klicken Sie auf Aktualisieren, um ihn zu laden.",
"Updated: %s": "Aktualisiert: %s",
"No open findings": "Keine offenen Befunde",
"Gaps found": "Lücken gefunden",
"Critical findings": "Kritische Befunde",
"Not checked yet": "Noch nicht geprüft",
"No scan yet": "Noch kein Scan",
"Ready": "Bereit",
"Waiting to start": "Wartet auf Start",
"Scan running": "Scan läuft",
"Last scan: %1$s. Scan state: %2$s.": "Letzter Scan: %1$s. Scan-Status: %2$s.",
"Critical": "Kritisch",
"Important": "Wichtig",
"Note": "Hinweis",
"Most important open findings": "Wichtigste offene Befunde",
"Refresh": "Aktualisieren",
"Scan now": "Jetzt scannen",
"Open in the Complianty dashboard": "Im Complianty-Dashboard öffnen",
"Check this website for free": "Diese Website kostenlos prüfen",
"The check could not be completed. Please try again later.": "Die Prüfung konnte nicht abgeschlossen werden. Bitte versuchen Sie es später erneut.",
"You can run a short check of this website without an account. It lists the cookie banner and the tools that were found.": "Sie können diese Website ohne Konto kurz prüfen lassen. Die Prüfung nennt den Cookie-Banner und die gefundenen Tools.",
"What is sent:": "Was gesendet wird:",
"Only the address of this website (%s) is sent to the Complianty service, and only when you press the button. No account, no e-mail address and no other data is sent.": "Nur die Adresse dieser Website (%s) wird an den Dienst Complianty gesendet, und zwar nur, wenn Sie auf den Button klicken. Es werden kein Konto, keine E-Mail-Adresse und keine weiteren Daten gesendet.",
"Privacy policy of Complianty": "Datenschutzerklärung von Complianty",
"Check this website now": "Diese Website jetzt prüfen",
"The check is running. This can take a minute.": "Die Prüfung läuft. Das kann eine Minute dauern.",
"Result of the short check": "Ergebnis der Kurzprüfung",
"A cookie banner was detected: %s": "Ein Cookie-Banner wurde erkannt: %s",
"A cookie banner was detected.": "Ein Cookie-Banner wurde erkannt.",
"No cookie banner was detected.": "Es wurde kein Cookie-Banner erkannt.",
"before consent": "vor Einwilligung",
"This is a short automatic check. It lists what was observed and is not a legal assessment.": "Das ist eine kurze automatische Prüfung. Sie nennt, was beobachtet wurde, und ist keine rechtliche Bewertung.",
"Get the full report in Complianty": "Vollständigen Bericht in Complianty ansehen",
"The free check is only available while no token is set.": "Der kostenlose Check ist nur verfügbar, solange kein Token gesetzt ist.",
"No check is running.": "Es läuft keine Prüfung.",
"Tracking": "Tracking",
"Cookie banner": "Cookie-Banner",
"External content": "Externe Inhalte",
"Documentation": "Dokumentation",
"Imprint": "Impressum",
"Accessibility": "Barrierefreiheit",
"Infrastructure": "Infrastruktur",
"Forms": "Formulare",
"Other": "Sonstiges",
"No connection token is set. Paste the token from your Complianty account to connect.": "Es ist kein Verbindungstoken gesetzt. Fügen Sie das Token aus Ihrem Complianty-Konto ein, um sich zu verbinden.",
"The token is invalid or was revoked. Create a new token in your Complianty account and connect again.": "Das Token ist ungültig oder wurde widerrufen. Erstellen Sie in Ihrem Complianty-Konto ein neues Token und verbinden Sie sich erneut.",
"Too many requests. Please try again in a few minutes.": "Zu viele Anfragen. Bitte versuchen Sie es in einigen Minuten erneut.",
"Too many requests. Please try again in %s.": "Zu viele Anfragen. Bitte versuchen Sie es in %s erneut.",
"The daily scan budget of your plan is used up. Please try again tomorrow.": "Das tägliche Scan-Kontingent Ihres Tarifs ist aufgebraucht. Bitte versuchen Sie es morgen erneut.",
"A scan is already running. The result appears here when it is finished.": "Ein Scan läuft bereits. Das Ergebnis erscheint hier, sobald er fertig ist.",
"The address of this website cannot be checked from outside, for example because it is a local or private address.": "Die Adresse dieser Website kann von außen nicht geprüft werden, zum Beispiel weil es eine lokale oder private Adresse ist.",
"The service did not accept the request. Please reconnect or try again later.": "Der Dienst hat die Anfrage nicht akzeptiert. Bitte verbinden Sie sich erneut oder versuchen Sie es später noch einmal.",
"The scanner is busy right now. Please try again in a few minutes.": "Der Scanner ist gerade ausgelastet. Bitte versuchen Sie es in einigen Minuten erneut.",
"The service reports a problem. Please try again later.": "Der Dienst meldet ein Problem. Bitte versuchen Sie es später erneut.",
"The service could not be reached. Check that your server may connect to app.complianty.de and try again.": "Der Dienst ist nicht erreichbar. Prüfen Sie, ob Ihr Server Verbindungen zu app.complianty.de aufbauen darf, und versuchen Sie es erneut.",
"The service was not reachable recently. The next attempt is made automatically, or press Refresh.": "Der Dienst war zuletzt nicht erreichbar. Der nächste Versuch erfolgt automatisch, oder klicken Sie auf Aktualisieren.",
"The service was not reachable recently. The next attempt is made automatically in %s, or press Refresh.": "Der Dienst war zuletzt nicht erreichbar. Der nächste Versuch erfolgt automatisch in %s, oder klicken Sie auf Aktualisieren.",
"The service sent an unexpected response. Please try again later.": "Der Dienst hat eine unerwartete Antwort gesendet. Bitte versuchen Sie es später erneut.",
"The Complianty subscription for this website is not active. Reactivate it in your Complianty account.": "Das Complianty-Abonnement für diese Website ist nicht aktiv. Aktivieren Sie es in Ihrem Complianty-Konto wieder.",
"The connection is not available for your plan at the moment. Check your plan in your Complianty account.": "Die Verbindung steht für Ihren Tarif derzeit nicht zur Verfügung. Prüfen Sie Ihren Tarif in Ihrem Complianty-Konto.",
"This token is not allowed to do this. Create a token with the required permission in your Complianty account.": "Dieses Token darf das nicht. Erstellen Sie in Ihrem Complianty-Konto ein Token mit der nötigen Berechtigung.",
"This website was checked recently. Please try again later.": "Diese Website wurde kürzlich geprüft. Bitte versuchen Sie es später erneut.",
"This website was checked recently. Please try again in %s.": "Diese Website wurde kürzlich geprüft. Bitte versuchen Sie es in %s erneut.",
"A scan was started recently. Please try again later.": "Ein Scan wurde kürzlich gestartet. Bitte versuchen Sie es später erneut.",
"A scan was started recently. Please try again in %s.": "Ein Scan wurde kürzlich gestartet. Bitte versuchen Sie es in %s erneut.",
"Connected": "Verbunden",
"Token invalid or revoked": "Token ungültig oder widerrufen",
"Subscription not active": "Abonnement nicht aktiv",
"Not available for your plan": "Für Ihren Tarif nicht verfügbar",
"Service not reachable": "Dienst nicht erreichbar",
"Unexpected response from the service": "Unerwartete Antwort des Dienstes",
"Suggested text. Review and adapt it to your situation.": "Textvorschlag. Bitte prüfen und an Ihre Situation anpassen.",
"If an administrator of this website connects the Complianty Connector plugin to a Complianty account, the server of this website contacts the service Complianty (app.complianty.de) to retrieve the status of the website check and the privacy policy that was created and approved in that account. Each request contains a connection token and the plugin version. Personal data of visitors of this website is not sent. Information about the provider is available in its privacy policy: %s": "Wenn ein Administrator dieser Website das Plugin Complianty Connector mit einem Complianty-Konto verbindet, ruft der Server dieser Website beim Dienst Complianty (app.complianty.de) den Status der Website-Prüfung und die in diesem Konto erstellte und freigegebene Datenschutzerklärung ab. Jede Anfrage enthält ein Verbindungstoken und die Plugin-Version. Personenbezogene Daten von Besuchern dieser Website werden nicht gesendet. Angaben zum Anbieter finden Sie in dessen Datenschutzerklärung: %s",
"Visitors of this website do not contact Complianty through this plugin. If the privacy policy of your Complianty account is shown on this website, it is stored on this website and delivered from there.": "Besucher dieser Website nehmen über dieses Plugin keinen Kontakt zu Complianty auf. Wird die Datenschutzerklärung Ihres Complianty-Kontos auf dieser Website angezeigt, ist sie auf dieser Website gespeichert und wird von dort ausgeliefert.",
"Before a token is entered, the plugin makes no requests. The optional free check of the website is only started by an administrator and sends the address of this website to Complianty.": "Solange kein Token eingegeben ist, sendet das Plugin keine Anfragen. Der optionale kostenlose Check der Website wird nur von einem Administrator gestartet und sendet die Adresse dieser Website an Complianty.",
"The state could not be read. Reload the page to try again.": "Der Status konnte nicht gelesen werden. Laden Sie die Seite neu und versuchen Sie es erneut.",
"This takes longer than expected. Reload the page later to see the result.": "Das dauert länger als erwartet. Laden Sie die Seite später neu, um das Ergebnis zu sehen.",
"The scan is running. This page updates when it is finished.": "Der Scan läuft. Diese Seite aktualisiert sich, wenn er fertig ist.",
"Starting the scan...": "Scan wird gestartet ...",
"The scan could not be started.": "Der Scan konnte nicht gestartet werden.",
"Starting the check...": "Prüfung wird gestartet ...",
"The check could not be started.": "Die Prüfung konnte nicht gestartet werden.",
"No approved privacy policy yet. Connect this site in Settings > Complianty and approve the policy in your Complianty account.": "Noch keine freigegebene Datenschutzerklärung. Verbinden Sie diese Website unter Einstellungen > Complianty und geben Sie die Erklärung in Ihrem Complianty-Konto frei.",
"Privacy policy (Complianty)": "Datenschutzerklärung (Complianty)",
"Outputs the approved privacy policy from your Complianty account.": "Gibt die freigegebene Datenschutzerklärung aus Ihrem Complianty-Konto aus.",
"privacy": "Datenschutz",
"privacy policy": "Datenschutzerklärung",
"complianty": "complianty",
"Privacy policy provided with Complianty": "Datenschutzerklärung bereitgestellt mit Complianty",
"Settings": "Einstellungen",
"Save": "Speichern",
"Show a link to Complianty below the privacy policy": "Link zu Complianty unter der Datenschutzerklärung anzeigen",
"Off by default. If you turn it on, a short link to complianty.de appears below the privacy policy on your website.": "Standardmäßig aus. Wenn Sie ihn einschalten, erscheint unter der Datenschutzerklärung auf Ihrer Website ein kurzer Link zu complianty.de.",
}
P = {
"%d critical": ("%d kritisch", "%d kritisch"),
"%d important": ("%d wichtig", "%d wichtig"),
"%d critical finding open": ("%d kritischer Befund offen", "%d kritische Befunde offen"),
"%d important finding open": ("%d wichtiger Befund offen", "%d wichtige Befunde offen"),
"%d tool was detected.": ("%d Tool wurde erkannt.", "%d Tools wurden erkannt."),
"%d tool was active before consent was given.": ("%d Tool war aktiv, bevor eingewilligt wurde.", "%d Tools waren aktiv, bevor eingewilligt wurde."),
}

def unq(s):
    return "".join(re.findall(r'"((?:[^"\\]|\\.)*)"', s)).replace('\\"', '"').replace("\\n", "\n")

def q(s):
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n") + '"'

src = open(POT, encoding="utf-8").read().split("\n\n")
head = src[0]
head = head.replace("FULL NAME <EMAIL@ADDRESS>", "Complianty").replace("LANGUAGE <LL@li.org>", "German").replace("YEAR-MO-DA HO:MI+ZONE", "2026-10-16 12:00+0000")
head = head.replace('"Content-Transfer-Encoding: 8bit\\n"', '"Content-Transfer-Encoding: 8bit\\n"\n"Language: de_DE\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"')
out = [head]
missing = []
for block in src[1:]:
    if not block.strip():
        continue
    lines = block.strip("\n").split("\n")
    meta = [l for l in lines if l.startswith("#")]
    body = [l for l in lines if not l.startswith("#")]
    text = "\n".join(body)
    mid = re.search(r'msgid ((?:"(?:[^"\\]|\\.)*"\n?)+)', text)
    mpl = re.search(r'msgid_plural ((?:"(?:[^"\\]|\\.)*"\n?)+)', text)
    msgid = unq(mid.group(1))
    if mpl:
        tr = P.get(msgid)
        if not tr:
            missing.append(msgid)
            tr = ("", "")
        out.append("\n".join(meta + ["msgid " + q(msgid), "msgid_plural " + q(unq(mpl.group(1))), "msgstr[0] " + q(tr[0]), "msgstr[1] " + q(tr[1])]))
    else:
        tr = T.get(msgid)
        if tr is None:
            missing.append(msgid)
            tr = ""
        out.append("\n".join(meta + ["msgid " + q(msgid), "msgstr " + q(tr)]))
open(OUT, "w", encoding="utf-8").write("\n\n".join(out) + "\n")
print("missing:", missing)
