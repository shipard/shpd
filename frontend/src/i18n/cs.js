// České překlady (zdrojový jazyk projektu).
//
// Klíče jsou ploché s tečkovou notací (`'sidebar.language'`). Konvence:
// `oblast.komponenta.element` — oblast = funkční doména (viewer, form,
// login, sidebar, common, browser, attachments...), element = label /
// placeholder / title / empty / loading / error.
//
// Anglická konvence v názvech klíčů — odpovídá zbytku kódu, kde
// komentáře/proměnné jsou anglicky.
//
// Pluralizace: hodnota může být ICU MessageFormat řetězec, např.
//   '{count, plural, one {# záznam} few {# záznamy} other {# záznamů}}'
//
// Synchronizace s en.js hlídá `npm run check:i18n`.

export default {
  // ── Společné ─────────────────────────────────────────────────────────────
  'common.cancel': 'Zrušit',
  'common.save': 'Uložit',
  'common.saving': 'Ukládám…',
  'common.close': 'Zavřít',
  'common.add': 'Přidat',
  'common.edit': 'Upravit',
  'common.delete': 'Smazat',
  'common.confirm': 'Potvrdit',
  'common.ok': 'OK',
  'common.yes': 'Ano',
  'common.no': 'Ne',
  'common.loading': 'Načítám…',
  'common.error': 'Nastala chyba',
  'common.unknownError': 'neznámá chyba',
  'common.empty': 'Žádné záznamy',

  // ── Sidebar ─────────────────────────────────────────────────────────────
  'sidebar.language': 'Jazyk',
  'sidebar.language.cs': 'Čeština',
  'sidebar.language.en': 'English',
  'sidebar.language.auto': 'Automaticky',
  'sidebar.appearance': 'Vzhled',
  'sidebar.appearance.light': 'Světlý',
  'sidebar.appearance.dark': 'Tmavý',
  'sidebar.appearance.auto': 'Auto',
  'sidebar.collapse': 'Sbalit menu',
  'sidebar.expand': 'Rozbalit menu',
  'sidebar.backToApp': 'Zpět do aplikace',
  'sidebar.accountSettings': 'Nastavení účtu',
  'sidebar.appSettings': 'Nastavení aplikace',
  'sidebar.logout': 'Odhlásit',
  'sidebar.notAuthenticated': 'Nepřihlášen',
  'sidebar.navigationLoadFailed': 'Nepodařilo se načíst navigaci',

  // ── App shell ───────────────────────────────────────────────────────────
  'app.selectMenuItem': 'Vyberte položku v menu',
  'app.unsupportedPanel': 'Nepodporovaný typ panelu: {type}',
  'tabbar.close': 'Zavřít {tab}',

  // ── Login ───────────────────────────────────────────────────────────────
  'login.heading': 'Shipard',
  'login.username': 'Přihlašovací jméno',
  'login.password': 'Heslo',
  'login.submit': 'Přihlásit se',
  'login.submitting': 'Přihlašování…',
  'login.failed': 'Přihlášení se nezdařilo.',
  'login.languagePicker.label': 'Jazyk:',

  // ── Viewer (záložky, hledání, status, reanalyze dialog) ─────────────────
  'viewer.tab.active': 'Aktivní',
  'viewer.tab.archive': 'Archív',
  'viewer.tab.trash': 'Koš',
  'viewer.tab.all': 'Vše',
  'viewer.search.placeholder': 'Hledat…',
  'viewer.search.clear': 'Vymazat hledání',
  'viewer.endOfList': 'To je všechno',
  'viewer.selectRecord': 'Vyberte záznam',
  'viewer.reanalyze.title': 'Znovu analyzovat zprávu',
  'viewer.reanalyze.body': 'Spustit AI analýzu znovu? Existující extrahované dokumenty ve stavech {states} budou označeny jako nahrazené. Dokumenty, které jste již použili nebo zamítli, zůstanou beze změny.',
  'viewer.reanalyze.replaceableStates': 'K použití / Čeká na review / Nízká jistota',
  'viewer.reanalyze.profileLabel': 'Profil (volitelné — výchozí ponechá DS-default):',
  'viewer.reanalyze.defaultProfile': '— výchozí profil DS —',
  'viewer.reanalyze.submit': 'Spustit analýzu',
  'viewer.reanalyze.submitting': 'Spouštím…',
  'viewer.reanalyze.failed': 'Nepodařilo se restartovat analýzu: {msg}',

  // ── Viewer detail (extrahované dokumenty + reject dialog) ───────────────
  'viewer.detail.empty': 'Žádné detaily',
  'viewer.detail.noExtracted': 'Žádné extrahované dokumenty.',
  'viewer.detail.applied': 'Použito: {date}',
  'viewer.detail.rejected': 'Důvod zamítnutí: {reason}',
  'viewer.detail.showDetail': 'Zobrazit detail',
  'viewer.detail.apply': 'Použít',
  'viewer.detail.reject': 'Zamítnout',
  'viewer.detail.rejectTitle': 'Zamítnout dokument',
  'viewer.detail.rejectReasonLabel': 'Důvod zamítnutí (povinné):',
  'viewer.detail.rejectReasonPlaceholder': 'Např. False positive, špatně rozpoznaný typ…',
  'viewer.detail.applyFailed': 'Nepodařilo se uložit: {msg}',
  'viewer.detail.rejectFailed': 'Nepodařilo se zamítnout: {msg}',

  // ── Form (FormDialog, FormEditor, FormRenderer, FormStateBar) ───────────
  'form.titleNew': 'Nový záznam',
  'form.unsavedChanges': 'Máte neuložené změny. Opravdu chcete zavřít formulář?',
  'form.loadFailed': 'Nepodařilo se načíst formulář.',
  'form.saveFailed': 'Nepodařilo se uložit záznam.',
  'form.transitionFailed': 'Nepodařilo se změnit stav.',
  'form.metaLoadFailed': 'Nepodařilo se načíst metadata tabulky.',
  'form.recordLoadFailed': 'Nepodařilo se načíst záznam.',
  'form.saveUnknownError': 'Při ukládání došlo k neznámé chybě.',
  'form.groupOther': 'Ostatní',

  // ── Form sub-table ──────────────────────────────────────────────────────
  'subtable.saveFirst': 'Nejprve uložte záznam, poté budete moci přidávat záznamy.',
  'subtable.confirmDelete': 'Opravdu chcete smazat tento záznam?',

  // ── Attachments ─────────────────────────────────────────────────────────
  'attachments.title': 'Přílohy',
  'attachments.saveFirst': 'Nejprve uložte záznam, poté budete moci přidávat přílohy.',
  'attachments.upload': 'Nahrát přílohu',
  'attachments.count': '{count, plural, one {# příloha} few {# přílohy} many {# příloh} other {# příloh}}',
  'attachments.loading': 'Načítám přílohy…',
  'attachments.dropHint': 'Přetáhněte soubory sem nebo klikněte na „Nahrát přílohu"',
  'attachments.action.download': 'Stáhnout',
  'attachments.action.rename': 'Přejmenovat',
  'attachments.confirmDelete': 'Opravdu smazat přílohu „{name}"?',

  // ── Server error codes (mapped via i18n/errors.js translateError()) ─────
  // Top-level error.code values from Response::error() in PHP. Unknown
  // codes fall back to error.message (English). Add a key here when a
  // user-visible code starts showing up in the UI.
  'error.VALIDATION_ERROR': 'Validace selhala',
  'error.NOT_FOUND': 'Záznam nenalezen',
  'error.RECORD_NOT_FOUND': 'Záznam nenalezen',
  'error.TABLE_NOT_FOUND': 'Tabulka neexistuje',
  'error.UNAUTHORIZED': 'Pro tuto akci musíte být přihlášen',
  'error.FORBIDDEN': 'Nedostatečná oprávnění',
  'error.BAD_REQUEST': 'Chybný požadavek',
  'error.METHOD_NOT_ALLOWED': 'Metoda není povolená',
  'error.INTERNAL_ERROR': 'Vnitřní chyba serveru',
  'error.UPLOAD_ERROR': 'Chyba při nahrávání souboru',
  'error.NETWORK_ERROR': 'Chyba sítě — zkontrolujte připojení',

  // ── Table browser ───────────────────────────────────────────────────────
  'browser.addRecord': 'Nový záznam',
  'browser.fetchFailed': 'Chyba při načítání dat',
  'browser.metaFetchFailed': 'Chyba při načítání metadat',
  'browser.pagination.info': 'Zobrazeno {start}–{end} z {total} záznamů',
  'browser.pagination.prev': '← Předchozí',
  'browser.pagination.next': 'Další →',
  'browser.pagination.pageSize': 'Na stránku:',
};
